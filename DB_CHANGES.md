# AVILIGHT — Database & System Changes
**Base schema:** avilight (5).sql  
**Optimization script:** database/optimization.sql  
**Date applied:** 2026-04-11  

---

## 1. INDEXES — Full List After Optimization

### `raw_bird_observation` (~295K rows | PARTITIONED by year)

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `id`, `year` | CHANGED (was `observation_id`) |
| `idx_rbo_latlon_species` | Index | `latitude`, `longitude`, `species_id` | NEW |
| `uidx_rbo_obs_id` | Unique | `observation_id`(100), `year` | NEW |
| `fk_obs_species` | Index | `species_id` | Original (FK dropped, index kept) |
| `idx_rbo_grid_time` | Index | `grid_lat`, `grid_lon`, `year`, `month` | Original |
| `idx_rbo_time` | Index | `year`, `month` | Original |

**Partitions:** `p2014` `p2015` `p2016` `p2017` `p2018` `p2019` `p2020` `p2021` `p2022` `p2023` `p2024` `pfuture`

---

### `observation_city_map`

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `rbo_id` | CHANGED (was `observation_id`) |
| `idx_ocm_area` | Index | `area` | Original |

---

### `viirs` (~705K rows | PARTITIONED by year)

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `viirs_id`, `year` | CHANGED (was `viirs_id` only) |
| `uidx_viirs_cell_time` | Unique | `cell_id`, `year`, `month` | Original |
| `idx_viirs_master` | Index | `latitude`, `longitude`, `year`, `month` | Original |
| `idx_viirs_cell_year_month` | Index | `cell_id`, `year`, `month` | Original |

**Partitions:** `p2014` to `p2024` + `pfuture`

---

### `ndvi` (~579K rows | PARTITIONED by year)

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `ndvi_id`, `year` | CHANGED (was `ndvi_id` only) |
| `uidx_ndvi_cell_time` | Unique | `cell_id`, `year`, `month` | Original |
| `idx_ndvi_master` | Index | `latitude`, `longitude`, `year`, `month` | Original |
| `idx_ndvi_cell_year_month` | Index | `cell_id`, `year`, `month` | Original |

**Partitions:** `p2014` to `p2024` + `pfuture`

---

### `land_temp` (~576K rows | PARTITIONED by year)

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `land_temp_id`, `year` | CHANGED (was `land_temp_id` only) |
| `uidx_ltemp_cell_time` | Unique | `cell_id`, `year`, `month` | Original |
| `idx_temp_master` | Index | `latitude`, `longitude`, `year`, `month` | Original |
| `idx_ltemp_cell_year_month` | Index | `cell_id`, `year`, `month` | Original |

**Partitions:** `p2014` to `p2024` + `pfuture`

---

### `precip` (~679K rows | PARTITIONED by year)

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `precip_id`, `year` | CHANGED (was `precip_id` only) |
| `uidx_precip_cell_time` | Unique | `cell_id`, `year`, `month` | Original |
| `idx_precip_master` | Index | `latitude`, `longitude`, `year`, `month` | Original |
| `idx_precip_cell_year_month` | Index | `cell_id`, `year`, `month` | Original |

**Partitions:** `p2014` to `p2024` + `pfuture`

---

### `model_parameters`

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `PRIMARY` | Primary Key | `id` | Original |
| `model_id` | Index | `model_id` | Original |
| `fk_mp_model` | Foreign Key | `model_id` to `models.id` | RENAMED (was `model_parameters_ibfk_1`) |

---

### `upload_rejection_log`

| Index Name | Type | Columns | Status |
|---|---|---|---|
| `fk_url_ul` | Foreign Key | `upload_log_id` to `upload_log.id` | RENAMED (was `upload_rejection_log_ibfk_1`) |

---

## 2. QUERY USAGE GUIDE

### `idx_rbo_latlon_species`
**Used by:** KBA bounding-box queries in `home.php`
**Activates when:**
```sql
WHERE latitude BETWEEN ? AND ?
  AND longitude BETWEEN ? AND ?
```
**Effect:** Eliminates full table scan. Query reads only the index without touching main table rows.

---

### PRIMARY `(id, year)` + Partitioning on `raw_bird_observation`
**Used by:** Any query filtering by `year`
**Activates when:**
```sql
WHERE r.year = ?
WHERE r.year BETWEEN ? AND ?
```
**Effect:** Scans only the relevant partition. A single-year query reads ~27K rows instead of 295K.

---

### `uidx_rbo_obs_id (observation_id, year)`
**Used by:** `upload_data.php` during eBird INSERT
**Effect:** Silently rejects duplicate uploads of the same eBird observation for the same year.

---

### `idx_rbo_grid_time (grid_lat, grid_lon, year, month)`
**Used by:** Scatter plot and grid-based spatial queries
**Activates when:**
```sql
WHERE r.grid_lat = ? AND r.grid_lon = ?
  AND r.year = ? AND r.month = ?
```

---

### `idx_rbo_time (year, month)`
**Used by:** Time-series queries
**Activates when:**
```sql
WHERE r.year = ? AND r.month = ?
```

---

### `uidx_viirs/ndvi/ltemp/precip_cell_time (cell_id, year, month)`
**Used by:** Environmental data lookups in scatter and report queries
**Activates when:**
```sql
WHERE cell_id = ? AND year = ? AND month = ?
```
**Effect:** O(1) lookup per grid cell per time period.

