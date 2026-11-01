<?php
/**
 * Endpoint per il bot: sincronizza utenti, ruoli e matricole.
 *
 * POST  /api/bot_sync.php
 * Header:  Authorization: Bearer <BOT_TOKEN>      (oppure  X-Bot-Token: <BOT_TOKEN>)
 * Body JSON:
 * {
 *   "deactivate_missing": false,          // true = disattiva chi non e' nell'elenco (solo account non "manuali")
 *   "users": [
 *     { "discord_id": "123", "nome": "Francesco", "cognome": "Papa",
 *       "matricola": "AS-12", "grado": "...", // facoltativi, usati solo alla creazione dell'account
 *       "roles": ["ID_RUOLO_1", "ID_RUOLO_2"], // ID dei ruoli Discord che la persona ha
 *       "reset_password": false }              // true = genera una nuova password
 *   ]
 * }
 * Risposta: { ok, created:[{discord_id,username,password}], passwords:[...], updated, deactivated }
 */
declare(strict_types=1);
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}
if (BOT_TOKEN === '' || str_starts_with(BOT_TOKEN, 'CAMBIA')) {
    out(['ok' => false, 'error' => 'BOT_TOKEN non configurato'], 503);
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
    out(['ok' => false, 'error' => 'Non autorizzato'], 401);
}

$raw = defined('BOT_TEST_INPUT') ? BOT_TEST_INPUT : (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['users']) || !is_array($body['users'])) {
    out(['ok' => false, 'error' => 'JSON non valido: manca "users"'], 400);
}
if (count($body['users']) > 3000) {
    out(['ok' => false, 'error' => 'Troppi utenti in una sola richiesta'], 413);
}

/** Ruolo del sito col livello piu' alto + abilitazione Centrale Operativa (ROLE_MAP valore 'co'). Ritorna [ruolo|null, co] */
function map_roles(array $discordRoles): array
{
    $best = null;
    $lvl = -1;
    $co = false;
    foreach ($discordRoles as $rid) {
        $key = ROLE_MAP[(string)$rid] ?? null;
        if ($key === 'co') {
            $co = true;
            continue;
        }
        if ($key !== null && isset(ROLES[$key]) && ROLES[$key]['level'] > $lvl) {
            $best = $key;
            $lvl = ROLES[$key]['level'];
        }
    }
    if ($best === null && $co) {
        $best = 'centralinista';   // chi ha solo il ruolo CO riceve l'account da centralinista
    }
    return [$best, $co];
}

$users = col('users');
$created = [];
$passwords = [];
$updated = 0;
$deactivated = 0;
$skipped = [];
$seen = [];

try {
    foreach ($body['users'] as $u) {
        if (!is_array($u)) {
            continue;
        }
        $did = preg_replace('/\D/', '', (string)($u['discord_id'] ?? ''));
        if ($did === '') {
            continue;
        }
        $seen[] = $did;
        [$role, $isCo] = map_roles((array)($u['roles'] ?? []));
        $nome = trim(mb_substr((string)($u['nome'] ?? ''), 0, 60));
        $cognome = trim(mb_substr((string)($u['cognome'] ?? ''), 0, 60));
        $matr = isset($u['matricola']) ? trim(mb_substr((string)$u['matricola'], 0, 20)) : '';
        $grado = isset($u['grado']) ? trim(mb_substr((string)$u['grado'], 0, 80)) : '';
        $reset = !empty($u['reset_password']);

        $ex = find_one_by('users', ['discord_id' => $did]);

        if ($ex) {
            if (empty($ex['locked'])) {
                if ($role === null) {
                    if (!empty($ex['attivo'])) {
                        $users->updateOne(id_filter($ex['id']), ['$set' => ['attivo' => false]]);
                        $deactivated++;
                    }
                } else {
                    // nome, cognome, matricola e grado li gestiscono gli amministrativi dal sito: il bot non li tocca
                    $users->updateOne(id_filter($ex['id']), ['$set' => ['ruolo' => $role, 'attivo' => true, 'co' => $isCo]]);
                    $updated++;
                }
            }
            if ($reset) {
                $pw = random_password();
                $users->updateOne(id_filter($ex['id']), ['$set' => ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_pw' => true]]);
                $passwords[] = ['discord_id' => $did, 'username' => $ex['username'], 'password' => $pw];
            }
            continue;
        }

        // nuova persona
        if ($role === null) {
            continue; // nessun ruolo riconosciuto: non crea l'account
        }
        if ($nome === '') {
            $skipped[] = $did;
            continue;
        }
        $pw = random_password();
        $c = create_user($nome, $cognome, $role, $matr !== '' ? $matr : null, $did, $pw, false, $grado !== '' ? $grado : null, $isCo);
        $created[] = ['discord_id' => $did, 'username' => $c['username'], 'password' => $pw, 'ruolo' => $role];
    }

    if (!empty($body['deactivate_missing']) && $seen) {
        $r = $users->updateMany(
            ['discord_id' => ['$type' => 'string', '$nin' => $seen], 'locked' => false, 'attivo' => true],
            ['$set' => ['attivo' => false]]
        );
        $deactivated += (int)$r->getModifiedCount();
    }
} catch (Throwable $ex) {
    error_log('bot_sync: ' . $ex->getMessage());
    out(['ok' => false, 'error' => 'Errore interno'], 500);
}

out([
    'ok' => true, 'created' => $created, 'passwords' => $passwords,
    'updated' => $updated, 'deactivated' => $deactivated, 'skipped' => $skipped,
]);
