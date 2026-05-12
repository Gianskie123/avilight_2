<?php
$page_title = 'Bird Species Catalog';
require_once 'includes/header.php';
require_once 'includes/db.php';

$pdo = get_mysql_db();

// Handle filters
$tolerance_filter = isset($_GET['tolerance']) ? $_GET['tolerance'] : 'all';
$migration_filter = isset($_GET['migration']) ? $_GET['migration'] : 'all';
$search_query     = isset($_GET['search'])    ? trim($_GET['search'])  : '';
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 50;

// Build WHERE clause
$where  = [];
$params = [];
$valid_tolerances = ['sensitive', 'tolerant'];
$valid_migrations = ['resident', 'migratory'];

// Tolerance filter (form sends 'Sensitive'/'Tolerant', DB stores lowercase)
$tol_lower = strtolower($tolerance_filter);
if (in_array($tol_lower, $valid_tolerances, true)) {
    $where[]  = 'light_tolerance = :tol';
    $params[':tol'] = $tol_lower;
}

// Migration filter (form sends 'Resident'/'Migratory', DB stores lowercase)
$mig_lower = strtolower($migration_filter);
if (in_array($mig_lower, $valid_migrations, true)) {
    $where[]  = 'migratory_status = :mig';
    $params[':mig'] = $mig_lower;
}

/**
 * PHP-side fuzzy match using per-word Levenshtein distance.
 * Returns [species_id => score] sorted best-first.
 * Tolerance: 1 edit per 3 chars (e.g. "Kinfisher" → "Kingfisher" = 1 edit).
 */
function fuzzy_species_ids(PDO $pdo, string $query): array {
    $q     = strtolower(trim($query));
    $words = array_values(array_filter(preg_split('/\s+/', $q), fn($w) => strlen($w) >= 2));
    if (!$words) return [];

    $rows = $pdo->query(
        'SELECT species_id, LOWER(species_name) AS name FROM species_masterlist'
    )->fetchAll(PDO::FETCH_ASSOC);

    $matches = [];
    foreach ($rows as $row) {
        $name       = $row['name'];
        $name_words = array_values(array_filter(
            preg_split('/[\s\-]+/', $name), fn($w) => strlen($w) >= 2
        ));
        $score = 0;

        // Bonus: full phrase is a substring (already caught by LIKE, but keeps ordering)
        if (strpos($name, $q) !== false) {
            $score = 1000;
        } else {
            // Per-word Levenshtein: each query word vs each name word
            foreach ($words as $qw) {
                $best_dist = PHP_INT_MAX;
                foreach ($name_words as $nw) {
                    $best_dist = min($best_dist, levenshtein($qw, $nw));
                }
                $max_allowed = max(1, (int)(strlen($qw) / 3));
                if ($best_dist <= $max_allowed) {
                    $score += (strlen($qw) - $best_dist) * 10;
                }
            }
        }

        if ($score > 0) {
            $matches[(int)$row['species_id']] = $score;
        }
    }

    arsort($matches);
    return $matches;
}

// ── Search strategy ─────────────────────────────────────────────────────────
// Pass 1: fast SQL LIKE (case-insensitive via utf8mb4_general_ci collation).
// Pass 2: if LIKE returns nothing, fall back to PHP Levenshtein for mid-word
//         typos that LIKE cannot catch (e.g. "Kinfisher" → "Kingfisher").
// The active filter (tolerance/migration) is always applied in SQL.

$fuzzy_ids     = null;   // null = not needed; array = IDs from Levenshtein pass
$order_sql     = 'ORDER BY species_name';

