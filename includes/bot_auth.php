<?php
/** Autenticazione e risposta JSON comuni agli endpoint del bot */

function bot_out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Annota quando il bot si e' fatto vivo (o ha sbagliato token): lo mostra la pagina Gestione personale */
function bot_heartbeat(string $field): void
{
    try {
        col('settings')->findOneAndUpdate(['_id' => 'bot'], ['$set' => [$field => time()]], ['upsert' => true]);
    } catch (Throwable $e) {
        error_log('bot_heartbeat: ' . $e->getMessage());
    }
}

/** Controlla metodo POST e token del bot; ritorna il corpo JSON gia' decodificato */
function bot_require_auth(): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bot_out(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }
    if (BOT_TOKEN === '' || str_starts_with(BOT_TOKEN, 'CAMBIA')) {
        bot_out(['ok' => false, 'error' => 'BOT_TOKEN non configurato'], 503);
    }
    // Token dall'header (alcuni server nascondono Authorization: per questo c'e' anche X-Bot-Token)
    $token = '';
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) {
        $token = trim(substr($h, 7));
    }
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_BOT_TOKEN'] ?? ''));
    }
    if (!hash_equals(BOT_TOKEN, $token)) {
        usleep(300000);
        bot_heartbeat('unauth_at');
        bot_out(['ok' => false, 'error' => 'Non autorizzato'], 401);
    }
    bot_heartbeat('seen_at');
    $raw = defined('BOT_TEST_INPUT') ? BOT_TEST_INPUT : (string)file_get_contents('php://input');
    $body = json_decode($raw, true);
    return is_array($body) ? $body : [];
}

/** Ruoli del sito che servono la conferma dell'amministratore quando arrivano da Discord */
function ruolo_da_confermare(string $ruolo): bool
{
    return in_array($ruolo, ['direttore', 'vice_direttore', 'coordinatore'], true);
}
