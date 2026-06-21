<?php
if (!isset($pageTitle)) $pageTitle = 'PMG Tracing - Header';
$cpu = getCpuUsage();
$mem = getMemoryUsage();
$disk = getDiskUsage();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html { 
        zoom: 80%; 
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9edf2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .top-header {
            background: linear-gradient(90deg, #1a2a3a 0%, #2c3e50 100%);
            color: white;
            padding: 12px 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .logo-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .logo-area img {
            height: 55px;
            width: auto;
            max-width: 245px;
        }
        .site-title {
            font-size: 1.2rem;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .server-metrics {
            background: rgba(0,0,0,0.3);
            border-radius: 30px;
            padding: 8px 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 0.8rem;
            font-family: monospace;
            backdrop-filter: blur(4px);
        }
        .server-metrics i {
            margin-right: 5px;
            color: #ffd966;
        }
        .user-area {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .user-area a, .dashboard-btn {
            color: white;
            text-decoration: none;
            background: #3a5a7a;
            padding: 6px 14px;
            border-radius: 30px;
            transition: 0.2s;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .user-area a:hover, .dashboard-btn:hover {
            background: #4c6e8f;
            transform: translateY(-2px);
        }
        .dashboard-btn {
            background: #27ae60;
            font-weight: bold;
        }
        .dashboard-btn:hover {
            background: #2ecc71;
        }
        .content {
            flex: 1;
            padding: 25px;
            width: 100%;
        }
        .footer {
            background: #1a2a3a;
            color: #bbb;
            text-align: center;
            padding: 20px;
            font-size: 0.85rem;
            margin-top: 30px;
        }
        /* Estilos reutilizáveis */
        .metrics-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-top: 4px solid #7f8c8d;
            transition: 0.2s;
        }
        .metric-card h3 {
            margin: 0 0 10px 0;
            font-size: 0.9rem;
            color: #555;
        }
        .metric-card .count {
            font-size: 1.8rem;
            font-weight: bold;
        }
        .filters {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .filters form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .filters label {
            font-size: 0.75rem;
            font-weight: bold;
            color: #555;
        }
        .filters input, .filters select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
        .filters button, .filters .clear-btn {
            padding: 6px 14px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .filters .clear-btn {
            background: #95a5a6;
            text-decoration: none;
            display: inline-block;
            line-height: normal;
        }
        .results {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #34495e;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .pagination {
            margin-top: 25px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: white;
            text-decoration: none;
            color: #2c3e50;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .pagination a.active {
            background: #2c3e50;
            color: white;
            border-color: #2c3e50;
        }
        .info {
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #555;
        }
        @media (max-width: 768px) {
            .content { padding: 15px; }
            .filters form { flex-direction: column; align-items: stretch; }
            .metrics-container { grid-template-columns: repeat(2, 1fr); }
            .top-header { flex-direction: column; text-align: center; }
            .server-metrics { justify-content: center; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="top-header">
        <div class="logo-area">
            <img src="img/logo.png">
            <div class="site-title">PMG Tracing 1.0</div>
        </div>
        <div class="server-metrics">
            <div><i class="fas fa-microchip"></i> CPU: <?= $cpu['1min'] ?> / <?= $cpu['5min'] ?> / <?= $cpu['15min'] ?></div>
            <div><i class="fas fa-memory"></i> RAM: <?= $mem['percent'] ?>% (<?= $mem['used'] ?>GB / <?= $mem['total'] ?>GB)</div>
            <div><i class="fas fa-hdd"></i> DISCO: <?= $disk['percent'] ?>% (<?= $disk['used'] ?>GB / <?= $disk['total'] ?>GB)</div>
        </div>
        <div class="user-area">
    <?php if (isset($_SESSION['username'])): ?>
        <a href="index.php" class="dashboard-btn"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="relatorios.php"><i class="fas fa-chart-line"></i> Relatórios</a>
        <a href="perfil.php"><i class="fas fa-user-edit"></i> Perfil</a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="users.php"><i class="fas fa-users"></i> Usuários</a>
            <a href="config_turnstile.php"><i class="fas fa-shield-alt"></i> Turnstile</a>
        <?php endif; ?>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Sair</a>
    <?php else: ?>
        <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
    <?php endif; ?>
</div>
    </div>
    <div class="content">