if ($search_query !== '') {
    $words = array_values(array_filter(
        array_map('trim', preg_split('/\s+/', $search_query))
    ));

    // Build LIKE conditions (pass 1)
    $like_conds   = [];
    $like_conds[] = 'species_name LIKE :phrase';
    $params[':phrase'] = '%' . $search_query . '%';
    foreach ($words as $i => $word) {
        $key = ':word' . $i;
        $like_conds[] = "species_name LIKE $key";
        $params[$key] = '%' . $word . '%';
    }
    $where[] = '(' . implode(' OR ', $like_conds) . ')';

    $order_sql = 'ORDER BY
        CASE WHEN species_name LIKE :rank_phrase THEN 0 ELSE 1 END,
        species_name';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pass 1 count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM species_masterlist $where_sql");
$count_stmt->execute($params ?: null);
$like_count = (int) $count_stmt->fetchColumn();

// Pass 2: Levenshtein fallback when LIKE found nothing
if ($search_query !== '' && $like_count === 0) {
    $fuzzy_map = fuzzy_species_ids($pdo, $search_query);

    if ($fuzzy_map) {
        $fuzzy_ids = array_keys($fuzzy_map);

        // Rebuild WHERE using the fuzzy ID list + active filters
        $filter_where  = [];
        $filter_params = [];
        $tol_lower2 = strtolower($tolerance_filter);
        if (in_array($tol_lower2, $valid_tolerances, true)) {
            $filter_where[]  = 'light_tolerance = :tol';
            $filter_params[':tol'] = $tol_lower2;
        }
        $mig_lower2 = strtolower($migration_filter);
        if (in_array($mig_lower2, $valid_migrations, true)) {
            $filter_where[]  = 'migratory_status = :mig';
            $filter_params[':mig'] = $mig_lower2;
        }

        // Inline IDs as integers — safe because they come from the DB as int PKs.
        $in_list        = implode(',', array_map('intval', $fuzzy_ids));
        $filter_where[] = "species_id IN ($in_list)";
        $fuzzy_where_sql = $filter_where ? 'WHERE ' . implode(' AND ', $filter_where) : '';

        // Count with filters applied (all named params, no positional mixing)
        $cs = $pdo->prepare("SELECT COUNT(*) FROM species_masterlist $fuzzy_where_sql");
        $cs->execute($filter_params ?: null);
        $like_count = (int) $cs->fetchColumn();

        // Preserve relevance order via FIELD()
        $field_list = implode(',', $fuzzy_ids);
        $order_sql  = "ORDER BY FIELD(species_id, $field_list)";

        // Override params/where_sql for fetch below
        $params    = $filter_params;
        $where_sql = $fuzzy_where_sql;
    } else {
        $fuzzy_ids = [];   // genuinely no matches
    }
}

$total_filtered = $like_count;
$total_pages    = max(1, (int) ceil($total_filtered / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

// Skip the fetch when there are no results
if ($total_filtered === 0) {
    $filtered_species = [];
} else {
    $fetch_params = $params;
    if ($search_query !== '' && $fuzzy_ids === null) {
        $fetch_params[':rank_phrase'] = '%' . $search_query . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT species_id AS id, species_name AS common_name, light_tolerance,
                migratory_status AS migration_status, description, image_path
         FROM species_masterlist
         $where_sql
         $order_sql
         LIMIT $per_page OFFSET $offset"
    );
    $stmt->execute($fetch_params ?: null);
    $filtered_species = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Stats (always from full dataset)
$total_species   = (int) $pdo->query('SELECT COUNT(*) FROM species_masterlist')->fetchColumn();
$sensitive_count = (int) $pdo->query("SELECT COUNT(*) FROM species_masterlist WHERE light_tolerance='sensitive'")->fetchColumn();
$tolerant_count  = (int) $pdo->query("SELECT COUNT(*) FROM species_masterlist WHERE light_tolerance='tolerant'")->fetchColumn();
$migratory_count = (int) $pdo->query("SELECT COUNT(*) FROM species_masterlist WHERE migratory_status='migratory'")->fetchColumn();
$resident_count  = (int) $pdo->query("SELECT COUNT(*) FROM species_masterlist WHERE migratory_status='resident'")->fetchColumn();

// Edit tab counts (lazy-load rows via API)
$no_category_count = (int) $pdo->query(
    "SELECT COUNT(*) FROM species_masterlist
     WHERE light_tolerance='' OR light_tolerance IS NULL OR migratory_status='' OR migratory_status IS NULL"
)->fetchColumn();
$all_species_count = $total_species;

function cache_bust_image_path(?string $path): string {
    if (empty($path)) {
        return '';
    }
    $clean = strtok($path, '?');
    $abs = __DIR__ . '/' . ltrim($clean, '/');
    if (is_file($abs)) {
        return $clean . '?v=' . filemtime($abs);
    }
    return $path;
}

function apply_image_cache_bust(array $rows): array {
    foreach ($rows as &$row) {
        if (!empty($row['image_path'])) {
            $row['image_path'] = cache_bust_image_path($row['image_path']);
        }
    }
    unset($row);
    return $rows;
}

$filtered_species = apply_image_cache_bust($filtered_species);
?>

<div class="page-header">
    <h1 class="page-title">Bird Species Catalog</h1>
    <p class="page-subtitle">Searchable library of <?php echo $total_species; ?> bird species recorded in Metro Manila</p>
</div>

<!-- Statistics Summary -->
<div style="display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap;">
    <div class="stat-card" style="flex:1;min-width:120px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div class="stat-label" style="margin:0;">Total Species</div>
        </div>
        <div class="stat-value"><?php echo number_format($total_species); ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent-yellow)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            <div class="stat-label" style="margin:0;">Sensitive</div>
        </div>
        <div class="stat-value"><?php echo number_format($sensitive_count); ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent-green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <div class="stat-label" style="margin:0;">Tolerant</div>
        </div>
        <div class="stat-value"><?php echo number_format($tolerant_count); ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--info-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/></svg>
            <div class="stat-label" style="margin:0;">Migratory</div>
        </div>
        <div class="stat-value"><?php echo number_format($migratory_count); ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent-teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <div class="stat-label" style="margin:0;">Resident</div>
        </div>
        <div class="stat-value"><?php echo number_format($resident_count); ?></div>
    </div>
</div>

<?php
$edit_total = $no_category_count;
?>

<!-- Main Tab Navigation -->
<div style="display:flex;gap:6px;margin-bottom:24px;background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:8px;padding:5px;">
    <button class="main-tab-btn active" data-tab="catalog"
            style="flex:1;padding:9px 20px;border-radius:6px;border:none;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-size:.95rem;font-weight:600;box-shadow:var(--shadow);transition:all .2s;">
        Bird Catalog
    </button>
    <button class="main-tab-btn" data-tab="edit"
            style="flex:1;padding:9px 20px;border-radius:6px;border:none;background:none;color:var(--text-secondary);cursor:pointer;font-size:.95rem;font-weight:500;transition:all .2s;">
        Edit Species
        <?php if ($edit_total > 0): ?>
        <span style="background:var(--danger-color,#dc2626);color:#fff;border-radius:999px;font-size:.72rem;padding:1px 7px;margin-left:5px;font-weight:700;">
            <?php echo $edit_total; ?>
        </span>
        <?php endif; ?>
    </button>
</div>

<!-- TAB: Catalog -->
<div id="tab-catalog" class="main-tab-panel">

<!-- Search and Filter Controls -->
<div class="filter-container">
    <form id="catalog-form" method="GET" action="species.php" onsubmit="return false;">
        <div class="filter-group">
            <div class="form-group" style="margin-bottom: 0;">
                <input type="text" id="catalog-search" name="search" class="form-control"
                       placeholder="Search by name..."
                       value="<?php echo htmlspecialchars($search_query); ?>" style="width: 300px;">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <select id="catalog-tolerance" name="tolerance" class="form-control" style="width: 200px;">
                    <option value="all" <?php echo $tolerance_filter === 'all' ? 'selected' : ''; ?>>All Tolerance Levels</option>
                    <option value="Sensitive" <?php echo strtolower($tolerance_filter) === 'sensitive' ? 'selected' : ''; ?>>Light-Sensitive</option>
                    <option value="Tolerant" <?php echo strtolower($tolerance_filter) === 'tolerant' ? 'selected' : ''; ?>>Light-Tolerant</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <select id="catalog-migration" name="migration" class="form-control" style="width: 200px;">
                    <option value="all" <?php echo $migration_filter === 'all' ? 'selected' : ''; ?>>All Migration Types</option>
                    <option value="Resident" <?php echo strtolower($migration_filter) === 'resident' ? 'selected' : ''; ?>>Resident</option>
                    <option value="Migratory" <?php echo strtolower($migration_filter) === 'migratory' ? 'selected' : ''; ?>>Migratory</option>
                </select>
            </div>

            <button type="button" class="btn btn-primary" onclick="fetchCatalog(1)">Search</button>
            <a href="species.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Results Count -->
<div id="catalog-results-count" style="margin: 20px 0; color: var(--text-secondary);">
    <?php if ($total_filtered > 0): ?>
    Showing <strong><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_filtered)); ?></strong>
    of <strong><?php echo number_format($total_filtered); ?></strong> species
    <?php if ($total_filtered !== $total_species): ?>
        (filtered from <?php echo number_format($total_species); ?> total)
    <?php endif; ?>
    <?php else: ?>
    No species found matching your search criteria.
    <?php endif; ?>
</div>

<!-- Species Grid -->
<div id="catalog-grid" class="species-grid">
    <?php foreach ($filtered_species as $species): ?>
    <?php
    $tol_val = strtolower($species['light_tolerance']);
    $mig_val = strtolower($species['migration_status']);
    $tolerance_class = $tol_val === 'tolerant'  ? 'badge-success' : 'badge-warning';
    $migration_class = $mig_val === 'resident'  ? 'badge-success' : 'badge-info';
    $tol_label = ucfirst($tol_val);
    $mig_label = ucfirst($mig_val);

    // Derive WebP sibling for <picture> element
    $has_img  = !empty($species['image_path']);
    $img_src  = $has_img ? htmlspecialchars($species['image_path']) : '';
    $webp_src = '';
    if ($has_img) {
        $img_clean  = strtok($species['image_path'], '?');
        $webp_clean = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $img_clean);
        $webp_abs   = __DIR__ . '/' . ltrim($webp_clean, '/');
        if (is_file($webp_abs)) {
            $webp_src = htmlspecialchars($webp_clean . '?v=' . filemtime($webp_abs));
        }
    }
    ?>
    <div class="species-card" style="background:var(--bg-card);"
         onclick="showSpeciesDetails(<?php echo (int)$species['id']; ?>)">
        <div class="species-image<?php echo $has_img ? ' has-photo' : ''; ?>"
             <?php if ($has_img): ?>style="--card-img-url:url('<?php echo $img_src; ?>')"<?php endif; ?>>
            <?php if ($has_img): ?>
                <picture>
                    <?php if ($webp_src): ?>
                    <source srcset="<?php echo $webp_src; ?>" type="image/webp">
                    <?php endif; ?>
                    <img class="species-photo"
                         src="<?php echo $img_src; ?>"
                         alt="<?php echo htmlspecialchars($species['common_name']); ?>"
                         loading="lazy" decoding="async" width="320" height="240"
                         onload="this.closest('.species-image')?.classList.add('img-loaded')"
                         onerror="handleImgError(this)">
                </picture>
            <?php else: ?>
                <div style="font-size: 3rem;">🦜</div>
                <small style="color: var(--text-muted);">Photo not available</small>
            <?php endif; ?>
        </div>
        <div class="species-info" style="background:var(--bg-card);">
            <div class="species-name"><?php echo htmlspecialchars($species['common_name']); ?></div>

            <div class="species-tags">
                <?php if ($tol_label): ?>
                <span class="badge <?php echo $tolerance_class; ?>"><?php echo htmlspecialchars($tol_label); ?></span>
                <?php endif; ?>
                <?php if ($mig_label): ?>
                <span class="badge <?php echo $migration_class; ?>"><?php echo htmlspecialchars($mig_label); ?></span>
                <?php endif; ?>
                <?php if (!$tol_label && !$mig_label): ?>
                <span style="font-size:.78rem;color:var(--text-muted);">Not yet classified</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination (JS-intercepted; PHP provides fallback links for first load) -->
<div id="catalog-pagination" style="display:flex;justify-content:center;gap:8px;margin:30px 0;flex-wrap:wrap;">
<?php if ($total_pages > 1):
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
    $start = max(1, $page - 3);
    $end   = min($total_pages, $page + 3);
    if ($page > 1): ?><a href="<?php echo htmlspecialchars($page_url($page - 1)); ?>" class="btn btn-secondary" data-catalog-page="<?php echo $page - 1; ?>">‹ Prev</a><?php endif; ?>
    <?php if ($start > 1): ?><a href="<?php echo htmlspecialchars($page_url(1)); ?>" class="btn btn-secondary" data-catalog-page="1">1</a><?php if ($start > 2): ?><span style="align-self:center;">…</span><?php endif; endif; ?>
    <?php for ($p = $start; $p <= $end; $p++): ?><a href="<?php echo htmlspecialchars($page_url($p)); ?>" class="btn <?php echo $p === $page ? 'btn-primary' : 'btn-secondary'; ?>" data-catalog-page="<?php echo $p; ?>"><?php echo $p; ?></a><?php endfor; ?>
    <?php if ($end < $total_pages): ?><?php if ($end < $total_pages - 1): ?><span style="align-self:center;">…</span><?php endif; ?><a href="<?php echo htmlspecialchars($page_url($total_pages)); ?>" class="btn btn-secondary" data-catalog-page="<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a><?php endif; ?>
    <?php if ($page < $total_pages): ?><a href="<?php echo htmlspecialchars($page_url($page + 1)); ?>" class="btn btn-secondary" data-catalog-page="<?php echo $page + 1; ?>">Next ›</a><?php endif; ?>
