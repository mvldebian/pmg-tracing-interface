<?php
require 'config.php';
checkAuth();

$pageTitle = 'PMG Tracing - Dashboard';

function queryString($params = []) {
    $current = $_GET;
    $merged = array_merge($current, $params);
    if (isset($merged['page']) && $merged['page'] == 1) unset($merged['page']);
    if (isset($merged['domain_filter']) && $merged['domain_filter'] === 'todos') unset($merged['domain_filter']);
    if (isset($merged['date_start']) && empty($merged['date_start'])) unset($merged['date_start']);
    if (isset($merged['date_end']) && empty($merged['date_end'])) unset($merged['date_end']);
    if (isset($merged['date_type']) && $merged['date_type'] === 'hoje') unset($merged['date_start'], $merged['date_end']);
    return http_build_query($merged);
}

// Seleção do domínio
$selectedDomain = $_GET['domain_filter'] ?? null;
if ($_SESSION['role'] !== 'admin') {
    if ($selectedDomain && !in_array($selectedDomain, $_SESSION['domains']) && $selectedDomain !== 'todos')
        $selectedDomain = $_SESSION['domains'][0] ?? null;
    if (!$selectedDomain && !empty($_SESSION['domains']))
        $selectedDomain = 'todos';
} else {
    $selectedDomain = $_GET['domain'] ?? '';
}

// Tipo de data: 'hoje', 'intervalo' ou 'data_unica'
$dateType = $_GET['date_type'] ?? 'hoje';
$dateStart = null;
$dateEnd = null;

