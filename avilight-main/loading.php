<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$allowedTargets = [
    'home.php',
    'dashboard.php',
    'geospatial.php',
    'species.php',
    'reports.php',
    'admin.php',
    'scenario.php',
    'validation.php',
    'methodology.php'
];

$next = isset($_GET['next']) ? basename($_GET['next']) : 'home.php';
if (!in_array($next, $allowedTargets, true)) {
    $next = 'home.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVILIGHT | Loading</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #ffffff;
            overflow: hidden;
        }

        .loading-stage {
            position: relative;
            width: min(260px, 62vw);
            aspect-ratio: 1 / 1;
            display: grid;
            place-items: center;
            --fill-duration: 1.65s;
            opacity: 0;
            animation: stageShow 0s linear 0.45s forwards;
        }

        .logo-fill-loader {
            position: relative;
            width: 100%;
            height: 100%;
            transform: scale(0.78);
            opacity: 0;
            animation: iconPopIn 0.56s cubic-bezier(0.2, 0.9, 0.2, 1) 0.5s forwards;
        }

        .logo-base,
        .logo-color {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .logo-base {
            filter: grayscale(1) brightness(0.86);
            opacity: 0.95;
        }

        .logo-color-wrap {
            position: absolute;
            inset: 0;
            clip-path: inset(100% 0 0 0);
            animation: iconFillUp var(--fill-duration) cubic-bezier(0.22, 1, 0.36, 1) 0.85s forwards;
            overflow: hidden;
        }

        .logo-color {
            filter: saturate(1.08) contrast(1.03);
        }

        @keyframes stageShow {
            to { opacity: 1; }
        }

        @keyframes iconPopIn {
            from { transform: scale(0.78); opacity: 0; }
            60% { transform: scale(1.06); opacity: 1; }
            to { transform: scale(1); opacity: 1; }
        }

        @keyframes iconFillUp {
            from { clip-path: inset(100% 0 0 0); }
            to { clip-path: inset(0 0 0 0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .loading-stage,
            .logo-fill-loader,
            .logo-color-wrap {
                animation: none;
            }

            .loading-stage,
            .logo-fill-loader {
                transform: scale(1);
                opacity: 1;
            }

            .logo-color-wrap {
                clip-path: inset(0 0 0 0);
            }
        }
    </style>
</head>
<body>
    <div class="loading-stage" role="status" aria-live="polite" aria-label="Loading interface">
        <div class="logo-fill-loader" aria-hidden="true">
            <img src="AviLight_Logo.png" alt="" class="logo-base">
            <div class="logo-color-wrap">
                <img src="AviLight_Logo.png" alt="" class="logo-color">
            </div>
        </div>
    </div>

    <script>
        setTimeout(function () {
            window.location.href = <?php echo json_encode($next); ?>;
        }, 2500);
    </script>
</body>
</html>
