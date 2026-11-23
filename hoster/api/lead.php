<?php
// Приём заявок с сайта на hoster.kz (PHP 7.2+). Аналог worker.js на Cloudflare:
// POST /api/lead.php (JSON) → { "ok": true } или { "ok": false, "error": "..." }.
// Настройки — в config.php (копия config.sample.php). Без них отвечает { ok: false, error: "not_configured" } (HTTP 200):
// это штатный режим — сайт тогда показывает кнопки «WhatsApp / почта / позвонить» с готовым текстом заявки.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DIRS = ['valuation' => 'Оценка', 'replan' => 'Перепланировка', 'deals' => 'Риелтор'];

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// $oneLine = true: имя, телефон и время — одна строка (переводы строк превращаются в пробелы)
function clip($v, $n, $oneLine = false)
{
    $s = trim((string) ($v === null ? '' : $v));
    if ($oneLine) {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

function text_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

// Не больше 20 заявок за 10 минут с одного адреса: защита от потока мусора.
function rate_limited($limit = 20, $window = 600)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $file = sys_get_temp_dir() . '/mip-lead-' . md5($ip) . '.json';
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $times = json_decode((string) stream_get_contents($fh), true);
    $times = is_array($times) ? array_values(array_filter($times, function ($t) use ($now, $window) {
        return $t > $now - $window;
    })) : [];
    $blocked = count($times) >= $limit;
    if (!$blocked) {
        $times[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($times));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $blocked;
}

function send_mail($to, $from, $subject, $body)
{
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('Сайт mip.com.kz') . '?= <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $from);
}

function send_telegram($token, $chat, $text)
{
    $payload = json_encode(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE);
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $res !== false && $code === 200;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false && strpos($res, '"ok":true') !== false;
}

function write_log($file, $row)
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'method'], 405);
}

// Принимаем заявки только со своего сайта
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '') {
    $oh = (string) parse_url($origin, PHP_URL_HOST);
    $op = parse_url($origin, PHP_URL_PORT);
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (strcasecmp($oh . ($op ? ':' . $op : ''), $host) !== 0) {
        respond(['ok' => false, 'error' => 'origin'], 403);
    }
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    respond(['ok' => false, 'error' => 'bad_json'], 400);
}

$name = clip(isset($body['name']) ? $body['name'] : '', 80, true);
$phone = clip(isset($body['phone']) ? $body['phone'] : '', 40, true);
$time = clip(isset($body['time']) ? $body['time'] : '', 40, true);
$comment = clip(isset($body['comment']) ? $body['comment'] : '', 1500);
$dir = (isset($body['dir']) && is_string($body['dir']) && isset(DIRS[$body['dir']])) ? $body['dir'] : 'valuation';
if (text_len($name) < 2 || strlen(preg_replace('/\D/', '', $phone)) < 10) {
    respond(['ok' => false, 'error' => 'invalid'], 400);
}

$cfg = is_file(__DIR__ . '/config.php') ? (array) include __DIR__ . '/config.php' : [];
$emailTo = isset($cfg['email_to_' . $dir]) ? $cfg['email_to_' . $dir] : (isset($cfg['email_to']) ? $cfg['email_to'] : '');
$emailFrom = isset($cfg['email_from']) ? $cfg['email_from'] : '';
$tgToken = isset($cfg['tg_token']) ? $cfg['tg_token'] : '';
$tgChat = isset($cfg['tg_chat_' . $dir]) ? $cfg['tg_chat_' . $dir] : (isset($cfg['tg_chat']) ? $cfg['tg_chat'] : '');
$useMail = filter_var($emailTo, FILTER_VALIDATE_EMAIL) && filter_var($emailFrom, FILTER_VALIDATE_EMAIL);
$useTg = $tgToken !== '' && $tgChat !== '';

// 200, а не 503: это штатный режим «отправьте сами», а не сбой
if (!$useMail && !$useTg) {
    respond(['ok' => false, 'error' => 'not_configured']);
}

if (rate_limited()) {
    respond(['ok' => false, 'error' => 'rate'], 429);
}

$lines = ['Заявка с сайта — ' . DIRS[$dir], 'Имя: ' . $name, 'Телефон: ' . $phone];
if ($time !== '') {
    $lines[] = 'Удобное время: ' . $time;
}
if ($comment !== '') {
    $lines[] = 'Вопрос: ' . $comment;
}
$text = implode("\n", $lines);

// Журнал на сервере — основная копия заявок (папка private рядом с httpdocs; если её нет — api/data)
$logFile = isset($cfg['log_file']) ? $cfg['log_file'] : '';
if ($logFile === '') {
    $priv = dirname(__DIR__, 2) . '/private';
    $logFile = (is_dir($priv) && is_writable($priv)) ? $priv . '/leads.log' : __DIR__ . '/data/leads.log';
}
write_log($logFile, ['date' => date('c'), 'dir' => $dir, 'name' => $name, 'phone' => $phone, 'time' => $time, 'comment' => $comment]);

$sent = false;
if ($useMail && send_mail($emailTo, $emailFrom, 'Заявка с сайта — ' . DIRS[$dir], $text)) {
    $sent = true;
}
if ($useTg && send_telegram($tgToken, $tgChat, $text)) {
    $sent = true;
}
respond($sent ? ['ok' => true] : ['ok' => false, 'error' => 'delivery'], $sent ? 200 : 502);