<?php endif; ?>
</div>

</div><!-- /tab-catalog -->

<!-- TAB: Edit Species -->
<div id="tab-edit" class="main-tab-panel" style="display:none;">

    <!-- Subtitle -->
    <p class="page-subtitle" style="margin-bottom:20px;">
        Managing <?php echo number_format($total_species); ?> bird species —
        <strong><?php echo number_format($no_category_count); ?></strong> need categorization
    </p>

    <!-- Sub-tab nav (same pill style as main tabs) -->
    <div style="display:flex;gap:6px;margin-bottom:20px;background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:8px;padding:5px;">
        <button class="edit-tab-btn active" data-etab="no-category"
                style="flex:1;padding:8px 16px;border-radius:6px;border:none;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-size:.9rem;font-weight:600;box-shadow:var(--shadow);transition:all .2s;">
            No Categories
            <span style="background:var(--danger-color,#dc2626);color:#fff;border-radius:999px;font-size:.72rem;padding:1px 7px;margin-left:4px;"><?php echo number_format($no_category_count); ?></span>
        </button>
        <button class="edit-tab-btn" data-etab="edit-details"
                style="flex:1;padding:8px 16px;border-radius:6px;border:none;background:none;color:var(--text-secondary);cursor:pointer;font-size:.9rem;font-weight:500;transition:all .2s;">
            Edit / Incomplete
            <span style="background:var(--bg-card-alt);border-radius:999px;font-size:.72rem;padding:1px 7px;margin-left:4px;border:1px solid var(--border-color);"><?php echo number_format($all_species_count); ?></span>
        </button>
    </div>

    <div id="etab-no-category" class="edit-tab-panel" data-etab="no-category">
        <p style="color:var(--text-secondary);font-size:.875rem;margin-bottom:14px;padding:10px 14px;background:var(--bg-card-alt);border-left:3px solid var(--accent-blue);border-radius:0 6px 6px 0;">
            These species are missing a light tolerance or migration status. Assign both to include them in analysis.
        </p>
        <div style="margin-bottom:12px;">
            <input type="text" class="form-control etable-search" data-etab="no-category" placeholder="Search species..."
                   style="max-width:320px;padding:7px 12px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
        </div>
        <div id="etable-loading-no-category" style="padding:16px;border-radius:8px;background:var(--bg-card-alt);border:1px solid var(--border-color);color:var(--text-secondary);">
            Loading species…
        </div>
        <div id="etable-empty-no-category" style="display:none;padding:16px;border-radius:8px;background:var(--bg-card-alt);border:1px solid var(--border-color);color:var(--text-secondary);">
            All species in this category are complete.
        </div>
        <div id="etable-no-category" style="display:none;"></div>
        <div id="etable-pagination-no-category" style="display:flex;justify-content:center;gap:8px;margin:20px 0;flex-wrap:wrap;"></div>
    </div>
    <div id="etab-edit-details" class="edit-tab-panel" data-etab="edit-details" style="display:none;">
        <p style="color:var(--text-secondary);font-size:.875rem;margin-bottom:14px;padding:10px 14px;background:var(--bg-card-alt);border-left:3px solid var(--accent-blue);border-radius:0 6px 6px 0;">
            All species — species missing a photo or description are flagged below. Click Edit to update any entry.
        </p>
        <div style="margin-bottom:12px;">
            <input type="text" class="form-control etable-search" data-etab="edit-details" placeholder="Search species..."
                   style="max-width:320px;padding:7px 12px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
        </div>
        <div id="etable-loading-edit-details" style="padding:16px;border-radius:8px;background:var(--bg-card-alt);border:1px solid var(--border-color);color:var(--text-secondary);">
            Loading species…
        </div>
        <div id="etable-empty-edit-details" style="display:none;padding:16px;border-radius:8px;background:var(--bg-card-alt);border:1px solid var(--border-color);color:var(--text-secondary);">
            No species found.
        </div>
        <div id="etable-edit-details" style="display:none;"></div>
        <div id="etable-pagination-edit-details" style="display:flex;justify-content:center;gap:8px;margin:20px 0;flex-wrap:wrap;"></div>
    </div>

</div><!-- /tab-edit -->

