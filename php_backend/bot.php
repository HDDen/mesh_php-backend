<?php

// ----------------------------
// FILE: bot.php (monolithic backend)
// ----------------------------
// This is the only runtime file required (besides config.php). Drop both files into the same folder.
// It exposes several endpoints through the single entry point (this file):
// - Telegram webhook (configured to BOT_WEBHOOK_URL) — receives updates and stores messages
// - External fetch endpoint: ?action=get_messages&token=... (returns undelivered messages and marks them sent)
// - External send endpoint: ?action=send_message&token=... (POST JSON: {"msg":"...","channel_id":"..."})
// - Optional manual webhook re-set: ?action=set_webhook&token=...
//
// Storage format: JSON files in DATA_DIR. All reads/writes use file locks and atomic writes.
/**
 * Можно вручную установить вебхук:
 * curl -X POST "https://api.telegram.org/bot<ВАШ_BOT_TOKEN>/setWebhook" -d "url=https://example.ru/telegram/meshTgBot/bot.php?token=TG_SUBSCRIBE_TOKEN"
 * 
 * Или создать задание в cron для подписки каждые n минут
 * curl https://example.ru/telegram/meshTgBot/bot.php?action=set_webhook&token=ADMIN_TOKEN
 */

// Immediately require configuration
require_once dirname(__FILE__).'/config.php'; // this file contains config constants above

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to create data directory: ' . DATA_DIR]);
        exit;
    }
}

// Simple helper: safe atomic write of JSON / text
function atomic_file_put_contents(string $path, string $data): bool {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $data) === false) return false;
    // Try to set restrictive permissions
    @chmod($tmp, 0640);
    return rename($tmp, $path);
}

// Safe JSON read that returns an array
// Special handling: messages file is stored as PHP-protected file where the first line is
// "<?php die(); /*". When reading, we strip that first line before JSON decoding.
function read_json_file(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    // shared lock
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Remove the protective PHP header if present
    $protect = "<?php die(); /*";
    if (strpos($content, $protect) === 0) {
        // remove header and optional following newline
        $content = substr($content, strlen($protect));
        if (strpos($content, "
") === 0) {
            $content = substr($content, 1);
        }
    }

    $json = json_decode($content, true);
    return is_array($json) ? $json : $default;
}

// Safe JSON write with exclusive lock
// When writing to messages file we prepend the protective header as the first line.
function write_json_file(string $path, $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    // Prepend protective header exactly as requested
    $payload = "<?php die(); /*
" . ($json === false ? '' : $json);
    return atomic_file_put_contents($path, $payload);
}

// Append message to storage (thread-safe)
function store_message(array $msg) {
    $messages = read_json_file(MESSAGES_FILE, []);
    $messages[] = $msg;
    return write_json_file(MESSAGES_FILE, $messages);
}

// Mark messages as delivered by their internal ids (indexes)
function mark_messages_delivered(array $indexes) {
    $messages = read_json_file(MESSAGES_FILE, []);
    foreach ($indexes as $i) {
        if (isset($messages[$i])) {
            $messages[$i]['delivered'] = true;
            $messages[$i]['delivered_at'] = time();
        }
    }
    return write_json_file(MESSAGES_FILE, $messages);
}

// Mark ALL messages as delivered (protected admin operation)
function mark_all_messages_delivered(): bool {
    $messages = read_json_file(MESSAGES_FILE, []);
    $now = time();
    foreach ($messages as &$m) {
        $m['delivered'] = true;
        if (!isset($m['delivered_at'])) {
            $m['delivered_at'] = $now;
        }
    }
    unset($m);
    return write_json_file(MESSAGES_FILE, $messages);
}

// Delete messages file completely (admin operation)
function delete_messages_file(): bool {
    if (!file_exists(MESSAGES_FILE)) {
        return true; // already deleted
    }
    return unlink(MESSAGES_FILE);
}

// Prepare messages for external consumption and return their indexes
function get_undelivered_messages_for_output(): array {
    $messages = read_json_file(MESSAGES_FILE, []);
    $out = [];
    $indexes = [];
    foreach ($messages as $i => $m) {
        if (!isset($m['delivered']) || $m['delivered'] !== true) {
            $name = $m['username'] ?? null;
            if (empty($name)) {
                $nameParts = array_filter([$m['first_name'] ?? null, $m['last_name'] ?? null]);
                $name = $nameParts ? implode(' ', $nameParts) : 'Unknown';
            }
            $ts = $m['date_ts'] ?? time();
            $out[] = [
                'name' => $name,
                'date' => date('d.m H:i', $ts), // required format d.m H:i
                'msg' => $m['text'] ?? ''
            ];
            $indexes[] = $i;
        }
    }
    return ['messages' => $out, 'indexes' => $indexes];
}

// Make a request to Telegram API
function telegram_api_request(string $method, array $params = []) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => $err];
    $json = json_decode($resp, true);
    return is_array($json) ? $json : ['ok' => false, 'raw' => $resp];
}

