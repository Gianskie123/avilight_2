# AVILIGHT Dashboard System

Bird Species Monitoring and Light Pollution Forecasting for Metro Manila

![AVILIGHT Logo](AviLight_Logo.png)

## Overview

The AVILIGHT Dashboard System is a comprehensive web-based platform for monitoring bird species diversity and forecasting the impacts of light pollution in Metro Manila, Philippines. The system integrates machine learning models (XGBoost and ConvLSTM) with geospatial analysis to provide actionable insights for conservation policy and urban planning.

## Features

### 📊 Executive Dashboard (Tab 1)
- Real-time statistics on tracked species and risk levels
- KBA/PA monitoring status
- DENR-BMB announcements
- Species distribution charts

### 🗺️ Geospatial Forecasting (Tab 2)
- Interactive Leaflet.js map with 13MB GeoJSON land cover data
- Toggleable layers (resident/migratory species, light intensity, NDVI, KBA/PA boundaries)
- Temporal timeline slider (monthly predictions)
- Click-to-analyze grid cells with SHAP explanations
- Local and global feature importance visualizations

### 🎯 Scenario Modeling (Tab 3)
- "What-if" analysis with policy simulation sliders
- Light reduction, vegetation increase, temperature change scenarios
- Real-time prediction updates
- Policy recommendations engine

### 📈 Statistical Reports (Tab 4)
- Feature correlation matrices
- KBA/PA performance audit with effectiveness scores
- Export capabilities (GeoJSON, PDF, CSV)
- Historical trend analysis (2020-2026)

### ✅ Model Validation (Tab 5)
- XGBoost performance metrics (RMSE, R², MAE)
- ConvLSTM training curves
- Predicted vs actual scatter plots
- 5-fold cross-validation results
- Confidence scoring system

### ⚙️ Admin Panel (Tab 6)
- Data ingestion (CSV/Excel upload)
- Satellite data fetch (VIIRS, MODIS, NOAA)
- Model versioning and management
- Threshold configuration
- Error logs and spatial integrity checks
- System health monitoring

### 🦜 Species Catalog
- Searchable database of 30 bird species
- Filter by light tolerance and migration status
- Detailed species profiles with conservation status

### 📚 Methodology Documentation
- Complete technical documentation
- Data sources and preprocessing steps
- Model architecture details
- SHAP interpretability explanation
- Limitations and references

## Installation

### Requirements

- PHP 7.4 or higher
- Web server (Apache/Nginx)
- Modern web browser with JavaScript enabled

### Setup Instructions

1. **Clone the repository:**
   ```bash
   git clone https://github.com/KaylBOOM/AVILIGHT-TEST.git
   cd AVILIGHT-TEST
   ```

2. **Configure web server:**
   
   **Apache (.htaccess is optional):**
   ```apache
   <VirtualHost *:80>
       DocumentRoot "/path/to/AVILIGHT-TEST"
       ServerName avilight.local
       
       <Directory "/path/to/AVILIGHT-TEST">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   **Nginx:**
   ```nginx
   server {
       listen 80;
       server_name avilight.local;
       root /path/to/AVILIGHT-TEST;
       index login.php index.php;

       location / {
           try_files $uri $uri/ /login.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
           fastcgi_index login.php;
           include fastcgi_params;
       }
   }
   ```

3. **Set file permissions:**
   ```bash
   chmod 755 .
   chmod 644 *.php
   chmod -R 755 assets data api includes
   ```

4. **Access the application:**
   - Navigate to `http://localhost/AVILIGHT-TEST/login.php`
   - Enter any email address to login (authentication is simplified for demo)
   - You'll be redirected to the dashboard

## Team Setup And Secrets

To keep the repository safe for GitHub and avoid having every team member manually configure Gmail:

- Commit `.env.example` only.
- Keep the real `.env` file local on each machine or on the deployment server.
- Do not commit Gmail passwords or SMTP secrets.
- Use a shared SMTP account only in your deployed environment, stored as environment variables or in the server's secret store.

For local development, you can also use a test SMTP tool such as Mailpit instead of Gmail.

Example local setup:
```env
MAIL_DRIVER=phpmailer
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=your@gmail.com
MAIL_PASS=your-gmail-app-password
AVILIGHT_OTP_FROM=your@gmail.com
```

The verification email code flow is handled by `login_otp_email.php` and `includes/auth.php`.

## File Structure

