#!/usr/bin/env python3
"""Rebuild KBA/PA audit aggregates from live DB tables and NCR shapefiles.

Inputs:
- NCR_Key_Biodiversity_Areas.shp
- NCR_Protected Areas.shp
- raw_bird_observation, species_masterlist, viirs, ndvi, land_temp, precip

Outputs:
- kba_pa_audit_live (MySQL table)
"""

import json
import math
import os
import re
import sys
from typing import Any, Dict, List, Optional, Sequence, Tuple

try:
    import pymysql
except Exception as exc:  # pragma: no cover
    print(json.dumps({"success": False, "error": f"pymysql import failed: {exc}"}))
    raise SystemExit(1)

try:
    import shapefile  # pyshp
except Exception as exc:  # pragma: no cover
    print(json.dumps({"success": False, "error": f"pyshp import failed: {exc}"}))
    raise SystemExit(1)


TARGET_AREAS: List[Dict[str, Any]] = [
    {
        "name": "Manila Bay",
        "type": "KBA",
        "layer": "KBA",
        "aliases": ["manila bay"],
    },
    {
        "name": "Luneta National Park",
        "type": "PA",
        "layer": "PA",
        "aliases": ["luneta national park"],
    },
    {
        "name": "Manila Bay Beach Resort",
        "type": "PA",
        "layer": "PA",
        "aliases": ["manila bay beach resort"],
    },
    {
        "name": "Las Piñas-Parañaque Wetland Park",
        "type": "PA",
        "layer": "PA",
        "aliases": [
            "las pinas paranaque critical habitat and ecotourism area lppchea also known as las pinas paranaque wetland park",
            "las pinas paranaque critical habitat",
            "las pinas-paranaque wetland park",
            "lppchea",
        ],
    },
    {
        "name": "Ninoy Aquino Parks and Wildlife Center",
        "type": "PA",
        "layer": "PA",
        "aliases": ["ninoy aquino parks and wildlife center", "ninoy aquino parks wildlife center"],
    },
]


def norm(s: Any) -> str:
    t = str(s or "").strip().lower()
    t = t.replace("&", " and ")
    t = re.sub(r"[^a-z0-9]+", " ", t)
    t = re.sub(r"\s+", " ", t).strip()
    return t


def connect_mysql() -> pymysql.connections.Connection:
    conn = pymysql.connect(
        host=os.getenv("AVILIGHT_DB_HOST", "127.0.0.1"),
        port=int(os.getenv("AVILIGHT_DB_PORT", "3306")),
        user=os.getenv("AVILIGHT_DB_USER", "root"),
        password=os.getenv("AVILIGHT_DB_PASS", ""),
        database=os.getenv("AVILIGHT_DB_NAME", "avilight"),
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )
    # Set lock wait timeout to 60 seconds to reduce contention
    with conn.cursor() as cur:
        cur.execute("SET innodb_lock_wait_timeout = 60")
    conn.commit()
    return conn


