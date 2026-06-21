<?php
require 'config.php';
checkAuth();
$pageTitle = 'PMG Tracing - Relatórios';

$relayDomains = [];
if ($_SESSION['role'] === 'admin') {
    try {
        $stmt = $pdo->query("SELECT DISTINCT domain FROM email_tracking WHERE domain IS NOT NULL AND domain != '' ORDER BY domain");
        $relayDomains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { error_log($e->getMessage()); }
}

$selectedDomain = $_GET['domain_filter'] ?? null;
if ($_SESSION['role'] !== 'admin') {
    if ($selectedDomain && !in_array($selectedDomain, $_SESSION['domains']) && $selectedDomain !== 'todos')
        $selectedDomain = $_SESSION['domains'][0] ?? null;
    if (!$selectedDomain && !empty($_SESSION['domains']))
        $selectedDomain = 'todos';
} else {
    $selectedDomain = $_GET['domain'] ?? '';
}

$periodo = $_GET['periodo'] ?? '7d';
$dataInicio = null; $dataFim = date('Y-m-d');
switch ($periodo) {
    case '7d': $dataInicio = date('Y-m-d', strtotime('-7 days')); break;
    case '30d': $dataInicio = date('Y-m-d', strtotime('-30 days')); break;
    case 'este_mes': $dataInicio = date('Y-m-01'); break;
    case 'mes_passado':
        $dataInicio = date('Y-m-01', strtotime('first day of previous month'));
        $dataFim = date('Y-m-t', strtotime('last day of previous month'));
        break;
    case 'personalizado':
        $dataInicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
        $dataFim = $_GET['data_fim'] ?? date('Y-m-d');
        break;
    default: $dataInicio = date('Y-m-d', strtotime('-7 days'));
}

$whereParts = [];
$params = [];
$whereParts[] = "DATE(timestamp) BETWEEN :inicio AND :fim";
$params[':inicio'] = $dataInicio;
$params[':fim'] = $dataFim;

if ($_SESSION['role'] !== 'admin') {
    if ($selectedDomain === 'todos') {
        $placeholders = [];
        foreach ($_SESSION['domains'] as $idx => $d) {
            $key = ":domain_$idx";
            $placeholders[] = $key;
            $params[$key] = $d;
        }
        $whereParts[] = "domain IN (" . implode(',', $placeholders) . ")";
    } elseif ($selectedDomain) {
        $whereParts[] = "domain = :domain";
        $params[':domain'] = $selectedDomain;
    }
} elseif (!empty($_GET['domain'])) {
    $whereParts[] = "domain = :domain";
    $params[':domain'] = $_GET['domain'];
}

if (!empty($_GET['status'])) {
    $filterStatus = normalizeStatus($_GET['status']);
    $whereParts[] = "status = :status";
    $params[':status'] = $filterStatus;
}
$whereClause = "WHERE " . implode(" AND ", $whereParts);

$sqlCount = "SELECT COUNT(*) as total FROM email_tracking $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$totalRegistros = $stmtCount->fetchColumn();

// Barras
$sqlBar = "SELECT DATE(timestamp) as data,
                  SUM(CASE WHEN status IN ('accepted','A','1','delivered','D','4') THEN 1 ELSE 0 END) as accepted,
                  SUM(CASE WHEN status IN ('rejected','R','3') THEN 1 ELSE 0 END) as rejected,
                  SUM(CASE WHEN status IN ('quarantined','Q','2') THEN 1 ELSE 0 END) as quarantined,
                  SUM(CASE WHEN status IN ('blocked','B','5') THEN 1 ELSE 0 END) as blocked,
                  SUM(CASE WHEN status IN ('greylisted','G') THEN 1 ELSE 0 END) as greylisted,
                  SUM(CASE WHEN status IN ('spam','S') THEN 1 ELSE 0 END) as spam
           FROM email_tracking $whereClause
           GROUP BY DATE(timestamp)
           ORDER BY data ASC";
$stmtBar = $pdo->prepare($sqlBar);
$stmtBar->execute($params);
$barData = $stmtBar->fetchAll(PDO::FETCH_ASSOC);

$barLabels = array_column($barData, 'data');
$barDatasets = [];
$categories = ['accepted', 'rejected', 'quarantined', 'blocked', 'greylisted', 'spam'];
foreach ($categories as $cat) {
    $color = getStatusColor($cat);
    $barDatasets[] = [
        'label' => translateStatus($cat),
        'data'  => array_column($barData, $cat),
        'backgroundColor' => $color . '80',
        'borderColor'     => $color,
        'borderWidth'     => 1
    ];
}

// Pizza
$sqlPie = "SELECT 
              SUM(CASE WHEN status IN ('accepted','A','1','delivered','D','4') THEN 1 ELSE 0 END) as accepted,
              SUM(CASE WHEN status IN ('rejected','R','3') THEN 1 ELSE 0 END) as rejected,
              SUM(CASE WHEN status IN ('quarantined','Q','2') THEN 1 ELSE 0 END) as quarantined,
              SUM(CASE WHEN status IN ('blocked','B','5') THEN 1 ELSE 0 END) as blocked,
              SUM(CASE WHEN status IN ('greylisted','G') THEN 1 ELSE 0 END) as greylisted,
              SUM(CASE WHEN status IN ('spam','S') THEN 1 ELSE 0 END) as spam
           FROM email_tracking $whereClause";
$stmtPie = $pdo->prepare($sqlPie);
$stmtPie->execute($params);
$pieTotals = $stmtPie->fetch(PDO::FETCH_ASSOC);

$pieLabels = [];
$pieData = [];
$pieColors = [];
foreach ($categories as $cat) {
    if ($pieTotals[$cat] > 0) {
        $pieLabels[] = translateStatus($cat);
        $pieData[] = (int)$pieTotals[$cat];
        $pieColors[] = getStatusColor($cat);
    }
}

$chartType = $_GET['chart_type'] ?? 'bar';

require 'header.php';
?>
<div class="filters">
    <form method="get" id="formRelatorio">
        <div class="filter-group"><label>📅 Período</label>
            <select name="periodo" id="periodo" onchange="togglePersonalizado()">
                <option value="7d" <?= $periodo=='7d'?'selected':'' ?>>Últimos 7 dias</option>
                <option value="30d" <?= $periodo=='30d'?'selected':'' ?>>Últimos 30 dias</option>
                <option value="este_mes" <?= $periodo=='este_mes'?'selected':'' ?>>Este mês</option>
                <option value="mes_passado" <?= $periodo=='mes_passado'?'selected':'' ?>>Mês passado</option>
                <option value="personalizado" <?= $periodo=='personalizado'?'selected':'' ?>>Personalizado</option>
            </select>
        </div>
        <div id="personalizadoGroup" style="display: <?= $periodo=='personalizado'?'flex':'none' ?>; gap:10px;">
            <div class="filter-group"><label>Data início</label><input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>"></div>
            <div class="filter-group"><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
        </div>
        <?php if ($_SESSION['role'] !== 'admin' && count($_SESSION['domains']) > 1): ?>
        <div class="filter-group"><label>🏢 Domínio</label>
            <select name="domain_filter" onchange="this.form.submit()">
                <option value="todos" <?= $selectedDomain == 'todos' ? 'selected' : '' ?>>Todos os meus domínios</option>
                <?php foreach ($_SESSION['domains'] as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $selectedDomain == $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif ($_SESSION['role'] === 'admin' && !empty($relayDomains)): ?>
        <div class="filter-group"><label>🏢 Domínio</label>
            <select name="domain">
                <option value="">Todos</option>
                <?php foreach ($relayDomains as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= ($_GET['domain']??'')==$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group"><label>📊 Tipo de gráfico</label>
            <select name="chart_type">
                <option value="bar" <?= $chartType=='bar'?'selected':'' ?>>Barras (evolução diária)</option>
                <option value="pie" <?= $chartType=='pie'?'selected':'' ?>>Pizza (totais do período)</option>
            </select>
        </div>
        <div class="filter-group"><label>⚙️ Status (filtro)</label>
            <select name="status">
                <option value="">Todos os status</option>
                <?php foreach (array_keys($statusColors) as $statusName): ?>
                    <option value="<?= $statusName ?>" <?= ($_GET['status']??'') == $statusName ? 'selected' : '' ?>><?= translateStatus($statusName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">📊 Gerar</button>
            <a href="relatorios.php" class="clear-btn">Limpar</a>
        </div>
    </form>
</div>

<div class="info" style="margin-bottom:15px; padding:10px; background:#e7f3fe; border-radius:8px;">
    <i class="fas fa-info-circle"></i> 
    <strong>Total de registros encontrados:</strong> <?= number_format($totalRegistros, 0, ',', '.') ?>
    <?php if ($totalRegistros == 0): ?>
        <span style="color:#e74c3c;">— Nenhum dado. Tente outros filtros.</span>
    <?php endif; ?>
</div>

<div class="chart-container">
    <?php if ($chartType == 'pie'): ?>
        <?php if (empty($pieData)): ?>
            <div style="text-align:center; padding:50px; background:#f9f9f9; border-radius:12px;">
                <i class="fas fa-chart-pie fa-3x" style="color:#7f8c8d;"></i>
                <p>Nenhum dado para exibir no gráfico de pizza.</p>
            </div>
        <?php else: ?>
            <canvas id="pieChart" style="max-height:500px; width:100%;"></canvas>
        <?php endif; ?>
    <?php else: ?>
        <?php if (empty($barLabels)): ?>
            <div style="text-align:center; padding:50px; background:#f9f9f9; border-radius:12px;">
                <i class="fas fa-chart-bar fa-3x" style="color:#7f8c8d;"></i>
                <p>Nenhum dado para exibir no gráfico de barras.</p>
            </div>
        <?php else: ?>
            <canvas id="barChart" style="max-height:500px; width:100%;"></canvas>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function togglePersonalizado() {
    let periodo = document.getElementById('periodo').value;
    let div = document.getElementById('personalizadoGroup');
    div.style.display = (periodo === 'personalizado') ? 'flex' : 'none';
}

<?php if ($chartType == 'pie' && !empty($pieData)): ?>
new Chart(document.getElementById('pieChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode($pieLabels) ?>,
        datasets: [{
            data: <?= json_encode($pieData) ?>,
            backgroundColor: <?= json_encode($pieColors) ?>,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw} e-mails` } }
        }
    }
});
<?php elseif ($chartType == 'bar' && !empty($barLabels)): ?>
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($barLabels) ?>,
        datasets: <?= json_encode($barDatasets) ?>
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Quantidade' } },
            x: { title: { display: true, text: 'Data' } }
        }
    }
});
<?php endif; ?>
</script>
<?php require 'footer.php'; ?>
