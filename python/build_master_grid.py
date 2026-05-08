"""
build_master_grid.py
--------------------
Rebuilds the `final_master_grid` MariaDB table by merging all environmental
covariates with aggregated bird observations, applying spatial gap-filling
identical to the combine_coords notebook logic.

Tables consumed:
  land_cover  → base grid scaffold + land cover type (per year, static)
  precip      → daily precip → monthly sum → nearest-neighbour fill
  land_temp   → LST day/night → hierarchical imputation
  ndvi        → NDVI → hierarchical imputation
  viirs       → VIIRS avg radiance
  aggregated_bird_observation → bird counts / species per grid cell
  kba_pa      → KBA / PA spatial flags (grid-level, static)

Target table: final_master_grid

Usage:
    python build_master_grid.py                  # rebuild all years in land_cover
    python build_master_grid.py --year 2025      # rebuild one year
    python build_master_grid.py --years 2024,2025

Dependencies:
    pip install pymysql pandas numpy scipy
"""

import os
import sys
import json as _json
import argparse
import warnings
import traceback

# Limit BLAS threading to avoid pthread_create failures on constrained hosts.
os.environ.setdefault('OPENBLAS_NUM_THREADS', '1')
os.environ.setdefault('OMP_NUM_THREADS', '1')
os.environ.setdefault('MKL_NUM_THREADS', '1')
os.environ.setdefault('NUMEXPR_NUM_THREADS', '1')

import numpy as np
import pandas as pd
import pymysql
from scipy.spatial import cKDTree

warnings.filterwarnings('ignore')


def emit(msg: str, level: str = 'info') -> None:
    """Write one JSON progress line to stdout."""
    sys.stdout.write(_json.dumps({'level': level, 'msg': msg}) + '\n')
    sys.stdout.flush()

# ── DB config ──────────────────────────────────────────────────────────────────
def _get_db_port(raw: str) -> int:
    try:
        return int(raw)
    except (TypeError, ValueError):
        return 3306


_db_host = os.getenv('DB_HOST') or os.getenv('MYSQL_HOST') or '127.0.0.1'
_db_port = _get_db_port(os.getenv('DB_PORT') or os.getenv('MYSQL_PORT') or '3306')
_db_name = os.getenv('DB_NAME') or os.getenv('MYSQL_DATABASE') or 'avilight'
_db_user = os.getenv('DB_USER') or os.getenv('MYSQL_USER') or 'root'
_db_pass = os.getenv('DB_PASS') or os.getenv('MYSQL_PASSWORD') or ''
_db_ssl_ca = os.getenv('DB_SSL_CA') or os.getenv('MYSQL_SSL_CA') or ''

# Also accept full-PEM env var (some platforms) and a common typo seen in envs
_db_ssl_ca_pem = os.getenv('DB_SSL_CA_PEM') or os.getenv('DB_SSL_CS_PEM') or os.getenv('MYSQL_SSL_CA_PEM') or ''

# If PEM content provided, materialize to temp file
if _db_ssl_ca_pem:
    try:
        _tmp_ca = os.path.join(os.getenv('TMPDIR') or '/tmp', 'db_ca.pem')
        with open(_tmp_ca, 'w') as fh:
            fh.write(_db_ssl_ca_pem)
        os.chmod(_tmp_ca, 0o640)
        _db_ssl_ca = _tmp_ca
    except Exception:
        # fallback to other detection below
        _db_ssl_ca = _db_ssl_ca or ''

# If path given but missing, clear it
if _db_ssl_ca and not os.path.exists(_db_ssl_ca):
    _db_ssl_ca = ''

# Fallback to standard secrets path written at container startup
if not _db_ssl_ca:
    _ca_fallback = '/var/www/html/secrets/ca.pem'
    if os.path.exists(_ca_fallback):
        _db_ssl_ca = _ca_fallback

# Emit which CA path will be used (or none)
if _db_ssl_ca:
    emit(f'Using DB SSL CA file: {_db_ssl_ca}')
else:
    emit('No DB SSL CA file found; SSL CA will not be used')

DB = {
    'host':     _db_host,
    'port':     _db_port,
    'user':     _db_user,
    'password': _db_pass,
    'database': _db_name,
    'charset':  'utf8mb4',
    'connect_timeout': 30,
}

if _db_ssl_ca:
    DB['ssl'] = {'ca': _db_ssl_ca}

del _db_host, _db_port, _db_name, _db_user, _db_pass, _db_ssl_ca

BATCH_SIZE = 5000


def get_conn():
    return pymysql.connect(**DB)