<!-- Merge Species Modal -->
<div id="mergeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2003;overflow-y:auto;backdrop-filter:blur(2px);">
    <div style="max-width:520px;margin:60px auto 60px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;box-shadow:var(--shadow-lg);overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:1px solid var(--border-color);background:var(--bg-card-alt);display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:2px;">Merge into existing species</div>
                <h2 id="mergeModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;color:var(--text-primary);"></h2>
            </div>
            <button onclick="closeMergeModal()" style="background:none;border:none;cursor:pointer;font-size:1.5rem;color:var(--text-muted);">&times;</button>
        </div>
        <div style="padding:24px;">
            <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:16px;padding:10px 14px;background:var(--bg-card-alt);border-left:3px solid var(--accent-blue,#2563eb);border-radius:0 6px 6px 0;">
                All observations linked to <strong id="mergeSourceName"></strong> will be reassigned to the species you select below, then the duplicate entry will be removed.
            </p>

            <!-- Search box -->
            <div style="margin-bottom:12px;">
                <input type="text" id="mergeSearchInput" placeholder="Type to search species..."
                       oninput="filterMergeList()"
                       style="width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:7px;background:var(--bg-input);color:var(--text-primary);font-size:.9rem;box-sizing:border-box;">
            </div>

            <!-- Scrollable species list -->
            <div id="mergeSpeciesList"
                 style="max-height:260px;overflow-y:auto;border:1px solid var(--border-color);border-radius:7px;">
                <!-- populated by JS -->
            </div>

            <div id="mergeSelectedDisplay" style="display:none;margin-top:14px;padding:10px 14px;background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:7px;font-size:.9rem;">
                Merging into: <strong id="mergeTargetName" style="color:var(--accent-blue,#2563eb);"></strong>
            </div>

            <div id="mergeStatusMsg" style="display:none;margin-top:12px;padding:10px 14px;border-radius:7px;font-size:.875rem;"></div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button id="mergeConfirmBtn" class="btn btn-primary"
                        style="flex:1;"
                        onclick="executeMerge()" disabled>
                    Confirm Merge
                </button>
                <button class="btn btn-secondary" onclick="closeMergeModal()" style="padding:10px 20px;border-radius:7px;">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2002;overflow-y:auto;backdrop-filter:blur(2px);">
    <div style="max-width:420px;margin:120px auto;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;box-shadow:var(--shadow-lg);overflow:hidden;">
        <div style="padding:6px 20px 0;background:rgba(220,38,38,0.06);border-bottom:1px solid rgba(220,38,38,0.15);">
            <div style="display:flex;align-items:center;gap:10px;padding:16px 4px;">
                <div style="width:36px;height:36px;border-radius:50%;background:rgba(220,38,38,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--danger-color,#dc2626)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:var(--text-primary);">Delete Species</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">This action cannot be undone</div>
                </div>
            </div>
        </div>
        <div style="padding:20px 24px 24px;">
            <p id="deleteModalMsg" style="color:var(--text-secondary);font-size:.9rem;margin-bottom:20px;line-height:1.5;"></p>
            <div style="display:flex;gap:10px;">
                <button id="deleteConfirmBtn"
                    style="flex:1;padding:9px;border-radius:7px;border:none;cursor:pointer;font-size:.9rem;font-weight:600;background:var(--danger-color,#dc2626);color:#fff;transition:opacity .15s;"
                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    Delete
                </button>
                <button onclick="closeDeleteModal()"
                        style="flex:1;padding:9px;border-radius:7px;border:1px solid var(--border-color);cursor:pointer;font-size:.9rem;background:var(--bg-card);color:var(--text-secondary);transition:background .15s;"
                        onmouseover="this.style.background='var(--bg-card-alt)'" onmouseout="this.style.background='var(--bg-card)'">
                    Cancel
                </button>
            </div>
            <div id="deleteStatusMsg" style="display:none;margin-top:14px;padding:10px 14px;border-radius:7px;font-size:.875rem;text-align:center;"></div>
        </div>
    </div>
</div>

<!-- Edit Species Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2001;overflow-y:auto;backdrop-filter:blur(2px);">
    <div style="max-width:580px;margin:40px auto 60px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;box-shadow:var(--shadow-lg);overflow:hidden;">

        <!-- Modal header -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;border-bottom:1px solid var(--border-color);background:var(--bg-card-alt);">
            <div>
                <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:2px;">Edit Species</div>
                <h2 id="editModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;color:var(--text-primary);"></h2>
            </div>
            <button onclick="closeEditModal()" style="background:none;border:none;cursor:pointer;font-size:1.5rem;color:var(--text-muted);line-height:1;padding:4px;">&times;</button>
        </div>

        <form id="editForm" onsubmit="saveSpecies(event)" enctype="multipart/form-data" style="padding:24px;">
            <input type="hidden" id="editSpeciesId" name="species_id">
            <input type="hidden" id="editSpeciesName" name="species_name">

            <!-- Image upload area -->
            <div style="margin-bottom:20px;">
                <label style="display:block;margin-bottom:8px;font-size:.85rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Species Photo</label>
                <div id="imageDropZone"
                     style="border:2px dashed var(--border-color);border-radius:10px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;position:relative;overflow:hidden;min-height:320px;display:flex;align-items:center;justify-content:center;"
                     onclick="document.getElementById('editImageFile').click()"
                     ondragover="event.preventDefault();this.style.borderColor='var(--accent-color)';this.style.background='var(--bg-card-alt)';"
                     ondragleave="this.style.borderColor='var(--border-color)';this.style.background='';"
                     ondrop="handleImageDrop(event)">
                    <img id="imagePreview" src="" alt=""
                         style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;inset:0;">
                    <div id="imageDropPlaceholder" style="padding:20px;">
                        <div style="font-size:2rem;margin-bottom:6px;">📷</div>
                        <div style="font-size:.9rem;color:var(--text-secondary);">Click or drag & drop to upload</div>
                        <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px;">JPG, PNG, WEBP — resized to 320×320 px</div>
                    </div>
                    <input type="file" id="editImageFile" name="image_file" accept="image/jpeg,image/png,image/webp"
                           style="display:none;" onchange="previewImage(this)">
                </div>
                <input type="hidden" id="removeImageFlag" name="remove_image" value="0">
                <div id="currentImageNote" style="margin-top:6px;display:none;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                    <span style="font-size:.8rem;color:var(--text-muted);">Current image on file — upload a new one to replace it.</span>
                    <button type="button" id="removePhotoBtn"
                            onclick="removeCurrentPhoto()"
                            style="font-size:.78rem;padding:3px 10px;border-radius:5px;border:1px solid rgba(220,38,38,0.4);background:rgba(220,38,38,0.07);color:var(--danger-color,#dc2626);cursor:pointer;white-space:nowrap;flex-shrink:0;">
                        Remove Photo
                    </button>
                </div>
            </div>

            <!-- Categories row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label style="display:block;margin-bottom:6px;font-size:.85rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Light Tolerance</label>
                    <select id="editTolerance" name="light_tolerance" class="form-control"
                            style="width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:7px;background:var(--bg-input);color:var(--text-primary);">
                        <option value="">— Not set —</option>
                        <option value="sensitive">Sensitive</option>
                        <option value="tolerant">Tolerant</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;font-size:.85rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Migration Status</label>
                    <select id="editMigration" name="migratory_status" class="form-control"
                            style="width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:7px;background:var(--bg-input);color:var(--text-primary);">
                        <option value="">— Not set —</option>
                        <option value="migratory">Migratory</option>
                        <option value="resident">Resident</option>
                    </select>
                </div>
            </div>

            <!-- Description -->
            <div style="margin-bottom:20px;">
                <label style="display:block;margin-bottom:6px;font-size:.85rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Description</label>
                <textarea id="editDescription" name="description" rows="4"
                          style="width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:7px;background:var(--bg-input);color:var(--text-primary);resize:vertical;font-size:.9rem;font-family:inherit;"
                          placeholder="Enter a description for this species..."></textarea>
            </div>

            <!-- Status message -->
            <div id="editSaveMsg" style="display:none;padding:10px 14px;border-radius:7px;font-size:.875rem;margin-bottom:16px;"></div>

            <!-- Actions -->
            <div style="display:flex;gap:10px;">
                <button type="submit" id="editSaveBtn" class="btn btn-primary" style="flex:1;padding:10px;font-size:.95rem;border-radius:7px;font-weight:600;">
                    Save Changes
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()"
                        style="padding:10px 20px;border-radius:7px;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Species Details Modal -->
<div id="speciesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:2000;overflow-y:auto;">
    <div style="max-width:860px;margin:50px auto 60px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:30px;color:var(--text-primary);box-shadow:var(--shadow-lg);">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:20px;">
            <h2 id="modalTitle" style="margin:0;"></h2>
            <span onclick="closeModal()" style="cursor:pointer;font-size:2rem;color:var(--text-muted);line-height:1;">&times;</span>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div id="imageLightbox" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.9); z-index: 3000; overflow: auto; padding: 20px;">
    <div style="display: flex; justify-content: center; align-items: center; min-height: 100%; position: relative;">
        <span onclick="closeLightbox()" style="position: absolute; top: 20px; right: 30px; cursor: pointer; font-size: 3rem; color: #fff; z-index: 3001;">&times;</span>
        <img id="lightboxImage" src="" alt="" style="max-width: 90vw; max-height: 90vh; object-fit: contain;">
    </div>
</div>

<?php
$page_species_js = json_encode(array_values($filtered_species), JSON_HEX_TAG | JSON_HEX_AMP);
$extra_scripts = <<<EOD
<script>
const pageSpecies = {$page_species_js};

let _locSpeciesId = null;

// ── Catalog AJAX (live search + pagination) ──────────────────────────────────
let _catalogPage = <?php echo (int)$page; ?>;
let _catalogSearchTimer = null;

async function fetchCatalog(page) {
    _catalogPage = page || 1;
    const search = document.getElementById('catalog-search')?.value  ?? '';
    const tol    = document.getElementById('catalog-tolerance')?.value ?? 'all';
    const mig    = document.getElementById('catalog-migration')?.value ?? 'all';

    const params = new URLSearchParams({ search, tolerance: tol, migration: mig, page: String(_catalogPage) });

    const grid    = document.getElementById('catalog-grid');
    const countEl = document.getElementById('catalog-results-count');
    if (grid) grid.style.opacity = '0.45';

    try {
        const res  = await fetch('api/get_catalog.php?' + params);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'fetch failed');

        pageSpecies = data.rows;
        history.replaceState({}, '', 'species.php?' + params);
        renderCatalogGrid(data);
    } catch (err) {
        if (countEl) countEl.textContent = 'Could not load species.';
    } finally {
        if (grid) grid.style.opacity = '1';
    }
}

