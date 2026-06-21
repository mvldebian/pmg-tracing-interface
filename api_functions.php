<?php
session_start();

// Configurações do Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'pmg_tracking');
define('DB_USER', 'mysqluser');
define('DB_PASS', 'userpass');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: ".$e->getMessage());
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function checkAdmin() {
    checkAuth();
    if ($_SESSION['role'] !== 'admin') {
        die("Acesso negado.");
    }
}

function loadUserDomains($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT domain FROM user_domains WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Funções de métricas do servidor (sem API do PMG)
function getCpuUsage() {
    $load = sys_getloadavg();
    return [
        '1min'  => round($load[0], 2),
        '5min'  => round($load[1], 2),
        '15min' => round($load[2], 2)
    ];
}

function getMemoryUsage() {
    $data = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $data, $total);
    preg_match('/MemAvailable:\s+(\d+)/', $data, $available);
    $totalMem = $total[1] ?? 0;
    $availableMem = $available[1] ?? 0;
    $used = $totalMem - $availableMem;
    $percent = ($totalMem > 0) ? round(($used / $totalMem) * 100, 1) : 0;
    return [
        'total'   => round($totalMem / 1024 / 1024, 2),
        'used'    => round($used / 1024 / 1024, 2),
        'percent' => $percent
    ];
}

function getDiskUsage() {
    $path = __DIR__;
    $total = disk_total_space($path);
    $free  = disk_free_space($path);
    $used  = $total - $free;
    $percent = ($total > 0) ? round(($used / $total) * 100, 1) : 0;
    return [
        'total'   => round($total / 1024 / 1024 / 1024, 2),
        'used'    => round($used / 1024 / 1024 / 1024, 2),
        'percent' => $percent
    ];
}
?>
