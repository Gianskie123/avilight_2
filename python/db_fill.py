"""
db_fill.py
----------
Hierarchical gap-fill for AviLight covariate extraction scripts.

After GEE extraction, some grid cells have no satellite reading for a given
month (cloud cover, sensor gaps).  Instead of dropping those cells, this
module fills them using the following priority order:

  Tier 1 – Temporal interpolation
      Queries the target covariate table for the same cell's value in the
      nearest available months before and after the gap (same table, any year).
      Linearly interpolates between the two neighbours, or uses the single
      available neighbour if only one side has data.

  Tier 2 – Land-cover monthly mean
      Looks up this cell's land-cover class from the `land_cover` table, then
      averages all values in the target table that share the same class and
      month (across all available years).

  Tier 3 – Global monthly mean
      Takes the mean of all valid values returned by GEE in the current
      extraction batch.  No extra DB query required.

  Unresolved gaps
      If all three tiers fail (e.g. the covariate table is empty and GEE
      returned nothing at all for this month), the cell value stays None so
      it is stored as NULL — the row still exists in the DB, keeping the
      grid intact.

Usage
-----
    from db_fill import connect_db, hierarchical_fill

    conn = connect_db()

    hierarchical_fill(
        gap_rows   = gap_rows,        # list of row dicts where band is None
        valid_rows = valid_rows,      # list of row dicts with real GEE values
        band       = 'ndvi',          # column name in both row dicts and DB
        table      = 'ndvi',          # MariaDB source table
        year       = 2024,
        month      = 7,
        conn       = conn,
        clamp_min  = -1.0,            # optional physical lower bound
        clamp_max  =  1.0,            # optional physical upper bound
        precision  = 8,
    )

    conn.close()

Dependencies
------------
    pip install pymysql numpy
"""

import os
import sys
from typing import Optional

import numpy as np


# ── DB connection ──────────────────────────────────────────────────────────────

def connect_db():
    """
    Open a connection to the AviLight MariaDB database.

    Credentials are read from environment variables with XAMPP defaults:
        DB_HOST  (default: 127.0.0.1)
        DB_PORT  (default: 3306)
        DB_NAME  (default: avilight)
        DB_USER  (default: root)
        DB_PASS  (default: empty)

    Raises ImportError if pymysql is not installed.
    """
    try:
        import pymysql
        import pymysql.cursors
    except ImportError:
        print(
            "pymysql is required for hierarchical gap-filling.\n"
            "Install with: pip install pymysql",
            file=sys.stderr,
        )
        raise

    return pymysql.connect(
        host            = os.environ.get('DB_HOST', '127.0.0.1'),
        port            = int(os.environ.get('DB_PORT', '3306')),
        database        = os.environ.get('DB_NAME', 'avilight'),
        user            = os.environ.get('DB_USER', 'root'),
        password        = os.environ.get('DB_PASS', ''),
        cursorclass     = pymysql.cursors.DictCursor,
        connect_timeout = 10,
    )


# ── Tier 1: Temporal interpolation ────────────────────────────────────────────

def _temporal_fill(
    cell_id: str,
    year: int,
    month: int,
    table: str,
    band: str,
    conn,
) -> Optional[float]:
    """
    Search the target table for the same cell in months near the gap.

    Looks up to 6 calendar months away in either direction (crossing year
    boundaries when needed).  Picks the closest available previous value
    and the closest available next value, then linearly interpolates.
    Falls back to a single neighbour if only one side has data.
    Returns None if no neighbours are found within the 6-month window.
    """
    # Convert the target month to an absolute month index for arithmetic.
    target_abs = year * 12 + month

    # Collect (year, month) pairs within ±6 months, crossing year boundaries.
    candidates = []
    for delta in range(1, 7):
        for sign in (-1, 1):
            abs_m = target_abs + sign * delta
            y, m  = divmod(abs_m - 1, 12)
            candidates.append((y, m + 1))

    # Fetch all available neighbours in one query.
    if not candidates:
        return None

    placeholders = ' OR '.join(['(year = %s AND month = %s)'] * len(candidates))
    params = [cell_id]
    for y, m in candidates:
        params += [y, m]

    sql = (
        f"SELECT year, month, `{band}` AS val "
        f"FROM `{table}` "
        f"WHERE cell_id = %s "
        f"  AND `{band}` IS NOT NULL "
        f"  AND ({placeholders})"
    )

    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()

    if not rows:
        return None

    # Find the closest previous and next neighbour.
    prev_val:  Optional[float] = None
    prev_dist: Optional[int]   = None
    next_val:  Optional[float] = None
    next_dist: Optional[int]   = None

    for row in rows:
        val  = float(row['val'])
        dist = (row['year'] * 12 + row['month']) - target_abs  # negative = before, positive = after

        if dist < 0:
            if prev_dist is None or abs(dist) < abs(prev_dist):
                prev_val, prev_dist = val, dist
        elif dist > 0:
            if next_dist is None or dist < next_dist:
                next_val, next_dist = val, dist

    # Linear interpolation between the two neighbours.
    if prev_val is not None and next_val is not None:
        total_gap = next_dist - prev_dist          # always > 0
        frac      = abs(prev_dist) / total_gap     # how far from prev to target
        return prev_val + (next_val - prev_val) * frac

    # Only one side available — use it directly.
    if prev_val is not None:
        return prev_val
    if next_val is not None:
        return next_val

    return None