def load_sql(conn, query: str) -> pd.DataFrame:
    return pd.read_sql(query, conn)


# ── Gap-fill helpers ───────────────────────────────────────────────────────────

def _round_precip(coord: float) -> float:
    """Round to nearest 0.05° (matches CHIRPS grid resolution)."""
    return round(float(coord) * 20) / 20


def fill_precip_nn(df: pd.DataFrame) -> pd.DataFrame:
    """
    For cells with NO precipitation value across any month in a given year,
    find the spatially nearest cell that DOES have data and borrow its values.
    Operates per-year to avoid cross-year spatial borrowing.
    """
    if 'monthly_precip_mm' not in df.columns:
        return df
    emit('  Precipitation: nearest-neighbour spatial fill...')

    year_frames = []
    for year, df_y in df.groupby('year'):
        df_y = df_y.copy()
        status = df_y.groupby(['lat', 'lon'])['monthly_precip_mm'].count().reset_index()
        has_data = status[status['monthly_precip_mm'] > 0][['lat', 'lon']].values
        no_data  = status[status['monthly_precip_mm'] == 0][['lat', 'lon']].values

        if len(has_data) == 0 or len(no_data) == 0:
            year_frames.append(df_y)
            continue

        _, idx = cKDTree(has_data).query(no_data)
        mapping = pd.DataFrame(no_data, columns=['lat', 'lon'])
        mapping['nlat'] = has_data[idx, 0]
        mapping['nlon'] = has_data[idx, 1]

        ref = (df_y[df_y['monthly_precip_mm'].notna()]
               [['lat', 'lon', 'month', 'monthly_precip_mm']]
               .drop_duplicates()
               .rename(columns={'lat': 'nlat', 'lon': 'nlon',
                                'monthly_precip_mm': '_filled'}))

        missing_rows = df_y[df_y['monthly_precip_mm'].isna()].copy()
        missing_rows = missing_rows.merge(mapping, on=['lat', 'lon'], how='left')
        missing_rows = missing_rows.merge(ref, on=['nlat', 'nlon', 'month'], how='left')

        df_y = df_y.set_index(['lat', 'lon', 'month'])
        missing_rows = missing_rows.set_index(['lat', 'lon', 'month'])
        df_y['monthly_precip_mm'] = df_y['monthly_precip_mm'].fillna(
            missing_rows['_filled'])
        year_frames.append(df_y.reset_index())

    return pd.concat(year_frames, ignore_index=True) if year_frames else df


def fill_land_cover_nn(df: pd.DataFrame, k: int = 5) -> pd.DataFrame:
    """
    For cells with a NULL land_cover value, assign the modal land cover class
    among the k spatially nearest cells that do have a value.
    Operates per year. Falls back to the global mode for the year if all
    neighbours are also null (should not happen in practice).

    Mode is used instead of mean because land cover codes are categorical —
    averaging class integers would be meaningless.
    """
    if 'land_cover' not in df.columns:
        return df
    emit('  Land cover: nearest-neighbour mode fill...')

    year_frames = []
    for year, df_y in df.groupby('year'):
        df_y = df_y.copy()

        # Summarise to one row per cell (land_cover is the same for all 12 months)
        cell_lc   = df_y.groupby(['lat', 'lon'])['land_cover'].first().reset_index()
        has_data  = cell_lc[cell_lc['land_cover'].notna()]
        no_data   = cell_lc[cell_lc['land_cover'].isna()]

        if len(no_data) == 0:
            year_frames.append(df_y)
            continue

        if len(has_data) == 0:
            year_frames.append(df_y)
            continue

        coords_has = has_data[['lat', 'lon']].values
        lc_values  = has_data['land_cover'].values
        coords_no  = no_data[['lat', 'lon']].values

        k_actual = min(k, len(has_data))
        _, idx = cKDTree(coords_has).query(coords_no, k=k_actual)

        # Build fill map: (lat, lon) → mode of k nearest LC class codes
        fill_map = {}
        for i, row in enumerate(no_data.itertuples(index=False)):
            neighbors = lc_values[[idx[i]]] if k_actual == 1 else lc_values[idx[i]]
            mode_val  = pd.Series(neighbors).mode()
            fill_map[(row.lat, row.lon)] = int(mode_val.iloc[0]) if len(mode_val) > 0 else None

        # Apply fill to every row (all 12 months) belonging to each null cell
        null_mask = df_y['land_cover'].isna()
        df_y.loc[null_mask, 'land_cover'] = df_y.loc[null_mask].apply(
            lambda r: fill_map.get((r['lat'], r['lon'])), axis=1
        )

        # Global fallback: mode of all valid LC values for this year
        if df_y['land_cover'].isna().any():
            global_mode = df_y['land_cover'].dropna().mode()
            if len(global_mode) > 0:
                df_y['land_cover'] = df_y['land_cover'].fillna(global_mode.iloc[0])

        year_frames.append(df_y)

    return pd.concat(year_frames, ignore_index=True) if year_frames else df


