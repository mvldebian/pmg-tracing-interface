<?php

session_start();

// Banco de dados da aplicação
define('DB_HOST', 'localhost');
define('DB_NAME', 'pmg_tracing');
define('DB_USER', 'usuariomysql');
define('DB_PASS', 'senhamysql');

// API PMG Proxmox
define('PMG_HOST', 'https://pmg.exemplo.com.br:8006');
define('PMG_USER', 'root@pam');
define('PMG_PASS', 'senharootpmg');

// Configurações SMTP
define('SMTP_HOST', 'smtp.seuprovedor.com.br');
define('SMTP_PORT', 587);
define('SMTP_USER', 'seuemail@dominio.com.br');
define('SMTP_PASS', 'senhadoemail');
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_FROM_EMAIL', 'naoresponda@seudominio.com.br');
define('SMTP_FROM_NAME', 'PMG Tracing');

// Global Cloudflare Turnstile
define('TURNSTILE_ENABLED', false);  // true ou false

// Carregamento do PHPMailer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die("PHPMailer não encontrado. Instale via Composer: composer require phpmailer/phpmailer");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Conexão com o banco
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão com o banco: ".$e->getMessage());
}

// Turnstile pegando chave e validando via banco
if (!function_exists('loadTurnstileFromDatabase')) {
    function loadTurnstileFromDatabase($pdo) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'turnstile_%'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (isset($settings['turnstile_enabled'])) {
                define('TURNSTILE_ENABLED_FROM_DB', $settings['turnstile_enabled'] == '1');
            }
            if (isset($settings['turnstile_site_key'])) {
                define('TURNSTILE_SITE_KEY_FROM_DB', $settings['turnstile_site_key']);
            }
            if (isset($settings['turnstile_secret_key'])) {
                define('TURNSTILE_SECRET_KEY_FROM_DB', $settings['turnstile_secret_key']);
            }
            return true;
        } catch (PDOException $e) {
            // Tabela settings não existe ou erro - usar constantes padrão
            return false;
        }
    }
}

// Prioridade: constantes do banco (se existirem) > constantes fixas
if (!defined('TURNSTILE_ENABLED')) {
    if (function_exists('loadTurnstileFromDatabase') && loadTurnstileFromDatabase($pdo)) {
        define('TURNSTILE_ENABLED', defined('TURNSTILE_ENABLED_FROM_DB') ? TURNSTILE_ENABLED_FROM_DB : false);
        define('TURNSTILE_SITE_KEY', defined('TURNSTILE_SITE_KEY_FROM_DB') ? TURNSTILE_SITE_KEY_FROM_DB : '');
        define('TURNSTILE_SECRET_KEY', defined('TURNSTILE_SECRET_KEY_FROM_DB') ? TURNSTILE_SECRET_KEY_FROM_DB : '');
    } else {
        // Usar valores padrão das constantes definidas no início
        define('TURNSTILE_ENABLED', TURNSTILE_ENABLED);
        define('TURNSTILE_SITE_KEY', TURNSTILE_SITE_KEY);
        define('TURNSTILE_SECRET_KEY', TURNSTILE_SECRET_KEY);
    }
}

// Funções de autenticação
if (!function_exists('checkAuth')) {
    function checkAuth() {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('checkAdmin')) {
    function checkAdmin() {
        checkAuth();
        if ($_SESSION['role'] !== 'admin') {
            die("Acesso negado. Área restrita ao administrador.");
        }
    }
}

if (!function_exists('loadUserDomains')) {
    function loadUserDomains($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT domain FROM user_domains WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Métricas do servidor
if (!function_exists('getCpuUsage')) {
    function getCpuUsage() {
        $load = sys_getloadavg();
        return [
            '1min'  => round($load[0], 2),
            '5min'  => round($load[1], 2),
            '15min' => round($load[2], 2)
        ];
    }
}

if (!function_exists('getMemoryUsage')) {
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
}

if (!function_exists('getDiskUsage')) {
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
}

// 2FA via SMTP
if (!function_exists('generateTwoFactorCode')) {
    function generateTwoFactorCode() {
        return sprintf("%06d", mt_rand(1, 999999));
    }
}

if (!function_exists('sendTwoFactorCode')) {
    function sendTwoFactorCode($to, $code) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(false);
            $mail->Subject = 'Seu código de autenticação de dois fatores';
            $mail->Body    = "Olá,\n\nSeu código de acesso é: $code\n\nEle é válido por 5 minutos.\n\nSe você não solicitou, ignore este e-mail.";
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Erro ao enviar e-mail 2FA: " . $mail->ErrorInfo);
            return false;
        }
    }
}

// Funções da API do PMG
if (!function_exists('authenticate')) {
    function authenticate() {
        $url = PMG_HOST . "/api2/json/access/ticket";
        $postData = json_encode(['username' => PMG_USER, 'password' => PMG_PASS]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("Autenticação PMG falhou: $response");
        $json = json_decode($response, true);
        return ['ticket' => $json['data']['ticket']];
    }
}

if (!function_exists('callAPI')) {
    function callAPI($endpoint, $params, $ticket) {
        $url = PMG_HOST . "/api2/json$endpoint?" . http_build_query($params);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Cookie: PMGAuthCookie=' . $ticket]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception("Erro na API PMG: $response");
        return json_decode($response, true);
    }
}

// Status MAP, cores dos status e traduções
$statusMapGlobal = [
    'A' => 'accepted', 'R' => 'rejected', 'Q' => 'quarantined', 'D' => 'delivered',
    'B' => 'blocked', 'G' => 'greylisted', 'N' => 'not_delivered', 'P' => 'pending',
    'S' => 'spam', '1' => 'accepted', '2' => 'quarantined', '3' => 'rejected',
    '4' => 'delivered', '5' => 'blocked'
];

$statusColors = [
    'accepted'    => '#27ae60',
    'rejected'    => '#e74c3c',
    'quarantined' => '#f1c40f',
    'delivered'   => '#2980b9',
    'blocked'     => '#95a5a6',
    'greylisted'  => '#3498db',
    'not_delivered' => '#e67e22',
    'pending'     => '#f39c12',
    'spam'        => '#9b59b6'
];

$statusTranslations = [
    'accepted'      => 'Aceito',
    'rejected'      => 'Rejeitado',
    'quarantined'   => 'Quarentena',
    'delivered'     => 'Entregue',
    'blocked'       => 'Bloqueado',
    'greylisted'    => 'Greylist',
    'not_delivered' => 'Não entregue',
    'pending'       => 'Pendente',
    'spam'          => 'Spam'
];

function normalizeStatus($rawStatus) {
    global $statusMapGlobal;
    return $statusMapGlobal[$rawStatus] ?? $rawStatus;
}

function getStatusColor($statusName) {
    global $statusColors;
    return $statusColors[$statusName] ?? '#7f8c8d';
}

function translateStatus($statusName) {
    global $statusTranslations;
    return $statusTranslations[$statusName] ?? ucfirst($statusName);
}

// Cloudflare Turnstile
if (!function_exists('verifyTurnstile')) {
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
}

?>
