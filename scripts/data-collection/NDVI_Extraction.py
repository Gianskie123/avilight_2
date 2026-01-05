import ee

ee.Authenticate()
ee.Initialize(project='avilight-483312')

# --- AOI: Metro Manila ---
AOI = ee.Geometry.Rectangle([120.9, 14.3, 121.15, 14.8])

# --- Grid resolution ---
GRID_RES = 0.005

# --- Time range ---
START_DATE = '2014-01-01'
END_DATE   = '2021-01-01'  # inclusive of 2020

# --- Export settings ---
DRIVE_FOLDER = 'AviLight_NDVI'
EXPORT_NAME  = 'MetroManila_NDVI_2014_2020'

def create_grid(aoi, res):
    bounds = ee.List(aoi.bounds().coordinates().get(0))
    lon_min = ee.Number(ee.List(bounds.get(0)).get(0))
    lat_min = ee.Number(ee.List(bounds.get(0)).get(1))
    lon_max = ee.Number(ee.List(bounds.get(2)).get(0))
    lat_max = ee.Number(ee.List(bounds.get(2)).get(1))

    lons = ee.List.sequence(lon_min, lon_max.subtract(res), res)
    lats = ee.List.sequence(lat_min, lat_max.subtract(res), res)

    def make_cell(lon, lat):
        geom = ee.Geometry.Rectangle([lon, lat, lon.add(res), lat.add(res)], proj='EPSG:4326', geodesic=False)
        return ee.Feature(geom).set({
            'cell_id': ee.String('cell_').cat(lon.format('%.4f')).cat('_').cat(lat.format('%.4f')),
            'longitude': lon.add(res / 2),
            'latitude': lat.add(res / 2)
        })

    grid = lons.map(lambda lon: lats.map(lambda lat: make_cell(ee.Number(lon), ee.Number(lat)))).flatten()
    return ee.FeatureCollection(grid).filterBounds(aoi)

grid_fc = create_grid(AOI, GRID_RES)

ndvi_ic = (ee.ImageCollection('MODIS/061/MOD13A1')
            .filterDate(START_DATE, END_DATE)
            .select('NDVI'))

def apply_scale(img):
    # Scale factor 0.0001 converts raw integers to NDVI range (-0.2 to 1.0)
    return img.multiply(0.0001).copyProperties(img, ['system:time_start'])

ndvi_scaled = ndvi_ic.map(apply_scale)

def reduce_regions_modis(image):
    date = ee.Date(image.get('system:time_start'))

    # Calculate mean per grid cell
    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=500
    )

    def format_row(f):
        return f.set({
            'year': date.get('year'),
            'month': date.get('month'),
            'date': date.format('YYYY-MM-dd'),
            'ndvi': f.get('mean')
        })

    # select() ensures only these specific columns appear in the CSV
    return reduced.map(format_row).select(['cell_id', 'longitude', 'latitude', 'year', 'month', 'date', 'ndvi'])

# Process all images and flatten into one table
panel_fc = ndvi_scaled.map(reduce_regions_modis).flatten()

# Remove rows where NDVI is missing (e.g., due to clouds)
panel_fc = panel_fc.filter(ee.Filter.notNull(['ndvi']))

task = ee.batch.Export.table.toDrive(
    collection=panel_fc,
    description=EXPORT_NAME,
    folder=DRIVE_FOLDER,
    fileNamePrefix=EXPORT_NAME,
    fileFormat='CSV'
)

task.start()

print('✅ Export started')
print('📁 Drive folder:', DRIVE_FOLDER)
