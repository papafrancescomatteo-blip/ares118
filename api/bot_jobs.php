<?php
/**
 * Lavori che il sito chiede al bot (oggi: invio delle credenziali in DM).
 *
 * POST /api/bot_jobs.php   (stesso token di bot_sync.php)
 * Body JSON:
 *   {"action":"peek"}                                    -> {pending: true|false}
 *   {"action":"claim"}                                   -> {job_id, items:[{discord_id, nome, username, password}]}
 *   {"action":"report","job_id":"..","sent":[ids],"failed":[ids],"errors":{id:motivo}}
 *
 * "claim" genera una nuova password temporanea per ogni destinatario: il bot la manda in DM e poi fa "report".
 */
declare(strict_types=1);
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bot_auth.php';

$body = bot_require_auth();
$action = (string)($body['action'] ?? '');
$jobs = col('jobs');
$users = col('users');

// un lavoro "in corso" da oltre 10 minuti e' rimasto a meta': torna in attesa
$jobs->updateMany(['tipo' => 'credenziali', 'stato' => 'in_corso', 'claimed_at' => ['$lt' => time() - 600]], ['$set' => ['stato' => 'in_attesa']]);

function next_job(): ?array
{
    $rows = find_all('jobs', ['tipo' => 'credenziali', 'stato' => 'in_attesa'], ['sort' => ['creato_at' => 1], 'limit' => 1]);
    return $rows[0] ?? null;
}

try {
    if ($action === 'peek') {
        bot_out(['ok' => true, 'pending' => next_job() !== null]);
    }

    if ($action === 'claim') {
        $job = next_job();
        if (!$job) {
            bot_out(['ok' => true, 'job_id' => null, 'items' => []]);
        }
        $target = (string)($job['target'] ?? 'nuovi');
        $f = ['attivo' => true];
        if ($target === 'nuovi') {
            $f += ['cred_sent_at' => null, 'must_change_pw' => true];
        } elseif ($target !== 'tutti') {
            $f += id_filter($target);
        }
        $items = [];
        $ids = [];
        foreach (find_all('users', $f) as $u) {
            $did = (string)($u['discord_id'] ?? '');
            if ($did === '') {
                continue;
            }
            $pw = random_password();
            $users->updateOne(id_filter($u['id']), ['$set' => ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_pw' => true]]);
            $items[] = ['discord_id' => $did, 'nome' => (string)$u['nome'], 'username' => (string)$u['username'], 'password' => $pw];
            $ids[$did] = full_name($u);
        }
        $jobs->updateOne(id_filter($job['id']), ['$set' => ['stato' => 'in_corso', 'claimed_at' => time(), 'destinatari' => $ids]]);
        bot_out(['ok' => true, 'job_id' => $job['id'], 'items' => $items]);
    }

    if ($action === 'report') {
        $job = find_one_by('jobs', id_filter((string)($body['job_id'] ?? '')));
        if (!$job) {
            bot_out(['ok' => false, 'error' => 'Lavoro non trovato'], 404);
        }
        $sent = array_values(array_filter(array_map(static fn($x) => preg_replace('/\D/', '', (string)$x), (array)($body['sent'] ?? []))));
        $failed = array_values(array_filter(array_map(static fn($x) => preg_replace('/\D/', '', (string)$x), (array)($body['failed'] ?? []))));
        if ($sent) {
            $users->updateMany(['discord_id' => ['$in' => $sent]], ['$set' => ['cred_sent_at' => time()]]);
        }
        $dest = (array)($job['destinatari'] ?? []);
        $why = (array)($body['errors'] ?? []);
        $failedNames = [];
        foreach ($failed as $did) {
            $reason = trim(mb_substr((string)($why[$did] ?? ''), 0, 80));
            $failedNames[] = (string)($dest[$did] ?? $did) . ($reason !== '' ? ' (' . $reason . ')' : '');
        }
        $jobs->updateOne(id_filter($job['id']), ['$set' => [
            'stato' => 'completato', 'completato_at' => time(), 'inviati' => count($sent), 'falliti' => $failedNames,
        ]]);
        bot_out(['ok' => true]);
    }

    bot_out(['ok' => false, 'error' => 'Azione non valida'], 400);
} catch (Throwable $ex) {
    error_log('bot_jobs: ' . $ex->getMessage());
    bot_out(['ok' => false, 'error' => 'Errore interno'], 500);
}