def read_shapefile_polygons(path: str) -> List[Tuple[str, List[List[Tuple[float, float]]]]]:
    # Returns list of (feature_name, polygons), each polygon is a ring list [(x, y), ...].
    has_records = False
    try:
        sf = shapefile.Reader(path)
        # Some files open successfully but still fail when reading DBF records.
        _ = sf.records()
        has_records = True
    except Exception:
        # Some workspace copies only include .shp geometry without .dbf attributes.
        sf = shapefile.Reader(shp=path, dbf=None, shx=None)
        has_records = False
    field_names = [f[0] for f in sf.fields[1:]]
    results: List[Tuple[str, List[List[Tuple[float, float]]]]] = []

    candidate_name_fields = [
        "NAME",
        "Name",
        "name",
        "SITE_NAME",
        "site_name",
        "AREA_NAME",
        "area_name",
        "KBA_NAME",
        "KBA_Name",
        "kba_name",
        "PA_NAME",
        "PA_Name",
        "pa_name",
        "Former_Nam",
    ]

    if has_records:
        iterable = [(idx, sr.shape, sr.record) for idx, sr in enumerate(sf.shapeRecords(), start=1)]
    else:
        iterable = [(idx, shp, None) for idx, shp in enumerate(sf.shapes(), start=1)]

    for idx, shp, record in iterable:
        rec = {field_names[i]: record[i] for i in range(len(field_names))} if record is not None else {}
        feat_name = ""
        for k in candidate_name_fields:
            if k in rec and str(rec[k] or "").strip():
                feat_name = str(rec[k]).strip()
                break
        if not feat_name:
            # fallback: first non-empty text field
            for key in field_names:
                val = rec.get(key)
                if isinstance(val, str) and val.strip():
                    feat_name = val.strip()
                    break

        if not feat_name:
            feat_name = f"feature_{idx}"

        points = shp.points or []
        parts = list(shp.parts or [])
        if not points:
            continue

        if not parts:
            parts = [0]
        parts.append(len(points))

        polys: List[List[Tuple[float, float]]] = []
        for i in range(len(parts) - 1):
            start = parts[i]
            end = parts[i + 1]
            ring = points[start:end]
            if len(ring) >= 3:
                polys.append([(float(x), float(y)) for x, y in ring])

        if polys:
            results.append((feat_name, polys))

    return results


def point_in_ring(x: float, y: float, ring: Sequence[Tuple[float, float]]) -> bool:
    inside = False
    n = len(ring)
    if n < 3:
        return False
    j = n - 1
    for i in range(n):
        xi, yi = ring[i]
        xj, yj = ring[j]
        intersects = ((yi > y) != (yj > y)) and (
            x < ((xj - xi) * (y - yi) / ((yj - yi) if (yj - yi) != 0 else 1e-12) + xi)
        )
        if intersects:
            inside = not inside
        j = i
    return inside


def point_in_any_polygon(x: float, y: float, polygons: Sequence[Sequence[Tuple[float, float]]]) -> bool:
    for ring in polygons:
        if point_in_ring(x, y, ring):
            return True
    return False


def polygon_intersects_grid_cell(
    lon: float,
    lat: float,
    polygons: Sequence[Sequence[Tuple[float, float]]],
    cell_size: float = 0.01,
) -> bool:
    """Check if polygon boundary overlaps with grid cell centered at (lon, lat).
    
    Returns True if:
    - Grid cell center is inside polygon, OR
    - Any polygon point is inside grid cell, OR
    - Any grid cell corner is inside polygon
    """
    half = cell_size / 2.0
    cell_min_lon = lon - half
    cell_max_lon = lon + half
    cell_min_lat = lat - half
    cell_max_lat = lat + half
    
    # Check 1: center point in polygon
    if point_in_any_polygon(lon, lat, polygons):
        return True
    
    # Check 2: any polygon point inside cell
    for ring in polygons:
        for px, py in ring:
            if cell_min_lon <= px <= cell_max_lon and cell_min_lat <= py <= cell_max_lat:
                return True
    
    # Check 3: any cell corner inside polygon
    for cx in [cell_min_lon, cell_max_lon]:
        for cy in [cell_min_lat, cell_max_lat]:
            if point_in_any_polygon(cx, cy, polygons):
                return True
    
    return False


def build_target_polygons(project_root: str) -> Dict[str, Dict[str, Any]]:
    kba_path = os.path.join(project_root, "NCR_Key_Biodiversity_Areas.shp")
    pa_path = os.path.join(project_root, "NCR_Protected Areas.shp")

    kba_features = read_shapefile_polygons(kba_path) if os.path.isfile(kba_path) else []
    pa_features = read_shapefile_polygons(pa_path) if os.path.isfile(pa_path) else []

    layer_map = {
        "KBA": kba_features,
        "PA": pa_features,
    }

    out: Dict[str, Dict[str, Any]] = {}
    for target in TARGET_AREAS:
        layer = target["layer"]
        aliases = [norm(a) for a in target["aliases"]]
        matched_polys: List[List[Tuple[float, float]]] = []

        for feat_name, polys in layer_map.get(layer, []):
            nname = norm(feat_name)
            if any(alias in nname for alias in aliases):
                matched_polys.extend(polys)

        out[target["name"]] = {
            "name": target["name"],
            "type": target["type"],
            "layer": layer,
            "polygons": matched_polys,
        }

    return out


