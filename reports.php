<?php
require 'config.php';
checkAuth();

$statusMap = [
    'A' => 'accepted', 'R' => 'rejected', 'Q' => 'quarantined',
    'D' => 'delivered', 'B' => 'blocked', 'G' => 'greylisted',
    'N' => 'not_delivered', 'P' => 'pending', 'S' => 'spam',
    '1' => 'accepted', '2' => 'quarantined', '3' => 'rejected',
    '4' => 'delivered', '5' => 'blocked'
];

// Determinar período com base no filtro
$period = $_GET['period'] ?? 'custom';
$startDate = null;
$endDate = null;

switch ($period) {
    case '7days':
        $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $endDate = date('Y-m-d 23:59:59');
        break;
    case '30days':
        $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $endDate = date('Y-m-d 23:59:59');
        break;
    case 'week':
        // Semana atual (segunda a domingo)
        $startDate = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $endDate = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        break;
    case 'month':
        $startDate = date('Y-m-01 00:00:00');
        $endDate = date('Y-m-t 23:59:59');
        break;
    default: // custom
        $startDate = !empty($_GET['start_date']) ? $_GET['start_date'] . ' 00:00:00' : date('Y-m-d 00:00:00', strtotime('-7 days'));
        $endDate = !empty($_GET['end_date']) ? $_GET['end_date'] . ' 23:59:59' : date('Y-m-d 23:59:59');
        break;
}

// Validar datas (evitar SQL injection)
$startDate = date('Y-m-d H:i:s', strtotime($startDate));
$endDate = date('Y-m-d H:i:s', strtotime($endDate));

// Construir WHERE
$where = [];
$paramsWhere = [];

if ($_SESSION['role'] !== 'admin') {
    $where[] = "domain = ?";
    $paramsWhere[] = $_SESSION['domain'];
} elseif (!empty($_GET['domain'])) {
    $where[] = "domain = ?";
    $paramsWhere[] = $_GET['domain'];
}

$where[] = "timestamp BETWEEN ? AND ?";
$paramsWhere[] = $startDate;
$paramsWhere[] = $endDate;

if (!empty($_GET['status'])) {
    $filterStatus = $_GET['status'];
    if (isset($statusMap[$filterStatus])) $filterStatus = $statusMap[$filterStatus];
    $where[] = "status = ?";
    $paramsWhere[] = $filterStatus;
}