// Set webhook to BOT_WEBHOOK_URL
function set_telegram_webhook(): array {
    $params = ['url' => BOT_WEBHOOK_URL.TG_SUBSCRIBE_TOKEN];
    return telegram_api_request('setWebhook', $params);
}

// Check webhook timestamp and set if needed (once per WEBHOOK_INTERVAL seconds)
function ensure_webhook_recent() {
    $lastSet = 0;
    if (file_exists(WEBHOOK_TIMESTAMP_FILE)) {
        $ts = @file_get_contents(WEBHOOK_TIMESTAMP_FILE);
        $lastSet = (int)$ts;
    }
    $now = time();
    if (($now - $lastSet) >= WEBHOOK_INTERVAL) {
        $res = set_telegram_webhook();
        // Record timestamp even if Telegram returned error to avoid hammering; you can change this behaviour.
        @file_put_contents(WEBHOOK_TIMESTAMP_FILE . '.tmp', (string)$now);
        @rename(WEBHOOK_TIMESTAMP_FILE . '.tmp', WEBHOOK_TIMESTAMP_FILE);
        return $res;
    }
    return ['ok' => true, 'info' => 'webhook still fresh'];
}

// Handle incoming Telegram update (webhook)
function handle_telegram_update(array $update) {
    // Only handle message updates (text). Expand as needed for other types.
    if (isset($update['message'])) {
        $m = $update['message'];
        $text = null;
        if (isset($m['text'])) {
            $text = $m['text'];
        } elseif (isset($m['caption'])) {
            // for media with caption
            $text = 'Картинка с подписью: '.$m['caption'];
        } elseif (isset($m['voice'])){
            $text = "Голосовое сообщение на ".(!empty($m['voice']['duration']) ? $m['voice']['duration'] : '?').' сек.';
        } elseif (isset($m['photo'])){
            $text = "Отправлено изображение";
        } elseif (isset($m['video'])){
            $text = "Отправлено видео на ".(!empty($m['video']['duration']) ? $m['video']['duration'] : '?').' сек.';
        }
        // skip if no text
        if ($text === null) return ['stored' => false, 'reason' => 'undefined msg type'];

        $stored = [
            'message_id' => $m['message_id'] ?? null,
            'chat_id' => $m['chat']['id'] ?? null,
            'text' => $text,
            'username' => $m['from']['username'] ?? null,
            'first_name' => $m['from']['first_name'] ?? null,
            'last_name' => $m['from']['last_name'] ?? null,
            'date_ts' => $m['date'] ?? time(), // Telegram provides unix timestamp
            'delivered' => false
        ];
        $ok = store_message($stored);
        return ['stored' => $ok];
    }
    return ['ignored' => true];
}

