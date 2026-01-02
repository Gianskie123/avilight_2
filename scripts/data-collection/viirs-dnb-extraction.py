import ee

ee.Authenticate()
ee.Initialize(project='avilight')

# --- AOI: Metro Manila ---
AOI = ee.Geometry.Rectangle([120.9, 14.3, 121.15, 14.8])

# --- Grid resolution ---
GRID_RES = 0.005

# --- Time range ---
START_DATE = '2014-01-01'
END_DATE   = '2021-01-01'  # inclusive of 2020

# --- Export settings ---
DRIVE_FOLDER = 'AviLight_VIIRS'
EXPORT_NAME  = 'MetroManila_VIIRS_Long_2014_2020'

def create_grid(aoi, res):
    bounds = ee.List(aoi.bounds().coordinates().get(0))

    lon_min = ee.Number(ee.List(bounds.get(0)).get(0))
    lat_min = ee.Number(ee.List(bounds.get(0)).get(1))
    lon_max = ee.Number(ee.List(bounds.get(2)).get(0))
    lat_max = ee.Number(ee.List(bounds.get(2)).get(1))

    lons = ee.List.sequence(lon_min, lon_max.subtract(res), res)
    lats = ee.List.sequence(lat_min, lat_max.subtract(res), res)

    def make_cell(lon, lat):
        geom = ee.Geometry.Rectangle(
            [lon, lat, lon.add(res), lat.add(res)],
            proj='EPSG:4326',
            geodesic=False
        )
        return ee.Feature(geom).set({
            'cell_id': ee.String('cell_')
                .cat(lon.format('%.4f')).cat('_')
                .cat(lat.format('%.4f')),
            'longitude': lon.add(res / 2),
            'latitude': lat.add(res / 2)
        })

    grid = lons.map(
        lambda lon: lats.map(
            lambda lat: make_cell(ee.Number(lon), ee.Number(lat))
        )
    ).flatten()

    return ee.FeatureCollection(grid).filterBounds(aoi)

grid_fc = create_grid(AOI, GRID_RES)

print('Grid cell count:', grid_fc.size().getInfo())

viirs_ic = (
    ee.ImageCollection('NOAA/VIIRS/DNB/MONTHLY_V1/VCMCFG')
    .filterDate(START_DATE, END_DATE)
    .select('avg_rad')
)

def reduce_month(image):
    date = ee.Date(image.get('system:time_start'))

    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=463.8
    )

    return reduced.map(lambda f: f.set({
        'year': date.get('year'),
        'month': date.get('month'),
        'date': date.format('YYYY-MM'),
        'viirs_avg_rad': f.get('mean')
    }).select([
        'cell_id',
        'longitude',
        'latitude',
        'year',
        'month',
        'date',
        'viirs_avg_rad'
    ]))

panel_fc = viirs_ic.map(reduce_month).flatten()

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
