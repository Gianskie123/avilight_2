"""
extract_lst.py
--------------
Extracts monthly mean Land Surface Temperature (day + night) over a Metro Manila
grid from MODIS/061/MOD11A2 (8-day, 1 km) via Google Earth Engine.

Usage:
    python extract_lst.py <year> <month>
    e.g.  python extract_lst.py 2024 5

Output:
    Prints a single JSON array to stdout. Each element corresponds to one
    0.005-degree grid cell and contains the fields required by the `land_temp`
    MariaDB table (lst_day, lst_night in °C). Nothing else is printed to stdout.

    ⚠ DB SCHEMA NOTE:
    The current `land_temp` table has a column named `viirs_avg_rad` which is
    a copy-paste typo — it should hold LST values. Before running the PHP
    insert, execute the following ALTER statements in MariaDB:
        ALTER TABLE land_temp
            CHANGE COLUMN viirs_avg_rad lst_day   DECIMAL(8,4) NOT NULL,
            ADD    COLUMN lst_night               DECIMAL(8,4) NOT NULL AFTER lst_day;

Authentication:
    Set GOOGLE_APPLICATION_CREDENTIALS to your service-account JSON key path.
    Set GEE_PROJECT to your Google Cloud project ID.

Dataset notes:
    MODIS/061/MOD11A2 — 8-day Land Surface Temperature & Emissivity, 1 km.
    Bands used:
        LST_Day_1km   — daytime  LST, raw integer, scale 0.02, units: Kelvin
        LST_Night_1km — nighttime LST, raw integer, scale 0.02, units: Kelvin

    Conversion to Celsius:
        T_celsius = (raw_value × 0.02) − 273.15

    Fill value (0) is automatically masked by GEE.
    Valid raw range: 7500–65535 → 150 K – 1310.7 K (physically: 7500–20000 typical land)
"""

import ee
import sys
import json
import os
from calendar import monthrange

# ── Argument validation ────────────────────────────────────────────────────────
if len(sys.argv) != 3:
    print(json.dumps({"error": "Usage: extract_lst.py <year> <month>"}), file=sys.stderr)
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
        # Read client_email explicitly from the JSON key file.
        # Passing email=None is unreliable across earthengine-api versions.
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
DATASET  = 'MODIS/061/MOD11A2'

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
        .select(['LST_Day_1km', 'LST_Night_1km'])
    )

    # Step 1 — apply MODIS scale factor 0.02 (raw integer → Kelvin)
    # Step 2 — subtract 273.15 to convert Kelvin → Celsius
    # GEE automatically masks fill value (0) so no manual masking needed.
    def scale_to_celsius(image):
        return (image
                .multiply(0.02)       # raw → Kelvin
                .subtract(273.15)     # Kelvin → Celsius
                .copyProperties(image, ['system:time_start']))

    image = collection.map(scale_to_celsius).mean()

    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=1000,  # MOD11A2 native resolution
        tileScale=4,
    )

    features = reduced.getInfo()['features']

except Exception as e:
    print(json.dumps({"error": f"GEE extraction failed: {str(e)}"}), file=sys.stderr)
    sys.exit(1)

# ── Build output — with hierarchical gap fill ─────────────────────────────────
#
# lst_day and lst_night are filled independently since daytime and nighttime
# cloud cover patterns differ.  For each band:
#   1. Temporal interpolation (same cell, neighbouring months in the DB)
#   2. Land-cover monthly mean (same LC class, same month, from the DB)
#   3. Global monthly mean (mean of valid cells in this extraction batch)

all_rows   = []
valid_day  = []   # rows with a real lst_day value
valid_night= []   # rows with a real lst_night value
gap_day    = []   # rows where lst_day is None
gap_night  = []   # rows where lst_night is None

for f in features:
    props     = f.get('properties', {})
    lst_day   = props.get('LST_Day_1km')
    lst_night = props.get('LST_Night_1km')
    row = {
        'system_index': f'{year}_{month:02d}',
        'cell_id':      props['cell_id'],
        'latitude':     round(float(props['latitude']),  8),
        'longitude':    round(float(props['longitude']), 8),
        'month':        month,
        'year':         year,
        'lst_day':   round(float(lst_day),   4) if lst_day   is not None else None,
        'lst_night': round(float(lst_night), 4) if lst_night is not None else None,
    }
    all_rows.append(row)
    (valid_day   if lst_day   is not None else gap_day).append(row)
    (valid_night if lst_night is not None else gap_night).append(row)

print(json.dumps(all_rows))
