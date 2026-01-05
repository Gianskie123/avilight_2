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
EXPORT_NAME  = 'LC_Type_2014-2020_500M'

def create_grid(aoi, res):
    bounds = ee.List(aoi.bounds().coordinates().get(0))
    lon_min = ee.Number(ee.List(bounds.get(0)).get(0))
    lat_min = ee.Number(ee.List(bounds.get(0)).get(1))
    lon_max = ee.Number(ee.List(bounds.get(2)).get(0))
    lat_max = ee.Number(ee.List(bounds.get(2)).get(1))

    lons = ee.List.sequence(lon_min, lon_max.subtract(res), res)
    lats = ee.List.sequence(lat_min, lat_max.subtract(res), res)

    def make_cell(lon, lat):
        sw = [lon, lat]
        ne = [lon.add(res), lat.add(res)]
        geom = ee.Geometry.Rectangle([sw[0], sw[1], ne[0], ne[1]], None, False)
        # We set longitude and latitude as properties RIGHT HERE
        return ee.Feature(geom).set({
            'cell_id': ee.String('cell_').cat(lon.format('%.4f')).cat('_').cat(lat.format('%.4f')),
            'longitude': lon.add(res/2),
            'latitude': lat.add(res/2)
        })

    grid = lons.map(lambda lon: lats.map(lambda lat: make_cell(ee.Number(lon), ee.Number(lat)))).flatten()
    return ee.FeatureCollection(grid).filterBounds(aoi)

grid_fc = create_grid(AOI, GRID_RES)

lc_ic = (ee.ImageCollection("MODIS/061/MCD12Q1")
         .filterDate(START_DATE, END_DATE)
         .select('LC_Type1'))

def reduce_lc(image):
    year = image.date().get('year')

    stats = image.reduceRegions(
        collection=grid_fc,
        reducer=ee.Reducer.mode(),
        scale=500
    )

    def format_row(f):
        return f.set({
            'year': year,
            'land_cover': f.get('mode'), # Copy 'mode' to 'land_cover'
            # longitude and latitude are already in grid_fc
        })

    return stats.map(format_row)

# Process all years
panel_fc = lc_ic.map(reduce_lc).flatten()

final_export_fc = panel_fc.select(['cell_id', 'year', 'longitude', 'latitude', 'land_cover'])

# Export
task = ee.batch.Export.table.toDrive(
    collection=final_export_fc,
    description='AviLight_LandCover_Clean',
    folder='AviLight_Data',
    fileFormat='CSV'
)
task.start()

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
