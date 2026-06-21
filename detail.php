<?php
require 'config.php';
checkAuth();
$pageTitle = 'PMG Tracing - Detalhes';

$email_id = $_GET['id'] ?? '';
if (!$email_id) die("ID não informado.");
$sql = "SELECT raw_data, domain FROM email_tracking WHERE email_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$email_id]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$email) die("Registro não encontrado.");
if ($_SESSION['role'] !== 'admin' && !in_array($email['domain'], $_SESSION['domains'])) die("Acesso negado.");
$data = json_decode($email['raw_data'], true);
require 'header.php';
?>
<div class="results">
    <h2>Detalhes do E-mail</h2>
    <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto;"><?php print_r($data); ?></pre>
    <div style="margin-top: 20px;">
        <a href="javascript:history.back()" class="clear-btn" style="display:inline-block; margin-right:10px;">← Voltar</a>
    </div>
</div>
<?php require 'footer.php'; ?>