def ensure_table(conn: pymysql.connections.Connection) -> None:
    sql = """
    CREATE TABLE IF NOT EXISTS kba_pa_audit_live (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        area_name VARCHAR(180) NOT NULL,
        area_type VARCHAR(20) NOT NULL,
        source_layer VARCHAR(20) NOT NULL,
        snapshot_year INT NOT NULL,
        snapshot_month INT NOT NULL,
        grid_cell_count INT NOT NULL DEFAULT 0,
        grid_cells_json LONGTEXT NULL,
        light_exposure DOUBLE NULL,
        mean_ndvi DOUBLE NULL,
        max_lst DOUBLE NULL,
        precipitation_total DOUBLE NULL,
        species_count INT NOT NULL DEFAULT 0,
        sensitive_species_count INT NOT NULL DEFAULT 0,
        sensitive_species_percent DOUBLE NOT NULL DEFAULT 0,
        effectiveness_score DOUBLE NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'Unknown',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_kba_pa_audit_live_name (area_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """
    with conn.cursor() as cur:
        cur.execute(sql)


def fetch_latest_snapshot(conn: pymysql.connections.Connection) -> Tuple[int, int]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT year, month
            FROM raw_bird_observation
            WHERE year IS NOT NULL AND month IS NOT NULL AND species_id IS NOT NULL
            ORDER BY year DESC, month DESC
            LIMIT 1
            """
        )
        row = cur.fetchone()
        if row:
            return int(row["year"]), int(row["month"])

        cur.execute(
            """
            SELECT year, month
            FROM viirs
            WHERE year IS NOT NULL AND month IS NOT NULL
            ORDER BY year DESC, month DESC
            LIMIT 1
            """
        )
        row2 = cur.fetchone()
        if row2:
            return int(row2["year"]), int(row2["month"])

    return 2025, 12


def fetch_precipitation_points(
    conn: pymysql.connections.Connection,
    snapshot_year: int,
    snapshot_month: int,
) -> List[Tuple[float, float, float]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT latitude AS lat, longitude AS lon, precip_mm
            FROM precip
            WHERE year = %s
              AND month = %s
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND precip_mm IS NOT NULL
            """,
            (snapshot_year, snapshot_month),
        )
        rows = cur.fetchall() or []

    points: List[Tuple[float, float, float]] = []
    for row in rows:
        points.append((float(row["lat"]), float(row["lon"]), float(row["precip_mm"])))
    return points