```
AVILIGHT-TEST/
├── login.php                          # Login page
├── dashboard.php                      # Tab 1: Executive Summary
├── geospatial.php                     # Tab 2: Geospatial Forecasting
├── scenario.php                       # Tab 3: Scenario Modeling
├── reports.php                        # Tab 4: Statistical Reports
├── validation.php                     # Tab 5: Model Validation
├── admin.php                          # Tab 6: Admin Panel
├── species.php                        # Bird Species Catalog
├── methodology.php                    # Technical Documentation
├── AviLight_Logo.png                  # Logo image
├── AviLight_LandCover_GeoJSON.geojson # Land cover data (13MB)
│
├── /includes/
│   ├── header.php                     # Common header with navigation
│   ├── footer.php                     # Common footer
│   └── auth.php                       # Session management
│
├── /assets/
│   └── /css/
│       └── main.css                   # Main stylesheet
│
├── /api/
│   ├── get_cell_data.php             # Get grid cell details
│   ├── run_scenario.php              # Execute scenario simulations
│   ├── export_geojson.php            # Export data as GeoJSON
│   └── export_pdf.php                # Generate PDF reports
│
└── /data/
    ├── sample_species.json            # Bird species database (30 species)
    ├── sample_metrics.json            # Model performance metrics
    ├── sample_cells.json              # Grid cell predictions
    └── sample_kba.json                # Key Biodiversity Areas
```

## Data Integration Points

### Replacing Hardcoded Data with Real Sources

The system currently uses sample JSON data for demonstration. To integrate real data sources:

#### 1. Species Observations (eBird)
**File:** `data/sample_species.json`

```php
// Replace with eBird API call
$api_key = 'YOUR_EBIRD_API_KEY';
$url = "https://api.ebird.org/v2/data/obs/PH-00/recent";
$response = file_get_contents($url, false, stream_context_create([
    'http' => ['header' => "X-eBirdApiToken: $api_key"]
]));
$observations = json_decode($response, true);
```

#### 2. Satellite Data (NASA VIIRS)
**Location:** API endpoints or data processing scripts

```python
# Python script to fetch VIIRS data
import requests
from datetime import datetime

def fetch_viirs_data(lat, lon, date):
    url = f"https://ladsweb.modaps.eosdis.nasa.gov/archive/allData/5200/VNP46A2/{date}"
    # Use NASA Earthdata credentials
    # Process HDF5 files
    # Extract radiance values for Metro Manila
    return radiance_data
```

#### 3. Model Predictions
**Files:** `data/sample_cells.json`, `data/sample_metrics.json`

```python
# Load trained model and generate predictions
import xgboost as xgb
import shap

model = xgb.Booster()
model.load_model('models/xgboost_v2.1.pkl')

# Make predictions
predictions = model.predict(X_test)

# Calculate SHAP values
explainer = shap.TreeExplainer(model)
shap_values = explainer.shap_values(X_test)

# Save to JSON for PHP consumption
import json
with open('data/predictions.json', 'w') as f:
    json.dump({
        'predictions': predictions.tolist(),
        'shap_values': shap_values.tolist()
    }, f)
```

#### 4. Database Integration (Optional)
Replace JSON files with MySQL/PostgreSQL queries:

```sql
-- Create tables
CREATE TABLE species (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scientific_name VARCHAR(100),
    common_name VARCHAR(100),
    light_tolerance ENUM('Sensitive', 'Moderate', 'Tolerant'),
    migration_status ENUM('Resident', 'Migratory', 'Both'),
    conservation_status VARCHAR(50)
);

CREATE TABLE predictions (
    cell_id VARCHAR(50) PRIMARY KEY,
    latitude DECIMAL(10, 6),
    longitude DECIMAL(10, 6),
    predicted_richness INT,
    actual_richness INT,
    light_intensity DECIMAL(6, 2),
    ndvi DECIMAL(4, 2),
    prediction_date DATE
);
```

