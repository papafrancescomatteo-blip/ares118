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
require_once __DIR__ . '/../includes/bot_auth.php';

$body = bot_require_auth();
if (!isset($body['users']) || !is_array($body['users'])) {
    bot_out(['ok' => false, 'error' => 'JSON non valido: manca "users"'], 400);
}
if (count($body['users']) > 3000) {
    bot_out(['ok' => false, 'error' => 'Troppi utenti in una sola richiesta'], 413);
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
$pending = 0;
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
                    // nome, cognome, matricola e grado li gestiscono gli amministrativi dal sito: il bot non li tocca.
                    // Direttore, vice e coordinatori arrivano da Discord solo come "richiesta": li conferma l'amministratore.
                    $set = ['attivo' => true, 'co' => $isCo];
                    if ($role === $ex['ruolo']) {
                        $set['ruolo_richiesto'] = null;
                    } elseif (ruolo_da_confermare($role)) {
                        if (($ex['ruolo_rifiutato'] ?? null) !== $role) {
                            if (($ex['ruolo_richiesto'] ?? null) !== $role) {
                                $pending++;
                            }
                            $set['ruolo_richiesto'] = $role;
                        }
                    } else {
                        $set['ruolo'] = $role;
                        $set['ruolo_richiesto'] = null;
                    }
                    if ($role !== ($ex['ruolo_rifiutato'] ?? null)) {
                        $set['ruolo_rifiutato'] = null;
                    }
                    $users->updateOne(id_filter($ex['id']), ['$set' => $set]);
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
        // la password non viene comunicata qui: l'amministratore usa il pulsante "Invia credenziali" del sito
        $conferma = ruolo_da_confermare($role);
        $c = create_user($nome, $cognome, $conferma ? 'recluta' : $role, $matr !== '' ? $matr : null, $did, random_password(), false, $grado !== '' ? $grado : null, $isCo);
        if ($conferma) {
            $users->updateOne(id_filter($c['id']), ['$set' => ['ruolo_richiesto' => $role]]);
            $pending++;
        }
        $created[] = ['discord_id' => $did, 'username' => $c['username'], 'ruolo' => $conferma ? 'recluta' : $role, 'ruolo_richiesto' => $conferma ? $role : null];
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
    bot_out(['ok' => false, 'error' => 'Errore interno'], 500);
}

bot_out([
    'ok' => true, 'created' => $created, 'passwords' => $passwords,
    'updated' => $updated, 'da_confermare' => $pending, 'deactivated' => $deactivated, 'skipped' => $skipped,
]);