function renderCatalogGrid(data) {
    const grid    = document.getElementById('catalog-grid');
    const countEl = document.getElementById('catalog-results-count');
    const pagEl   = document.getElementById('catalog-pagination');
    if (!grid) return;

    const offset = (data.page - 1) * data.per_page;
    if (countEl) {
        if (data.total_filtered > 0) {
            const from = (offset + 1).toLocaleString();
            const to   = Math.min(offset + data.per_page, data.total_filtered).toLocaleString();
            countEl.innerHTML = `Showing <strong>${from}–${to}</strong> of <strong>${data.total_filtered.toLocaleString()}</strong> species`;
        } else {
            countEl.textContent = 'No species found matching your search criteria.';
        }
    }

    grid.innerHTML = data.rows.length
        ? data.rows.map(renderSpeciesCard).join('')
        : '<div class="alert alert-info">No species found matching your search criteria. Try adjusting your filters.</div>';

    if (pagEl) renderCatalogPagination(data.page, data.total_pages);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function renderSpeciesCard(sp) {
    const tol      = (sp.light_tolerance  || '').toLowerCase();
    const mig      = (sp.migration_status || '').toLowerCase();
    const tolLabel = tol ? tol.charAt(0).toUpperCase() + tol.slice(1) : '';
    const migLabel = mig ? mig.charAt(0).toUpperCase() + mig.slice(1) : '';
    const tolClass = tol === 'tolerant' ? 'badge-success' : (tol ? 'badge-warning' : '');
    const migClass = mig === 'resident' ? 'badge-success' : (mig ? 'badge-info'    : '');
    const imgSrc   = escapeHtml(sp.image_path  || '');
    const webpSrc  = escapeHtml(sp.webp_path   || '');
    const altText  = escapeHtml(sp.common_name || '');
    const id       = Number(sp.id);

    let imgHtml;
    if (imgSrc) {
        const src = webpSrc ? `<source srcset="${webpSrc}" type="image/webp">` : '';
        imgHtml = `<div class="species-image has-photo" style="--card-img-url:url('${imgSrc}')">
            <picture>${src}<img class="species-photo" src="${imgSrc}" alt="${altText}"
                loading="lazy" decoding="async" width="320" height="240"
                onload="this.closest('.species-image')?.classList.add('img-loaded')"
                onerror="handleImgError(this)"></picture></div>`;
    } else {
        imgHtml = `<div class="species-image"><div style="font-size:3rem;">🦜</div>
            <small style="color:var(--text-muted);">Photo not available</small></div>`;
    }

    let badges;
    if (!tolLabel && !migLabel) {
        badges = '<span style="font-size:.78rem;color:var(--text-muted);">Not yet classified</span>';
    } else {
        badges = (tolLabel ? `<span class="badge ${tolClass}">${tolLabel}</span>` : '')
               + (migLabel ? `<span class="badge ${migClass}">${migLabel}</span>` : '');
    }

    return `<div class="species-card" style="background:var(--bg-card);" onclick="showSpeciesDetails(${id})">
        ${imgHtml}
        <div class="species-info" style="background:var(--bg-card);">
            <div class="species-name">${escapeHtml(sp.common_name)}</div>
            <div class="species-tags">${badges}</div>
        </div></div>`;
}

function renderCatalogPagination(page, totalPages) {
    const pagEl = document.getElementById('catalog-pagination');
    if (!pagEl) return;
    if (totalPages <= 1) { pagEl.innerHTML = ''; return; }

    const btn = (label, p, active) =>
        `<button class="btn ${active ? 'btn-primary' : 'btn-secondary'}"
                 onclick="fetchCatalog(${p})"${active ? ' disabled' : ''}>${label}</button>`;

    const start = Math.max(1, page - 3);
    const end   = Math.min(totalPages, page + 3);
    let html = '';
    if (page > 1) html += btn('‹ Prev', page - 1, false);
    if (start > 1) { html += btn('1', 1, false); if (start > 2) html += '<span style="align-self:center">…</span>'; }
    for (let p = start; p <= end; p++) html += btn(String(p), p, p === page);
    if (end < totalPages) { if (end < totalPages - 1) html += '<span style="align-self:center">…</span>'; html += btn(String(totalPages), totalPages, false); }
    if (page < totalPages) html += btn('Next ›', page + 1, false);
    pagEl.innerHTML = html;
}

// Intercept PHP-rendered pagination links on first load
document.getElementById('catalog-pagination')?.addEventListener('click', e => {
    const link = e.target.closest('[data-catalog-page]');
    if (!link) return;
    e.preventDefault();
    fetchCatalog(parseInt(link.dataset.catalogPage, 10));
});

// Live search: debounce input, instant on select change
document.getElementById('catalog-search')?.addEventListener('input', () => {
    clearTimeout(_catalogSearchTimer);
    _catalogSearchTimer = setTimeout(() => fetchCatalog(1), 350);
});
document.getElementById('catalog-tolerance')?.addEventListener('change', () => fetchCatalog(1));
document.getElementById('catalog-migration')?.addEventListener('change', () => fetchCatalog(1));

function showSpeciesDetails(speciesId) {
    const species = pageSpecies.find(s => parseInt(s.id) === speciesId);
    if (!species) return;

    _locSpeciesId = speciesId;

    const tol      = (species.light_tolerance  || '').toLowerCase();
    const mig      = (species.migration_status || '').toLowerCase();
    const tolLabel = tol.charAt(0).toUpperCase() + tol.slice(1);
    const migLabel = mig.charAt(0).toUpperCase() + mig.slice(1);
    const tolClass = tol === 'tolerant'  ? 'badge-success' : 'badge-warning';
    const migClass = mig === 'resident'  ? 'badge-success' : 'badge-info';

    const description = (species.description && species.description.trim())
        ? species.description.trim()
        : 'N/A';

    const escapedImagePath = (species.image_path || '').replace(/'/g, "\\'");
    const escapedCommonName = (species.common_name || '').replace(/'/g, "\\'");
    const imageBgPath = species.image_path ? encodeURI(species.image_path) : '';

    const imageHtml = species.image_path
                ? '<div class="species-detail-image-shell has-photo">'
            + '<div class="species-detail-image-bg" style="background-image:url(' + imageBgPath + ');"></div>'
            + '<img class="species-detail-photo" src="' + species.image_path + '" alt="' + species.common_name + '" title="Click to zoom" onclick="openLightbox(\'' + escapedImagePath + '\',\'' + escapedCommonName + '\')">'
          + '</div>'
        : '<div class="species-detail-image-shell species-detail-image-empty" style="font-size:4rem;flex-direction:column;"><div>🦜</div><small style="margin-top:10px;color:var(--text-muted);">Photo not available</small></div>';

    const selStyle = 'padding:5px 10px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:.82rem;cursor:pointer;';

    const html = '<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">'
        + '<div style="flex:0 0 320px;text-align:center;">' + imageHtml + '</div>'
        + '<div style="flex:1;min-width:200px;">'
        + '<div style="margin-bottom:12px;"><strong style="color:var(--text-primary);">Light Tolerance:</strong><br>'
        + '<span class="badge ' + tolClass + '" style="font-size:.9rem;margin-top:4px;">' + tolLabel + '</span></div>'
        + '<div style="margin-bottom:16px;"><strong style="color:var(--text-primary);">Migratory Status:</strong><br>'
        + '<span class="badge ' + migClass + '" style="font-size:.9rem;margin-top:4px;">' + migLabel + '</span></div>'
        + '<div style="margin-bottom:16px;"><strong style="color:var(--text-primary);">Description:</strong>'
        + '<p style="margin:6px 0 0;color:var(--text-secondary);font-size:.9rem;">' + description + '</p></div>'
        + '<div><strong style="color:var(--text-primary);">Sighted At:</strong>'
        + '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px;">'
        + '<select id="locYearFilter" style="' + selStyle + '"><option value="">All Years</option></select>'
        + '<select id="locMonthFilter" style="' + selStyle + '">'
        + '<option value="">All Months</option>'
        + '<option value="1">January</option><option value="2">February</option><option value="3">March</option>'
        + '<option value="4">April</option><option value="5">May</option><option value="6">June</option>'
        + '<option value="7">July</option><option value="8">August</option><option value="9">September</option>'
        + '<option value="10">October</option><option value="11">November</option><option value="12">December</option>'
        + '</select>'
        + '<button onclick="applyLocationFilter()" style="padding:5px 12px;border-radius:6px;border:none;background:var(--accent-blue,#2563eb);color:#fff;font-size:.82rem;cursor:pointer;font-weight:500;">Apply</button>'
        + '<button id="locClearBtn" onclick="clearLocationFilter()" style="display:none;padding:5px 10px;border-radius:6px;border:1px solid var(--border-color);background:none;color:var(--text-secondary);font-size:.82rem;cursor:pointer;">Clear</button>'
        + '</div>'
        + '<div id="locActiveFilter" style="display:none;margin-top:5px;font-size:.78rem;color:var(--text-muted);font-style:italic;"></div>'
        + '<div id="speciesLocations" style="margin-top:8px;color:var(--text-secondary);font-size:.85rem;">'
        + '<span style="color:var(--text-muted);">Loading locations…</span></div>'
        + '</div></div></div>';

    document.getElementById('modalTitle').textContent = species.common_name;
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('speciesModal').style.display = 'block';
    loadSpeciesLocations(speciesId, '', '');
}

function loadSpeciesLocations(speciesId, year, month) {
    const el = document.getElementById('speciesLocations');
    if (!el) return;
    el.innerHTML = '<span style="color:var(--text-muted);">Loading locations…</span>';

    let url = 'api/get_species_locations.php?species_id=' + speciesId;
    if (year)  url += '&year='  + encodeURIComponent(year);
    if (month) url += '&month=' + encodeURIComponent(month);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const yearSel = document.getElementById('locYearFilter');
            if (yearSel && yearSel.options.length === 1 && data.available_years && data.available_years.length) {
                data.available_years.forEach(yr => {
                    const opt = document.createElement('option');
                    opt.value       = yr;
                    opt.textContent = yr;
                    yearSel.appendChild(opt);
                });
            }
            const el2 = document.getElementById('speciesLocations');
            if (!el2) return;
            if (!data.success || !data.locations || !data.locations.length) {
                el2.innerHTML = '<span style="color:var(--text-muted);">No sighting records found for this period.</span>';
                return;
            }
            el2.innerHTML = data.locations.map((loc, i) => {
                const count = Number(loc.sighting_count).toLocaleString();
                const label = loc.sighting_count == 1 ? 'sighting' : 'sightings';
                const name  = loc.site_name.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                return '<div style="display:flex;justify-content:space-between;align-items:baseline;padding:5px 0;border-bottom:1px solid var(--border-color);">'
                    + '<span style="flex-shrink:0;width:22px;color:var(--text-muted);font-size:.78rem;font-weight:700;">#' + (i + 1) + '</span>'
                    + '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-left:4px;" title="' + name + '">' + loc.site_name + '</span>'
                    + '<span style="flex-shrink:0;margin-left:12px;color:var(--text-muted);font-size:.78rem;">' + count + ' ' + label + '</span>'
                    + '</div>';
            }).join('');
        })
        .catch(() => {
            const el2 = document.getElementById('speciesLocations');
            if (el2) el2.innerHTML = '<span style="color:var(--text-muted);">Could not load locations.</span>';
        });
}

function applyLocationFilter() {
    const yearSel  = document.getElementById('locYearFilter');
    const monthSel = document.getElementById('locMonthFilter');
    if (!yearSel || !monthSel || !_locSpeciesId) return;

    const year  = yearSel.value;
    const month = monthSel.value;
    const monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

    const labelEl  = document.getElementById('locActiveFilter');
    const clearBtn = document.getElementById('locClearBtn');

    if (year || month) {
        const parts = [];
        if (year)  parts.push(year);
        if (month) parts.push(monthNames[parseInt(month)]);
        if (labelEl)  { labelEl.style.display = ''; labelEl.textContent = 'Showing: ' + parts.join(', '); }
        if (clearBtn) clearBtn.style.display = '';
    } else {
        if (labelEl)  labelEl.style.display  = 'none';
        if (clearBtn) clearBtn.style.display = 'none';
    }

    loadSpeciesLocations(_locSpeciesId, year, month);
}

function clearLocationFilter() {
    const yearSel  = document.getElementById('locYearFilter');
    const monthSel = document.getElementById('locMonthFilter');
    if (yearSel)  yearSel.value  = '';
    if (monthSel) monthSel.value = '';
    const labelEl  = document.getElementById('locActiveFilter');
    const clearBtn = document.getElementById('locClearBtn');
    if (labelEl)  labelEl.style.display  = 'none';
    if (clearBtn) clearBtn.style.display = 'none';
    if (_locSpeciesId) loadSpeciesLocations(_locSpeciesId, '', '');
}

function closeModal() {
    document.getElementById('speciesModal').style.display = 'none';
}
document.getElementById('speciesModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModal(); closeEditModal(); }
});

