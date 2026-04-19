<?php
require_once __DIR__ . '/auth.php';

// Handle logout before any output
if (isset($_GET['logout'])) {
    logout(); // destroys session and redirects to login.php
}

require_login(); // Require login for all dashboard pages
?>
<?php
$current_page = basename($_SERVER['PHP_SELF']);
$avp_pages = ['home.php', 'dashboard.php', 'geospatial.php', 'species.php', 'reports.php', 'admin.php'];
$nav_links = [
    ['label' => 'Home', 'href' => 'home.php'],
    ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    ['label' => 'Analytics', 'href' => 'geospatial.php'],
    ['label' => 'Species', 'href' => 'species.php'],
    ['label' => 'Reports', 'href' => 'reports.php'],
    ['label' => 'Settings', 'href' => 'admin.php'],
];

if (is_file(__DIR__ . '/fetch_bmb_announcements.php')) {
    require_once __DIR__ . '/fetch_bmb_announcements.php';
}

$header_notifications = [];
if (function_exists('fetch_bmb_announcements')) {
    $fetched_notifications = fetch_bmb_announcements(4, 3600, false);
    if (is_array($fetched_notifications)) {
        $header_notifications = $fetched_notifications;
    }
}

function header_safe_notification_link($url): string {
    $url = is_string($url) ? trim($url) : '';
    if ($url === '') {
        return 'home.php';
    }
    $parts = @parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'home.php';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== '' && !in_array($host, ['faps.bmb.gov.ph', 'www.faps.bmb.gov.ph'], true)) {
        return 'home.php';
    }
    return $url;
}

