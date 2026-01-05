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
DRIVE_FOLDER = 'AviLight_Land_Cover'
EXPORT_NAME  = 'LC_Phenology_2014-2020_500M'

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

pheno_ic = (ee.ImageCollection("MODIS/061/MCD12Q2")
            .filterDate(START_DATE, END_DATE)
            .select(['Greenup_1', 'MidGreenup_1', 'Peak_1', 'Dormancy_1']))

def reduce_phenology(image):
    year = ee.Date(image.get('system:time_start')).get('year')

    reduced = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mean(),
        scale=500
    )

    return reduced.map(lambda f: f.set({
        'year': year,
        'greenup': f.get('Greenup_1'),
        'peak': f.get('Peak_1'),
        'dormancy': f.get('Dormancy_1')
    }).select(['cell_id', 'longitude', 'latitude', 'year', 'greenup', 'peak', 'dormancy']))

# Process and Flatten
panel_fc = pheno_ic.map(reduce_phenology).flatten()

# Filter out nulls (areas with no vegetation detected)
panel_fc = panel_fc.filter(ee.Filter.notNull(['greenup']))

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

import time

# Get the list of all tasks
tasks = ee.batch.Task.list()

# Print the status of the most recent task
latest_task = tasks[0]
print(f"Task Name: {latest_task.status()['description']}")
print(f"Status: {latest_task.status()['state']}")

if latest_task.status()['state'] == 'RUNNING':
    print("Please wait... the servers are still processing your MODIS data.")
elif latest_task.status()['state'] == 'COMPLETED':
    print("Done! Check your Google Drive folder now.")
