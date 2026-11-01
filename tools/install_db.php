<?php
/**
 * Prepara il database all'avvio del container: crea gli indici e, se non esiste nessun utente,
 * crea l'account "Admin ARES".
 * Password iniziale: variabile ADMIN_PASSWORD, altrimenti ne viene generata una e scritta nei log di Render.
 * Solo da riga di comando.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('NO_SESSION', true);
require __DIR__ . '/../includes/bootstrap.php';

$tries = 0;
while (true) {
    try {
        ensure_indexes();
        break;
    } catch (Throwable $e) {
        if (++$tries >= 5) {
            fwrite(STDERR, "[install] Database non raggiungibile: " . $e->getMessage() . "\n");
            exit(0); // il sito parte comunque; mostrera' errore finche' il database non e' configurato
        }
        sleep(3);
    }
}

$n = seed_mezzi();
if ($n > 0) {
    echo "[install] Creati $n mezzi iniziali.\n";
}

if (col('users')->countDocuments([]) === 0) {
    $given = env_or('ADMIN_PASSWORD');
    $pw = $given !== '' ? $given : random_password(14);
    create_user('Admin', 'ARES', 'direttore', 'AS-00', null, $pw, true, 'Amministratore');
    echo "[install] Creato utente 'Admin ARES'" . ($given !== '' ? '' : " con password temporanea: $pw") . " (va cambiata al primo accesso)\n";
}
echo "[install] Database pronto.\n";
