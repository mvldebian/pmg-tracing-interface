<?php
require 'config.php';
checkAdmin();
$pageTitle = 'PMG Tracing - Cloudflare Turnstile';

$msg = '';
$error = '';

// Processar salvamento das configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $enabled = isset($_POST['turnstile_enabled']) ? 1 : 0;
    $siteKey = trim($_POST['turnstile_site_key'] ?? '');
    $secretKey = trim($_POST['turnstile_secret_key'] ?? '');
    
    // Validar se as chaves foram preenchidas quando ativado
    if ($enabled && (empty($siteKey) || empty($secretKey))) {
        $error = "Para ativar o Turnstile, você precisa preencher a Site Key e a Secret Key.";
    } else {
        try {
            // Verificar se a tabela settings existe, se não, criar
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL
            )");
            
            // Salvar configurações
            $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute(['turnstile_enabled', $enabled]);
            $stmt->execute(['turnstile_site_key', $siteKey]);
            $stmt->execute(['turnstile_secret_key', $secretKey]);
            
            $msg = "Configurações salvas com sucesso!";
            
            // Atualizar constantes para uso imediato
            if (!defined('TURNSTILE_ENABLED')) {
                define('TURNSTILE_ENABLED', $enabled);
                define('TURNSTILE_SITE_KEY', $siteKey);
                define('TURNSTILE_SECRET_KEY', $secretKey);
            }
            
        } catch (PDOException $e) {
            $error = "Erro ao salvar configurações: " . $e->getMessage();
        }
    }
}

// Carregar configurações atuais
$currentEnabled = false;
$currentSiteKey = '';
$currentSecretKey = '';

try {
    // Verificar se a tabela existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
    
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'turnstile_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $currentEnabled = isset($settings['turnstile_enabled']) && $settings['turnstile_enabled'] == '1';
    $currentSiteKey = $settings['turnstile_site_key'] ?? '';
    $currentSecretKey = $settings['turnstile_secret_key'] ?? '';
    
} catch (PDOException $e) {
    $error = "Erro ao carregar configurações: " . $e->getMessage();
}

require 'header.php';
?>