def fetch_land_temp_points(
    conn: pymysql.connections.Connection,
    snapshot_year: int,
    snapshot_month: int,
) -> List[Tuple[float, float, float]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT latitude AS lat, longitude AS lon,
                   CASE
                       WHEN lst_day > 100 THEN (lst_day * 0.02) - 273.15
                       WHEN lst_day > 0 THEN lst_day
                       ELSE NULL
                   END AS lst_c
            FROM land_temp
            WHERE year = %s
              AND month = %s
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND lst_day IS NOT NULL
              AND lst_day != 0
            """,
            (snapshot_year, snapshot_month),
        )
        rows = cur.fetchall() or []

    points: List[Tuple[float, float, float]] = []
    for row in rows:
        lst_val = row.get("lst_c")
        if lst_val is None:
            continue
        points.append((float(row["lat"]), float(row["lon"]), float(lst_val)))
    return points


def nearest_precipitation_total(
    cells: Sequence[Tuple[float, float]],
    precip_points: Sequence[Tuple[float, float, float]],
) -> Optional[float]:
    if not cells or not precip_points:
        return None

    total = 0.0
    for lat, lon in cells:
        nearest_value = None
        nearest_distance = float("inf")
        for p_lat, p_lon, precip_mm in precip_points:
            distance = (lat - p_lat) ** 2 + (lon - p_lon) ** 2
            if distance < nearest_distance:
                nearest_distance = distance
                nearest_value = precip_mm

        if nearest_value is not None:
            total += nearest_value

    return total


def nearest_max_lst(
    cells: Sequence[Tuple[float, float]],
    lst_points: Sequence[Tuple[float, float, float]],
) -> Optional[float]:
    if not cells or not lst_points:
        return None

    max_lst = None
    for lat, lon in cells:
        nearest_value = None
        nearest_distance = float("inf")
        for p_lat, p_lon, lst_c in lst_points:
            distance = (lat - p_lat) ** 2 + (lon - p_lon) ** 2
            if distance < nearest_distance:
                nearest_distance = distance
                nearest_value = lst_c

        if nearest_value is not None:
            if max_lst is None or nearest_value > max_lst:
                max_lst = nearest_value

    return max_lst


def fetch_all_grid_cells(conn: pymysql.connections.Connection) -> List[Tuple[float, float]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT latitude AS lat, longitude AS lon FROM viirs
            UNION
            SELECT latitude AS lat, longitude AS lon FROM ndvi
            UNION
            SELECT latitude AS lat, longitude AS lon FROM land_temp
            UNION
            SELECT latitude AS lat, longitude AS lon FROM precip
            """
        )
        rows = cur.fetchall() or []
    return [(float(r["lat"]), float(r["lon"])) for r in rows]