def fill_hierarchical(df: pd.DataFrame, cols: list) -> pd.DataFrame:
    """
    3-step hierarchical imputation for NDVI / LST:
      1. Temporal interpolation within each (cell_id, year)
      2. Land-cover-informed monthly mean  (requires 'land_cover' column)
      3. Global monthly mean fallback
    """
    for col in cols:
        if col not in df.columns:
            continue
        emit(f'  Hierarchical fill: {col}...')
        df = df.sort_values(['cell_id', 'year', 'month'])

        # Step 1 – temporal interpolation within each cell-year
        df[col] = df.groupby(['cell_id', 'year'])[col].transform(
            lambda x: x.interpolate(method='linear', limit_direction='both'))

        # Step 2 – land-cover monthly mean
        if 'land_cover' in df.columns:
            lc_mean = df.groupby(['month', 'year', 'land_cover'])[col].transform('mean')
            df[col] = df[col].fillna(lc_mean)

        # Step 3 – global monthly mean
        global_mean = df.groupby(['month', 'year'])[col].transform('mean')
        df[col] = df[col].fillna(global_mean)

    return df


# ── Species-list helpers ───────────────────────────────────────────────────────

def _parse_species(s) -> list:
    """Parse a JSON array string like '["Dove","Sparrow"]' → list of names."""
    if not s:
        return []
    try:
        parsed = _json.loads(s)
        return parsed if isinstance(parsed, list) else [str(parsed)]
    except Exception:
        return [str(s)]


def _merge_species(series) -> str:
    """Combine multiple JSON species-list strings into one '; '-delimited string."""
    all_species: set = set()
    for val in series:
        all_species.update(_parse_species(val))
    return '; '.join(sorted(all_species))


# ── NaN-safe value helper ──────────────────────────────────────────────────────

def _clean(v):
    """Convert NaN / NaT → None for MySQL insertion."""
    if v is None:
        return None
    try:
        if pd.isna(v):
            return None
    except (TypeError, ValueError):
        pass
    return v


# ── Core pipeline ──────────────────────────────────────────────────────────────

