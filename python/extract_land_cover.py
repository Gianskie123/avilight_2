"""
extract_land_cover.py
---------------------
Extracts annual land cover type per grid cell for Metro Manila from
MODIS/061/MCD12Q1 (annual, 500 m) via Google Earth Engine.

Usage:
    python extract_land_cover.py <year>
    e.g.  python extract_land_cover.py 2024

Output:
    Prints a single JSON array to stdout. Each element corresponds to one
    0.005-degree grid cell and contains the fields required by the `land_cover`
    MariaDB table. Nothing else is printed to stdout.

Authentication:
    Set GOOGLE_APPLICATION_CREDENTIALS to your service-account JSON key path.
    Set GEE_PROJECT to your Google Cloud project ID.

Dataset notes:
    MODIS/061/MCD12Q1 — MODIS Land Cover Type, annual, 500 m.
    Band used:
        LC_Type1 — Annual IGBP classification. Integer codes 1–17:
            1  Evergreen Needleleaf Forests
            2  Evergreen Broadleaf Forests
            3  Deciduous Needleleaf Forests
            4  Deciduous Broadleaf Forests
            5  Mixed Forests
            6  Closed Shrublands
            7  Open Shrublands
            8  Woody Savannas
            9  Savannas
           10  Grasslands
           11  Permanent Wetlands
           12  Croplands
           13  Urban and Built-up Lands
           14  Cropland/Natural Vegetation Mosaics
           15  Permanent Snow and Ice
           16  Barren
           17  Water Bodies

    Reducer:
        ee.Reducer.mode() — most frequent class code within each 0.005° cell.
        Never average class codes: they are categorical, not ordinal.

    Availability note:
        MCD12Q1 for year Y is typically released in GEE around mid-year Y+1.
        Attempting to extract a year before the product is available will
        return no features; the script will output an empty array in that case.
"""

import ee
import sys
import json
import os

# ── Argument validation ────────────────────────────────────────────────────────
if len(sys.argv) != 2:
    print(json.dumps({"error": "Usage: extract_land_cover.py <year>"}), file=sys.stderr)
    sys.exit(1)

try:
    year = int(sys.argv[1])
    if year < 2001 or year > 2100:
        raise ValueError
except ValueError:
    print(json.dumps({"error": "year must be a valid integer (2001–present)."}), file=sys.stderr)
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
DATASET  = 'MODIS/061/MCD12Q1'

# MCD12Q1 images are dated Jan 1 of the observation year.
start_date = f'{year}-01-01'
end_date   = f'{year}-12-31'

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
        .select('LC_Type1')
    )

    # MCD12Q1 is annual — .first() gets the single image for the requested year.
    # ee.Reducer.mode() returns the most frequent IGBP class code within each
    # 0.005° cell. scale=500 matches the native MCD12Q1 resolution.
    image = collection.first()

    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mode(),
        scale=500,
        tileScale=4,
    )

    features = reduced.getInfo()['features']

except Exception as e:
    print(json.dumps({"error": f"GEE extraction failed: {str(e)}"}), file=sys.stderr)
    sys.exit(1)

# ── Build output ───────────────────────────────────────────────────────────────
rows = []
for f in features:
    props  = f.get('properties', {})
    lc_val = props.get('mode')
    rows.append({
        'system_index': f'{year}_01_01',
        'cell_id':      props['cell_id'],
        'latitude':     round(float(props['latitude']),  8),
        'longitude':    round(float(props['longitude']), 8),
        'year':         year,
        'land_cover':   int(lc_val) if lc_val is not None else None,
    })

print(json.dumps(rows))