# ── Tier 2: Land-cover monthly mean ───────────────────────────────────────────

def _lc_monthly_mean(
    cell_id: str,
    year: int,
    month: int,
    table: str,
    band: str,
    conn,
) -> Optional[float]:
    """
    Average `band` across all cells that share this cell's land-cover class
    for the same calendar month (all available years in the table).
    """
    sql = f"""
        SELECT AVG(t.`{band}`) AS mean_val
        FROM `{table}` t
        JOIN `land_cover` lc_target
          ON lc_target.cell_id = %s
         AND lc_target.year    = %s
        JOIN `land_cover` lc_peers
          ON lc_peers.cell_id    = t.cell_id
         AND lc_peers.land_cover = lc_target.land_cover
        WHERE t.month        = %s
          AND t.`{band}` IS NOT NULL
    """
    with conn.cursor() as cur:
        cur.execute(sql, [cell_id, year, month])
        row = cur.fetchone()

    if row and row['mean_val'] is not None:
        return float(row['mean_val'])
    return None


# ── Tier 3: Global monthly mean ───────────────────────────────────────────────

def _global_mean(valid_rows: list, band: str) -> Optional[float]:
    """Mean of all real values returned by GEE in the current extraction batch."""
    vals = [r[band] for r in valid_rows if r.get(band) is not None]
    return float(np.mean(vals)) if vals else None


# ── Public API ─────────────────────────────────────────────────────────────────

def hierarchical_fill(
    gap_rows:   list,
    valid_rows: list,
    band:       str,
    table:      str,
    year:       int,
    month:      int,
    conn,
    clamp_min:  Optional[float] = None,
    clamp_max:  Optional[float] = None,
    precision:  int             = 8,
) -> None:
    """
    Fill gap_rows[band] in-place using the 3-tier hierarchy.

    Parameters
    ----------
    gap_rows   : rows from GEE where `band` is None
    valid_rows : rows from GEE where `band` has a real value
    band       : column name (e.g. 'ndvi', 'lst_day', 'viirs_avg_rad')
    table      : MariaDB source table (e.g. 'ndvi', 'land_temp')
    year       : extraction year
    month      : extraction month
    conn       : active pymysql connection
    clamp_min  : floor applied after filling (e.g. 0 for precip)
    clamp_max  : ceiling applied after filling (e.g. 1 for ndvi)
    precision  : decimal places for rounding the filled value
    """
    # Pre-compute the global mean once (used by every gap cell as Tier 3).
    global_mean = _global_mean(valid_rows, band)

    for row in gap_rows:
        val: Optional[float] = None

        # Tier 1 — temporal interpolation from the DB
        try:
            val = _temporal_fill(row['cell_id'], year, month, table, band, conn)
        except Exception:
            pass   # DB error: fall through to next tier

        # Tier 2 — land-cover monthly mean from the DB
        if val is None:
            try:
                val = _lc_monthly_mean(row['cell_id'], year, month, table, band, conn)
            except Exception:
                pass

        # Tier 3 — global mean from current GEE batch
        if val is None:
            val = global_mean

        # Apply physical clamps and store.
        if val is not None:
            if clamp_min is not None:
                val = max(clamp_min, val)
            if clamp_max is not None:
                val = min(clamp_max, val)
            row[band] = round(val, precision)
        # else: row[band] stays None (genuine data gap — stored as NULL)
