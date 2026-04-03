# AviLight – Local Setup Guide (Gian's Modifications)

This guide covers everything a new developer needs to run the full application locally,
including the Environmental Covariates satellite fetch feature.

---

## Prerequisites

Install these before anything else:

| Tool | Version | Download |
|---|---|---|
| XAMPP | 8.0+ | https://www.apachefriends.org |
| Python | 3.9+ | https://www.python.org/downloads |
| Git | any | https://git-scm.com |

Make sure Python is added to your system PATH during installation.

---

## 1. Clone the repository

Place the project inside XAMPP's `htdocs` folder:

```bash
cd C:\xampp\htdocs
git clone <repo-url> avilight
```

---

## 2. Configure php.ini

Open `C:\xampp\php\php.ini` and find + update these four values:

```ini
upload_max_filesize = 150M
post_max_size       = 160M
max_execution_time  = 300
memory_limit        = 512M
```

After saving, **restart Apache** from the XAMPP Control Panel.

---

## 3. Set up the database

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open **phpMyAdmin**: http://localhost/phpmyadmin
3. Create a new database named exactly `avilight`.
4. Select the `avilight` database, go to the **Import** tab.
5. Import this file: `database/avilight.sql`

That creates all tables (`viirs`, `ndvi`, `land_temp`, `precip`, `raw_bird_observation`, `species_masterlist`, etc.).

---

## 4. Verify the database connection

Open `includes/db.php` and confirm the credentials match your XAMPP setup.
XAMPP defaults are already set — you only need to change these if you customised your MySQL password:

```php
$host   = '127.0.0.1';
$dbname = 'avilight';
$user   = 'root';
$pass   = '';          // change this if you set a MySQL root password
```

---

## 5. Install Python dependencies

Open a terminal and run:

```bash
pip install earthengine-api
```

---

## 6. Authenticate with Google Earth Engine

This is required for the Environmental Covariates fetch buttons to work.
Use the shared Google account below — it already has access to the AviLight GEE project.

email: fit.statyx@gmail.com
password: statyx123.

Run this once in your terminal:
```bash
earthengine authenticate
```

A browser window will open. **Log in using the account above**, approve access,
and paste the verification code back into the terminal.
Credentials are saved to `~/.config/earthengine/credentials` and persist across sessions.

> Do not use your personal Google account — it will not have access to the GEE project.

---

## 7. Verify Python is callable from PHP

Open a browser and go to:

```
http://localhost/avilight/python/test_env.php
```

This page confirms that PHP can find and execute your Python installation.
If it shows an error, make sure `python` is in your system PATH and restart Apache.

> **Note:** On some Windows machines Python is registered as `python3` instead of
> `python`. If the test fails, open `includes/backend_config.php` and change the
> `PYTHON_BIN` fallback from `'python'` to `'python3'`.

---

## 8. Open the application

Go to: http://localhost/avilight

Log in with any email (authentication is not enforced in the current dev build).
Navigate to **Admin Panel** to access Data Ingestion and Environmental Covariates.

---

## File structure reference

```
avilight/
├── admin.php                  # Admin panel (data ingestion + covariate fetching)
├── api/
│   ├── fetch_satellite.php    # Triggers Python GEE workers
│   ├── covariate_status.php   # Returns last-fetch status for each covariate
│   └── upload_data.php        # Bird observation CSV/XLSX ingestion
├── includes/
│   ├── db.php                 # Database connections (SQLite + MariaDB)
│   ├── backend_config.php     # Python path, GEE project ID, key file path
│   └── auth.php               # Session management
├── python/
│   ├── extract_viirs.py       # GEE worker: Artificial Light (VIIRS)
│   ├── extract_ndvi.py        # GEE worker: Vegetation Index (MODIS)
│   ├── extract_lst.py         # GEE worker: Land Surface Temperature (MODIS)
│   └── extract_precip.py      # GEE worker: Precipitation (CHIRPS)
└── database/
    └── avilight.sql           # Full database schema + seed data
```

---

## Troubleshooting

**Fetch buttons do nothing / show a server error**
- Confirm Python is installed and `earthengine authenticate` has been run.
- Check that `python` (or `python3`) works in a plain terminal window.
- Check `includes/backend_config.php` — `PYTHON_BIN` must match the correct command.

**"GEE initialisation failed"**
- Run `earthengine authenticate` again. Cached credentials may have expired.
- Confirm your Google account has been added to the GEE project.

**Upload returns a 500 error or blank response**
- Confirm the `php.ini` changes from Step 2 were saved and Apache was restarted.
- Make sure the `avilight` database exists in phpMyAdmin.

**Covariate status shows "Error" or "Unavailable"**
- The `avilight` database is not running or the tables were not imported.
- Go to phpMyAdmin and verify the `viirs`, `ndvi`, `land_temp`, and `precip` tables exist.

**"No bird observation data found" when clicking Fetch**
- Upload a bird observation CSV/XLSX file via the Data Ingestion section first.
  The covariate fetch compares against existing observation periods.
