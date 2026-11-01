<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$all = (($_GET['scope'] ?? '') === 'tutti');
if ($all) {
    require_perm('personale', $user);
}
$page = max(1, (int)($_GET['p'] ?? 1));

$filter = $all ? [] : ['user_id' => $user['id']];
$total = (int)col('shifts')->countDocuments($filter);
$rows = find_all('shifts', $filter, ['sort' => ['start_at' => -1], 'skip' => ($page - 1) * PER_PAGE, 'limit' => PER_PAGE]);

// dati della persona per ogni turno (una sola query)
$people = [];
if ($all && $rows) {
    $ids = array_values(array_filter(array_map(static fn($r) => oid($r['user_id']), $rows)));
    foreach (find_all('users', ['_id' => ['$in' => $ids]]) as $p) {
        $people[$p['id']] = $p;
    }
    foreach ($rows as &$r) {
        $p = $people[$r['user_id']] ?? [];
        $r['nome'] = $p['nome'] ?? '?';
        $r['cognome'] = $p['cognome'] ?? '';
        $r['matricola'] = $p['matricola'] ?? '';
    }
    unset($r);
}

page_head('Storico shift', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1><?= $all ? 'Storico shift · tutto il personale' : 'Il mio storico shift' ?></h1>
  <p class="sub" style="margin:0"><?= $total ?> turni registrati</p></div>
</div>
<div class="tbl-wrap">
<table class="tbl">
  <tr><?php if ($all): ?><th>Operatore</th><th>Matricola</th><?php endif; ?><th>Data</th><th>Mezzo</th><th>Ruolo</th><th>Inizio</th><th>Fine</th><th>Pausa</th><th>Durata effettiva</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <?php if ($all): ?><td><?= e(trim($r['nome'] . ' ' . $r['cognome'])) ?></td><td><?= e($r['matricola']) ?></td><?php endif; ?>
    <td><?= e(date('d/m/Y', (int)$r['start_at'])) ?></td>
    <td><?= e($r['mezzo_sigla'] ?? '—') ?></td>
    <td><?= e($r['ruolo_eq'] ?? '—') ?></td>
    <td><?= e(date('H:i', (int)$r['start_at'])) ?></td>
    <td><?= $r['end_at'] ? e(date('H:i', (int)$r['end_at'])) : '<span class="badge on">' . ($r['pause_started'] ? 'In pausa' : 'In corso') . '</span>' ?></td>
    <td><?= e(fmt_duration((int)$r['pause_seconds'])) ?></td>
    <td><b><?= e(fmt_duration(shift_net_seconds($r))) ?></b></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td class="empty" colspan="9">Nessun turno registrato.</td></tr><?php endif; ?>
</table>
</div>
<?= pager($total, $page, $all ? ['scope' => 'tutti'] : []) ?>
<a class="btn teal small" href="dashboard.php" style="margin-top:18px">Torna alla Dashboard</a>
<?php page_foot();
