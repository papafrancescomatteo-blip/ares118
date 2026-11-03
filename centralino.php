<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
require_perm('centralino', $user);

$board = board_data();
$mezzi = $board['mezzi'];
$liberi = array_values(array_filter($mezzi, static fn($m) => $m['stato'] === 'libero'));

$calls = find_all('chiamate', ['stato' => ['$in' => ['in_attesa', 'in_corso']]], ['sort' => ['created_at' => 1]]);
$done = find_all('chiamate', ['stato' => ['$in' => ['chiusa', 'annullata']]], ['sort' => ['created_at' => -1], 'limit' => 12]);
$ids = array_map(static fn($c) => $c['id'], array_merge($calls, $done));
$assBy = [];
if ($ids) {
    foreach (find_all('assegnazioni', ['chiamata_id' => ['$in' => $ids]], ['sort' => ['assegnata_at' => 1]]) as $a) {
        $assBy[$a['chiamata_id']][] = $a;
    }
}
$inAttesa = count(array_filter($calls, static fn($c) => $c['stato'] === 'in_attesa'));

/** Campi nascosti comuni dei piccoli moduli */
function hid(string $action, string $id): string
{
    return csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="back" value="centralino.php">';
}

page_head('Centrale Operativa', 'wide', 'dark');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Centrale Operativa 118</h1>
  <p class="sub" style="margin:0">Operatore: <b><?= e(full_name($user)) ?></b> · <?= count($liberi) ?> mezzi liberi · <?= $inAttesa ?> chiamate in attesa</p></div>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
  <a class="btn red small" href="chiamata_nuova.php">+ Nuova chiamata</a>
  <a class="btn teal small" href="dashboard.php">Dashboard</a>
</div>

<div id="board" data-live="8">
<div class="grid2">
  <div class="col">
    <h2>Chiamate attive</h2>
    <?php foreach ($calls as $c): $as = $assBy[$c['id']] ?? []; ?>
    <div class="call <?= e($c['codice'] ?? '') ?>">
      <div class="call-h">
        <b><?= e($c['numero']) ?></b> <?= code_badge($c['codice'] ?? null) ?>
        <span class="badge <?= $c['stato'] === 'in_attesa' ? 'off' : 'busy' ?>"><?= e(STATI_CHIAMATA[$c['stato']]) ?></span>
        <small>ore <?= e(hm($c['created_at'])) ?> · <?= e($c['creata_da_nome'] ?? '') ?></small>
      </div>
      <p><b><?= e($c['indirizzo'] ?? '') ?><?= !empty($c['numero_civico']) ? ', ' . e($c['numero_civico']) : '' ?></b><?= !empty($c['luogo_riferito']) ? ' · ' . e(LUOGHI[$c['luogo_riferito']] ?? '') : '' ?></p>
      <p><?= e($c['condizione_riferita'] ?? '') ?></p>
      <?php if (!empty($c['tipo_chiamata'])): ?><div class="tags"><?php foreach ($c['tipo_chiamata'] as $t): ?><span><?= e(TIPI_CHIAMATA[$t] ?? $t) ?></span><?php endforeach; ?></div><?php endif; ?>
      <?php if (!empty($c['chiamante'])): ?><p><small>Chiamante: <?= e($c['chiamante']) ?></small></p><?php endif; ?>
      <?php if (!empty($c['note_co'])): ?><p><small>Note CO: <?= e($c['note_co']) ?></small></p><?php endif; ?>

      <?php foreach ($as as $a): if ($a['stato'] === 'annullata') { continue; } $att = in_array($a['stato'], ATTIVE, true); ?>
      <div class="ass">
        <span><b><?= e($a['mezzo_sigla']) ?></b> — <?= e(STATI_ASSEGNAZIONE[$a['stato']]) ?> <small>(assegnata alle <?= e(hm($a['assegnata_at'])) ?>)</small></span>
        <?php if ($att): ?>
        <form method="post" action="missione.php"><?= hid('richiama', $a['id']) ?><button class="btn orange small" type="submit" onclick="return confirm('Richiamare questo mezzo dalla chiamata?')">Richiama</button></form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($liberi): ?>
      <form method="post" action="missione.php" class="inline"><?= hid('assegna', $c['id']) ?>
        <select name="mezzo" required><option value="">– Assegna mezzo –</option>
          <?php foreach ($liberi as $m): ?><option value="<?= e($m['id']) ?>"><?= e(mezzo_label($m)) ?> · <?= count($m['crew']) ?> a bordo</option><?php endforeach; ?>
        </select>
        <button class="btn red small" type="submit">Assegna</button>
      </form>
      <?php else: ?><p class="hint">Nessun mezzo libero al momento.</p><?php endif; ?>
      <form method="post" action="missione.php" class="inline"><?= hid('annulla_chiamata', $c['id']) ?>
        <button class="btn slate small" type="submit" onclick="return confirm('Annullare la chiamata? I mezzi assegnati tornano liberi.')">Annulla chiamata</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$calls): ?><div class="empty">Nessuna chiamata attiva.</div><?php endif; ?>

    <h2 style="margin-top:26px">Ultime chiamate concluse</h2>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>N°</th><th>Ora</th><th>Indirizzo</th><th>Codice</th><th>Mezzi</th><th>Esito</th></tr>
      <?php foreach ($done as $c): ?>
      <tr>
        <td><b><?= e($c['numero']) ?></b></td><td><?= e(date('d/m H:i', (int)$c['created_at'])) ?></td>
        <td><?= e(($c['indirizzo'] ?? '') . (!empty($c['numero_civico']) ? ', ' . $c['numero_civico'] : '')) ?></td>
        <td><?= code_badge($c['codice'] ?? null) ?></td>
        <td><?= e(implode(', ', array_map(static fn($a) => $a['mezzo_sigla'], array_filter($assBy[$c['id']] ?? [], static fn($a) => $a['stato'] !== 'annullata')))) ?: '—' ?></td>
        <td><?= e(STATI_CHIAMATA[$c['stato']]) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$done): ?><tr><td class="empty" colspan="6">Ancora nessuna chiamata conclusa.</td></tr><?php endif; ?>
    </table></div>
  </div>

  <div class="col">
    <h2>Mezzi</h2>
    <?php if ($board['co']): ?><p><small>In Centrale: <?= e(implode(', ', array_map(static fn($c) => $c['nome'], $board['co']))) ?></small></p><?php endif; ?>
    <?php $grp = null; foreach ($mezzi as $m): if ($m['gruppo'] !== $grp) { $grp = $m['gruppo']; echo '<div class="grp">' . e($grp) . '</div>'; } ?>
    <div class="mz">
      <div class="mz-h"><b><?= e(mezzo_label($m)) ?></b><span class="badge <?= e(BADGE_MEZZO[$m['stato']]) ?>"><?= e(STATI_MEZZO[$m['stato']]) ?></span></div>
      <?php foreach ($m['crew'] as $cr): ?>
        <small><?= e($cr['ruolo']) ?>: <?= e($cr['nome']) ?><?= !empty($cr['user']['matricola']) ? ' (' . e($cr['user']['matricola']) . ')' : '' ?><?= !empty($cr['shift']['pause_started']) ? ' · in pausa' : '' ?></small>
      <?php endforeach; ?>
      <?php foreach ($m['assegnazioni'] as $a): ?>
        <small><b><?= e($a['chiamata_numero']) ?></b> · <?= e(STATI_ASSEGNAZIONE[$a['stato']]) ?></small>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>
<?php page_foot();
