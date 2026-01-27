<?php
// Literal na copy pasted lang yung itsura niya from my figma design
session_start();

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Avilight | Dashboard</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #fff;
        }
        header {
            background: #020617;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
        }
        .container {
            display: flex;
            padding: 20px;
        }
        .map {
            flex: 3;
            height: 520px;
            background: #1e293b;
            border-radius: 12px;
            margin-right: 20px;
        }
        .side {
            flex: 1;
        }
        .card {
            background: #020617;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        .stat {
            font-size: 32px;
            margin-top: 10px;
        }
        .green { color: #22c55e; }
        .red { color: #ef4444; }
        .activity {
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<header>
    <h3>AVILIGHT</h3>
    <span><?= htmlspecialchars($_SESSION['user_email']) ?></span>
</header>

<div class="container">
    <div class="map">
        <!-- Map placeholder -->
    </div>

    <div class="side">
        <div class="card">
            <h4>At Risk Zones</h4>
            <div class="stat">18 <span class="red">-5%</span></div>
        </div>

        <div class="card">
            <h4>Light Intensity</h4>
            <div class="stat">78% <span class="green">+8%</span></div>
        </div>

        <div class="card">
            <h4>Recent Activity</h4>
            <div class="activity red">High light intensity detected in Zone A3</div>
            <div class="activity green">Bird richness increased by 12%</div>
            <div class="activity">Monitoring update scheduled</div>
        </div>
    </div>
</div>

</body>
</html>
