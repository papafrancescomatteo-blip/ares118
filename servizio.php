<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
csrf_check();

$action = post_str('action');
$shift = open_shift($user['id']);
$shifts = col('shifts');

if ($action === 'start') {
    // all'avvio si sceglie il mezzo (equipaggio) e il ruolo a bordo, oppure la Centrale Operativa
    [$ok, $msg] = try_start_shift($user, post_str('mezzo'), post_str('ruolo_eq'));
    flash($msg, $ok ? 'ok' : 'err');
} elseif ($action === 'pause') {
    if (!$shift) {
        flash('Non sei in servizio.', 'err');
    } elseif ($shift['pause_started']) {
        $shifts->updateOne(id_filter($shift['id']), ['$set' => [
            'pause_seconds' => (int)$shift['pause_seconds'] + max(0, time() - (int)$shift['pause_started']),
            'pause_started' => null,
        ]]);
        flash('Servizio ripreso.');
    } else {
        $shifts->updateOne(id_filter($shift['id']), ['$set' => ['pause_started' => time()]]);
        flash('Servizio in pausa.');
    }
} elseif ($action === 'end') {
    if (!$shift) {
        flash('Non sei in servizio.', 'err');
    } elseif ($block = can_end_shift($shift)) {
        flash($block, 'err');
    } else {
        $pause = (int)$shift['pause_seconds'] + ($shift['pause_started'] ? max(0, time() - (int)$shift['pause_started']) : 0);
        $shifts->updateOne(id_filter($shift['id']), ['$set' => [
            'pause_seconds' => $pause, 'pause_started' => null, 'end_at' => time(),
        ]]);
        flash('Servizio terminato.');
    }
}
redirect('dashboard.php');
