<?php
$page_title = 'Bird Species Catalog';
require_once 'includes/header.php';

// Load species data
$species_data = json_decode(file_get_contents('data/sample_species.json'), true);

// Handle filters
$tolerance_filter = isset($_GET['tolerance']) ? $_GET['tolerance'] : 'all';
$migration_filter = isset($_GET['migration']) ? $_GET['migration'] : 'all';
$search_query = isset($_GET['search']) ? strtolower($_GET['search']) : '';

// Filter species
$filtered_species = array_filter($species_data, function($species) use ($tolerance_filter, $migration_filter, $search_query) {
    $tolerance_match = ($tolerance_filter === 'all' || $species['light_tolerance'] === $tolerance_filter);
    $migration_match = ($migration_filter === 'all' || $species['migration_status'] === $migration_filter);
    $search_match = empty($search_query) || 
                    strpos(strtolower($species['common_name']), $search_query) !== false ||
                    strpos(strtolower($species['scientific_name']), $search_query) !== false;
    
    return $tolerance_match && $migration_match && $search_match;
});
?>

<div class="page-header">
    <h1 class="page-title">Bird Species Catalog</h1>
    <p class="page-subtitle">Searchable library of bird species in Metro Manila database</p>
</div>

<!-- Search and Filter Controls -->
<div class="filter-container">
    <form method="GET" action="species.php">
        <div class="filter-group">
            <div class="form-group" style="margin-bottom: 0;">
                <input type="text" name="search" class="form-control" placeholder="Search by name..." 
                       value="<?php echo htmlspecialchars($search_query); ?>" style="width: 300px;">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <select name="tolerance" class="form-control" style="width: 200px;">
                    <option value="all" <?php echo $tolerance_filter === 'all' ? 'selected' : ''; ?>>All Tolerance Levels</option>
                    <option value="Sensitive" <?php echo $tolerance_filter === 'Sensitive' ? 'selected' : ''; ?>>Light-Sensitive</option>
                    <option value="Moderate" <?php echo $tolerance_filter === 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                    <option value="Tolerant" <?php echo $tolerance_filter === 'Tolerant' ? 'selected' : ''; ?>>Light-Tolerant</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <select name="migration" class="form-control" style="width: 200px;">
                    <option value="all" <?php echo $migration_filter === 'all' ? 'selected' : ''; ?>>All Migration Types</option>
                    <option value="Resident" <?php echo $migration_filter === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                    <option value="Migratory" <?php echo $migration_filter === 'Migratory' ? 'selected' : ''; ?>>Migratory</option>
                    <option value="Both" <?php echo $migration_filter === 'Both' ? 'selected' : ''; ?>>Both</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="species.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Results Count -->
<div style="margin: 20px 0; color: #666;">
    Showing <strong><?php echo count($filtered_species); ?></strong> of <strong><?php echo count($species_data); ?></strong> species
</div>

<!-- Species Grid -->
<div class="species-grid">
    <?php foreach ($filtered_species as $species): ?>
    <div class="species-card">
        <div class="species-image">
            <!-- Placeholder for bird image -->
            <div style="font-size: 3rem;">🦜</div>
            <small style="color: #999;">Photo not available</small>
        </div>
        <div class="species-info">
            <div class="species-name"><?php echo htmlspecialchars($species['common_name']); ?></div>
            <div class="species-scientific"><?php echo htmlspecialchars($species['scientific_name']); ?></div>
            
            <div class="species-tags">
                <?php
                $tolerance_class = $species['light_tolerance'] === 'Sensitive' ? 'badge-danger' :
                                  ($species['light_tolerance'] === 'Moderate' ? 'badge-warning' : 'badge-success');
                $migration_class = $species['migration_status'] === 'Migratory' ? 'badge-info' : 'badge-success';
                ?>
                <span class="badge <?php echo $tolerance_class; ?>">
                    <?php echo $species['light_tolerance']; ?>
                </span>
                <span class="badge <?php echo $migration_class; ?>">
                    <?php echo $species['migration_status']; ?>
                </span>
            </div>
            
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                <small style="color: #666;">
                    <strong>Conservation:</strong> <?php echo $species['conservation_status']; ?>
                </small>
            </div>
            
            <button class="btn btn-primary" style="width: 100%; margin-top: 10px; font-size: 0.9rem;" 
                    onclick="showSpeciesDetails(<?php echo $species['id']; ?>)">
                View Details
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (count($filtered_species) === 0): ?>
<div class="alert alert-info">
    No species found matching your search criteria. Try adjusting your filters.
</div>
<?php endif; ?>

