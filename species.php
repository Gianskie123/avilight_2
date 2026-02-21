<?php
$page_title = 'Bird Species Catalog';
require_once 'includes/header.php';
require_once 'includes/db.php';

$pdo = get_db();

// Handle filters
$tolerance_filter = isset($_GET['tolerance']) ? $_GET['tolerance'] : 'all';
$migration_filter = isset($_GET['migration']) ? $_GET['migration'] : 'all';
$search_query     = isset($_GET['search'])    ? trim($_GET['search'])  : '';
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 50;

// Build WHERE clause
$where  = [];
$params = [];
$valid_tolerances = ['Sensitive', 'Tolerant'];
$valid_migrations = ['Resident', 'Migratory'];

if (in_array($tolerance_filter, $valid_tolerances, true)) {
    $where[]  = 'light_tolerance = :tol';
    $params[':tol'] = $tolerance_filter;
}
if (in_array($migration_filter, $valid_migrations, true)) {
    $where[]  = 'migration_status = :mig';
    $params[':mig'] = $migration_filter;
}
if ($search_query !== '') {
    $where[]  = 'common_name LIKE :search';
    $params[':search'] = '%' . $search_query . '%';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count for pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM species $where_sql");
$count_stmt->execute($params);
$total_filtered = (int) $count_stmt->fetchColumn();
$total_pages    = max(1, (int) ceil($total_filtered / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

// Fetch this page's species
$params[':limit']  = $per_page;
$params[':offset'] = $offset;
$stmt = $pdo->prepare("SELECT * FROM species $where_sql ORDER BY common_name LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($k, $v, $type);
}
$stmt->execute();
$filtered_species = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats (always from full dataset)
$total_species    = (int) $pdo->query('SELECT COUNT(*) FROM species')->fetchColumn();
$sensitive_count  = (int) $pdo->query("SELECT COUNT(*) FROM species WHERE light_tolerance='Sensitive'")->fetchColumn();
$migratory_count  = (int) $pdo->query("SELECT COUNT(*) FROM species WHERE migration_status='Migratory'")->fetchColumn();
?>

<div class="page-header">
    <h1 class="page-title">Bird Species Catalog</h1>
    <p class="page-subtitle">Searchable library of <?php echo $total_species; ?> bird species recorded in Metro Manila</p>
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
                    <option value="Tolerant" <?php echo $tolerance_filter === 'Tolerant' ? 'selected' : ''; ?>>Light-Tolerant</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <select name="migration" class="form-control" style="width: 200px;">
                    <option value="all" <?php echo $migration_filter === 'all' ? 'selected' : ''; ?>>All Migration Types</option>
                    <option value="Resident" <?php echo $migration_filter === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                    <option value="Migratory" <?php echo $migration_filter === 'Migratory' ? 'selected' : ''; ?>>Migratory</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="species.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Results Count -->
<div style="margin: 20px 0; color: #666;">
    Showing <strong><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_filtered)); ?></strong>
    of <strong><?php echo number_format($total_filtered); ?></strong> species
    <?php if ($total_filtered !== $total_species): ?>
        (filtered from <?php echo number_format($total_species); ?> total)
    <?php endif; ?>
</div>

<!-- Species Grid -->
<div class="species-grid">
    <?php foreach ($filtered_species as $species): ?>
    <?php
    $tolerance_class = $species['light_tolerance'] === 'Sensitive' ? 'badge-danger' : 'badge-success';
    $migration_class = $species['migration_status'] === 'Migratory' ? 'badge-info' :
                      ($species['migration_status'] === 'Both' ? 'badge-warning' : 'badge-success');
    ?>
    <div class="species-card">
        <div class="species-image">
            <div style="font-size: 3rem;">🦜</div>
            <small style="color: #999;">Photo not available</small>
        </div>
        <div class="species-info">
            <div class="species-name"><?php echo htmlspecialchars($species['common_name']); ?></div>
            
            <div class="species-tags">
                <span class="badge <?php echo $tolerance_class; ?>">
                    <?php echo htmlspecialchars($species['light_tolerance']); ?>
                </span>
                <span class="badge <?php echo $migration_class; ?>">
                    <?php echo htmlspecialchars($species['migration_status']); ?>
                </span>
            </div>
            
            <button class="btn btn-primary" style="width: 100%; margin-top: 10px; font-size: 0.9rem;" 
                    onclick="showSpeciesDetails(<?php echo (int)$species['id']; ?>)">
                View Details
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($total_filtered === 0): ?>
<div class="alert alert-info">
    No species found matching your search criteria. Try adjusting your filters.
</div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<?php
// Build base URL for pagination (preserve filters)
$base_params = array_filter([
    'search'    => $search_query,
    'tolerance' => $tolerance_filter !== 'all' ? $tolerance_filter : null,
    'migration' => $migration_filter !== 'all' ? $migration_filter : null,
]);
$base_query = http_build_query($base_params);
$page_url = function(int $p) use ($base_query): string {
    $q = $base_query ? $base_query . '&page=' . $p : 'page=' . $p;
    return 'species.php?' . $q;
};
?>
<div style="display: flex; justify-content: center; gap: 8px; margin: 30px 0; flex-wrap: wrap;">
    <?php if ($page > 1): ?>
    <a href="<?php echo htmlspecialchars($page_url($page - 1)); ?>" class="btn btn-secondary">‹ Prev</a>
    <?php endif; ?>
    
    <?php
    $start = max(1, $page - 3);
    $end   = min($total_pages, $page + 3);
    if ($start > 1): ?><a href="<?php echo htmlspecialchars($page_url(1)); ?>" class="btn btn-secondary">1</a><?php if ($start > 2): ?><span style="align-self:center;">…</span><?php endif; endif; ?>
    <?php for ($p = $start; $p <= $end; $p++): ?>
    <a href="<?php echo htmlspecialchars($page_url($p)); ?>" 
       class="btn <?php echo $p === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
    <?php if ($end < $total_pages): ?><?php if ($end < $total_pages - 1): ?><span style="align-self:center;">…</span><?php endif; ?><a href="<?php echo htmlspecialchars($page_url($total_pages)); ?>" class="btn btn-secondary"><?php echo $total_pages; ?></a><?php endif; ?>
    
    <?php if ($page < $total_pages): ?>
    <a href="<?php echo htmlspecialchars($page_url($page + 1)); ?>" class="btn btn-secondary">Next ›</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Species Details Modal -->
<div id="speciesModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
     background: rgba(0,0,0,0.5); z-index: 2000; overflow-y: auto;">
    <div style="max-width: 700px; margin: 50px auto; background: white; border-radius: 8px; padding: 30px;">
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
        <div class="stat-value"><?php echo number_format($total_species); ?></div>
    </div>
    <div class="stat-card danger">
        <div class="stat-label">Light-Sensitive Species</div>
        <div class="stat-value"><?php echo number_format($sensitive_count); ?></div>
    </div>
    <div class="stat-card info">
        <div class="stat-label">Migratory Species</div>
        <div class="stat-value"><?php echo number_format($migratory_count); ?></div>
    </div>
</div>

<?php
// Build compact JS array of only current page for modal (no need to send all 869)
$page_species_js = json_encode(array_values($filtered_species), JSON_HEX_TAG | JSON_HEX_AMP);
$extra_scripts = <<<EOD
<script>
const pageSpecies = {$page_species_js};

function showSpeciesDetails(speciesId) {
    const species = pageSpecies.find(s => s.id === speciesId);
    if (!species) return;

    const tolClass = species.light_tolerance === 'Sensitive' ? 'badge-danger' : 'badge-success';
    const migClass = species.migration_status === 'Migratory' ? 'badge-info' :
                     species.migration_status === 'Both'      ? 'badge-warning' : 'badge-success';

    const tolDesc = species.light_tolerance === 'Sensitive' ?
        'This species is highly affected by artificial light at night and prefers darker habitats.' :
        'This species adapts well to urban environments with artificial lighting.';

    const migDesc = species.migration_status === 'Migratory' ?
        'Migratory species that visits Metro Manila seasonally, typically during winter months (Sep–Feb).' :
        species.migration_status === 'Both' ?
        'Has both resident and migratory populations in Metro Manila.' :
        'Year-round resident of Metro Manila and surrounding areas.';

    document.getElementById('modalTitle').textContent = species.common_name;
    document.getElementById('modalContent').innerHTML = `
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:0 0 100px;text-align:center;">
                <div style="background:#f8f9fa;padding:30px 10px;border-radius:8px;font-size:4rem;">🦜</div>
                <small style="color:#999;">Photo not available</small>
            </div>
            <div style="flex:1;min-width:200px;">
                <h3 style="margin:0 0 16px;">\${species.common_name}</h3>

                <div style="margin-bottom:16px;">
                    <strong>Light Tolerance:</strong><br>
                    <span class="badge \${tolClass}" style="font-size:.9rem;margin-top:4px;">\${species.light_tolerance}</span>
                    <p style="margin:8px 0 0;color:#666;font-size:.9rem;">\${tolDesc}</p>
                </div>

                <div style="margin-bottom:16px;">
                    <strong>Migratory Status:</strong><br>
                    <span class="badge \${migClass}" style="font-size:.9rem;margin-top:4px;">\${species.migration_status}</span>
                    <p style="margin:8px 0 0;color:#666;font-size:.9rem;">\${migDesc}</p>
                </div>

                <div style="padding:12px;background:#f8f9fa;border-radius:8px;">
                    <strong>Monitoring Priority:</strong>
                    <p style="margin:6px 0 0;color:#666;font-size:.9rem;">
                        \${species.light_tolerance === 'Sensitive' ?
                          '⚠️ High – Indicator species for light pollution impacts' :
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
document.getElementById('speciesModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
EOD;

require_once 'includes/footer.php';
?>
