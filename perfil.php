<?php
require 'config.php';
checkAuth();
$pageTitle = 'PMG Tracing - Perfil';

$user_id = $_SESSION['user_id'];
$msg = '';

$stmt = $pdo->prepare("SELECT email, two_factor_enabled FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $email = trim($_POST['email']);
    $two_factor = isset($_POST['two_factor_enabled']) ? 1 : 0;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "E‑mail inválido.";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET email = ?, two_factor_enabled = ? WHERE id = ?");
        if ($stmt->execute([$email, $two_factor, $user_id])) {
            $msg = "Perfil atualizado com sucesso.";
            $user['email'] = $email;
            $user['two_factor_enabled'] = $two_factor;
        } else {
            $msg = "Erro ao atualizar perfil.";
        }
    }
}

require 'header.php';
?>
<div class="filters" style="max-width: 600px; margin: 0 auto;">
    <h2>Meu Perfil</h2>
    <?php if ($msg): ?>
        <div class="info" style="background: #d4edda; color: #155724;"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="filter-group">
            <label>📧 E‑mail para receber códigos de verificação)</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
        </div>
        <div class="filter-group" style="flex-direction: row; align-items: center; gap: 10px;">
            <label>🔐 Verificação em duas etapas (2FA)</label>
            <input type="checkbox" name="two_factor_enabled" value="1" <?= $user['two_factor_enabled'] ? 'checked' : '' ?>>
            <span style="font-size:0.8rem;">Ativar - Receber códigos neste e‑mail</span>
        </div>
        <div class="filter-group">
            <button type="submit" name="update_profile">Salvar alterações</button>
            <a href="index.php" class="clear-btn" style="text-align:center;">Cancelar</a>
        </div>
    </form>
    <div class="info" style="margin-top: 20px; background: #e7f3fe;">
        <i class="fas fa-info-circle"></i> Ao ativar o 2FA, precisará ter um e‑mail válido receber os códigos.
    </div>
</div>
<?php require 'footer.php'; ?>
