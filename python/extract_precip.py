"""
extract_precip.py
-----------------
Extracts total monthly precipitation (mm) over a Metro Manila grid from
UCSB-CHG/CHIRPS/DAILY via Google Earth Engine.

Usage:
    python extract_precip.py <year> <month>
    e.g.  python extract_precip.py 2024 5

Output:
    Prints a single JSON array to stdout. Each element corresponds to one
    0.005-degree grid cell and contains the fields required by the `precip`
    MariaDB table. Nothing else is printed to stdout.

Authentication:
    Set GOOGLE_APPLICATION_CREDENTIALS to your service-account JSON key path.
    Set GEE_PROJECT to your Google Cloud project ID.

Dataset notes:
    UCSB-CHG/CHIRPS/DAILY — Climate Hazards Group InfraRed Precipitation with
    Station data, daily, ~5 km (0.05°) resolution.
    Band 'precipitation': daily rainfall in mm/day. No scaling factor needed.

    Aggregation strategy:
        Sum all daily images within the month → total monthly precipitation (mm).
        This is the ecologically meaningful quantity for habitat/species models.

    Note on spatial resolution:
        CHIRPS native resolution is ~5 km (0.05°). Our grid cells are 0.005°,
        so ~100 grid cells share each CHIRPS pixel value. The reduce scale is
        set to 5000 m to sample one CHIRPS pixel per reduce call, avoiding
        artificial sub-pixel variation.
"""

import ee
import sys
import json
import os
from calendar import monthrange

# ── Argument validation ────────────────────────────────────────────────────────
if len(sys.argv) != 3:
    print(json.dumps({"error": "Usage: extract_precip.py <year> <month>"}), file=sys.stderr)
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
DATASET  = 'UCSB-CHG/CHIRPS/DAILY'

_, last_day = monthrange(year, month)
start_date  = f'{year}-{month:02d}-01'
# filterDate end is exclusive, so use the 1st of next month
if month == 12:
    end_date = f'{year + 1}-01-01'
else:
    end_date = f'{year}-{month + 1:02d}-01'

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
        .select('precipitation')
    )

    # Sum all daily images → total monthly precipitation in mm.
    # ee.Reducer.sum() with sharedInputs=True is equivalent to collection.sum()
    # but explicit here for clarity.
    monthly_total = collection.sum()

    reduced = monthly_total.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=500,
        tileScale=4,
    )

    features = reduced.getInfo()['features']

except Exception as e:
    print(json.dumps({"error": f"GEE extraction failed: {str(e)}"}), file=sys.stderr)
    sys.exit(1)

# ── Build output ───────────────────────────────────────────────────────────────
result = []
for f in features:
    props      = f.get('properties', {})
    precip_val = props.get('mean')

    if precip_val is None:
        continue

    result.append({
        'system_index': f'{year}_{month:02d}',
        'cell_id':      props['cell_id'],
        'latitude':     round(float(props['latitude']),  8),
        'longitude':    round(float(props['longitude']), 8),
        'month':        month,
        'year':         year,
        'precip_mm':    round(float(precip_val), 4),
    })

print(json.dumps(result))
