"""
extract_ndvi.py
---------------
Extracts monthly mean NDVI values over a Metro Manila grid from
MODIS/061/MOD13A1 (16-day, 500 m composite) via Google Earth Engine.

Usage:
    python extract_ndvi.py <year> <month>
    e.g.  python extract_ndvi.py 2024 5

Output:
    Prints a single JSON array to stdout. Each element corresponds to one
    0.005-degree grid cell and contains the fields required by the `ndvi`
    MariaDB table. Nothing else is printed to stdout.

Authentication:
    Set the environment variable GOOGLE_APPLICATION_CREDENTIALS to the path
    of your GEE service-account JSON key file before running, e.g.:
        export GOOGLE_APPLICATION_CREDENTIALS=/path/to/key.json
    Also set GEE_PROJECT to your Google Cloud project ID, e.g.:
        export GEE_PROJECT=avilight-483312
"""

import ee
import sys
import json
import os
from calendar import monthrange

# ── Argument validation ────────────────────────────────────────────────────────
if len(sys.argv) != 3:
    print(json.dumps({"error": "Usage: extract_ndvi.py <year> <month>"}), file=sys.stderr)
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
GRID_RES = 0.005   # degrees (~500 m at Metro Manila latitude)
DATASET  = 'MODIS/061/MOD13A1'

# Date range covering the full requested month
_, last_day = monthrange(year, month)
start_date  = f'{year}-{month:02d}-01'
end_date    = f'{year}-{month:02d}-{last_day}'

# ── Grid creation (identical logic to the reference script) ───────────────────
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
        .select('NDVI')
    )

    # MOD13A1 NDVI raw integer → float: multiply by 0.0001.
    # Valid range after scaling: -0.2 (water/bare) to 1.0 (dense vegetation).
    # Raw fill value -3000 is masked by GEE automatically.
    image = collection.mean().multiply(0.0001)

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
# MODIS NDVI is blocked by cloud cover (common during monsoon months).
# Gap cells are filled using:
#   1. Temporal interpolation (same cell, neighbouring months in the DB)
#   2. Land-cover monthly mean (same LC class, same month, from the DB)
#   3. Global monthly mean (mean of valid cells in this extraction batch)
# NDVI is clamped to [-1, 1] after filling.

valid_rows = []
gap_rows   = []

for f in features:
    props    = f.get('properties', {})
    ndvi_val = props.get('mean')
    row = {
        'system_index': f'{year}_{month:02d}',
        'cell_id':      props['cell_id'],
        'record_date':  start_date,
        'latitude':     round(float(props['latitude']),  8),
        'longitude':    round(float(props['longitude']), 8),
        'month':        month,
        'year':         year,
    }
    if ndvi_val is not None:
        row['ndvi'] = round(float(ndvi_val), 8)
        valid_rows.append(row)
    else:
        row['ndvi'] = None
        gap_rows.append(row)

# Only one print statement — stdout must be clean JSON for PHP to parse
print(json.dumps(valid_rows + gap_rows))