$body_classes = [];
if (in_array($current_page, $avp_pages, true)) {
    $body_classes[] = 'avp-animated-page';
    $body_classes[] = 'page-' . str_replace('.php', '', $current_page);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Apply saved theme before first paint to prevent flash -->
    <script>
        (function(){
            if(localStorage.getItem('avilight-theme')==='dark'){
                document.documentElement.setAttribute('data-theme','dark');
            }
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>AVILIGHT Dashboard</title>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="assets/css/main.css">
    
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="<?php echo htmlspecialchars(implode(' ', $body_classes)); ?>">
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <span class="nav-title">AVILIGHT</span>
            </div>
            <ul class="nav-menu">
                <?php foreach ($nav_links as $item): ?>
                    <?php $is_active_nav = $current_page === $item['href']; ?>
                    <li><a href="<?php echo htmlspecialchars($item['href']); ?>" class="<?php echo $is_active_nav ? 'active' : ''; ?>"<?php echo $is_active_nav ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($item['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="nav-user">
                <!-- Dark / light mode toggle -->
                <button class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode" title="Switch to dark mode">
                    <!-- Sun icon (shown in dark mode to switch to light) -->
                    <svg id="iconSun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="display:none"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                    <!-- Moon icon (shown in light mode to switch to dark) -->
                    <svg id="iconMoon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                    <span id="themeLabel">Dark</span>
                </button>
                <button class="nav-icon nav-search-btn" id="globalSearchToggle" type="button" aria-label="Global search (press slash)" title="Global search ( / )"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg></button>

                <div class="nav-menu-item">
                    <button class="nav-icon" id="notificationsToggle" type="button" aria-label="Notifications" title="Notifications">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                    </button>
                    <div class="nav-popover nav-popover-notifications" id="notificationsMenu">
                        <div class="nav-popover-title">Notifications</div>
                        <?php foreach ($header_notifications as $notification): ?>
                            <a class="nav-notification-row" href="<?php echo htmlspecialchars(header_safe_notification_link($notification['link'] ?? '')); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo htmlspecialchars(($notification['title'] ?? 'Notification') . ' (opens in new tab)'); ?>">
                                <span class="nav-notification-title"><?php echo htmlspecialchars($notification['title'] ?? 'System update'); ?></span>
                                <?php if (!empty($notification['date'])): ?><span class="nav-notification-date"><?php echo htmlspecialchars($notification['date']); ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="nav-menu-item">
                    <button class="nav-icon" id="accountToggle" type="button" aria-label="Account menu" title="Account">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                    </button>
                    <div class="nav-popover nav-popover-account" id="accountMenu">
                        <div class="nav-account-email"><?php echo htmlspecialchars(get_logged_user()); ?></div>
                        <a href="admin.php" class="nav-account-link">Account settings</a>
                        <a href="?logout=1" class="nav-account-link nav-account-logout">Log out</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="global-search-overlay" id="globalSearchOverlay" hidden>
        <div class="global-search-dialog" role="dialog" aria-modal="true" aria-label="Global search">
            <input type="search" id="globalSearchInput" class="global-search-input" placeholder="Search pages..." autocomplete="off" aria-label="Search pages">
            <div class="global-search-results" id="globalSearchResults">
                <?php foreach ($nav_links as $item): ?>
                    <a class="global-search-result" href="<?php echo htmlspecialchars($item['href']); ?>" data-search="<?php echo htmlspecialchars(strtolower($item['label'] . ' ' . str_replace('.php', '', $item['href']))); ?>"><?php echo htmlspecialchars($item['label']); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sr-only" id="globalSearchLive" aria-live="polite"></div>
        </div>
    </div>

    <script>
    // Theme toggle logic
    (function() {
        var btn   = document.getElementById('themeToggle');
        var label = document.getElementById('themeLabel');
        var sun   = document.getElementById('iconSun');
        var moon  = document.getElementById('iconMoon');
        var html  = document.documentElement;

        function applyTheme(theme) {
            if (theme === 'dark') {
                html.setAttribute('data-theme', 'dark');
                sun.style.display  = '';
                moon.style.display = 'none';
                label.textContent  = 'Light';
                btn.setAttribute('aria-label', 'Switch to light mode');
                btn.setAttribute('title', 'Switch to light mode');
            } else {
                html.removeAttribute('data-theme');
                sun.style.display  = 'none';
                moon.style.display = '';
                label.textContent  = 'Dark';
                btn.setAttribute('aria-label', 'Switch to dark mode');
                btn.setAttribute('title', 'Switch to dark mode');
            }
        }

        // Initialize from storage (already applied by inline head script; just sync UI)
        applyTheme(localStorage.getItem('avilight-theme') || 'light');

        btn.addEventListener('click', function() {
            var current = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var next    = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem('avilight-theme', next);
            applyTheme(next);
        });

        var searchToggle = document.getElementById('globalSearchToggle');
        var searchOverlay = document.getElementById('globalSearchOverlay');
        var searchInput = document.getElementById('globalSearchInput');
        var searchResults = document.getElementById('globalSearchResults');
        var searchLive = document.getElementById('globalSearchLive');
        var popovers = [
            {toggle: document.getElementById('notificationsToggle'), menu: document.getElementById('notificationsMenu')},
            {toggle: document.getElementById('accountToggle'), menu: document.getElementById('accountMenu')}
        ];

        function closePopovers(exceptMenu) {
            popovers.forEach(function(item) {
                if (item.menu && item.menu !== exceptMenu) {
                    item.menu.classList.remove('is-open');
                }
            });
        }

        function openSearch() {
            searchOverlay.hidden = false;
            closePopovers(null);
            searchInput.value = '';
            filterSearchResults('');
            setTimeout(function() { searchInput.focus(); }, 0);
        }

        function closeSearch() {
            searchOverlay.hidden = true;
        }

        function filterSearchResults(query) {
            var normalized = query.toLowerCase().trim();
            var visibleCount = 0;
            searchResults.querySelectorAll('.global-search-result').forEach(function(link) {
                var haystack = link.getAttribute('data-search') || '';
                link.hidden = normalized !== '' && !haystack.includes(normalized);
                if (!link.hidden) {
                    visibleCount += 1;
                }
            });
            if (searchLive) {
                searchLive.textContent = visibleCount + ' result' + (visibleCount === 1 ? '' : 's') + ' found';
            }
        }

        if (searchToggle) {
            searchToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (searchOverlay.hidden) openSearch();
                else closeSearch();
            });
        }

        searchOverlay.addEventListener('click', function(e) {
            if (e.target === searchOverlay) {
                closeSearch();
            }
        });

        searchInput.addEventListener('input', function() {
            filterSearchResults(searchInput.value || '');
        });

        popovers.forEach(function(item) {
            if (!item.toggle || !item.menu) return;
            item.toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var willOpen = !item.menu.classList.contains('is-open');
                closePopovers(willOpen ? item.menu : null);
                if (willOpen) item.menu.classList.add('is-open');
                else item.menu.classList.remove('is-open');
                closeSearch();
            });
            item.menu.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        document.addEventListener('click', function() {
            closePopovers(null);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSearch();
                closePopovers(null);
                return;
            }
            var activeElement = document.activeElement;
            var tagName = activeElement ? (activeElement.tagName || '') : '';
            var isInputFocused = /input|textarea|select/i.test(tagName) || !!(activeElement && activeElement.isContentEditable);
            if (e.key === '/' && !isInputFocused) {
                e.preventDefault();
                openSearch();
            }
        });
    })();
    </script>
    
    <main class="main-content">
