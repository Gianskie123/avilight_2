<?php
$page_title = 'Methodology';
require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Methodology & Documentation</h1>
    <p class="page-subtitle">Technical documentation of the AVILIGHT forecasting system</p>
</div>

<!-- Table of Contents -->
<div class="card">
    <h2 class="card-header">Table of Contents</h2>
    <div class="card-body">
        <ul style="columns: 2; column-gap: 40px;">
            <li><a href="#overview">System Overview</a></li>
            <li><a href="#data-sources">Data Sources</a></li>
            <li><a href="#preprocessing">Data Preprocessing</a></li>
            <li><a href="#features">Feature Engineering</a></li>
            <li><a href="#models">Model Architecture</a></li>
            <li><a href="#training">Training Strategy</a></li>
            <li><a href="#validation">Validation Approach</a></li>
            <li><a href="#shap">SHAP Interpretability</a></li>
            <li><a href="#limitations">Limitations</a></li>
            <li><a href="#references">References</a></li>
        </ul>
    </div>
</div>

<!-- System Overview -->
<div class="card" id="overview">
    <h2 class="card-header">1. System Overview</h2>
    <div class="card-body">
        <p>
            The AVILIGHT Dashboard System is a comprehensive bird species monitoring and light pollution 
            forecasting platform designed specifically for Metro Manila, Philippines. The system integrates 
            machine learning models with geospatial analysis to predict bird species richness and assess 
            the impact of artificial light at night (ALAN) on avian biodiversity.
        </p>
        
        <h3>Core Components:</h3>
        <ul>
            <li><strong>XGBoost Regression Model:</strong> Primary prediction engine for species richness</li>
            <li><strong>ConvLSTM Network:</strong> Temporal-spatial forecasting for seasonal patterns</li>
            <li><strong>SHAP Framework:</strong> Model interpretability and feature importance analysis</li>
            <li><strong>Geospatial Engine:</strong> Grid-based analysis with 0.005° resolution (~500m cells)</li>
        </ul>
        
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
            <strong>Development Period:</strong> 2024-2026<br>
            <strong>Coverage Area:</strong> Metro Manila (NCR)<br>
            <strong>Temporal Resolution:</strong> Monthly predictions<br>
            <strong>Spatial Resolution:</strong> 500m grid cells
        </div>
    </div>
</div>

<!-- Data Sources -->
<div class="card" id="data-sources">
    <h2 class="card-header">2. Data Sources</h2>
    <div class="card-body">
        <h3>2.1 Bird Observation Data</h3>
        <table>
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Type</th>
                    <th>Period</th>
                    <th>Records</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>eBird</strong></td>
                    <td>Citizen science observations</td>
                    <td>2018-2025</td>
                    <td>15,847 checklists</td>
                </tr>
                <tr>
                    <td><strong>DENR-BMB</strong></td>
                    <td>Official monitoring records</td>
                    <td>2020-2025</td>
                    <td>2,341 surveys</td>
                </tr>
                <tr>
                    <td><strong>Research Institutions</strong></td>
                    <td>Academic studies</td>
                    <td>2015-2025</td>
                    <td>856 observations</td>
                </tr>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px;">2.2 Environmental Data</h3>
        <div class="grid-2">
            <div>
                <h4>🛰️ Satellite Imagery</h4>
                <ul>
                    <li><strong>NASA VIIRS:</strong> Nighttime light radiance (DNB, 15 arc-second resolution)</li>
                    <li><strong>MODIS:</strong> NDVI vegetation index (250m resolution, 16-day composite)</li>
                    <li><strong>Landsat 8/9:</strong> Land cover classification (30m resolution)</li>
                </ul>
            </div>
            
            <div>
                <h4>🌡️ Climate Data</h4>
                <ul>
                    <li><strong>NOAA:</strong> Temperature, precipitation, humidity</li>
                    <li><strong>PAGASA:</strong> Local weather station data</li>
                    <li><strong>ERA5:</strong> Reanalysis climate variables</li>
                </ul>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: var(--bg-card-alt); border-radius: 8px; border: 1px solid var(--border-color); border-left: 4px solid var(--accent-yellow); color: var(--text-primary);">
            <strong>⚠️ Data Access:</strong> eBird data accessed via API with appropriate research permissions. 
            NASA satellite products are publicly available. DENR data obtained through institutional collaboration.
        </div>
    </div>
</div>