if (!empty($_GET['search'])) {
    $where[] = "(sender LIKE ? OR recipient LIKE ?)";
    $paramsWhere[] = "%{$_GET['search']}%";
    $paramsWhere[] = "%{$_GET['search']}%";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Métricas gerais do período
$sqlMetrics = "SELECT status, COUNT(*) as total FROM email_tracking $whereClause GROUP BY status";
$stmtMetrics = $pdo->prepare($sqlMetrics);
$stmtMetrics->execute($paramsWhere);
$metrics = $stmtMetrics->fetchAll(PDO::FETCH_ASSOC);

$totalEmails = 0;
$statusCounts = [];
foreach ($metrics as $row) {
    $statusKey = $row['status'];
    if (isset($statusMap[$statusKey])) $statusKey = $statusMap[$statusKey];
    $statusCounts[$statusKey] = ($statusCounts[$statusKey] ?? 0) + (int)$row['total'];
    $totalEmails += (int)$row['total'];
}
$statusOrder = ['accepted', 'rejected', 'quarantined', 'delivered', 'blocked', 'greylisted', 'spam'];
foreach ($statusCounts as $st => $cnt) {
    if (!in_array($st, $statusOrder)) $statusOrder[] = $st;
}

// Estatísticas por dia (para gráfico simples - via tabela)
$sqlDaily = "SELECT DATE(timestamp) as dia, COUNT(*) as total, 
                    SUM(CASE WHEN status IN ('accepted','1','A') THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN status IN ('rejected','3','R') THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status IN ('quarantined','2','Q') THEN 1 ELSE 0 END) as quarantined
             FROM email_tracking $whereClause 
             GROUP BY DATE(timestamp) ORDER BY dia DESC LIMIT 30";
$stmtDaily = $pdo->prepare($sqlDaily);
$stmtDaily->execute($paramsWhere);
$dailyStats = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

// Domínios para filtro (admin)
$domains = [];
if ($_SESSION['role'] === 'admin') {
    $domStmt = $pdo->query("SELECT DISTINCT domain FROM email_tracking WHERE domain IS NOT NULL AND domain != '' ORDER BY domain");
    $domains = $domStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Paginação para a listagem (opcional)
$limit = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$sqlCount = "SELECT COUNT(*) FROM email_tracking $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($paramsWhere);
$totalRegs = $stmtCount->fetchColumn();
$totalPages = ceil($totalRegs / $limit);

$sql = "SELECT email_id, sender, recipient, domain, status, timestamp 
        FROM email_tracking $whereClause 
        ORDER BY timestamp DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($paramsWhere);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

function queryString($params = []) {
    $current = $_GET;
    $merged = array_merge($current, $params);
    if (isset($merged['page']) && $merged['page'] == 1) unset($merged['page']);
    return http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Avançados - PMG</title>
    <style>
        /* Mesmo estilo do index, adaptado */
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; margin: 0; padding: 0; }
        .container { width: 100%; padding: 20px; }
        .header { background: #2c3e50; color: white; padding: 15px 25px; border-radius: 8px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 1.5rem; }
        .user-info { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .user-info a { color: #ecf0f1; text-decoration: none; padding: 5px 12px; background: #34495e; border-radius: 4px; transition: 0.2s; }
        .user-info a:hover { background: #3b5998; }
        .metrics-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .metric-card { background: white; border-radius: 8px; padding: 15px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-top: 4px solid #7f8c8d; }
        .metric-card.total { border-top-color: #2c3e50; }
        .metric-card.accepted { border-top-color: #27ae60; }
        .metric-card.rejected { border-top-color: #e74c3c; }
        .metric-card.quarantined { border-top-color: #f39c12; }
        .metric-card h3 { margin: 0 0 10px 0; font-size: 0.9rem; color: #555; }
        .metric-card .count { font-size: 1.8rem; font-weight: bold; }
        .filters { background: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .filters form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 0.75rem; font-weight: bold; color: #555; }
        input, select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; }
        button, .clear-btn { padding: 6px 14px; background: #2c3e50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
        .clear-btn { background: #95a5a6; text-decoration: none; display: inline-block; line-height: normal; }
        .results { background: white; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #34495e; color: white; }
        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; }
        .status-accepted { background: #d5f5e3; color: #1e8449; }
        .status-rejected { background: #fadbd8; color: #c0392b; }
        .status-quarantined { background: #fdebd0; color: #e67e22; }
        .pagination { margin-top: 25px; display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
        .pagination a, .pagination span { display: inline-block; padding: 6px 12px; border: 1px solid #ddd; background: white; text-decoration: none; color: #2c3e50; border-radius: 4px; }
        .pagination a.active { background: #2c3e50; color: white; }
        @media (max-width: 768px) { .container { padding: 10px; } .filters form { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📊 Relatórios Avançados</h1>
        <div class="user-info">
            <span>👤 <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="index.php">🏠 Dashboard Hoje</a>
            <a href="users.php" <?= $_SESSION['role'] !== 'admin' ? 'style="display:none"' : '' ?>>👥 Gerenciar usuários</a>
            <a href="logout.php">🚪 Sair</a>
        </div>
    </div>

    <!-- Filtros de período -->
    <div class="filters">
        <form method="get" action="">
            <div class="filter-group">
                <label>Período rápido</label>
                <select name="period" onchange="this.form.submit()">
                    <option value="7days" <?= $period == '7days' ? 'selected' : '' ?>>Últimos 7 dias</option>
                    <option value="30days" <?= $period == '30days' ? 'selected' : '' ?>>Últimos 30 dias</option>
                    <option value="week" <?= $period == 'week' ? 'selected' : '' ?>>Esta semana</option>
                    <option value="month" <?= $period == 'month' ? 'selected' : '' ?>>Este mês</option>
                    <option value="custom" <?= $period == 'custom' ? 'selected' : '' ?>>Personalizado</option>
                </select>
            </div>
            <div class="filter-group" id="customDates" style="<?= $period == 'custom' ? 'display:flex' : 'display:none' ?>">
                <label>Data inicial</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars(substr($startDate,0,10)) ?>">
                <label>Data final</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars(substr($endDate,0,10)) ?>">
            </div>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="filter-group">
                <label>Domínio</label>
                <select name="domain">
                    <option value="">Todos</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= ($_GET['domain'] ?? '') == $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Todos</option>
                    <?php foreach (array_unique($statusMap) as $textStatus): ?>
                        <option value="<?= htmlspecialchars($textStatus) ?>" <?= ($_GET['status'] ?? '') == $textStatus ? 'selected' : '' ?>><?= ucfirst($textStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Busca</label>
                <input type="text" name="search" placeholder="remetente/destinatário" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <button type="submit">Filtrar</button>
                <a href="reports.php" class="clear-btn">Limpar</a>
            </div>
        </form>
    </div>

    <script>
        document.querySelector('select[name="period"]').addEventListener('change', function() {
            let customDiv = document.getElementById('customDates');
            if (this.value === 'custom') {
                customDiv.style.display = 'flex';
            } else {
                customDiv.style.display = 'none';
            }
        });
    </script>

    <!-- Cards de resumo -->
    <div class="metrics-container">
        <div class="metric-card total">
            <h3>📊 Total no período</h3>
            <div class="count"><?= number_format($totalEmails, 0, ',', '.') ?></div>
        </div>
        <?php foreach ($statusOrder as $st): 
            $count = $statusCounts[$st] ?? 0;
            if ($count === 0 && !in_array($st, ['accepted','rejected','quarantined'])) continue;
        ?>
        <div class="metric-card <?= htmlspecialchars($st) ?>">
            <h3><?= ucfirst($st) ?></h3>
            <div class="count"><?= number_format($count, 0, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Estatísticas diárias -->
    <div class="results">
        <h3>📈 Movimento diário (últimos 30 dias)</h3>
        <table>
            <thead><tr><th>Data</th><th>Total</th><th>Aceitos</th><th>Rejeitados</th><th>Quarentena</th></tr></thead>
            <tbody>
            <?php foreach ($dailyStats as $day): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($day['dia'])) ?></td>
                    <td><?= $day['total'] ?></td>
                    <td><?= $day['accepted'] ?></td>
                    <td><?= $day['rejected'] ?></td>
                    <td><?= $day['quarantined'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($dailyStats)): ?>
                <tr><td colspan="5">Nenhum dado no período.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Listagem de e-mails (com paginação) -->
    <div class="results">
        <h3>📧 E-mails no período</h3>
        <div class="info">Mostrando <?= count($emails) ?> de <?= number_format($totalRegs,0,',','.') ?> registros (página <?= $page ?> de <?= $totalPages ?>)</div>
        <?php if (count($emails) > 0): ?>
        <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>Data/Hora</th><th>Remetente</th><th>Destinatário</th><th>Domínio</th><th>Status</th><th>Ação</th></tr></thead>
                <tbody>
                <?php foreach ($emails as $email):
                    $statusRaw = $email['status'];
                    $statusDisplay = isset($statusMap[$statusRaw]) ? $statusMap[$statusRaw] : $statusRaw;
                    $statusDisplay = ucfirst($statusDisplay);
                ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($email['timestamp']))) ?></td>
                        <td><?= htmlspecialchars($email['sender']) ?></td>
                        <td><?= htmlspecialchars($email['recipient']) ?></td>
                        <td><?= htmlspecialchars($email['domain']) ?></td>
                        <td><span class="status-badge status-<?= htmlspecialchars($statusDisplay) ?>"><?= $statusDisplay ?></span></td>
                        <td><a href="detail.php?id=<?= urlencode($email['email_id']) ?>">Detalhes</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <?php
            $maxVisible = 7;
            $half = floor($maxVisible / 2);
            $startPage = max(1, $page - $half);
            $endPage = min($totalPages, $page + $half);
            if ($startPage == 1) $endPage = min($totalPages, $maxVisible);
            if ($endPage == $totalPages) $startPage = max(1, $totalPages - $maxVisible + 1);
            if ($totalPages > 1 && $startPage > 1): ?>
                <a href="?<?= queryString(['page' => 1]) ?>">« Primeira</a>
                <?php if ($startPage > 2): ?><span>...</span><?php endif;
            endif;
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?<?= queryString(['page' => $i]) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($totalPages > 1 && $endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
                <a href="?<?= queryString(['page' => $totalPages]) ?>">Última (<?= $totalPages ?>)</a>
            <?php endif;
            if ($page > 1): ?>
                <a href="?<?= queryString(['page' => $page-1]) ?>">‹ Anterior</a>
            <?php endif;
            if ($page < $totalPages): ?>
                <a href="?<?= queryString(['page' => $page+1]) ?>">Próxima ›</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <p>Nenhum e-mail encontrado no período.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
