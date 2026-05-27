<?php

/**
 * Importador de logs do Proxmox Mail Gateway para MySQL
 * Compatível com versões antigas do PMG (timestamp Unix, sem 'limit' e 'direction')
 * Uso: php import_pmg_tracker.php [--days=1] [--node=localhost]
 * Por Marcelo Leães - marcelo@spamcop.com.br
*/

// Configurações
define('PMG_HOST', 'https://hostname.exemplo.com.br:8006'); // IP/domínio do PMG + porta 8006
define('PMG_USER', 'root@pam');
define('PMG_PASS', 'senhadoroot');
define('PMG_NODE', 'localhost'); // Nome do nó (geralmente 'localhost')

define('DB_HOST', 'localhost');
define('DB_NAME', 'pmg_tracking');
define('DB_USER', 'usuariobanco');
define('DB_PASS', 'senhausuario');

define('LOG_FILE', __DIR__ . '/import_pmg.log');
define('API_TIMEOUT', 120); // segundos
define('INTERVAL_HOURS', 6); // Divide a consulta em blocos de N horas

$options = getopt('', ['days::', 'node::']);
$days = isset($options['days']) ? (int)$options['days'] : 1;
$node = isset($options['node']) ? $options['node'] : PMG_NODE;

logMsg("Iniciando importação dos últimos {$days} dia(s) para o nó {$node}");

try {
    // Conexão com o banco
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Autenticação no PMG
    $auth = authenticate();
    $ticket = $auth['ticket'];
    
    // Período total em timestamps Unix
    $endDate = new DateTime();
    $startDate = (new DateTime())->sub(new DateInterval("P{$days}D"));
    $startTs = $startDate->getTimestamp();
    $endTs = $endDate->getTimestamp();
    
    $intervalSecs = INTERVAL_HOURS * 3600;
    $totalInserted = 0;
    
    for ($ts = $startTs; $ts < $endTs; $ts += $intervalSecs) {
        $blockStart = $ts;
        $blockEnd = min($ts + $intervalSecs, $endTs);
        
        $startHuman = date('Y-m-d H:i:s', $blockStart);
        $endHuman = date('Y-m-d H:i:s', $blockEnd);
        logMsg("Consultando período: $startHuman até $endHuman");
        
        $params = [
            'starttime' => $blockStart,
            'endtime'   => $blockEnd
            // NÃO enviar 'limit' ou 'direction'
        ];
        
        try {
            $data = callAPI("/nodes/{$node}/tracker", $params, $ticket);
            if (!empty($data['data']) && is_array($data['data'])) {
                $inserted = processEmails($pdo, $data['data']);
                $totalInserted += $inserted;
                logMsg("  ↳ Inseridos {$inserted} novos registros (duplicatas ignoradas).");
            } else {
                logMsg("  ↳ Nenhum registro encontrado.");
            }
        } catch (Exception $e) {
            logMsg("  ✗ ERRO no bloco: " . $e->getMessage());
        }
    }
    
    logMsg("Importação concluída. Total de novos registros inseridos: {$totalInserted}");
    
} catch (Exception $e) {
    logMsg("ERRO FATAL: " . $e->getMessage());
    exit(1);
}

// Funções