def fetch_bird_points_snapshot(
    conn: pymysql.connections.Connection,
    snapshot_year: int,
    snapshot_month: int,
) -> List[Tuple[float, float, int, bool]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
                r.latitude AS lat,
                r.longitude AS lon,
                r.species_id,
                CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'sensitive' THEN 1 ELSE 0 END AS is_sensitive
            FROM raw_bird_observation r
            JOIN species_masterlist sm
              ON sm.species_id = r.species_id
            WHERE r.year = %s
              AND r.month = %s
              AND r.species_id IS NOT NULL
              AND r.latitude IS NOT NULL
              AND r.longitude IS NOT NULL
            """,
            (snapshot_year, snapshot_month),
        )
        rows = cur.fetchall() or []

    out: List[Tuple[float, float, int, bool]] = []
    for row in rows:
        out.append(
            (
                float(row["lat"]),
                float(row["lon"]),
                int(row["species_id"]),
                bool(int(row.get("is_sensitive") or 0)),
            )
        )
    return out


def effectiveness_score(
    grid_cell_count: int,
    species_count: int,
    sensitive_species_count: int,
    light_exposure: float,
    mean_ndvi: float,
    max_lst: float,
    precipitation_total: float,
) -> float:
    """
    Compute comprehensive effectiveness score (0-100) across 7 environmental and ecological dimensions.

    Dimensions:
    1. Total Species Richness: unique species count (baseline: 50 species)
    2. Density (Richness-to-Area): species per grid cell (baseline: 3 species/cell)
    3. Sensitive Species Ratio: proportion of light-sensitive species
    4. Habitat Health (NDVI): vegetation vigor (baseline: 0.5 for healthy urban canopy)
    5. ALAN Safety (VIIRS): artificial light exposure (baseline: 60 nW critical threshold)
    6. Thermal Buffering (LST): temperature stress (baseline: 45°C critical threshold)
    7. Water Availability (Precipitation): monthly precipitation (baseline: 300mm optimal)

    Weights:
    richness=0.15, density=0.15, sensitive=0.15, ndvi=0.15,
    alan=0.15, lst=0.15, precip=0.10
    """
    # Dimension 1: Total Species Richness
    score_richness = min(1.0, float(species_count) / 50.0)

    # Dimension 2: Density (Richness-to-Area Ratio)
    density = species_count / float(max(1, grid_cell_count))
    score_density = min(1.0, density / 3.0)

    # Dimension 3: Sensitive Species Ratio
    score_sensitive = float(sensitive_species_count) / float(max(1, species_count))

    # Dimension 4: Habitat Health (NDVI)
    score_ndvi = min(1.0, float(mean_ndvi or 0.0) / 0.5)

    # Dimension 5: ALAN Safety (VIIRS)
    alan_val = float(light_exposure or 0.0)
    score_alan = max(0.0, 1.0 - (alan_val / 60.0))

    # Dimension 6: Thermal Buffering (LST)
    lst_val = float(max_lst or 0.0)
    score_lst = max(0.0, 1.0 - (lst_val / 45.0))

    # Dimension 7: Water Availability (Precipitation)
    precip_val = float(precipitation_total or 0.0)
    score_precip = min(1.0, precip_val / 300.0)

    # Weighted composite score
    score = (
        (score_richness * 0.15)
        + (score_density * 0.15)
        + (score_sensitive * 0.15)
        + (score_ndvi * 0.15)
        + (score_alan * 0.15)
        + (score_lst * 0.15)
        + (score_precip * 0.10)
    ) * 100.0

    return round(score, 1)


def status_from_score(score: float) -> str:
    if score < 40:
        return "Critical"
    if score < 60:
        return "At Risk"
    if score < 75:
        return "Moderate"
    return "Good"


def build_area_metrics(
    conn: pymysql.connections.Connection,
    cells: List[Tuple[float, float]],
    polygons: Sequence[Sequence[Tuple[float, float]]],
    bird_points: Sequence[Tuple[float, float, int, bool]],
    snapshot_year: int,
    snapshot_month: int,
    precip_points: Sequence[Tuple[float, float, float]],
    lst_points: Sequence[Tuple[float, float, float]],
) -> Dict[str, Any]:
    if not cells:
        return {
            "grid_cell_count": 0,
            "light_exposure": 0.0,
            "mean_ndvi": 0.0,
            "max_lst": 0.0,
            "precipitation_total": None,
            "species_count": 0,
            "sensitive_species_count": 0,
            "sensitive_species_percent": 0.0,
        }

    with conn.cursor() as cur:
        cur.execute("DROP TEMPORARY TABLE IF EXISTS tmp_kba_cells")
        cur.execute(
            """
            CREATE TEMPORARY TABLE tmp_kba_cells (
                lat DECIMAL(10,8) NOT NULL,
                lon DECIMAL(11,8) NOT NULL,
                PRIMARY KEY (lat, lon)
            ) ENGINE=MEMORY
            """
        )
        cur.executemany(
            "INSERT IGNORE INTO tmp_kba_cells (lat, lon) VALUES (%s, %s)",
            [(round(lat, 8), round(lon, 8)) for lat, lon in cells],
        )

        cur.execute(
            """
            SELECT
                AVG(NULLIF(v.viirs_avg_rad, 0)) AS light_exposure,
                AVG(
                    CASE
                        WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000
                        WHEN n.ndvi = 0 THEN NULL
                        ELSE n.ndvi
                    END
                ) AS mean_ndvi,
                MAX(
                    CASE
                        WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                        WHEN lt.lst_day <= 0 THEN NULL
                        ELSE lt.lst_day
                    END
                ) AS max_lst,
                    NULL AS precipitation_total
            FROM tmp_kba_cells c
            LEFT JOIN viirs v
                ON v.latitude = c.lat
               AND v.longitude = c.lon
               AND v.year = %s
               AND v.month = %s
            LEFT JOIN ndvi n
                ON n.latitude = c.lat
               AND n.longitude = c.lon
               AND n.year = %s
               AND n.month = %s
            LEFT JOIN land_temp lt
                ON lt.latitude = c.lat
               AND lt.longitude = c.lon
               AND lt.year = %s
               AND lt.month = %s
            """,
            (
                snapshot_year,
                snapshot_month,
                snapshot_year,
                snapshot_month,
                snapshot_year,
                snapshot_month,
            ),
        )
        env_row = cur.fetchone() or {}

        cur.execute("DROP TEMPORARY TABLE IF EXISTS tmp_kba_cells")

    species_ids = set()
    sensitive_species_ids = set()
    if polygons and bird_points:
        for lat, lon, species_id, is_sensitive in bird_points:
            if polygon_intersects_grid_cell(lon, lat, polygons):
                species_ids.add(species_id)
                if is_sensitive:
                    sensitive_species_ids.add(species_id)

    species_count = len(species_ids)
    sensitive_count = len(sensitive_species_ids)
    sensitive_pct = (float(sensitive_count) / float(species_count) * 100.0) if species_count > 0 else 0.0
    precip_total = nearest_precipitation_total(cells, precip_points)
    max_lst = float(env_row.get("max_lst") or 0.0)
    if max_lst <= 0.0:
        nearest_lst = nearest_max_lst(cells, lst_points)
        if nearest_lst is not None:
            max_lst = float(nearest_lst)
        # DEBUG
        if len(cells) > 0 and max_lst <= 0.0:
            import sys
            print(f"DEBUG: {polygons[0] if polygons else 'NO POLYGON'} - cells={len(cells)}, lst_points={len(lst_points)}, max_lst={max_lst}", file=sys.stderr)
            if cells and lst_points:
                print(f"  First cell: {cells[0]}", file=sys.stderr)
                print(f"  First lst_point: {lst_points[0]}", file=sys.stderr)

    return {
        "grid_cell_count": len(cells),
        "light_exposure": float(env_row.get("light_exposure") or 0.0),
        "mean_ndvi": float(env_row.get("mean_ndvi") or 0.0),
        "max_lst": max_lst,
        "precipitation_total": None if precip_total is None else float(precip_total),
        "species_count": species_count,
        "sensitive_species_count": sensitive_count,
        "sensitive_species_percent": round(sensitive_pct, 1),
    }


def upsert_area(
    conn: pymysql.connections.Connection,
    area: Dict[str, Any],
    metrics: Dict[str, Any],
    cells: List[Tuple[float, float]],
    snapshot_year: int,
    snapshot_month: int,
) -> None:
    score = effectiveness_score(
        metrics["grid_cell_count"],
        metrics["species_count"],
        metrics["sensitive_species_count"],
        metrics["light_exposure"],
        metrics["mean_ndvi"],
        metrics["max_lst"],
        metrics["precipitation_total"],
    )
    status = status_from_score(score)

    grid_cells_json = json.dumps(
        [{"lat": round(lat, 8), "lon": round(lon, 8)} for lat, lon in cells],
        ensure_ascii=False,
    )

    sql = """
    INSERT INTO kba_pa_audit_live (
        area_name,
        area_type,
        source_layer,
        snapshot_year,
        snapshot_month,
        grid_cell_count,
        grid_cells_json,
        light_exposure,
        mean_ndvi,
        max_lst,
        precipitation_total,
        species_count,
        sensitive_species_count,
        sensitive_species_percent,
        effectiveness_score,
        status
    ) VALUES (
        %(area_name)s,
        %(area_type)s,
        %(source_layer)s,
        %(snapshot_year)s,
        %(snapshot_month)s,
        %(grid_cell_count)s,
        %(grid_cells_json)s,
        %(light_exposure)s,
        %(mean_ndvi)s,
        %(max_lst)s,
        %(precipitation_total)s,
        %(species_count)s,
        %(sensitive_species_count)s,
        %(sensitive_species_percent)s,
        %(effectiveness_score)s,
        %(status)s
    )
    ON DUPLICATE KEY UPDATE
        area_type = VALUES(area_type),
        source_layer = VALUES(source_layer),
        snapshot_year = VALUES(snapshot_year),
        snapshot_month = VALUES(snapshot_month),
        grid_cell_count = VALUES(grid_cell_count),
        grid_cells_json = VALUES(grid_cells_json),
        light_exposure = VALUES(light_exposure),
        mean_ndvi = VALUES(mean_ndvi),
        max_lst = VALUES(max_lst),
        precipitation_total = VALUES(precipitation_total),
        species_count = VALUES(species_count),
        sensitive_species_count = VALUES(sensitive_species_count),
        sensitive_species_percent = VALUES(sensitive_species_percent),
        effectiveness_score = VALUES(effectiveness_score),
        status = VALUES(status)
    """

    with conn.cursor() as cur:
        cur.execute(
            sql,
            {
                "area_name": area["name"],
                "area_type": area["type"],
                "source_layer": area["layer"],
                "snapshot_year": snapshot_year,
                "snapshot_month": snapshot_month,
                "grid_cell_count": metrics["grid_cell_count"],
                "grid_cells_json": grid_cells_json,
                "light_exposure": metrics["light_exposure"],
                "mean_ndvi": metrics["mean_ndvi"],
                "max_lst": metrics["max_lst"],
                "precipitation_total": metrics["precipitation_total"],
                "species_count": metrics["species_count"],
                "sensitive_species_count": metrics["sensitive_species_count"],
                "sensitive_species_percent": metrics["sensitive_species_percent"],
                "effectiveness_score": score,
                "status": status,
            },
        )


def prune_stale_areas(conn: pymysql.connections.Connection, target_names: Sequence[str]) -> None:
    """Remove audit rows that are not part of the current configured target list."""
    if not target_names:
        return
    placeholders = ",".join(["%s"] * len(target_names))
    sql = f"DELETE FROM kba_pa_audit_live WHERE area_name NOT IN ({placeholders})"
    with conn.cursor() as cur:
        cur.execute(sql, tuple(target_names))


def main() -> int:
    project_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

    conn = connect_mysql()
    try:
        ensure_table(conn)
        snapshot_year, snapshot_month = fetch_latest_snapshot(conn)
        all_cells = fetch_all_grid_cells(conn)
        bird_points = fetch_bird_points_snapshot(conn, snapshot_year, snapshot_month)
        precip_points = fetch_precipitation_points(conn, snapshot_year, snapshot_month)
        lst_points = fetch_land_temp_points(conn, snapshot_year, snapshot_month)

        targets = build_target_polygons(project_root)
        prune_stale_areas(conn, list(targets.keys()))
        summary: List[Dict[str, Any]] = []

        for area_name, area in targets.items():
            polygons = area.get("polygons") or []
            cells: List[Tuple[float, float]] = []
            if polygons:
                for lat, lon in all_cells:
                    if polygon_intersects_grid_cell(lon, lat, polygons):
                        cells.append((lat, lon))

            metrics = build_area_metrics(
                conn,
                cells,
                polygons,
                bird_points,
                snapshot_year,
                snapshot_month,
                precip_points,
                lst_points,
            )
            upsert_area(conn, area, metrics, cells, snapshot_year, snapshot_month)
            conn.commit()  # Commit after each area to reduce lock contention

            summary.append(
                {
                    "name": area_name,
                    "type": area["type"],
                    "cells": len(cells),
                    "species_count": metrics["species_count"],
                    "sensitive_species_percent": metrics["sensitive_species_percent"],
                }
            )

        conn.commit()
        print(
            json.dumps(
                {
                    "success": True,
                    "snapshot_year": snapshot_year,
                    "snapshot_month": snapshot_month,
                    "areas_processed": len(summary),
                    "summary": summary,
                }
            )
        )
        return 0
    except Exception as exc:
        conn.rollback()
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
