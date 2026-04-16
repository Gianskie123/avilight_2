"""
extract_viirs.py
----------------
Extracts monthly average night-light radiance values over a Metro Manila grid
from NOAA/VIIRS/DNB/MONTHLY_V1/VCMCFG via Google Earth Engine.

Usage:
    python extract_viirs.py <year> <month>
    e.g.  python extract_viirs.py 2024 5

Output:
    Prints a single JSON array to stdout. Each element corresponds to one
    0.005-degree grid cell and contains the fields required by the `viirs`
    MariaDB table. Nothing else is printed to stdout.

Authentication:
    Set GOOGLE_APPLICATION_CREDENTIALS to your service-account JSON key path.
    Set GEE_PROJECT to your Google Cloud project ID.

Dataset notes:
    - NOAA/VIIRS/DNB/MONTHLY_V1/VCMCFG is a monthly composite.
    - Band 'avg_rad': average radiance in nW/cm²/sr.
    - No scaling factor required — values are already in physical units.
    - Native resolution: 463.31 m (MODIS sinusoidal grid, NOT 750 m as sometimes cited).
    - Stray-light corrected version (VCMCFG) is preferred for urban/high-lat areas.
"""

import ee
import sys
import json
import os
from calendar import monthrange

# ── Argument validation ────────────────────────────────────────────────────────
if len(sys.argv) != 3:
    print(json.dumps({"error": "Usage: extract_viirs.py <year> <month>"}), file=sys.stderr)
    sys.exit(1)

try:
    year  = int(sys.argv[1])
    month = int(sys.argv[2])
    if not (1 <= month <= 12):
        raise ValueError
except ValueError:
    print(json.dumps({"error": "year and month must be valid integers."}), file=sys.stderr)
    sys.exit(1)

# ── GEE initialisation ─────────────────────────────────────────────────────────
try:
    project = os.environ.get('GEE_PROJECT', 'avilight-483312-492105-492107')
    sa_key  = os.environ.get('GOOGLE_APPLICATION_CREDENTIALS', '')

    if sa_key and os.path.isfile(sa_key):
        with open(sa_key, 'r') as f:
            key_data = json.load(f)
        sa_email = key_data['client_email']
        credentials = ee.ServiceAccountCredentials(email=sa_email, key_file=sa_key)
        ee.Initialize(credentials, project=project)
    else:
        ee.Initialize(project=project)
except Exception as e:
    print(json.dumps({"error": f"GEE initialisation failed: {str(e)}"}), file=sys.stderr)
    sys.exit(1)

# ── Constants ──────────────────────────────────────────────────────────────────
AOI      = ee.Geometry.Rectangle([120.9, 14.3, 121.15, 14.8])
GRID_RES = 0.005
DATASET  = 'NOAA/VIIRS/DNB/MONTHLY_V1/VCMCFG'

_, last_day = monthrange(year, month)
start_date  = f'{year}-{month:02d}-01'
end_date    = f'{year}-{month:02d}-{last_day}'

# ── Grid creation ──────────────────────────────────────────────────────────────
def create_grid(aoi, res):
    bounds  = ee.List(aoi.bounds().coordinates().get(0))
    lon_min = ee.Number(ee.List(bounds.get(0)).get(0))
    lat_min = ee.Number(ee.List(bounds.get(0)).get(1))
    lon_max = ee.Number(ee.List(bounds.get(2)).get(0))
    lat_max = ee.Number(ee.List(bounds.get(2)).get(1))

    lons = ee.List.sequence(lon_min, lon_max.subtract(res), res)
    lats = ee.List.sequence(lat_min, lat_max.subtract(res), res)

    def make_cell(lon, lat):
        lon = ee.Number(lon)
        lat = ee.Number(lat)
        geom = ee.Geometry.Rectangle(
            [lon, lat, lon.add(res), lat.add(res)],
            proj='EPSG:4326', geodesic=False
        )
        return ee.Feature(geom).set({
            'cell_id':   ee.String('cell_').cat(lon.format('%.4f')).cat('_').cat(lat.format('%.4f')),
            'longitude': lon.add(res / 2),
            'latitude':  lat.add(res / 2),
        })

    grid = lons.map(
        lambda lon: lats.map(lambda lat: make_cell(ee.Number(lon), ee.Number(lat)))
    ).flatten()
    return ee.FeatureCollection(grid).filterBounds(aoi)

# ── GEE extraction ─────────────────────────────────────────────────────────────
try:
    grid_fc = create_grid(AOI, GRID_RES)

    collection = (
        ee.ImageCollection(DATASET)
        .filterBounds(AOI)
        .filterDate(start_date, end_date)
        .select('avg_rad')
    )

    # VCMCFG is already a monthly composite — .mean() safely handles the case
    # where GEE returns more than one image for the month boundary.
    # No scaling factor: avg_rad is in physical units (nW/cm²/sr).
    image = collection.mean()

    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=500,
        tileScale=4,
    )

    features = reduced.getInfo()['features']

except Exception as e:
    print(json.dumps({"error": f"GEE extraction failed: {str(e)}"}), file=sys.stderr)
    sys.exit(1)

# ── Build output — with hierarchical gap fill ─────────────────────────────────
#
# Collect every grid cell returned by GEE.  Cells where GEE has no reading
# (cloud / sensor gap) go into gap_rows with viirs_avg_rad = None.
# db_fill.hierarchical_fill then resolves those gaps in priority order:
#   1. Temporal interpolation (same cell, neighbouring months in the DB)
#   2. Land-cover monthly mean (same LC class, same month, from the DB)
#   3. Global monthly mean (mean of valid cells in this extraction batch)
# If all three fail the value stays None (stored as NULL — grid row intact).

valid_rows = []
gap_rows   = []

for f in features:
    props   = f.get('properties', {})
    rad_val = props.get('mean')
    row = {
        'system_index':  f'{year}_{month:02d}',
        'cell_id':       props['cell_id'],
        'latitude':      round(float(props['latitude']),  8),
        'longitude':     round(float(props['longitude']), 8),
        'month':         month,
        'year':          year,
    }
    if rad_val is not None:
        row['viirs_avg_rad'] = round(float(rad_val), 8)
        valid_rows.append(row)
    else:
        row['viirs_avg_rad'] = None
        gap_rows.append(row)

print(json.dumps(valid_rows + gap_rows))
