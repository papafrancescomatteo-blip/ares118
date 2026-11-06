<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$all = (($_GET['scope'] ?? '') === 'tutti');
if ($all) {
    require_perm('personale', $user);
}
$page = max(1, (int)($_GET['p'] ?? 1));

// ---- Modifica degli shift degli altri (solo gestione personale, solo persone che puoi gestire) ----
if ($all && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');
    $now = time();
    if ($action === 'shift_new') {
        $t = find_one_by('users', id_filter(post_str('user_id')));
        $d = post_str('data');
        $a = strtotime($d . ' ' . post_str('inizio'));
        $b = strtotime($d . ' ' . post_str('fine'));
        if ($b !== false && $a !== false && $b <= $a) {
            $b += 86400;   // turno a cavallo della mezzanotte
        }
        if (!$t || !manageable($user, $t)) {
            flash('Scegli una persona che puoi gestire.', 'err');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !$a || !$b) {
            flash('Data e orari non validi.', 'err');
        } elseif ($b - $a > 24 * 3600 || $b > $now + 60) {
            flash('Il turno non può durare più di 24 ore né finire nel futuro.', 'err');
        } else {
            col('shifts')->insertOne([
                'user_id' => $t['id'], 'start_at' => $a, 'end_at' => $b, 'pause_started' => null, 'pause_seconds' => 0,
                'mezzo_id' => null, 'mezzo_sigla' => 'Aggiunto a mano', 'ruolo_eq' => mb_substr(post_str('ruolo'), 0, 40) ?: '—',
                'aggiunto_da' => full_name($user), 'modificato_at' => $now,
            ]);
            flash('Shift aggiunto a <b>' . e(full_name($t)) . '</b> (' . e(fmt_duration($b - $a)) . ').');
        }
    } else {
        $sh = find_one_by('shifts', id_filter(post_str('id')));
        $t = $sh ? find_one_by('users', id_filter((string)$sh['user_id'])) : null;
        if (!$sh || !$t || !manageable($user, $t)) {
            flash('Non puoi modificare questo shift.', 'err');
        } elseif ($action === 'shift_del') {
            col('shifts')->deleteOne(id_filter($sh['id']));
            flash('Shift di <b>' . e(full_name($t)) . '</b> del ' . e(date('d/m/Y', (int)$sh['start_at'])) . ' eliminato.');
        } elseif ($action === 'shift_tempo') {
            $min = (int)post_str('minuti');
            $segno = post_str('segno') === '-' ? -1 : 1;
            if ($min < 1 || $min > 24 * 60) {
                flash('Indica da 1 a 1440 minuti.', 'err');
            } else {
                col('shifts')->updateOne(id_filter($sh['id']), ['$set' => [
                    'adjust_seconds' => (int)($sh['adjust_seconds'] ?? 0) + $segno * $min * 60,
                    'modificato_da' => full_name($user), 'modificato_at' => $now,
                ]]);
                flash(($segno > 0 ? 'Aggiunti ' : 'Rimossi ') . $min . ' minuti allo shift di <b>' . e(full_name($t)) . '</b>.');
            }
        }
    }
    redirect('shifts.php?scope=tutti&p=' . $page);
}

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
<?php if ($all): $gest = persone_gestibili($user); ?>
<details class="sec" style="margin-bottom:14px"><summary style="cursor:pointer;padding:10px 14px;font-weight:600">Aggiungi uno shift a mano</summary>
  <form method="post" style="padding:12px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?>
    <div class="field" style="margin:0"><label>Persona</label><select name="user_id" required><option value="">Scegli…</option><?php foreach ($gest as $g): ?><option value="<?= e($g['id']) ?>"><?= e(full_name($g)) ?><?= !empty($g['matricola']) ? ' · ' . e($g['matricola']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0"><label>Data</label><input type="date" name="data" required max="<?= e(date('Y-m-d')) ?>"></div>
    <div class="field" style="margin:0"><label>Inizio</label><input type="time" name="inizio" required></div>
    <div class="field" style="margin:0"><label>Fine</label><input type="time" name="fine" required></div>
    <div class="field" style="margin:0"><label>Ruolo (facoltativo)</label><input type="text" name="ruolo" maxlength="40" placeholder="es. Autista"></div>
    <button class="btn teal small" name="action" value="shift_new">Aggiungi shift</button>
  </form>
</details>
<?php endif; ?>
<div class="tbl-wrap">
<table class="tbl">
  <tr><?php if ($all): ?><th>Operatore</th><th>Matricola</th><?php endif; ?><th>Data</th><th>Mezzo</th><th>Ruolo</th><th>Inizio</th><th>Fine</th><th>Pausa</th><th>Durata effettiva</th><?php if ($all): ?><th>Modifica</th><?php endif; ?></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <?php if ($all): ?><td><?= e(trim($r['nome'] . ' ' . $r['cognome'])) ?></td><td><?= e($r['matricola']) ?></td><?php endif; ?>
    <td><?= e(date('d/m/Y', (int)$r['start_at'])) ?></td>
    <td><?= e($r['mezzo_sigla'] ?? '—') ?></td>
    <td><?= e($r['ruolo_eq'] ?? '—') ?></td>
    <td><?= e(date('H:i', (int)$r['start_at'])) ?></td>
    <td><?= $r['end_at'] ? e(date('H:i', (int)$r['end_at'])) : '<span class="badge on">' . ($r['pause_started'] ? 'In pausa' : 'In corso') . '</span>' ?></td>
    <td><?= e(fmt_duration((int)$r['pause_seconds'])) ?></td>
    <td><b><?= e(fmt_duration(shift_net_seconds($r))) ?></b><?php if (!empty($r['adjust_seconds'])): ?><br><small style="color:#b45309"><?= $r['adjust_seconds'] > 0 ? '+' : '−' ?><?= (int)(abs($r['adjust_seconds']) / 60) ?> min rettificati<?= !empty($r['modificato_da']) ? ' da ' . e($r['modificato_da']) : '' ?></small><?php endif; ?></td>
    <?php if ($all): $pp = $people[$r['user_id']] ?? null; ?>
    <td><?php if ($pp && manageable($user, $pp)): ?>
      <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
        <select name="segno" style="width:auto"><option value="+">+</option><option value="-">−</option></select>
        <input type="number" name="minuti" min="1" max="1440" placeholder="min" style="width:70px">
        <button class="btn teal small" name="action" value="shift_tempo">Applica</button>
        <button class="btn red small" name="action" value="shift_del" onclick="return confirm('Eliminare questo shift?')">Elimina</button>
      </form><?php else: ?>—<?php endif; ?></td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td class="empty" colspan="10">Nessun turno registrato.</td></tr><?php endif; ?>
</table>
</div>
<?= pager($total, $page, $all ? ['scope' => 'tutti'] : []) ?>
<a class="btn teal small" href="dashboard.php" style="margin-top:18px">Torna alla Dashboard</a>
<?php page_foot();
