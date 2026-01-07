!pip install geopandas shapely pyproj fiona

import geopandas as gpd
import numpy as np
from shapely.geometry import box
from google.colab import drive

drive.mount('/content/drive')

KBA_PATH = "/content/drive/MyDrive/[DENR] KBA/KBA"
PA_PATH  = "/content/drive/MyDrive/[DENR] KBA/PA"

OUTPUT_FOLDER = "/content/drive/MyDrive/[DENR] KBA/"

AOI_BOUNDS = {
    "minx": 120.9,
    "miny": 14.3,
    "maxx": 121.15,
    "maxy": 14.8
}

GRID_RES = 0.005  # ~500m

def create_grid(bounds, resolution):
    xs = np.arange(bounds["minx"], bounds["maxx"], resolution)
    ys = np.arange(bounds["miny"], bounds["maxy"], resolution)

    cells = []
    for x in xs:
        for y in ys:
            cells.append(box(x, y, x + resolution, y + resolution))

    return gpd.GeoDataFrame(geometry=cells, crs="EPSG:4326")

grid = create_grid(AOI_BOUNDS, GRID_RES)
print("Number of grid cells:", len(grid))

kba = gpd.read_file(KBA_PATH).to_crs("EPSG:4326")
pa  = gpd.read_file(PA_PATH).to_crs("EPSG:4326")

grid["is_kba"] = grid.intersects(kba.unary_union)
grid["is_pa"]  = grid.intersects(pa.unary_union)

# Add centroid coordinates
grid["lon"] = grid.geometry.centroid.x
grid["lat"] = grid.geometry.centroid.y

final_df = grid[[
    "lon", "lat",
    "is_kba", "is_pa"
]]

output_path = OUTPUT_FOLDER + "ncr_grid_kba_pa_flags.csv"
final_df.to_csv(output_path, index=False)

print("Saved to:", output_path)

print(KBA_PATH)
print(PA_PATH)


kba_union = kba.unary_union
pa_union  = pa.unary_union

print(kba_union.difference(pa_union).area)

# 1. File sanity
print("KBA features:", len(kba))
print("PA features:", len(pa))

# 2. Are they literally the same geometry?
print("Same geometry:", kba.unary_union.equals(pa.unary_union))

# 3. Does KBA extend outside PA?
print("KBA outside PA area:", kba.unary_union.difference(pa.unary_union).area)

# 4. How many grid cells intersect?
print("KBA cells:", grid["is_kba"].sum())
print("PA cells:", grid["is_pa"].sum())

print(kba.head())
print(pa.head())