function authenticate() {
    $url = PMG_HOST . "/api2/json/access/ticket";
    $postData = json_encode([
        'username' => PMG_USER,
        'password' => PMG_PASS
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postData)
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("Erro de conexão: $error");
    if ($httpCode !== 200) throw new Exception("Autenticação falhou: HTTP $httpCode - $response");
    
    $json = json_decode($response, true);
    if (!isset($json['data']['ticket'])) {
        throw new Exception("Resposta de autenticação inválida: " . print_r($json, true));
    }
    
    return ['ticket' => $json['data']['ticket']];
}

function callAPI($endpoint, $params, $ticket) {
    $url = PMG_HOST . "/api2/json" . $endpoint . "?" . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Cookie: PMGAuthCookie=' . $ticket   // Cookie correto para PMG
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("Erro cURL: $error");
    if ($httpCode !== 200) throw new Exception("HTTP $httpCode: $response");
    
    $data = json_decode($response, true);
    if (isset($data['data'])) return $data;
    if (is_array($data)) return ['data' => $data];
    throw new Exception("Resposta inesperada: " . print_r($data, true));
}

function processEmails($pdo, $emails) {
    if (empty($emails)) return 0;
    
    $sql = "INSERT IGNORE INTO email_tracking 
            (email_id, sender, recipient, domain, status, timestamp, raw_data) 
            VALUES (:id, :sender, :recipient, :domain, :status, :timestamp, :raw_data)";
    
    $stmt = $pdo->prepare($sql);
    $count = 0;
    $warnedStatus = false;
    
    // Mapeamento dos códigos de status (ex: 'A' -> 'accepted')
    $statusMap = [
        'A' => 'accepted', 'R' => 'rejected', 'Q' => 'quarantined', 'D' => 'delivered',
        'B' => 'blocked', 'G' => 'greylisted', 'N' => 'not_delivered', 'P' => 'pending',
        'S' => 'spam', '1' => 'accepted', '2' => 'quarantined', '3' => 'rejected',
        '4' => 'delivered', '5' => 'blocked'
    ];
    
    foreach ($emails as $email) {
        $emailId = $email['id'] ?? null;
        if (!$emailId) {
            logMsg("Aviso: registro sem campo 'id', ignorado: " . json_encode($email));
            continue;
        }
        
        $sender = $email['from'] ?? ($email['sender'] ?? ($email['origin'] ?? ''));
        $recipient = $email['to'] ?? ($email['recipient'] ?? ($email['dest'] ?? ''));
        
        // Prioridade: 'dstatus' (comum em versões antigas), depois 'status', etc.
        $rawStatus = '';
        if (isset($email['dstatus'])) {
            $rawStatus = $email['dstatus'];
        } elseif (isset($email['status'])) {
            $rawStatus = $email['status'];
        } elseif (isset($email['action'])) {
            $rawStatus = $email['action'];
        } elseif (isset($email['verdict'])) {
            $rawStatus = $email['verdict'];
        } else {
            if (!$warnedStatus) {
                $availableKeys = implode(', ', array_keys($email));
                logMsg("Aviso: campo de status não encontrado. Chaves disponíveis: " . $availableKeys);
                $warnedStatus = true;
            }
        }
        
        $status = $statusMap[$rawStatus] ?? $rawStatus;
        
        // Domínio a partir do destinatário
        $domain = '';
        if (strpos($recipient, '@') !== false) {
            $parts = explode('@', $recipient);
            $domain = strtolower(trim($parts[1]));
        }
        
        // Timestamp
        $timestamp = null;
        if (isset($email['time'])) {
            $timestamp = date('Y-m-d H:i:s', (int)$email['time']);
        } elseif (isset($email['timestamp'])) {
            $ts = is_numeric($email['timestamp']) ? (int)$email['timestamp'] : strtotime($email['timestamp']);
            $timestamp = date('Y-m-d H:i:s', $ts);
        } else {
            $timestamp = date('Y-m-d H:i:s'); // fallback
        }
        
        try {
            $stmt->execute([
                ':id'         => (string)$emailId,
                ':sender'     => substr($sender, 0, 255),
                ':recipient'  => substr($recipient, 0, 255),
                ':domain'     => substr($domain, 0, 255),
                ':status'     => substr($status, 0, 50),
                ':timestamp'  => $timestamp,
                ':raw_data'   => json_encode($email, JSON_UNESCAPED_UNICODE)
            ]);
            if ($stmt->rowCount() > 0) $count++;
        } catch (PDOException $e) {
            logMsg("Erro ao processar email_id {$emailId}: " . $e->getMessage());
        }
    }
    return $count;
}

function logMsg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

?>