// ── Pill tab helper (shared by both main and edit sub-tabs) ──────────────────
function initPillTabs(btnSelector, panelPrefix) {
    document.querySelectorAll(btnSelector).forEach(btn => {
        btn.addEventListener('click', () => {
            // Deactivate all
            document.querySelectorAll(btnSelector).forEach(b => {
                b.style.background  = 'none';
                b.style.color       = 'var(--text-secondary)';
                b.style.fontWeight  = '500';
                b.style.boxShadow   = 'none';
            });
            // Hide all panels
            document.querySelectorAll(btnSelector).forEach(b => {
                const id = panelPrefix + b.dataset[panelPrefix === 'tab-' ? 'tab' : 'etab'];
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            // Activate clicked
            btn.style.background = 'var(--bg-card)';
            btn.style.color      = 'var(--text-primary)';
            btn.style.fontWeight = '600';
            btn.style.boxShadow  = 'var(--shadow)';
            const key    = panelPrefix === 'tab-' ? btn.dataset.tab : btn.dataset.etab;
            const panel  = document.getElementById(panelPrefix + key);
            if (panel) panel.style.display = '';
        });
    });
}
initPillTabs('.main-tab-btn',  'tab-');
initPillTabs('.edit-tab-btn',  'etab-');

// ── Edit tab lazy-load + pagination ───────────────────────────────────────
const editTableState = {
    'no-category': { loaded: false, page: 1, q: '', perPage: 25 },
    'edit-details': { loaded: false, page: 1, q: '', perPage: 25 },
};

function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function encodeAttr(value) {
    return encodeURIComponent(String(value || ''));
}

function decodeAttr(value) {
    return value ? decodeURIComponent(value) : '';
}

function setEditLoading(tab, loading) {
    const loadingEl = document.getElementById('etable-loading-' + tab);
    const emptyEl   = document.getElementById('etable-empty-' + tab);
    const tableEl   = document.getElementById('etable-' + tab);
    const pagEl     = document.getElementById('etable-pagination-' + tab);
    if (loadingEl) loadingEl.style.display = loading ? '' : 'none';
    if (emptyEl)   emptyEl.style.display   = 'none';
    if (tableEl)   tableEl.style.display   = loading ? 'none' : '';
    if (pagEl)     pagEl.style.display     = loading ? 'none' : '';
}

async function fetchEditTable(tab, pageOverride) {
    const state = editTableState[tab];
    if (!state) return;
    const page = pageOverride || state.page || 1;
    state.page = page;

    setEditLoading(tab, true);

    const params = new URLSearchParams({
        tab,
        page: String(page),
        per_page: String(state.perPage),
    });
    if (state.q) params.set('q', state.q);

    try {
        const res = await fetch('api/get_species_edit_table.php?' + params.toString());
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load data');

        renderEditTable(tab, data.rows || [], data);
        state.loaded = true;
    } catch {
        const emptyEl = document.getElementById('etable-empty-' + tab);
        if (emptyEl) {
            emptyEl.textContent = 'Could not load species.';
            emptyEl.style.display = '';
        }
    } finally {
        setEditLoading(tab, false);
    }
}

function renderEditTable(tab, rows, meta) {
    const tableEl = document.getElementById('etable-' + tab);
    const emptyEl = document.getElementById('etable-empty-' + tab);
    const pagEl   = document.getElementById('etable-pagination-' + tab);
    if (!tableEl || !emptyEl || !pagEl) return;

    if (!rows.length) {
        tableEl.innerHTML = '';
        tableEl.style.display = 'none';
        pagEl.innerHTML = '';
        emptyEl.style.display = '';
        return;
    }

    emptyEl.style.display = 'none';

    const hasDetailFlags = tab === 'edit-details';
    const offset = ((meta.page || 1) - 1) * (meta.per_page || rows.length);

    const headerCells = hasDetailFlags
        ? '<th style="text-align:left;padding:10px 14px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border-color);">Missing</th>'
        : '<th style="text-align:left;padding:10px 14px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border-color);">Light Tolerance</th>'
          + '<th style="text-align:left;padding:10px 14px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border-color);">Migration Status</th>';

    const bodyRows = rows.map((r, idx) => {
        const commonName = escapeHtml(r.common_name || '');
        const lightTol   = (r.light_tolerance || '').toLowerCase();
        const migStatus  = (r.migration_status || '').toLowerCase();
        const noImage    = Number(r.no_image) === 1;
        const noDesc     = Number(r.no_desc) === 1;

        const missingCell = hasDetailFlags
            ? '<td style="padding:10px 14px;">'
                + (noImage ? '<span style="display:inline-block;font-size:.75rem;padding:2px 8px;border-radius:4px;background:var(--warning-color, #ca8a04);color:#fff;border:1px solid var(--warning-color, #ca8a04);margin-right:4px;">No Photo</span>' : '')
                + (noDesc  ? '<span style="display:inline-block;font-size:.75rem;padding:2px 8px;border-radius:4px;background:var(--secondary-color, #6366f1);color:#fff;border:1px solid var(--secondary-color, #6366f1);">No Description</span>' : '')
              + '</td>'
            : '<td style="padding:10px 14px;">'
                + (lightTol
                    ? '<span class="badge ' + (lightTol === 'tolerant' ? 'badge-success' : 'badge-danger') + '">' + escapeHtml(lightTol.charAt(0).toUpperCase() + lightTol.slice(1)) + '</span>'
                    : '<span style="color:var(--text-muted);font-size:.8rem;">Not set</span>')
              + '</td>'
              + '<td style="padding:10px 14px;">'
                + (migStatus
                    ? '<span class="badge ' + (migStatus === 'migratory' ? 'badge-danger' : 'badge-success') + '">' + escapeHtml(migStatus.charAt(0).toUpperCase() + migStatus.slice(1)) + '</span>'
                    : '<span style="color:var(--text-muted);font-size:.8rem;">Not set</span>')
              + '</td>';

        return '<tr class="etable-row" style="border-bottom:1px solid var(--border-color);transition:background .15s;"'
            + ' onmouseover="this.style.background=\'var(--bg-card-alt)\'" onmouseout="this.style.background=\'\'">'
            + '<td style="padding:10px 14px;color:var(--text-muted);font-size:.8rem;">' + (offset + idx + 1) + '</td>'
            + '<td style="padding:10px 14px;color:var(--text-primary);font-weight:500;">' + commonName + '</td>'
            + missingCell
            + '<td style="padding:10px 14px;text-align:right;white-space:nowrap;">'
                + '<button data-action="edit"'
                + ' data-id="' + Number(r.id) + '"'
                + ' data-common-name="' + encodeAttr(r.common_name) + '"'
                + ' data-light-tolerance="' + encodeAttr(r.light_tolerance) + '"'
                + ' data-migration-status="' + encodeAttr(r.migration_status) + '"'
                + ' data-image-path="' + encodeAttr(r.image_path) + '"'
                + ' data-description="' + encodeAttr(r.description) + '"'
                + ' style="font-size:.8rem;padding:5px 14px;border-radius:6px;margin-right:6px;cursor:pointer;transition:all .15s;background:var(--accent-blue,#2563eb);color:#fff;border:1px solid var(--accent-blue,#2563eb);"'
                + ' onmouseover="this.style.opacity=\'.85\'" onmouseout="this.style.opacity=\'1\'">Edit</button>'
                + '<button data-action="merge"'
                + ' data-id="' + Number(r.id) + '"'
                + ' data-common-name="' + encodeAttr(r.common_name) + '"'
                + ' style="font-size:.8rem;padding:5px 14px;border-radius:6px;margin-right:6px;cursor:pointer;transition:all .15s;background:rgba(99,102,241,0.08);color:#4f46e5;border:1px solid rgba(99,102,241,0.4);"'
                + ' onmouseover="this.style.background=\'rgba(99,102,241,0.16)\'" onmouseout="this.style.background=\'rgba(99,102,241,0.08)\'">Merge</button>'
                + '<button data-action="delete"'
                + ' data-id="' + Number(r.id) + '"'
                + ' data-common-name="' + encodeAttr(r.common_name) + '"'
                + ' style="font-size:.8rem;padding:5px 14px;border-radius:6px;cursor:pointer;transition:all .15s;background:rgba(220,38,38,0.07);color:#dc2626;border:1px solid rgba(220,38,38,0.35);"'
                + ' onmouseover="this.style.background=\'rgba(220,38,38,0.14)\'" onmouseout="this.style.background=\'rgba(220,38,38,0.07)\'">Delete</button>'
            + '</td>'
            + '</tr>';
    }).join('');

    tableEl.innerHTML = '<div style="overflow-x:auto;border:1px solid var(--border-color);border-radius:8px;">'
        + '<table class="etable" style="width:100%;border-collapse:collapse;font-size:.9rem;">'
        + '<thead><tr style="background:var(--bg-card-alt);">'
        + '<th style="text-align:left;padding:10px 14px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border-color);">#</th>'
        + '<th style="text-align:left;padding:10px 14px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border-color);">Species Name</th>'
        + headerCells
        + '<th style="padding:10px 14px;border-bottom:1px solid var(--border-color);"></th>'
        + '</tr></thead>'
        + '<tbody>' + bodyRows + '</tbody>'
        + '</table>'
        + '</div>';

    tableEl.style.display = '';
    renderEditPagination(tab, meta.page || 1, meta.total_pages || 1);
}

function renderEditPagination(tab, page, totalPages) {
    const pagEl = document.getElementById('etable-pagination-' + tab);
    if (!pagEl) return;
    if (totalPages <= 1) {
        pagEl.innerHTML = '';
        return;
    }

    const mkBtn = (label, p, active) => {
        const cls = active ? 'btn-primary' : 'btn-secondary';
        return '<button class="btn ' + cls + '" data-etab="' + tab + '" data-page="' + p + '">' + label + '</button>';
    };

    const start = Math.max(1, page - 3);
    const end   = Math.min(totalPages, page + 3);
    let html = '';
    if (page > 1) html += mkBtn('‹ Prev', page - 1, false);
    if (start > 1) {
        html += mkBtn('1', 1, false);
        if (start > 2) html += '<span style="align-self:center;">…</span>';
    }
    for (let p = start; p <= end; p += 1) {
        html += mkBtn(String(p), p, p === page);
    }
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<span style="align-self:center;">…</span>';
        html += mkBtn(String(totalPages), totalPages, false);
    }
    if (page < totalPages) html += mkBtn('Next ›', page + 1, false);

    pagEl.innerHTML = html;
}

function ensureEditTabLoaded(tab) {
    const state = editTableState[tab];
    if (!state) return;
    if (!state.loaded) fetchEditTable(tab, 1);
}

const editSearchTimers = {};
document.querySelectorAll('.etable-search').forEach(input => {
    input.addEventListener('input', () => {
        const tab = input.dataset.etab;
        if (!tab || !editTableState[tab]) return;
        clearTimeout(editSearchTimers[tab]);
        editSearchTimers[tab] = setTimeout(() => {
            editTableState[tab].q = input.value.trim();
            editTableState[tab].page = 1;
            fetchEditTable(tab, 1);
        }, 250);
    });
});

document.addEventListener('click', (e) => {
    const pagBtn = e.target.closest('button[data-etab][data-page]');
    if (pagBtn) {
        const tab = pagBtn.dataset.etab;
        const page = parseInt(pagBtn.dataset.page, 10) || 1;
        fetchEditTable(tab, page);
        return;
    }
    const actionBtn = e.target.closest('button[data-action]');
    if (!actionBtn) return;

    const row = {
        id: parseInt(actionBtn.dataset.id, 10),
        common_name: decodeAttr(actionBtn.dataset.commonName),
        light_tolerance: decodeAttr(actionBtn.dataset.lightTolerance),
        migration_status: decodeAttr(actionBtn.dataset.migrationStatus),
        image_path: decodeAttr(actionBtn.dataset.imagePath),
        description: decodeAttr(actionBtn.dataset.description),
    };

    if (actionBtn.dataset.action === 'edit') {
        openEditModal(row);
    } else if (actionBtn.dataset.action === 'merge') {
        openMergeModal(row.id, row.common_name);
    } else if (actionBtn.dataset.action === 'delete') {
        confirmDelete(row.id, row.common_name, actionBtn);
    }
});

document.querySelectorAll('.main-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.dataset.tab === 'edit') {
            ensureEditTabLoaded('no-category');
        }
    });
});
document.querySelectorAll('.edit-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        ensureEditTabLoaded(btn.dataset.etab);
    });
});
const activeMainTab = document.querySelector('.main-tab-btn.active');
if (activeMainTab && activeMainTab.dataset.tab === 'edit') {
    ensureEditTabLoaded('no-category');
}

