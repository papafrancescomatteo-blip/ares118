<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
csrf_check();

$back = in_array(post_str('back'), ['dashboard.php', 'centralino.php'], true) ? post_str('back') : 'dashboard.php';
$action = post_str('action');
$id = post_str('id');
$isCo = can('centralino', $user);
$col = col('assegnazioni');

if ($action === 'avanza') {
    // L'equipaggio del mezzo (o la Centrale Operativa) aggiorna lo stato dell'intervento
    $a = $id !== '' ? find_one_by('assegnazioni', id_filter($id)) : null;
    $shift = open_shift($user['id']);
    $mine = $a && $shift && !empty($shift['mezzo_id']) && $shift['mezzo_id'] === $a['mezzo_id'];
    $mz = $mine ? find_mezzo_in(board_data(), $a['mezzo_id']) : null;
    $lead = $mz ? crew_lead($mz['crew']) : null;
    $to = post_str('to');
    if (!$a || (!$mine && !$isCo)) {
        flash('Non puoi aggiornare questo intervento.', 'err');
    } elseif (!$isCo && !is_crew_lead($user, $mz)) {
        flash('Gli interventi li gestisce il capo equipaggio' . ($lead ? ' (<b>' . e($lead['nome']) . '</b>)' : '') . '.', 'err');
    } elseif (!in_array($to, TRANSIZIONI[$a['stato']] ?? [], true)) {
        flash('Lo stato dell\'intervento è già cambiato: la pagina è stata aggiornata.', 'err');
    } else {
        $col->updateOne(id_filter($a['id']), ['$set' => ['stato' => $to, $to . '_at' => time()]]);
        refresh_call($a['chiamata_id']);
        flash('Stato aggiornato: ' . e(STATI_ASSEGNAZIONE[$to]) . '.');
    }
} elseif (!$isCo) {
    flash('Non sei abilitato alla Centrale Operativa.', 'err');
    $back = 'dashboard.php';
} elseif ($action === 'assegna') {
    $call = $id !== '' ? find_one_by('chiamate', id_filter($id)) : null;
    if (!$call) {
        flash('Chiamata non trovata.', 'err');
    } else {
        $err = assign_mezzo($call, post_str('mezzo'), $user);
        $err ? flash($err, 'err') : flash('Mezzo assegnato alla chiamata ' . e($call['numero']) . '.');
    }
} elseif ($action === 'richiama') {
    $a = $id !== '' ? find_one_by('assegnazioni', id_filter($id)) : null;
    if (!$a || !in_array($a['stato'], ATTIVE, true)) {
        flash('Assegnazione non più attiva.', 'err');
    } else {
        $col->updateOne(id_filter($a['id']), ['$set' => ['stato' => 'annullata', 'annullata_at' => time()]]);
        refresh_call($a['chiamata_id']);
        flash(e($a['mezzo_sigla']) . ' richiamato dalla chiamata ' . e($a['chiamata_numero']) . '.');
    }
} elseif ($action === 'annulla_chiamata') {
    $call = $id !== '' ? find_one_by('chiamate', id_filter($id)) : null;
    if (!$call || !in_array($call['stato'], ['in_attesa', 'in_corso'], true)) {
        flash('Chiamata non più attiva.', 'err');
    } else {
        $col->updateMany(['chiamata_id' => $call['id'], 'stato' => ['$in' => ATTIVE]], ['$set' => ['stato' => 'annullata', 'annullata_at' => time()]]);
        col('chiamate')->updateOne(id_filter($call['id']), ['$set' => ['stato' => 'annullata', 'chiusa_at' => time()]]);
        flash('Chiamata ' . e($call['numero']) . ' annullata: i mezzi sono di nuovo liberi.');
    }
}
redirect($back);
