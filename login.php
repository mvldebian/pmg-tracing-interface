<?php
require 'config.php';

$error = '';

// Função para validar Turnstile (se estiver ativado)
function verifyTurnstile($token) {
    if (!TURNSTILE_ENABLED) {
        return true; // Se desativado, sempre retorna verdadeiro
    }
    
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $data = [
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return false;
    }
    
    $result = json_decode($response, true);
    return $result['success'] === true;
}

// Passo 2: verificação do código 2FA
if (isset($_SESSION['2fa_temp_user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['2fa_code'])) {
    $code = trim($_POST['2fa_code']);
    $userId = $_SESSION['2fa_temp_user_id'];
    $expires = $_SESSION['2fa_expires'] ?? 0;
    
    if (time() > $expires) {
        $error = "Código expirado. Faça login novamente.";
        unset($_SESSION['2fa_temp_user_id'], $_SESSION['2fa_expires']);
    } elseif (isset($_SESSION['2fa_code']) && $_SESSION['2fa_code'] === $code) {
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            if ($user['role'] !== 'admin') {
                $stmtDomains = $pdo->prepare("SELECT domain FROM user_domains WHERE user_id = ?");
                $stmtDomains->execute([$user['id']]);
                $_SESSION['domains'] = $stmtDomains->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $_SESSION['domains'] = [];
            }
            unset($_SESSION['2fa_temp_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires']);
            header('Location: index.php');
            exit;
        } else {
            $error = "Erro na autenticação.";
            unset($_SESSION['2fa_temp_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires']);
        }
    } else {
        $error = "Código inválido. Tente novamente.";
    }
}

// Passo 1: login com usuário/senha + Turnstile (se ativado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password']) && !isset($_SESSION['2fa_temp_user_id'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
    
    // Validar Turnstile apenas se estiver ativado
    $turnstileValid = true;
    if (TURNSTILE_ENABLED) {
        if (empty($turnstileToken) || !verifyTurnstile($turnstileToken)) {
            $turnstileValid = false;
            $error = "Por favor, complete o desafio.";
        }
    }
    
    if ($turnstileValid && (empty($username) || empty($password))) {
        $error = "Preencha usuário e senha.";
    } elseif ($turnstileValid) {
        $stmt = $pdo->prepare("SELECT id, username, password, role, email, two_factor_enabled FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['two_factor_enabled'] && !empty($user['email'])) {
                $code = generateTwoFactorCode();
                $_SESSION['2fa_temp_user_id'] = $user['id'];
                $_SESSION['2fa_code'] = $code;
                $_SESSION['2fa_expires'] = time() + 300;
                if (sendTwoFactorCode($user['email'], $code)) {
                    // Mostrar formulário de código
                } else {
                    $error = "Não foi possível enviar o e‑mail.";
                    unset($_SESSION['2fa_temp_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires']);
                }
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                if ($user['role'] !== 'admin') {
                    $stmtDomains = $pdo->prepare("SELECT domain FROM user_domains WHERE user_id = ?");
                    $stmtDomains->execute([$user['id']]);
                    $_SESSION['domains'] = $stmtDomains->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $_SESSION['domains'] = [];
                }
                header('Location: index.php');
                exit;
            }
        } else {
            $error = "Usuário ou senha inválidos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMG Tracing - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php if (TURNSTILE_ENABLED): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { 
        zoom: 80%; 
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #2c3e50;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 450px;
            padding: 35px 30px;
        }
        h2 { text-align: center; color: #2c3e50; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 25px; border-bottom: 1px solid #ecf0f1; padding-bottom: 15px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; color: #34495e; margin-bottom: 6px; }
        input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            transition: 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(44,62,80,0.2);
        }
        button {
            width: 100%;
            background: linear-gradient(90deg, #2c3e50, #1a2a3a);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
            margin-top: 10px;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 4px solid #e74c3c;
        }
        .footer { text-align: center; margin-top: 25px; font-size: 0.75rem; color: #95a5a6; }
        .turnstile-container {
            display: flex;
            justify-content: center;
            margin: 20px 0;
        }
        .info-badge {
            background: #e7f3fe;
            color: #0c5460;
            padding: 8px;
            border-radius: 8px;
            text-align: center;
            font-size: 0.75rem;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <center><img src="img/proxmox.png"></center>
        <div class="subtitle"><?php echo isset($_SESSION['2fa_temp_user_id']) ? 'Verificação em duas etapas' : 'Acesse o sistema'; ?></div>
        <?php if (!empty($error)) echo "<div class='error'>".htmlspecialchars($error)."</div>"; ?>
        
        <?php if (isset($_SESSION['2fa_temp_user_id'])): ?>
            <form method="post">
                <div class="form-group">
                    <label><i class="fas fa-key"></i> Código de verificação</label>
                    <input type="text" name="2fa_code" required autofocus placeholder="Digite o código de 6 dígitos">
                </div>
                <button type="submit">Verificar e entrar</button>
            </form>
            <div class="footer">Enviamos um código para seu e‑mail. Verifique sua caixa de entrada.</div>
        <?php else: ?>
            <form method="post">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Usuário</label>
                    <input type="text" name="username" required autofocus placeholder="Digite seu usuário">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Senha</label>
                    <input type="password" name="password" required placeholder="Digite sua senha">
                </div>
                <?php if (TURNSTILE_ENABLED): ?>
                <div class="turnstile-container">
                    <div class="cf-turnstile" data-sitekey="<?= TURNSTILE_SITE_KEY ?>" data-theme="light"></div>
                </div>
                <?php else: ?>
                <div class="info-badge">
                    <i class="fas fa-shield-alt"></i> Proteção Turnstile desativada
                </div>
                <?php endif; ?>
                <button type="submit">Entrar</button>
            </form>
            <div class="footer">PMG Tracing 1.0 - Proxmox Mail Gateway<br>Powered by Spamcop Serviços de Internet</div>
        <?php endif; ?>
    </div>
</body>
</html>