if ($dateType === 'hoje') {
    $dateStart = date('Y-m-d');
    $dateEnd = date('Y-m-d');
} elseif ($dateType === 'intervalo') {
    $dateStart = $_GET['date_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateEnd = $_GET['date_end'] ?? date('Y-m-d');
    if ($dateStart > $dateEnd) {
        $temp = $dateStart;
        $dateStart = $dateEnd;
        $dateEnd = $temp;
    }
} elseif ($dateType === 'data_unica') {
    $dateStart = $_GET['single_date'] ?? date('Y-m-d');
    $dateEnd = $dateStart;
}

// Montagem WHERE
$where = [];
$paramsWhere = [];

if ($dateStart && $dateEnd) {
    $where[] = "DATE(timestamp) BETWEEN ? AND ?";
    $paramsWhere[] = $dateStart;
    $paramsWhere[] = $dateEnd;
} else {
    // Fallback: hoje
    $where[] = "DATE(timestamp) = CURDATE()";
}

if ($_SESSION['role'] !== 'admin') {
    if ($selectedDomain === 'todos') {
        $placeholders = implode(',', array_fill(0, count($_SESSION['domains']), '?'));
        $where[] = "domain IN ($placeholders)";
        $paramsWhere = array_merge($paramsWhere, $_SESSION['domains']);
    } elseif ($selectedDomain) {
        $where[] = "domain = ?";
        $paramsWhere[] = $selectedDomain;
    }
} elseif (!empty($_GET['domain'])) {
    $where[] = "domain = ?";
    $paramsWhere[] = $_GET['domain'];
}

if (!empty($_GET['status'])) {
    $filterStatus = normalizeStatus($_GET['status']);
    $where[] = "status = ?";
    $paramsWhere[] = $filterStatus;
}
if (!empty($_GET['search'])) {
    $where[] = "(sender LIKE ? OR recipient LIKE ?)";
    $paramsWhere[] = "%{$_GET['search']}%";
    $paramsWhere[] = "%{$_GET['search']}%";
}
$whereClause = "WHERE " . implode(" AND ", $where);

// Métricas
$sqlMetrics = "SELECT status, COUNT(*) as total FROM email_tracking $whereClause GROUP BY status";
$stmtMetrics = $pdo->prepare($sqlMetrics);
$stmtMetrics->execute($paramsWhere);
$metrics = $stmtMetrics->fetchAll(PDO::FETCH_ASSOC);

$totalEmails = 0;
$statusCounts = [];
foreach ($metrics as $row) {
    $statusKey = normalizeStatus($row['status']);
    $statusCounts[$statusKey] = ($statusCounts[$statusKey] ?? 0) + (int)$row['total'];
    $totalEmails += (int)$row['total'];
}
$statusOrder = ['accepted', 'rejected', 'quarantined', 'delivered', 'blocked', 'greylisted', 'spam'];
foreach ($statusCounts as $st => $cnt) if (!in_array($st, $statusOrder)) $statusOrder[] = $st;

// Paginação
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

// Domínios para admin
$relayDomains = [];
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->query("SELECT DISTINCT domain FROM email_tracking WHERE domain IS NOT NULL AND domain != '' ORDER BY domain");
    $relayDomains = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

require 'header.php';
?>

<div class="metrics-container">
    <div class="metric-card total"><h3>📊 Total</h3><div class="count"><?= number_format($totalEmails, 0, ',', '.') ?></div></div>
    <?php foreach ($statusOrder as $st):
        $count = $statusCounts[$st] ?? 0;
        if ($count === 0 && !in_array($st, ['accepted','rejected','quarantined'])) continue;
        $color = getStatusColor($st);
    ?>
        <div class="metric-card" style="border-top-color: <?= $color ?>;">
            <h3><?= translateStatus($st) ?></h3>
            <div class="count"><?= number_format($count, 0, ',', '.') ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="filters">
    <form method="get" id="filterForm">
        <!-- Domínio -->
        <?php if ($_SESSION['role'] !== 'admin' && count($_SESSION['domains']) > 1): ?>
        <div class="filter-group">
            <label>🏢 Domínio</label>
            <select name="domain_filter" onchange="this.form.submit()">
                <option value="todos" <?= $selectedDomain == 'todos' ? 'selected' : '' ?>>Todos os meus domínios</option>
                <?php foreach ($_SESSION['domains'] as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $selectedDomain == $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif ($_SESSION['role'] === 'admin' && !empty($relayDomains)): ?>
        <div class="filter-group">
            <label>🏢 Domínio</label>
            <select name="domain">
                <option value="">Todos</option>
                <?php foreach ($relayDomains as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= ($_GET['domain'] ?? '') == $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- Tipo de data -->
        <div class="filter-group">
            <label>📅 Período</label>
            <select name="date_type" id="date_type" onchange="toggleDateFields()">
                <option value="hoje" <?= $dateType == 'hoje' ? 'selected' : '' ?>>Hoje</option>
                <option value="intervalo" <?= $dateType == 'intervalo' ? 'selected' : '' ?>>Intervalo de datas</option>
                <option value="data_unica" <?= $dateType == 'data_unica' ? 'selected' : '' ?>>Data única</option>
            </select>
        </div>

        <!-- Campos de data (intervalo) -->
        <div id="intervaloFields" style="display: <?= $dateType == 'intervalo' ? 'flex' : 'none' ?>; gap: 10px;">
            <div class="filter-group">
                <label>Data inicial</label>
                <input type="date" name="date_start" value="<?= htmlspecialchars($dateStart ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Data final</label>
                <input type="date" name="date_end" value="<?= htmlspecialchars($dateEnd ?? '') ?>">
            </div>
        </div>

        <!-- Campo de data única -->
        <div id="dataUnicaField" style="display: <?= $dateType == 'data_unica' ? 'flex' : 'none' ?>;">
            <div class="filter-group">
                <label>Data</label>
                <input type="date" name="single_date" value="<?= htmlspecialchars($_GET['single_date'] ?? date('Y-m-d')) ?>">
            </div>
        </div>

        <!-- Status -->
        <div class="filter-group">
            <label>⚙️ Status</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach (array_keys($statusColors) as $statusName): ?>
                    <option value="<?= $statusName ?>" <?= ($_GET['status']??'') == $statusName ? 'selected' : '' ?>><?= translateStatus($statusName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Busca -->
        <div class="filter-group">
            <label>🔍 Buscar</label>
            <input type="text" name="search" placeholder="remetente/destinatário" value="<?= htmlspecialchars($_GET['search']??'') ?>">
        </div>

        <div class="filter-group">
            <button type="submit">🔎 Filtrar</button>
            <a href="index.php" class="clear-btn">🗑️ Limpar</a>
        </div>
    </form>
</div>

<div class="results">
    <div class="info">
        📄 Mostrando <strong><?= count($emails) ?></strong> de <strong><?= number_format($totalRegs, 0, ',', '.') ?></strong> registros
        <?php if ($dateStart && $dateEnd): ?>
            (período: <?= date('d/m/Y', strtotime($dateStart)) ?> até <?= date('d/m/Y', strtotime($dateEnd)) ?>)
        <?php endif; ?>
        (página <?= $page ?> de <?= $totalPages ?>)
    </div>
    <?php if (count($emails) > 0): ?>
    <div style="overflow-x: auto;">
        <table><thead>
            <tr><th>Data/Hora</th><th>Remetente</th><th>Destinatário</th><th>Domínio</th><th>Status</th><th>Ação</th></tr>
        </thead>
        <tbody>
        <?php foreach ($emails as $email):
            $statusNormalized = normalizeStatus($email['status']);
            $statusDisplay = translateStatus($statusNormalized);
            $statusColor = getStatusColor($statusNormalized);
        ?>
            <tr>
                <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($email['timestamp']))) ?></td>
                <td><?= htmlspecialchars($email['sender']) ?></td>
                <td><?= htmlspecialchars($email['recipient']) ?></td>
                <td><?= htmlspecialchars($email['domain']) ?></td>
                <td><span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>;"><?= $statusDisplay ?></span></td>
                <td><a href="detail.php?id=<?= urlencode($email['email_id']) ?>">🔍 Detalhes</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
    </div>
    <div class="pagination">
        <?php
        $maxVisible = 7; $half = floor($maxVisible / 2);
        $startPage = max(1, $page - $half); $endPage = min($totalPages, $page + $half);
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
            <a href="?<?= queryString(['page' => $page-1]) ?>" class="nav">‹ Anterior</a>
        <?php endif;
        if ($page < $totalPages): ?>
            <a href="?<?= queryString(['page' => $page+1]) ?>" class="nav">Próxima ›</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
        <p style="text-align:center; padding:20px;">Nenhum e-mail encontrado para o período selecionado.</p>
    <?php endif; ?>
</div>

<script>
function toggleDateFields() {
    let dateType = document.getElementById('date_type').value;
    let intervaloDiv = document.getElementById('intervaloFields');
    let dataUnicaDiv = document.getElementById('dataUnicaField');
    
    if (dateType === 'intervalo') {
        intervaloDiv.style.display = 'flex';
        dataUnicaDiv.style.display = 'none';
    } else if (dateType === 'data_unica') {
        intervaloDiv.style.display = 'none';
        dataUnicaDiv.style.display = 'flex';
    } else {
        intervaloDiv.style.display = 'none';
        dataUnicaDiv.style.display = 'none';
    }
}
</script>
<?php require 'footer.php'; ?>