<div class="filters" style="max-width: 700px; margin: 0 auto;">
    <h2 style="margin-bottom: 20px;">⚙️ Configuração do Cloudflare Turnstile</h2>
    
    <?php if ($msg): ?>
        <div class="info" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="post" style="background: white; padding: 20px; border-radius: 12px;">
        <!-- Ativar/Desativar Turnstile -->
        <div class="filter-group" style="margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
            <label style="font-weight: bold; font-size: 1rem; margin-bottom: 10px;">
                <i class="fas fa-power-off"></i> Status do Turnstile
            </label>
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="turnstile_enabled" value="1" <?= $currentEnabled ? 'checked' : '' ?>> 
                    <span style="color: #27ae60;"><i class="fas fa-check-circle"></i> Ativado</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="turnstile_enabled" value="0" <?= !$currentEnabled ? 'checked' : '' ?>> 
                    <span style="color: #e74c3c;"><i class="fas fa-ban"></i> Desativado</span>
                </label>
            </div>
            <small style="color: #666; display: block; margin-top: 8px;">
                Quando ativado, o formulário de login exigirá verificação do Cloudflare Turnstile
            </small>
        </div>
        
        <!-- Site Key -->
        <div class="filter-group" style="margin-bottom: 25px;">
            <label style="font-weight: bold; margin-bottom: 8px;">
                <i class="fas fa-key"></i> Site Key (Chave Pública)
            </label>
            <input type="text" 
                   name="turnstile_site_key" 
                   value="<?= htmlspecialchars($currentSiteKey) ?>" 
                   style="width: 100%; padding: 10px; font-family: monospace; border: 1px solid #ddd; border-radius: 6px;" 
                   placeholder="0x4AAAAAAA-xxxxxxxxxxxxxxxxxx">
            <small style="color: #666; font-size: 0.75rem; display: block; margin-top: 5px;">
                A chave pública que será usada no formulário de login
            </small>
        </div>
        
        <!-- Secret Key -->
        <div class="filter-group" style="margin-bottom: 25px;">
            <label style="font-weight: bold; margin-bottom: 8px;">
                <i class="fas fa-lock"></i> Secret Key (Chave Secreta)
            </label>
            <input type="password" 
                   name="turnstile_secret_key" 
                   value="<?= htmlspecialchars($currentSecretKey) ?>" 
                   style="width: 100%; padding: 10px; font-family: monospace; border: 1px solid #ddd; border-radius: 6px;" 
                   placeholder="0x4AAAAAAA-xxxxxxxxxxxxxxxxxx">
            <small style="color: #666; font-size: 0.75rem; display: block; margin-top: 5px;">
                A chave secreta usada para validar as respostas do Turnstile
            </small>
            <div style="margin-top: 8px;">
                <button type="button" onclick="toggleSecretKey()" style="background: none; border: none; color: #3498db; cursor: pointer; font-size: 0.8rem;">
                    <i class="fas fa-eye"></i> Mostrar/Esconder chave
                </button>
            </div>
        </div>
        
        <!-- Botões -->
        <div class="filter-group" style="display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
            <button type="submit" name="save_config" style="background: #27ae60; padding: 10px 20px;">
                <i class="fas fa-save"></i> Salvar configurações
            </button>
            <a href="index.php" class="clear-btn" style="padding: 10px 20px; text-align: center;">
                <i class="fas fa-times"></i> Cancelar
            </a>
        </div>
    </form>
    
    <!-- Informações de como obter as chaves -->
    <div class="info" style="margin-top: 25px; background: #e7f3fe; border-radius: 12px; padding: 20px;">
        <h3 style="margin-bottom: 15px; color: #2c3e50;">
            <i class="fas fa-question-circle"></i> Como obter as chaves do Turnstile
        </h3>
        <ol style="margin-left: 20px; line-height: 1.6;">
            <li>Acesse o painel do Cloudflare: <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">https://dash.cloudflare.com/turnstile</a></li>
            <li>Clique em <strong>"Add Widget"</strong></li>
            <li>Preencha:
                <ul style="margin-left: 20px; margin-top: 5px;">
                    <li><strong>Nome do Widget:</strong> PMG TRACING</li>
                    <li><strong>Domínio(s):</strong> adicione o domínio do seu sistema tracing.exemplo.com.br</li>
                    <li><strong>Modo:</strong> Managed (recomendado)</li>
                </ul>
            </li>
            <li>Clique em <strong>"Create"</strong></li>
            <li>Copie a <strong>Site Key</strong> e a <strong>Secret Key</strong></li>
            <li>Cole as chaves nos campos acima e clique em <strong>"Salvar configurações"</strong></li>
        </ol>
        
        <!-- Exemplo visual do Turnstile -->
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
            <i class="fas fa-shield-alt" style="font-size: 2rem; color: #27ae60;"></i>
            <p style="margin-top: 10px; font-size: 0.85rem; color: #555;">
                <strong>Exemplo de como o Turnstile aparecerá no login:</strong><br>
                Um widget de verificação será exibido abaixo do formulário de login.
            </p>
            <?php if ($currentEnabled && $currentSiteKey): ?>
                <div style="margin-top: 10px; padding: 10px; background: #d4edda; border-radius: 6px; color: #155724;">
                    <i class="fas fa-check-circle"></i> Turnstile está <strong>ATIVADO</strong> com a chave: <?= substr($currentSiteKey, 0, 20) ?>...
                </div>
            <?php else: ?>
                <div style="margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 6px; color: #721c24;">
                    <i class="fas fa-exclamation-triangle"></i> Turnstile está <strong>DESATIVADO</strong>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSecretKey() {
    const input = document.querySelector('input[name="turnstile_secret_key"]');
    if (input.type === 'password') {
        input.type = 'text';
    } else {
        input.type = 'password';
    }
}
</script>

<?php require 'footer.php'; ?>