```php
// In PHP files, replace:
$species_data = json_decode(file_get_contents('data/sample_species.json'), true);

// With database query:
$pdo = new PDO('mysql:host=localhost;dbname=avilight', 'user', 'password');
$stmt = $pdo->query('SELECT * FROM species');
$species_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

## Technologies Used

### Frontend
- **HTML5 / CSS3** - Modern responsive design
- **JavaScript (ES6+)** - Interactive functionality
- **Leaflet.js 1.9.4** - Interactive mapping
- **Chart.js 4.4.1** - Data visualizations
- **OpenStreetMap** - Base map tiles

### Backend
- **PHP 7.4+** - Server-side processing
- **JSON** - Data storage (demo phase)

### Machine Learning (External)
- **Python 3.9+**
- **XGBoost 1.7** - Primary prediction model
- **TensorFlow 2.12** - ConvLSTM implementation
- **SHAP 0.42** - Model interpretability

### Data Sources
- **eBird** - Bird observation data
- **NASA VIIRS** - Nighttime light pollution data
- **NASA MODIS** - Vegetation indices (NDVI)
- **NOAA** - Climate data
- **PAGASA** - Local weather data

## Usage Guide

### For Researchers

1. **Explore Species Data:** Use the Species Catalog to identify light-sensitive species
2. **Analyze Spatial Patterns:** Use the Geospatial tab to identify hotspots and at-risk areas
3. **Generate Reports:** Export data and visualizations for publications
4. **Understand Model:** Review Methodology and Validation tabs for technical details

### For Policy Makers

1. **Review Dashboard:** Check current risk levels and KBA/PA status
2. **Run Scenarios:** Test impact of different policy interventions
3. **Identify Priorities:** Use Performance Audit to allocate resources
4. **Download Reports:** Export PDF summaries for presentations

### For Administrators

1. **Upload Data:** Use Admin panel to ingest new observation data
2. **Fetch Satellite Data:** Trigger updates from NASA/NOAA APIs
3. **Manage Models:** Upload new model versions and switch between them
4. **Monitor System:** Check error logs and system health

## Security Considerations

### Current Implementation (Demo)
- Basic session-based authentication
- No password verification
- All logged-in users have admin access

### Production Recommendations
1. **Implement proper authentication:**
   ```php
   // Use password hashing
   $hashed_password = password_hash($password, PASSWORD_DEFAULT);
   
   // Verify login
   if (password_verify($input_password, $stored_hash)) {
       $_SESSION['user_id'] = $user_id;
       $_SESSION['role'] = $user_role;
   }
   ```

2. **Add role-based access control:**
   - Separate roles: Admin, Researcher, Viewer
   - Restrict admin functions to authorized users
   - Implement permission checks before sensitive operations

3. **Secure file uploads:**
   - Validate file types and sizes
   - Scan for malware
   - Store uploads outside web root
   - Use unique filenames

4. **Implement CSRF protection:**
   ```php
   // Generate token
   $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
   
   // Validate on form submission
   if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
       die('CSRF validation failed');
   }
   ```

5. **Use HTTPS in production**
6. **Sanitize all user inputs**
7. **Implement rate limiting for API endpoints**

## Performance Optimization

### Current Optimizations
- Client-side rendering for charts
- Async loading of GeoJSON data
- Loading indicators for large files

### Additional Recommendations
1. **GeoJSON Optimization:**
   - Pre-simplify geometries with `mapshaper`
   - Split into smaller files by region
   - Implement vector tiles (Mapbox Vector Tiles)

2. **Caching:**
   ```php
   // Cache predictions
   $cache_file = "cache/predictions_" . date('Y-m-d') . ".json";
   if (file_exists($cache_file)) {
       return json_decode(file_get_contents($cache_file), true);
   }
   ```

3. **Database Indexing:**
   ```sql
   CREATE INDEX idx_cell_coords ON predictions(latitude, longitude);
   CREATE INDEX idx_species_tolerance ON species(light_tolerance);
   ```

## Troubleshooting

### Common Issues

**Issue:** GeoJSON map not loading
- **Solution:** Check browser console for errors. Verify `AviLight_LandCover_GeoJSON.geojson` exists and is valid JSON.

**Issue:** Charts not rendering
- **Solution:** Ensure Chart.js CDN is accessible. Check browser console for JavaScript errors.

**Issue:** Session not persisting
- **Solution:** Verify PHP session is properly configured. Check `session.save_path` in php.ini.

**Issue:** File upload fails
- **Solution:** Check PHP `upload_max_filesize` and `post_max_size` in php.ini. Verify directory permissions.

## Contributing

This is an academic project. For questions or collaboration:
- Contact: [Project Email]
- Institution: University of the Philippines
- Department: [Department Name]

## License

[Specify License - e.g., MIT, GPL, or Academic Use Only]

## Acknowledgments

- **Data Sources:** eBird, NASA, NOAA, PAGASA, DENR-BMB
- **Institutions:** University of the Philippines, DENR-Biodiversity Management Bureau
- **Community:** Contributors to open-source mapping and data science libraries

## Citation

If you use this system in your research, please cite:

```
[Author Names]. (2026). AVILIGHT: Bird Species Monitoring and Light Pollution 
Forecasting Dashboard for Metro Manila. [Thesis/Report], University of the Philippines.
```

## Version History

- **v1.0.0** (February 2026) - Initial release
  - All 6 tabs functional
  - Sample data integration
  - Complete documentation

## Contact & Support

For technical support or questions:
- Email: [contact email]
- GitHub Issues: https://github.com/KaylBOOM/AVILIGHT-TEST/issues

---

**Built with ❤️ for Philippine biodiversity conservation**