// Protected endpoint: get undelivered messages and mark them delivered
function endpoint_get_messages() {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    if (!hash_equals(EXTERNAL_ACCESS_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (!is_allowed_by_ip()){
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden by IP']);
        exit;
    }

    $res = get_undelivered_messages_for_output();
    // mark as delivered by indexes
    if (!empty($res['indexes'])) {
        mark_messages_delivered($res['indexes']);
    }
    echo json_encode(['messages' => $res['messages']]);
    exit;
}

// Protected endpoint: accept JSON POST {msg, channel_id} and send via Telegram
function endpoint_send_message() {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    if (!hash_equals(EXTERNAL_ACCESS_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (!is_allowed_by_ip()){
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden by IP']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['msg']) || empty($data['channel_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request, expected JSON with msg and channel_id']);
        exit;
    }

    $params = [
        'chat_id' => $data['channel_id'],
        'text' => $data['msg']
    ];
    // Basic options: parse_mode can be added, etc.
    $result = telegram_api_request('sendMessage', $params);
    echo json_encode($result);
    exit;
}

// Optional endpoint: manual webhook set
function endpoint_set_webhook() {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(ADMIN_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $res = set_telegram_webhook();
    // update timestamp on success (or always to avoid hammering)
    @file_put_contents(WEBHOOK_TIMESTAMP_FILE, (string)time());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res);
    exit;
}

// Protected admin endpoint: mark all messages as delivered
function endpoint_mark_all_delivered() {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    if (!hash_equals(ADMIN_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (!is_allowed_by_ip()){
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden by IP']);
        exit;
    }

    $ok = mark_all_messages_delivered();
    echo json_encode(['ok' => $ok]);
    exit;
}

// Protected admin endpoint: delete messages file
function endpoint_delete_messages() {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    if (!hash_equals(ADMIN_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (!is_allowed_by_ip()){
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden by IP']);
        exit;
    }

    $ok = delete_messages_file();
    echo json_encode(['ok' => $ok]);
    exit;
}

function is_allowed_by_ip(){
    $result = true; // дефолт

    if (defined("ALLOWED_IP") && !empty(ALLOWED_IP)){
        $result = false; // если задан массив разрешенных адресов

        if (empty($_SERVER['REMOTE_ADDR'])){
            $result = false; // если не удалось прочесть адрес клиента
        } else {
            if (in_array($_SERVER['REMOTE_ADDR'], ALLOWED_IP)){
                $result = true; // адрес прочесть удалось, и он есть в списке разрешенных
            } else {
                $result = false; // удалось прочесть, но в списке нет
            }
        }
    }

    return $result;
}

// Router: determine action based on query param or incoming webhook
$action = $_GET['action'] ?? null;

// Always attempt to ensure webhook is set no more frequently than WEBHOOK_INTERVAL.
// This satisfies requirement 1: "раз в 30 минут обращаться к telegram и подписывать себя на вебхук"
// We perform this check on every request to the script (webhook hits from Telegram themselves, or external calls).
$webhookEnsureResult = ensure_webhook_recent();
// (We do not expose it to the client unless needed)

if ($action === 'get_messages') {
    endpoint_get_messages();
}

if ($action === 'send_message') {
    endpoint_send_message();
}

if ($action === 'set_webhook') {
    endpoint_set_webhook();
}

if ($action === 'mark_all_delivered') {
    endpoint_mark_all_delivered();
}

if ($action === 'delete_messages') {
    endpoint_delete_messages();
}

// If this is a POST from Telegram (webhook), Telegram will POST JSON to this script
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = file_get_contents('php://input');
if ($method === 'POST' && !empty($rawInput)) {
    $token = $_GET['token'] ?? '';
    if (hash_equals(TG_SUBSCRIBE_TOKEN, $token)) {
        // Try to decode as JSON — Telegram sends JSON updates
        $update = json_decode($rawInput, true);
        if (is_array($update)) {
            $res = handle_telegram_update($update);
            // reply 200 OK
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'result' => $res]);
            exit;
        }
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// Default: show a small HTML status for browser visits
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Telegram bot backend</title>
<style>body{font-family:Arial,Helvetica,sans-serif;line-height:1.5;padding:20px;color:#222}</style>
</head>
<body>
<h2>Telegram bot backend</h2>
<p>Этот скрипт обслуживает webhook Telegram и внешние защищённые эндпоинты.</p>
<ul>
<li><strong>Webhook URL (для BotFather):</strong> <?php echo htmlspecialchars(BOT_WEBHOOK_URL).'TG_SUBSCRIBE_TOKEN'; ?></li>
<li><strong>Получить неотданные сообщения (GET):</strong> <code>?action=get_messages&token=YOUR_TOKEN</code></li>
<li><strong>Отправить сообщение (POST JSON):</strong> <code>?action=send_message&token=YOUR_TOKEN</code></li>
<li><strong>Вручную установить webhook (GET):</strong> <code>?action=set_webhook&token=YOUR_ADMIN_TOKEN</code></li>
<li><strong>Пометить все сообщения доставленными (GET):</strong> <code>?action=mark_all_delivered&token=YOUR_ADMIN_TOKEN</code></li>
<li><strong>Удалить файл сообщений (GET):</strong> <code>?action=delete_messages&token=YOUR_ADMIN_TOKEN</code></li>
</ul>
</body>
</html>
