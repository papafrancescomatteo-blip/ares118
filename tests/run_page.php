<?php
/**
 * Esegue una pagina da riga di comando simulando una richiesta (SOLO TEST, da una copia temporanea).
 * uso: php tests/run_page.php pagina.php '{"get":{},"post":{},"sid":"...","ip":"1.2.3.4","body":"..."}'
 */
$cfg = json_decode($argv[2] ?? '{}', true) ?: [];
chdir(dirname(__DIR__));
$_GET = $cfg['get'] ?? [];
$_POST = $cfg['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? ($_POST ? 'POST' : 'GET');
$_SERVER['REMOTE_ADDR'] = $cfg['ip'] ?? '10.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/' . $argv[1];
foreach ($cfg['server'] ?? [] as $k => $v) { $_SERVER[$k] = $v; }
if (!empty($cfg['sid'])) { $_COOKIE['ares118sid'] = $cfg['sid']; }
if (isset($cfg['body'])) { define('BOT_TEST_INPUT', $cfg['body']); }
session_save_path(sys_get_temp_dir());
ob_start();
register_shutdown_function(static function () use ($argv) {
    $html = ob_get_clean();
    $loc = '';
    foreach (headers_list() as $h) { if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); } }
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html))));
    echo json_encode([
        'status' => http_response_code() ?: 200,
        'location' => $loc,
        'sid' => session_id(),
        'text' => mb_substr($text, 0, (int)(getenv('SHOW') ?: 400)),
        'html' => getenv('RAW') ? $html : null,
    ], JSON_UNESCAPED_UNICODE) . "\n";
});
require $argv[1];