def build(years: list) -> None:
    year_filter = ','.join(str(y) for y in sorted(years))
    emit(f'Starting rebuild for year(s): {years}')

    # ── Load source tables ─────────────────────────────────────────────────────
    conn = get_conn()

    emit('Loading land_cover...')
    df_lc = load_sql(conn,
        f"SELECT cell_id, latitude AS lat, longitude AS lon, year, land_cover "
        f"FROM land_cover WHERE year IN ({year_filter})")
    emit(f'  {len(df_lc):,} rows')

    emit('Loading precip (aggregate daily → monthly)...')
    df_pr = load_sql(conn,
        f"SELECT latitude AS lat, longitude AS lon, month, year, "
        f"SUM(precip_mm) AS monthly_precip_mm "
        f"FROM precip WHERE year IN ({year_filter}) "
        f"GROUP BY latitude, longitude, month, year")
    emit(f'  {len(df_pr):,} month-cell rows')

    emit('Loading land_temp...')
    df_lt = load_sql(conn,
        f"SELECT cell_id, month, year, lst_day, lst_night "
        f"FROM land_temp WHERE year IN ({year_filter})")
    emit(f'  {len(df_lt):,} rows')

    emit('Loading ndvi...')
    df_nv = load_sql(conn,
        f"SELECT cell_id, month, year, ndvi "
        f"FROM ndvi WHERE year IN ({year_filter})")
    emit(f'  {len(df_nv):,} rows')

    emit('Loading viirs...')
    df_vi = load_sql(conn,
        f"SELECT cell_id, month, year, viirs_avg_rad "
        f"FROM viirs WHERE year IN ({year_filter})")
    emit(f'  {len(df_vi):,} rows')

    emit('Loading aggregated_bird_observation...')
    df_bd = load_sql(conn,
        f"SELECT grid_lat AS lat, grid_lon AS lon, month, year, "
        f"bird_count, unique_species_count, "
        f"total_tolerant, total_sensitive, total_resident, total_migratory, "
        f"species_list "
        f"FROM aggregated_bird_observation "
        f"WHERE year IN ({year_filter}) "
        f"  AND grid_lat IS NOT NULL AND grid_lon IS NOT NULL")
    emit(f'  {len(df_bd):,} rows')

    emit('Loading kba_pa...')
    df_kp = load_sql(conn, "SELECT lat, lon, is_kba, is_pa FROM kba_pa")
    emit(f'  {len(df_kp):,} rows')

    conn.close()

    # ── Build master grid ──────────────────────────────────────────────────────
    emit('Building master grid (cells × years × 12 months)...')
    cells = df_lc[['cell_id', 'lat', 'lon', 'year']].drop_duplicates()
    months_df = pd.DataFrame({'month': range(1, 13)})
    mg = cells.merge(months_df, how='cross')
    emit(f'  {len(mg):,} rows ({len(cells):,} cell-years)')

    # ── A. Land cover ──────────────────────────────────────────────────────────
    mg = mg.merge(
        df_lc[['cell_id', 'year', 'land_cover']],
        on=['cell_id', 'year'], how='left')
    mg = fill_land_cover_nn(mg)

    # ── B. Precipitation ──────────────────────────────────────────────────────
    emit('Merging precipitation...')
    df_pr['lj']  = df_pr['lat'].apply(_round_precip)
    df_pr['lonj'] = df_pr['lon'].apply(_round_precip)
    pr_m = (df_pr.groupby(['lj', 'lonj', 'year', 'month'])['monthly_precip_mm']
            .mean().reset_index())

    mg['lj']  = mg['lat'].apply(_round_precip)
    mg['lonj'] = mg['lon'].apply(_round_precip)
    mg = mg.merge(pr_m, on=['lj', 'lonj', 'year', 'month'], how='left')
    mg.drop(columns=['lj', 'lonj'], inplace=True)
    mg = fill_precip_nn(mg)

    # ── C. Land surface temperature ───────────────────────────────────────────
    emit('Merging land_temp (LST)...')
    lt_a = (df_lt.groupby(['cell_id', 'year', 'month'])[['lst_day', 'lst_night']]
            .mean().reset_index())
    mg = mg.merge(lt_a, on=['cell_id', 'year', 'month'], how='left')

    # ── D. NDVI ───────────────────────────────────────────────────────────────
    emit('Merging ndvi...')
    nv_a = (df_nv.groupby(['cell_id', 'year', 'month'])[['ndvi']]
            .mean().reset_index())
    mg = mg.merge(nv_a, on=['cell_id', 'year', 'month'], how='left')

    # ── E. VIIRS ──────────────────────────────────────────────────────────────
    emit('Merging viirs...')
    vi_a = (df_vi.groupby(['cell_id', 'year', 'month'])[['viirs_avg_rad']]
            .mean().reset_index())
    mg = mg.merge(vi_a, on=['cell_id', 'year', 'month'], how='left')

    # ── F. Hierarchical fill for NDVI, LST, and VIIRS ────────────────────────
    emit('Applying hierarchical gap-fill (NDVI, LST, VIIRS)...')
    mg = fill_hierarchical(mg, ['ndvi', 'lst_day', 'lst_night', 'viirs_avg_rad'])

    # ── G. Bird observations ──────────────────────────────────────────────────
    emit('Snapping bird observations...')
    if not df_bd.empty:
        df_bd['lat'] = df_bd['lat'].astype(float).round(5)
        df_bd['lon'] = df_bd['lon'].astype(float).round(5)

        bird_num = (df_bd.groupby(['lat', 'lon', 'month', 'year'])
                    .agg(bird_count=('bird_count', 'sum'),
                         unique_species_count=('unique_species_count', 'sum'),
                         total_tolerant_species=('total_tolerant', 'sum'),
                         total_sensitive_species=('total_sensitive', 'sum'),
                         total_resident_species=('total_resident', 'sum'),
                         total_migrant_species=('total_migratory', 'sum'))
                    .reset_index())

        bird_sp = (df_bd.groupby(['lat', 'lon', 'month', 'year'])['species_list']
                   .apply(_merge_species)
                   .reset_index()
                   .rename(columns={'species_list': 'species_list'}))

        bird_agg = bird_num.merge(bird_sp, on=['lat', 'lon', 'month', 'year'])
        bird_agg = bird_agg.rename(columns={'lat': '_blat', 'lon': '_blon'})

        mg['_latr'] = mg['lat'].astype(float).round(5)
        mg['_lonr'] = mg['lon'].astype(float).round(5)
        mg = mg.merge(bird_agg,
                      left_on=['_latr', '_lonr', 'month', 'year'],
                      right_on=['_blat', '_blon', 'month', 'year'],
                      how='left')
        mg.drop(columns=['_latr', '_lonr', '_blat', '_blon'], errors='ignore', inplace=True)

        for col in ['bird_count', 'unique_species_count', 'total_tolerant_species',
                    'total_sensitive_species', 'total_resident_species', 'total_migrant_species']:
            if col in mg.columns:
                mg[col] = mg[col].fillna(0).astype(int)
        if 'species_list' in mg.columns:
            mg['species_list'] = mg['species_list'].fillna('')
        else:
            mg['species_list'] = ''
    else:
        emit('  No bird observations found — filling with zeros.')
        for col in ['bird_count', 'unique_species_count', 'total_tolerant_species',
                    'total_sensitive_species', 'total_resident_species', 'total_migrant_species']:
            mg[col] = 0
        mg['species_list'] = ''

    # ── H. KBA / PA flags ─────────────────────────────────────────────────────
    emit('Merging kba_pa flags...')
    df_kp['lat'] = df_kp['lat'].astype(float).round(5)
    df_kp['lon'] = df_kp['lon'].astype(float).round(5)
    df_kp = df_kp.rename(columns={'lat': '_klat', 'lon': '_klon'})

    mg['_latr'] = mg['lat'].astype(float).round(5)
    mg['_lonr'] = mg['lon'].astype(float).round(5)
    mg = mg.merge(df_kp, left_on=['_latr', '_lonr'],
                  right_on=['_klat', '_klon'], how='left')
    mg.drop(columns=['_latr', '_lonr', '_klat', '_klon'], errors='ignore', inplace=True)
    mg['is_kba'] = mg['is_kba'].fillna(0).astype(int)
    mg['is_pa']  = mg['is_pa'].fillna(0).astype(int)

    # ── Final column selection ─────────────────────────────────────────────────
    FINAL_COLS = [
        'lat', 'lon', 'month', 'cell_id', 'year',
        'land_cover', 'monthly_precip_mm', 'lst_day', 'lst_night',
        'ndvi', 'viirs_avg_rad',
        'bird_count', 'unique_species_count',
        'total_tolerant_species', 'total_sensitive_species',
        'total_resident_species', 'total_migrant_species',
        'is_kba', 'is_pa', 'species_list',
    ]
    for col in FINAL_COLS:
        if col not in mg.columns:
            mg[col] = None
    final = mg[FINAL_COLS].copy()

    emit(f'Final dataset: {len(final):,} rows')

    # ── Write to DB ────────────────────────────────────────────────────────────
    emit('Writing to final_master_grid...')
    conn2 = get_conn()
    cur = conn2.cursor()

    emit('  Inserting rows into final_master_grid...')

    INSERT_SQL = (
        "INSERT INTO final_master_grid "
        "(lat, lon, month, cell_id, year, land_cover, "
        " monthly_precip_mm, lst_day, lst_night, ndvi, viirs_avg_rad, "
        " bird_count, unique_species_count, "
        " total_tolerant_species, total_sensitive_species, "
        " total_resident_species, total_migrant_species, "
        " is_kba, is_pa, species_list) "
        "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)"
    )

    rows = final.values.tolist()
    total = len(rows)
    inserted = 0

    for i in range(0, total, BATCH_SIZE):
        chunk = [tuple(_clean(v) for v in row) for row in rows[i:i + BATCH_SIZE]]
        cur.executemany(INSERT_SQL, chunk)
        conn2.commit()
        inserted += len(chunk)
        pct = round(inserted / total * 100)
        emit(f'  Inserted {inserted:,}/{total:,} rows ({pct}%)')

    cur.close()
    conn2.close()
    emit(f'Rebuild complete — {total:,} rows written to final_master_grid.', 'success')


# ── Entrypoint ─────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description='Rebuild final_master_grid')
    parser.add_argument('--year',  type=int, help='Single year to rebuild')
    parser.add_argument('--years', type=str, help='Comma-separated years (e.g. 2024,2025)')
    args = parser.parse_args()

    try:
        if args.year:
            years = [args.year]
        elif args.years:
            years = [int(y.strip()) for y in args.years.split(',') if y.strip().isdigit()]
        else:
            conn = get_conn()
            df_y = pd.read_sql("SELECT DISTINCT year FROM land_cover ORDER BY year", conn)
            conn.close()
            years = df_y['year'].tolist()
            emit(f'Auto-discovered years from land_cover: {years}')

        if not years:
            emit('No years to process.', 'error')
            sys.exit(1)

        build(years)

    except Exception as exc:
        emit(f'ERROR: {exc}\n{traceback.format_exc()}', 'error')
        sys.exit(1)


if __name__ == '__main__':
    main()