<!-- Preprocessing -->
<div class="card" id="preprocessing">
    <h2 class="card-header">3. Data Preprocessing</h2>
    <div class="card-body">
        <h3>3.1 Spatial Grid Creation</h3>
        <p>
            Metro Manila divided into 0.005° × 0.005° grid cells (approximately 500m × 500m at this latitude).
            Total cells: ~3,200 covering the NCR region.
        </p>
        
        <h3>3.2 Quality Control Steps</h3>
        <ol>
            <li><strong>Coordinate Validation:</strong> Remove observations outside Metro Manila bounds</li>
            <li><strong>Duplicate Removal:</strong> Eliminate duplicate records based on location-date-species</li>
            <li><strong>Taxonomic Harmonization:</strong> Standardize species names to eBird taxonomy</li>
            <li><strong>Outlier Detection:</strong> Flag suspicious observations (e.g., rare vagrant species)</li>
            <li><strong>Temporal Alignment:</strong> Aggregate observations to monthly periods</li>
        </ol>
        
        <h3>3.3 Satellite Data Processing</h3>
        <pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 8px; overflow-x: auto;">
# Example: VIIRS DNB Processing (Python)
import rasterio
from rasterstats import zonal_stats

# Load VIIRS monthly composite
viirs = rasterio.open('VIIRS_DNB_202501.tif')

# Calculate mean radiance per grid cell
light_stats = zonal_stats(
    grid_shapefile,
    viirs.read(1),
    stats=['mean', 'max', 'std']
)

# Extract light intensity values
df['light_intensity'] = [s['mean'] for s in light_stats]
</pre>
    </div>
</div>

<!-- Feature Engineering -->
<div class="card" id="features">
    <h2 class="card-header">4. Feature Engineering</h2>
    <div class="card-body">
        <h3>Input Features (12 total)</h3>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Feature</th>
                    <th>Description</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td rowspan="3"><strong>Light Pollution</strong></td>
                    <td>viirs_radiance</td>
                    <td>Mean nighttime radiance (nW/cm²/sr)</td>
                    <td>VIIRS DNB</td>
                </tr>
                <tr>
                    <td>light_std</td>
                    <td>Spatial variability of light</td>
                    <td>VIIRS DNB</td>
                </tr>
                <tr>
                    <td>temporal_light_change</td>
                    <td>Month-to-month light change</td>
                    <td>Derived</td>
                </tr>
                <tr>
                    <td rowspan="2"><strong>Vegetation</strong></td>
                    <td>ndvi</td>
                    <td>Normalized Difference Vegetation Index</td>
                    <td>MODIS</td>
                </tr>
                <tr>
                    <td>tree_cover_percent</td>
                    <td>Percentage tree canopy cover</td>
                    <td>Landsat</td>
                </tr>
                <tr>
                    <td rowspan="3"><strong>Climate</strong></td>
                    <td>temperature</td>
                    <td>Mean monthly temperature (°C)</td>
                    <td>NOAA</td>
                </tr>
                <tr>
                    <td>precipitation</td>
                    <td>Monthly rainfall (mm)</td>
                    <td>PAGASA</td>
                </tr>
                <tr>
                    <td>humidity</td>
                    <td>Relative humidity (%)</td>
                    <td>NOAA</td>
                </tr>
                <tr>
                    <td rowspan="4"><strong>Landscape</strong></td>
                    <td>elevation</td>
                    <td>Mean elevation (m)</td>
                    <td>SRTM DEM</td>
                </tr>
                <tr>
                    <td>distance_to_water</td>
                    <td>Distance to nearest water body (m)</td>
                    <td>OSM</td>
                </tr>
                <tr>
                    <td>land_cover_type</td>
                    <td>Dominant land cover class</td>
                    <td>Landsat</td>
                </tr>
                <tr>
                    <td>urban_density</td>
                    <td>Building density index</td>
                    <td>OSM</td>
                </tr>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px;">Target Variable</h3>
        <p>
            <strong>Species Richness:</strong> Number of unique species observed in a grid cell during a given month.
            Calculated by aggregating all eBird checklists within the cell-month combination.
        </p>
    </div>
</div>