---

### `idx_viirs/ndvi/temp/precip_master (latitude, longitude, year, month)`
**Used by:** Spatial JOIN queries in `get_report_data.php` and `tmp_scatter_probe.php`
**Activates when:**
```sql
JOIN viirs v ON v.latitude = r.latitude
           AND v.longitude = r.longitude
           AND v.year = r.year
           AND v.month = r.month
```

---

### PRIMARY `(rbo_id)` on `observation_city_map`
**Used by:** All city-level aggregation queries
**Activates when:**
```sql
JOIN observation_city_map m ON m.rbo_id = r.id
```
**Effect:** O(1) BIGINT integer join instead of slow VARCHAR(255) string comparison.

---

## 3. DATABASE CHANGES SUMMARY

### Structural Changes

| Table | What Changed |
|---|---|
| `raw_bird_observation` | Added `id BIGINT AUTO_INCREMENT` as first column |
| `raw_bird_observation` | PRIMARY KEY changed from `(observation_id)` to `(id, year)` |
| `raw_bird_observation` | Added covering index `idx_rbo_latlon_species` |
| `raw_bird_observation` | Added unique key `uidx_rbo_obs_id (observation_id, year)` |
| `raw_bird_observation` | Dropped FK `fk_obs_species` — MariaDB partitioning incompatibility |
| `raw_bird_observation` | Applied RANGE partitioning by `year` (12 partitions) |
| `observation_city_map` | Added `rbo_id BIGINT` column |
| `observation_city_map` | Back-filled `rbo_id` from `raw_bird_observation.id` |
| `observation_city_map` | PRIMARY KEY changed from `(observation_id)` to `(rbo_id)` |
| `observation_city_map` | Dropped `observation_id` column |
| `viirs` | PRIMARY KEY extended to `(viirs_id, year)` |
| `viirs` | Applied RANGE partitioning by `year` (12 partitions) |
| `ndvi` | PRIMARY KEY extended to `(ndvi_id, year)` |
| `ndvi` | Applied RANGE partitioning by `year` (12 partitions) |
| `land_temp` | PRIMARY KEY extended to `(land_temp_id, year)` |
| `land_temp` | Applied RANGE partitioning by `year` (12 partitions) |
| `precip` | PRIMARY KEY extended to `(precip_id, year)` |
| `precip` | Applied RANGE partitioning by `year` (12 partitions) |
| `model_parameters` | FK renamed: `model_parameters_ibfk_1` to `fk_mp_model` |
| `upload_rejection_log` | FK renamed: `upload_rejection_log_ibfk_1` to `fk_url_ul` |

### Dropped Constraints

| Constraint | Table | Reason |
|---|---|---|
| `fk_obs_species` | `raw_bird_observation` | MariaDB cannot partition a table with FK constraints |
| `fk_ocm_rbo` | `observation_city_map` | MariaDB cannot FK-reference a partitioned table |
| `fk_rbo_species` | `raw_bird_observation` | Same partitioning incompatibility |

**Data integrity replacement:** Species validation enforced by `upload_data.php`. City mapping rebuilt on refresh by `get_report_data.php`.

---

## 4. PHP SYSTEM CHANGES

### `api/get_report_data.php`

| What | Old | New |
|---|---|---|
| `ensureSpatialMapTables()` schema | `observation_id VARCHAR(255) NOT NULL, PRIMARY KEY (observation_id)` | `rbo_id BIGINT NOT NULL, PRIMARY KEY (rbo_id)` |
| INSERT column | `(observation_id, area)` | `(rbo_id, area)` |
| SELECT for map build | `SELECT observation_id, latitude, longitude` | `SELECT id, latitude, longitude` |
| Bind value | `$row['observation_id']` (string) | `(int) $row['id']` |
| 7 JOIN clauses | `m.observation_id = r.observation_id` | `m.rbo_id = r.id` |

---

### `api/refresh_report_cache.php`

| What | Old | New |
|---|---|---|
| INSERT column | `(observation_id, area)` | `(rbo_id, area)` |
| SELECT for map build | `observation_id` | `id` |
| Bind value | `$row['observation_id']` | `(int) $row['id']` |
| JOIN clause | `m.observation_id = r.observation_id` | `m.rbo_id = r.id` |

---

### `tmp_scatter_probe.php`

| What | Old | New |
|---|---|---|
| 3 JOIN clauses | `m.observation_id = r.observation_id` | `m.rbo_id = r.id` |

---

### `tmp_area_year_audit.php`

| What | Old | New |
|---|---|---|
| JOIN clause | `m.observation_id = r.observation_id` | `m.rbo_id = r.id` |

---

### `api/upload_data.php`
Not modified. Inserts still use `observation_id` which remains as a UNIQUE key for duplicate detection. The `id` column is AUTO_INCREMENT and populates itself.

---

## 5. CONFIG CHANGES

### `C:\xampp\phpMyAdmin\config.inc.php`
```php
$cfg['ExecTimeLimit'] = 3600;
```

### `C:\xampp\php\php.ini`
```ini
max_execution_time = 3600
max_input_time = 3600
```

### `C:\xampp\mysql\bin\my.ini`
```ini
wait_timeout = 28800
interactive_timeout = 28800
max_allowed_packet = 256M
```
