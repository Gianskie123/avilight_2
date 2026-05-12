#!/usr/bin/env python3
"""
Populate kba_pa_locations and kba_pa_monthly_viirs from existing data.

Reads grid_cells_json from kba_pa_audit_live (Table 1 reference), then joins
each site's cells against final_master_grid (already imputed) to compute the
average VIIRS per site per year/month. Results are stored in kba_pa_monthly_viirs.

Annual averages and risk classification are derived at query time by the
application layer — thresholds are configurable so they must never be stored.

Usage:
    python prewarm_kba_dashboard.py

Environment variables:
    AVILIGHT_DB_HOST, AVILIGHT_DB_USER, AVILIGHT_DB_PASS,
    AVILIGHT_DB_NAME, AVILIGHT_DB_PORT
"""

import json
import os
from typing import Any, Dict, List, Tuple

try:
    import pymysql
    import pymysql.cursors
except Exception as exc:
    print(json.dumps({"success": False, "error": f"pymysql import failed: {exc}"}))
    raise SystemExit(1)

MIN_YEAR = 2014
MAX_YEAR = 2025


def connect_mysql() -> pymysql.connections.Connection:
    return pymysql.connect(
        host=os.getenv("AVILIGHT_DB_HOST", "127.0.0.1"),
        user=os.getenv("AVILIGHT_DB_USER", "root"),
        password=os.getenv("AVILIGHT_DB_PASS", ""),
        database=os.getenv("AVILIGHT_DB_NAME", "avilight"),
        port=int(os.getenv("AVILIGHT_DB_PORT", "3306")),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def ensure_tables(conn: pymysql.connections.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS kba_pa_locations (
                id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                area_name       VARCHAR(180)  NOT NULL,
                area_type       VARCHAR(20)   NOT NULL,
                source_layer    VARCHAR(20)   NOT NULL DEFAULT '',
                grid_cell_count INT           NOT NULL DEFAULT 0,
                grid_cells_json LONGTEXT      NOT NULL,
                updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_kba_pa_locations_name (area_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS kba_pa_monthly_viirs (
                id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                location_id   INT UNSIGNED  NOT NULL,
                area_name     VARCHAR(180)  NOT NULL,
                area_type     VARCHAR(20)   NOT NULL,
                year          SMALLINT      NOT NULL,
                month         TINYINT       NOT NULL,
                avg_viirs     DECIMAL(12,6),
                matched_cells INT           NOT NULL DEFAULT 0,
                updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_kba_pa_monthly_viirs (location_id, year, month),
                KEY idx_kba_pa_monthly_viirs_year (year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
    conn.commit()


def upsert_location(
    conn: pymysql.connections.Connection,
    area_name: str,
    area_type: str,
    source_layer: str,
    grid_cell_count: int,
    grid_cells_json_raw: str,
) -> int:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO kba_pa_locations
                (area_name, area_type, source_layer, grid_cell_count, grid_cells_json)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                area_type       = VALUES(area_type),
                source_layer    = VALUES(source_layer),
                grid_cell_count = VALUES(grid_cell_count),
                grid_cells_json = VALUES(grid_cells_json),
                updated_at      = NOW()
            """,
            (area_name, area_type, source_layer, grid_cell_count, grid_cells_json_raw),
        )
        cur.execute(
            "SELECT id FROM kba_pa_locations WHERE area_name = %s", (area_name,)
        )
        row = cur.fetchone()
        return int(row["id"]) if row else 0


def compute_and_upsert_monthly(
    conn: pymysql.connections.Connection,
    area_name: str,
    area_type: str,
    location_id: int,
    cell_list: List[Tuple[float, float]],
) -> int:
    if not cell_list or not location_id:
        return 0

    with conn.cursor() as cur:
        cur.execute("DROP TEMPORARY TABLE IF EXISTS tmp_prewarm_cells")
        cur.execute(
            """
            CREATE TEMPORARY TABLE tmp_prewarm_cells (
                lat DECIMAL(10,8) NOT NULL,
                lon DECIMAL(11,8) NOT NULL,
                PRIMARY KEY (lat, lon)
            ) ENGINE=MEMORY
            """
        )
        cur.executemany(
            "INSERT IGNORE INTO tmp_prewarm_cells (lat, lon) VALUES (%s, %s)",
            cell_list,
        )
        # Join against final_master_grid (already imputed — no nulls from gap-fill)
        cur.execute(
            """
            SELECT
                f.year,
                f.month,
                AVG(NULLIF(f.viirs_avg_rad, 0))       AS avg_viirs,
                COUNT(DISTINCT f.lat, f.lon)           AS matched_cells
            FROM tmp_prewarm_cells c
            JOIN final_master_grid f
                ON f.lat = c.lat
               AND f.lon = c.lon
            WHERE f.year BETWEEN %s AND %s
            GROUP BY f.year, f.month
            ORDER BY f.year ASC, f.month ASC
            """,
            (MIN_YEAR, MAX_YEAR),
        )
        month_rows = cur.fetchall() or []
        cur.execute("DROP TEMPORARY TABLE IF EXISTS tmp_prewarm_cells")

    count = 0
    for row in month_rows:
        year    = int(row["year"])
        month   = int(row["month"])
        avg_v   = float(row["avg_viirs"]) if row["avg_viirs"] is not None else None
        matched = int(row["matched_cells"])

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO kba_pa_monthly_viirs
                    (location_id, area_name, area_type, year, month, avg_viirs, matched_cells)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    area_name     = VALUES(area_name),
                    area_type     = VALUES(area_type),
                    avg_viirs     = VALUES(avg_viirs),
                    matched_cells = VALUES(matched_cells),
                    updated_at    = NOW()
                """,
                (location_id, area_name, area_type, year, month, avg_v, matched),
            )
        count += 1

    return count


def main() -> int:
    conn = connect_mysql()
    try:
        ensure_tables(conn)

        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT area_name, area_type, source_layer,
                       grid_cell_count, grid_cells_json
                FROM kba_pa_audit_live
                ORDER BY area_name ASC
                """
            )
            audit_rows = cur.fetchall() or []

        if not audit_rows:
            print(json.dumps({
                "success": False,
                "error": "kba_pa_audit_live is empty — run the KBA/PA audit rebuild first.",
            }))
            return 1

        results: List[Dict[str, Any]] = []

        for row in audit_rows:
            area_name       = str(row["area_name"])
            area_type       = str(row.get("area_type") or "")
            source_layer    = str(row.get("source_layer") or "")
            grid_cell_count = int(row.get("grid_cell_count") or 0)
            raw_json        = str(row.get("grid_cells_json") or "[]")

            location_id = upsert_location(
                conn, area_name, area_type, source_layer, grid_cell_count, raw_json
            )

            cells_parsed = json.loads(raw_json)
            cell_list = [
                (round(float(c["lat"]), 8), round(float(c["lon"]), 8))
                for c in cells_parsed
                if "lat" in c and "lon" in c
            ]

            months_written = compute_and_upsert_monthly(
                conn, area_name, area_type, location_id, cell_list
            )
            conn.commit()

            results.append({
                "name":           area_name,
                "cells":          len(cell_list),
                "months_written": months_written,
            })

        print(json.dumps({
            "success": True,
            "message": "kba_pa_locations and kba_pa_monthly_viirs populated.",
            "areas":   results,
        }))
        return 0

    except Exception as exc:
        conn.rollback()
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