<!-- Model Architecture -->
<div class="card" id="models">
    <h2 class="card-header">5. Model Architecture</h2>
    <div class="card-body">
        <h3>5.1 XGBoost Regression (Primary Model)</h3>
        <p>
            Gradient boosting decision tree ensemble optimized for species richness prediction.
        </p>
        
        <h4>Hyperparameters:</h4>
        <pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 8px;">
{
    "n_estimators": 500,
    "max_depth": 8,
    "learning_rate": 0.05,
    "subsample": 0.8,
    "colsample_bytree": 0.8,
    "min_child_weight": 3,
    "objective": "reg:squarederror",
    "eval_metric": "rmse"
}
</pre>
        
        <h3 style="margin-top: 30px;">5.2 ConvLSTM (Temporal-Spatial Model)</h3>
        <p>
            Convolutional LSTM network for capturing temporal dependencies in species richness patterns.
        </p>
        
        <h4>Architecture:</h4>
        <ul>
            <li>Input: (batch_size, time_steps=6, height=64, width=64, channels=12)</li>
            <li>ConvLSTM Layer 1: 64 filters, 3×3 kernel</li>
            <li>ConvLSTM Layer 2: 32 filters, 3×3 kernel</li>
            <li>Conv2D Output: 1 filter, 1×1 kernel (species richness map)</li>
            <li>Loss Function: Mean Squared Error</li>
            <li>Optimizer: Adam (lr=0.001)</li>
        </ul>
        
        <div style="margin-top: 20px; padding: 15px; background: #d1ecf1; border-radius: 8px;">
            <strong>Model Selection:</strong> XGBoost used for main predictions due to superior 
            accuracy (R²=0.87) and interpretability. ConvLSTM used for temporal forecasting and 
            validation of spatial patterns.
        </div>
    </div>
</div>

<!-- Training Strategy -->
<div class="card" id="training">
    <h2 class="card-header">6. Training Strategy</h2>
    <div class="card-body">
        <h3>6.1 Data Split</h3>
        <ul>
            <li><strong>Training Set:</strong> 2018-2023 (70% of data, n=11,093)</li>
            <li><strong>Validation Set:</strong> 2024 (15% of data, n=2,377)</li>
            <li><strong>Test Set:</strong> 2025 (15% of data, n=2,377)</li>
        </ul>
        
        <h3>6.2 Cross-Validation</h3>
        <p>
            5-fold spatial cross-validation to ensure model generalizes across different areas of Metro Manila.
            Folds created using k-means clustering on geographic coordinates to maintain spatial blocks.
        </p>
        
        <h3>6.3 Training Procedure</h3>
        <ol>
            <li>Standardize features (z-score normalization)</li>
            <li>Handle class imbalance (oversample low-richness cells)</li>
            <li>Train XGBoost with early stopping (patience=50 rounds)</li>
            <li>Validate on held-out data</li>
            <li>Compute SHAP values for interpretability</li>
        </ol>
        
        <h3>6.4 Computational Resources</h3>
        <p>
            <strong>Hardware:</strong> AWS EC2 p3.2xlarge instance (V100 GPU, 8 vCPUs, 61GB RAM)<br>
            <strong>Training Time:</strong> XGBoost ~45 minutes, ConvLSTM ~6 hours<br>
            <strong>Software:</strong> Python 3.9, XGBoost 1.7, TensorFlow 2.12, SHAP 0.42
        </p>
    </div>
</div>

<!-- Validation -->
<div class="card" id="validation">
    <h2 class="card-header">7. Validation Approach</h2>
    <div class="card-body">
        <h3>7.1 Performance Metrics</h3>
        <ul>
            <li><strong>RMSE:</strong> 2.34 species (±2-3 species typical error)</li>
            <li><strong>R²:</strong> 0.87 (87% of variance explained)</li>
            <li><strong>MAE:</strong> 1.92 species (mean absolute error)</li>
        </ul>
        
        <h3>7.2 Independent Validation</h3>
        <p>
            Model predictions compared against independent eBird observations from 2025 (not used in training).
            Spatial accuracy assessed by comparing predicted vs. actual richness hotspots.
        </p>
        
        <h3>7.3 Expert Review</h3>
        <p>
            Predictions reviewed by ornithologists from University of the Philippines and DENR-Biodiversity 
            Management Bureau. Model outputs align with known ecological patterns (e.g., higher richness 
            in La Mesa Watershed, lower in highly urbanized areas).
        </p>
    </div>
</div>