// ── Merge modal ───────────────────────────────────────────────────────────────
let mergeSourceId   = null;
let mergeTargetId   = null;
let mergeAllSpecies = [];   // full masterlist for the search list

async function openMergeModal(sourceId, sourceName) {
    mergeSourceId = sourceId;
    mergeTargetId = null;

    document.getElementById('mergeModalTitle').textContent  = sourceName;
    document.getElementById('mergeSourceName').textContent  = sourceName;
    document.getElementById('mergeSearchInput').value       = '';
    document.getElementById('mergeSelectedDisplay').style.display = 'none';
    document.getElementById('mergeStatusMsg').style.display = 'none';
    document.getElementById('mergeConfirmBtn').disabled     = true;
    document.getElementById('mergeConfirmBtn').textContent  = 'Confirm Merge';

    // Fetch full species list for the search (exclude the source itself)
    try {
        const res  = await fetch('api/get_species_list.php');
        const data = await res.json();
        mergeAllSpecies = (data.species || []).filter(s => parseInt(s.id) !== sourceId);
    } catch {
        mergeAllSpecies = [];
    }

    renderMergeList(mergeAllSpecies);
    document.getElementById('mergeModal').style.display = 'block';
}

function closeMergeModal() {
    document.getElementById('mergeModal').style.display = 'none';
}
document.getElementById('mergeModal').addEventListener('click', function(e) {
    if (e.target === this) closeMergeModal();
});

