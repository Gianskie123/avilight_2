<?php
require_once __DIR__ . '/auth.php';
require_login(); // Require login for all dashboard pages
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
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <span class="nav-title">AVILIGHT</span>
            </div>
            <ul class="nav-menu">
                <li><a href="home.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a></li>
                <li><a href="geospatial.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'geospatial.php' ? 'active' : ''; ?>">Analytics</a></li>
                <li><a href="reports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">Reports</a></li>
                <li><a href="admin.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">Settings</a></li>
            </ul>
            <div class="nav-user">
                <span class="user-email"><?php echo htmlspecialchars(get_logged_user()); ?></span>
                <a href="?logout=1" class="logout-btn">Logout</a>
                <!-- Dark / light mode toggle -->
                <button class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode" title="Switch to dark mode">
                    <!-- Sun icon (shown in dark mode to switch to light) -->
                    <svg id="iconSun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="display:none"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                    <!-- Moon icon (shown in light mode to switch to dark) -->
                    <svg id="iconMoon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                    <span id="themeLabel">Dark</span>
                </button>
                <span class="nav-icon" aria-label="Search"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg></span>
                <span class="nav-icon" aria-label="Notifications"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg></span>
                <span class="nav-icon" aria-label="User profile"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg></span>
            </div>
        </div>
    </nav>

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
            // Enable transitions only for this toggle action
            html.classList.add('theme-transitioning');
            localStorage.setItem('avilight-theme', next);
            applyTheme(next);
            setTimeout(function() { html.classList.remove('theme-transitioning'); }, 300);
        });
    })();
    </script>
    
    <main class="main-content">