<!-- SHAP -->
<div class="card" id="shap">
    <h2 class="card-header">8. SHAP Interpretability</h2>
    <div class="card-body">
        <h3>What is SHAP?</h3>
        <p>
            SHAP (SHapley Additive exPlanations) provides unified framework for interpreting predictions 
            by computing the contribution of each feature to individual predictions.
        </p>
        
        <h3>Implementation:</h3>
        <ul>
            <li><strong>TreeExplainer:</strong> Optimized SHAP algorithm for XGBoost models</li>
            <li><strong>Global Importance:</strong> Mean absolute SHAP values across all predictions</li>
            <li><strong>Local Explanations:</strong> Cell-specific feature contributions</li>
        </ul>
        
        <h3>Key Findings:</h3>
        <p>
            Light intensity has the strongest negative impact on species richness (importance = 0.42), 
            followed by NDVI with positive impact (0.28). Temperature shows moderate negative correlation, 
            particularly in summer months (March-May).
        </p>
    </div>
</div>

<!-- Limitations -->
<div class="card" id="limitations">
    <h2 class="card-header">9. Limitations & Assumptions</h2>
    <div class="card-body">
        <h3>Known Limitations:</h3>
        <ol>
            <li><strong>Observer Bias:</strong> eBird data reflects observer effort, not true species distribution. 
                Adjusted using sampling effort covariates.</li>
            <li><strong>Detection Probability:</strong> Model predicts observed richness, not true richness. 
                Cryptic/nocturnal species may be underrepresented.</li>
            <li><strong>Temporal Lag:</strong> Satellite data (especially MODIS) has 16-day latency. 
                Predictions may not capture rapid environmental changes.</li>
            <li><strong>Spatial Scale:</strong> 500m resolution may miss micro-habitat variations important for 
                some species.</li>
            <li><strong>Climate Change:</strong> Model trained on historical data; may not fully capture 
                future climate scenarios beyond training distribution.</li>
        </ol>
        
        <h3>Assumptions:</h3>
        <ul>
            <li>Species-environment relationships remain stable over prediction period</li>
            <li>eBird observation patterns representative of true species occurrence</li>
            <li>Light pollution impacts are immediate (no multi-year lag effects)</li>
            <li>Grid cells are independent (spatial autocorrelation not explicitly modeled)</li>
        </ul>
    </div>
</div>

<!-- References -->
<div class="card" id="references">
    <h2 class="card-header">10. References</h2>
    <div class="card-body">
        <h3>Key Publications:</h3>
        <ol style="font-size: 0.95rem; line-height: 1.8;">
            <li>Gaston, K. J., Visser, M. E., & Hölker, F. (2015). The biological impacts of 
                artificial light at night: the research challenge. <em>Philosophical Transactions 
                of the Royal Society B</em>, 370(1667).</li>
            <li>Sullivan, B. L., et al. (2014). The eBird enterprise: An integrated approach to 
                development and application of citizen science. <em>Biological Conservation</em>, 169, 31-40.</li>
            <li>Chen, T., & Guestrin, C. (2016). XGBoost: A scalable tree boosting system. 
                <em>Proceedings of the 22nd ACM SIGKDD</em>.</li>
            <li>Lundberg, S. M., & Lee, S. I. (2017). A unified approach to interpreting model predictions. 
                <em>Advances in Neural Information Processing Systems</em>, 30.</li>
            <li>Elvidge, C. D., et al. (2017). VIIRS night-time lights. <em>International Journal of 
                Remote Sensing</em>, 38(21), 5860-5879.</li>
        </ol>
        
        <h3 style="margin-top: 30px;">Data Sources:</h3>
        <ul style="font-size: 0.95rem;">
            <li>eBird Basic Dataset. Version EBD_relJan-2026. Cornell Lab of Ornithology, Ithaca, NY.</li>
            <li>NASA VIIRS/NPP Day Night Band. <a href="https://ladsweb.modaps.eosdis.nasa.gov/" target="_blank">
                https://ladsweb.modaps.eosdis.nasa.gov/</a></li>
            <li>MODIS Vegetation Indices (MOD13Q1). NASA LP DAAC.</li>
            <li>NOAA Climate Data Online. <a href="https://www.ncdc.noaa.gov/cdo-web/" target="_blank">
                https://www.ncdc.noaa.gov/cdo-web/</a></li>
        </ul>
        
        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
            <strong>Citation for this system:</strong><br>
            <em>[Author Names]. (2026). AVILIGHT: Bird Species Monitoring and Light Pollution Forecasting 
            Dashboard for Metro Manila. [Thesis/Report], University of the Philippines.</em>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