function filterMergeList() {
    const q = document.getElementById('mergeSearchInput').value.toLowerCase();
    renderMergeList(q
        ? mergeAllSpecies.filter(s => s.name.toLowerCase().includes(q))
        : mergeAllSpecies
    );
}

function renderMergeList(list) {
    const container = document.getElementById('mergeSpeciesList');
    if (!list.length) {
        container.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:.875rem;">No species found.</div>';
        return;
    }
    container.innerHTML = list.map(s => `
        <div onclick="selectMergeTarget(\${s.id}, '\${s.name.replace(/'/g, "\\'")}')"
             style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);font-size:.9rem;color:var(--text-primary);transition:background .15s;"
             onmouseover="this.style.background='var(--bg-card-alt)'"
             onmouseout="this.style.background=''">
            \${s.name}
        </div>`
    ).join('');
}

function selectMergeTarget(id, name) {
    mergeTargetId = id;
    document.getElementById('mergeTargetName').textContent         = name;
    document.getElementById('mergeSelectedDisplay').style.display  = '';
    document.getElementById('mergeConfirmBtn').disabled            = false;
    document.getElementById('mergeStatusMsg').style.display        = 'none';
}

async function executeMerge() {
    if (!mergeSourceId || !mergeTargetId) return;
    const btn = document.getElementById('mergeConfirmBtn');
    const msg = document.getElementById('mergeStatusMsg');
    btn.disabled    = true;
    btn.textContent = 'Merging…';
    msg.style.display = 'none';

    const body = new FormData();
    body.append('source_id', mergeSourceId);
    body.append('target_id', mergeTargetId);

    try {
        const res  = await fetch('api/merge_species.php', { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
            closeMergeModal();
            showToast('Species merged successfully');
            // Refresh the active edit sub-tab
            const activePanel = Array.from(document.querySelectorAll('.edit-tab-panel'))
                .find(p => p.style.display !== 'none');
            const tab = activePanel ? activePanel.dataset.etab : null;
            if (tab && editTableState[tab]) {
                editTableState[tab].loaded = false;
                fetchEditTable(tab, editTableState[tab].page);
            }
        } else {
            msg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px 14px;border-radius:7px;';
            msg.textContent = data.error || 'Merge failed.';
            btn.disabled    = false;
            btn.textContent = 'Confirm Merge';
        }
    } catch {
        msg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px 14px;border-radius:7px;';
        msg.textContent = 'Network error.';
        btn.disabled    = false;
        btn.textContent = 'Confirm Merge';
    }
}

// ── Edit modal ────────────────────────────────────────────────────────────────
function openEditModal(row) {
    document.getElementById('editSpeciesId').value        = row.id;
    document.getElementById('editSpeciesName').value      = row.common_name;
    document.getElementById('editModalTitle').textContent = row.common_name;
    document.getElementById('editTolerance').value       = row.light_tolerance  || '';
    document.getElementById('editMigration').value       = row.migration_status || '';
    document.getElementById('editDescription').value     = row.description      || '';

    // Image preview
    const preview     = document.getElementById('imagePreview');
    const placeholder = document.getElementById('imageDropPlaceholder');
    const note        = document.getElementById('currentImageNote');
    const fileInput   = document.getElementById('editImageFile');
    fileInput.value   = '';

    document.getElementById('removeImageFlag').value = '0';
    if (row.image_path) {
        preview.src                = row.image_path;
        preview.style.display      = 'block';
        placeholder.style.display  = 'none';
        note.style.display         = 'flex';
    } else {
        preview.style.display      = 'none';
        placeholder.style.display  = '';
        note.style.display         = 'none';
    }

    const msg = document.getElementById('editSaveMsg');
    msg.style.display = 'none';
    document.getElementById('editSaveBtn').disabled = false;
    document.getElementById('editSaveBtn').textContent = 'Save Changes';
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

function removeCurrentPhoto() {
    document.getElementById('removeImageFlag').value   = '1';
    document.getElementById('imagePreview').style.display     = 'none';
    document.getElementById('imageDropPlaceholder').style.display = '';
    document.getElementById('currentImageNote').style.display  = 'none';
    document.getElementById('editImageFile').value             = '';
}

function previewImage(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const preview = document.getElementById('imagePreview');
        preview.src   = e.target.result;
        preview.style.display = 'block';
        document.getElementById('imageDropPlaceholder').style.display = 'none';
        document.getElementById('currentImageNote').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

function handleImageDrop(e) {
    e.preventDefault();
    const zone = document.getElementById('imageDropZone');
    zone.style.borderColor = 'var(--border-color)';
    zone.style.background  = '';
    const file = e.dataTransfer.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    const dt  = new DataTransfer();
    dt.items.add(file);
    document.getElementById('editImageFile').files = dt.files;
    previewImage(document.getElementById('editImageFile'));
}

// ── Delete modal ──────────────────────────────────────────────────────────────
function confirmDelete(id, name, btn) {
    document.getElementById('deleteModalMsg').textContent =
        'Are you sure you want to delete "' + name + '" from the database?';
    document.getElementById('deleteStatusMsg').style.display = 'none';
    const confirmBtn = document.getElementById('deleteConfirmBtn');
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Yes, Delete';
    confirmBtn.onclick = () => executeDelete(id, name, btn);
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

async function executeDelete(id, name, rowBtn) {
    const confirmBtn = document.getElementById('deleteConfirmBtn');
    const statusMsg  = document.getElementById('deleteStatusMsg');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting…';

    const body = new FormData();
    body.append('species_id', id);

    try {
        const res  = await fetch('api/delete_species.php', { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
            // Remove the table row from the DOM
            const row = rowBtn.closest('tr');
            if (row) row.remove();
            closeDeleteModal();
        } else {
            statusMsg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px;border-radius:7px;';
            statusMsg.textContent = data.error || 'Delete failed.';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Yes, Delete';
        }
    } catch {
        statusMsg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px;border-radius:7px;';
        statusMsg.textContent = 'Network error.';
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Yes, Delete';
    }
}

function handleImgError(img) {
    img.onerror = null;
    const wrapper = img.closest('.species-image');
    if (!wrapper) return;
    wrapper.classList.remove('has-photo', 'img-loaded');
    wrapper.classList.add('img-error');
    img.style.display = 'none';
    if (!wrapper.querySelector('.img-fallback')) {
        const fb = document.createElement('div');
        fb.className = 'img-fallback';
        fb.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:4px;';
        fb.innerHTML = '<div style="font-size:3rem;">🦜</div>'
                     + '<small style="color:var(--text-muted);">Photo not available</small>';
        wrapper.appendChild(fb);
    }
}

function showToast(message, type) {
    const existing = document.getElementById('species-toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.id = 'species-toast';
    const color = (type === 'error') ? 'var(--danger-color,#dc2626)' : 'var(--accent-green,#16a34a)';
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;'
        + 'padding:12px 20px;border-radius:8px;background:var(--bg-card);'
        + 'border:1px solid ' + color + ';color:var(--text-primary);'
        + 'font-size:.9rem;font-weight:500;box-shadow:var(--shadow-lg);'
        + 'transition:opacity .4s ease;opacity:1;max-width:320px;pointer-events:none;';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 2800);
}

async function saveSpecies(e) {
    e.preventDefault();
    const msg = document.getElementById('editSaveMsg');
    const btn = document.getElementById('editSaveBtn');
    msg.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const body = new FormData(document.getElementById('editForm'));

    try {
        const res  = await fetch('api/update_species.php', { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
            closeEditModal();
            showToast('Changes saved');
            // Refresh only the visible edit sub-tab in place — no page reload
            const activePanel = Array.from(document.querySelectorAll('.edit-tab-panel'))
                .find(p => p.style.display !== 'none');
            const activeTab = activePanel ? activePanel.dataset.etab : null;
            if (activeTab && editTableState[activeTab]) {
                editTableState[activeTab].loaded = false;
                fetchEditTable(activeTab, editTableState[activeTab].page);
            }
        } else {
            msg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px 14px;border-radius:7px;';
            msg.textContent = data.error || 'Save failed.';
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        }
    } catch {
        msg.style.cssText = 'display:block;background:var(--bg-card-alt);border:1px solid var(--danger-color,#dc2626);color:var(--text-primary);padding:10px 14px;border-radius:7px;';
        msg.textContent = 'Network error — check your connection.';
        btn.disabled = false;
        btn.textContent = 'Save Changes';
    }
}
</script>
EOD;

require_once 'includes/footer.php';
?>