<!-- Species Details Modal (Hidden by default) -->
<div id="speciesModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
     background: rgba(0,0,0,0.5); z-index: 2000; overflow-y: auto;">
    <div style="max-width: 800px; margin: 50px auto; background: white; border-radius: 8px; padding: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
            <h2 id="modalTitle" style="margin: 0;"></h2>
            <span onclick="closeModal()" style="cursor: pointer; font-size: 2rem; color: #999;">&times;</span>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<!-- Statistics Summary -->
<div class="grid-3" style="margin-top: 40px;">
    <div class="stat-card">
        <div class="stat-label">Total Species</div>
        <div class="stat-value"><?php echo count($species_data); ?></div>
    </div>
    <div class="stat-card danger">
        <div class="stat-label">Light-Sensitive Species</div>
        <div class="stat-value">
            <?php echo count(array_filter($species_data, function($s) { return $s['light_tolerance'] === 'Sensitive'; })); ?>
        </div>
    </div>
    <div class="stat-card info">
        <div class="stat-label">Migratory Species</div>
        <div class="stat-value">
            <?php echo count(array_filter($species_data, function($s) { return $s['migration_status'] === 'Migratory'; })); ?>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'EOD'
<script>
const speciesData = <?php echo json_encode($species_data); ?>;

function showSpeciesDetails(speciesId) {
    const species = speciesData.find(s => s.id === speciesId);
    if (!species) return;
    
    document.getElementById('modalTitle').textContent = species.common_name;
    document.getElementById('modalContent').innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px;">
            <div>
                <div style="background: #f8f9fa; padding: 60px 20px; text-align: center; border-radius: 8px; font-size: 5rem;">
                    🦜
                </div>
                <p style="text-align: center; color: #999; margin-top: 10px;">
                    <small>Image placeholder</small>
                </p>
            </div>
            
            <div>
                <h3>${species.common_name}</h3>
                <p style="font-style: italic; color: #666; margin-bottom: 20px;">
                    ${species.scientific_name}
                </p>
                
                <div style="margin: 20px 0;">
                    <strong>Light Tolerance Classification:</strong><br>
                    <span class="badge ${species.light_tolerance === 'Sensitive' ? 'badge-danger' : 
                                        species.light_tolerance === 'Moderate' ? 'badge-warning' : 'badge-success'}" 
                          style="font-size: 1rem; margin-top: 5px;">
                        ${species.light_tolerance}
                    </span>
                    <p style="margin-top: 10px; color: #666; font-size: 0.9rem;">
                        ${species.light_tolerance === 'Sensitive' ? 
                          'This species is highly affected by artificial light at night and prefers darker habitats.' :
                          species.light_tolerance === 'Moderate' ?
                          'This species can tolerate moderate levels of light pollution but prefers natural conditions.' :
                          'This species adapts well to urban environments with artificial lighting.'}
                    </p>
                </div>
                
                <div style="margin: 20px 0;">
                    <strong>Migration Status:</strong><br>
                    <span class="badge ${species.migration_status === 'Migratory' ? 'badge-info' : 'badge-success'}" 
                          style="font-size: 1rem; margin-top: 5px;">
                        ${species.migration_status}
                    </span>
                    <p style="margin-top: 10px; color: #666; font-size: 0.9rem;">
                        ${species.migration_status === 'Migratory' ? 
                          'Migratory species that visits Metro Manila seasonally, typically during winter months (Sep-Feb).' :
                          species.migration_status === 'Both' ?
                          'Has both resident and migratory populations in Metro Manila.' :
                          'Year-round resident of Metro Manila and surrounding areas.'}
                    </p>
                </div>
                
                <div style="margin: 20px 0;">
                    <strong>Conservation Status:</strong><br>
                    <span class="badge badge-info" style="font-size: 1rem; margin-top: 5px;">
                        ${species.conservation_status}
                    </span>
                </div>
                
                <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <strong>Habitat Preferences:</strong>
                    <p style="margin-top: 10px; color: #666; font-size: 0.9rem;">
                        ${species.light_tolerance === 'Sensitive' ? 
                          'Prefers densely vegetated areas with minimal artificial lighting. Often found in protected areas like La Mesa Watershed.' :
                          'Adapts to various habitats including urban parks and residential areas.'}
                    </p>
                    
                    <strong style="margin-top: 15px; display: block;">Monitoring Priority:</strong>
                    <p style="margin-top: 5px; color: #666; font-size: 0.9rem;">
                        ${species.light_tolerance === 'Sensitive' ? 
                          '⚠️ High - Indicator species for light pollution impacts' :
                          'Standard monitoring protocol'}
                    </p>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('speciesModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('speciesModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('speciesModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});
</script>
EOD;

require_once 'includes/footer.php';
?>
