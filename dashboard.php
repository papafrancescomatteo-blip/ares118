<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$board = board_data();
$shift = open_shift($user['id']);
$state = shift_state($shift);
$role = role_info($user['ruolo']);
$isPers = can('personale', $user);
$isAll = can('vedi_tutte', $user);
$isCo = can('centralino', $user);
$crewRoles = allowed_crew_roles($user);

$myMezzo = ($shift && !empty($shift['mezzo_id'])) ? find_mezzo_in($board, $shift['mezzo_id']) : null;
$mine = my_assignments($user, $shift);

page_head('Dashboard');
?>
<h1>Dashboard ARES 118</h1>
<p class="sub">Benvenuto, <b><?= e(full_name($user)) ?></b></p>

<div class="box">
  <h3>Il tuo profilo</h3>
  <p>Nome e cognome: <b><?= e(full_name($user)) ?></b></p>
  <p>Matricola: <?= !empty($user['matricola']) ? '<b>' . e($user['matricola']) . '</b>' : '<i>non ancora assegnata</i>' ?></p>
  <p>Grado: <?= !empty($user['grado']) ? '<b>' . e($user['grado']) . '</b>' : '<i>non ancora assegnato</i>' ?></p>
  <p>Ruolo sul sito: <b><?= e($role['label']) ?></b><?= !empty($user['co']) ? ' · abilitato Centrale Operativa' : '' ?></p>
</div>

<div id="live-dash" data-live="10">
<?php if ($myMezzo): ?>
  <?php foreach ($mine['attive'] as $a): $c = $a['call']; $next = TRANSIZIONI[$a['stato']] ?? []; ?>
  <div class="call <?= e($c['codice'] ?? '') ?>">
    <div class="call-h"><b>Intervento <?= e($a['chiamata_numero']) ?></b> <?= code_badge($c['codice'] ?? null) ?> <span class="badge busy"><?= e(STATI_ASSEGNAZIONE[$a['stato']]) ?></span></div>
    <p><b><?= e($c['indirizzo'] ?? '') ?><?= !empty($c['numero_civico']) ? ', ' . e($c['numero_civico']) : '' ?></b><?= !empty($c['luogo_riferito']) ? ' · ' . e(LUOGHI[$c['luogo_riferito']] ?? '') : '' ?></p>
    <p><?= e($c['condizione_riferita'] ?? '') ?></p>
    <?php if (!empty($c['note_co'])): ?><p><small>Note CO: <?= e($c['note_co']) ?></small></p><?php endif; ?>
    <p><small>Assegnata alle <?= e(hm($a['assegnata_at'])) ?> da <?= e($a['assegnata_da'] ?? '') ?></small></p>
    <?php foreach ($next as $i => $to): ?>
    <form method="post" action="missione.php"><?= csrf_field() ?><input type="hidden" name="action" value="avanza"><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="back" value="dashboard.php">
      <button class="btn <?= $i === 0 ? 'teal' : 'slate' ?>" type="submit"><?= e(AZIONE_LABEL[$to]) ?><?= $to === 'libera' && $a['stato'] === 'sul_posto' ? ' (senza trasporto)' : '' ?></button></form>
    <?php endforeach; ?>
    <a class="btn blue" href="scheda_nuova.php?a=<?= e($a['id']) ?>">Compila scheda di soccorso</a>
  </div>
  <?php endforeach; ?>
  <?php if (!$mine['attive']): ?><div class="box info">Nessun intervento assegnato: in attesa di chiamate dalla Centrale Operativa.</div><?php endif; ?>
<?php endif; ?>
<?php foreach ($mine['da_compilare'] as $a): ?>
  <div class="box"><h3>Scheda da compilare</h3>
    <p>Intervento <b><?= e($a['chiamata_numero']) ?></b> concluso alle <?= e(hm($a['libera_at'] ?? null)) ?> con <?= e($a['mezzo_sigla']) ?>.</p>
    <a class="btn blue" href="scheda_nuova.php?a=<?= e($a['id']) ?>">Compila scheda di soccorso</a></div>
<?php endforeach; ?>
</div>

<div class="box">
  <h3>Stato sistema</h3>
  <p>Connessione attiva. Account autenticato correttamente.</p>
</div>

<div class="box">
  <h3>Firma Servizio</h3>
  <p>Stato attuale: <b><?= e($state) ?></b><?php if ($shift): ?> · dalle <?= e(hm($shift['start_at'])) ?> (<?= e(fmt_duration(shift_net_seconds($shift))) ?> effettive)<?php endif; ?></p>
  <?php if ($shift): ?>
    <p>Postazione: <b><?= e($myMezzo ? mezzo_label($myMezzo) : 'Centrale Operativa') ?></b> · Ruolo a bordo: <b><?= e($shift['ruolo_eq'] ?? '—') ?></b></p>
    <?php if ($myMezzo): ?>
      <div class="crew-list"><?php foreach ($myMezzo['crew'] as $cr): ?><small><?= e($cr['ruolo']) ?>: <b><?= e($cr['nome']) ?></b><?= !empty($cr['user']['matricola']) ? ' (' . e($cr['user']['matricola']) . ')' : '' ?></small><?php endforeach; ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="servizio.php" data-once>
    <?= csrf_field() ?>
    <?php if (!$shift): ?>
    <div class="field" style="margin-top:12px"><label for="mezzo">Equipaggio / mezzo</label>
      <select name="mezzo" id="mezzo" required>
        <option value="">– Seleziona il mezzo –</option>
        <?php if ($isCo): ?><option value="CO">Centrale Operativa (CO)</option><?php endif; ?>
        <?php $grp = null; foreach ($board['mezzi'] as $m): if (empty($m['attivo'])) { continue; }
          if ($m['gruppo'] !== $grp) { if ($grp !== null) { echo '</optgroup>'; } $grp = $m['gruppo']; echo '<optgroup label="' . e($grp) . '">'; } ?>
          <option value="<?= e($m['id']) ?>"><?= e(mezzo_label($m)) ?><?= $m['crew'] ? ' · ' . count($m['crew']) . ' a bordo' : '' ?></option>
        <?php endforeach; if ($grp !== null) { echo '</optgroup>'; } ?>
      </select></div>
    <?php if ($crewRoles): ?>
    <div class="field"><label for="ruolo_eq">Ruolo a bordo</label>
      <select name="ruolo_eq" id="ruolo_eq">
        <?php foreach ($crewRoles as $r): ?><option><?= e($r) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <?php endif; ?>
    <div class="btn-row">
      <button class="btn teal" name="action" value="start" type="submit"<?= $shift ? ' disabled style="opacity:.5"' : '' ?>>Avvia Servizio</button>
      <button class="btn orange" name="action" value="pause" type="submit"<?= $shift ? '' : ' disabled style="opacity:.5"' ?>><?= $state === 'In pausa' ? 'Riprendi Servizio' : 'Metti in Pausa' ?></button>
      <button class="btn red" name="action" value="end" type="submit"<?= $shift ? '' : ' disabled style="opacity:.5"' ?>>Termina Servizio</button>
    </div>
  </form>
  <a class="btn teal" href="shifts.php">Storico Shift</a>
  <a class="btn teal" href="schede.php">Le mie schede di soccorso</a>
  <a class="btn blue" href="scheda_nuova.php">Compila Scheda di Soccorso</a>
  <?php if ($isCo): ?><a class="btn red" href="centralino.php">Centrale Operativa (chiamate)</a><?php endif; ?>

  <?php if ($isPers || $isAll): ?>
    <?php if ($isAll): ?><a class="btn slate" href="schede.php?scope=tutte">Storico schede (tutto il personale)</a><?php endif; ?>
    <?php if ($isPers): ?>
      <a class="btn slate" href="shifts.php?scope=tutti">Storico shift (tutto il personale)</a>
      <a class="btn slate" href="personale.php">Gestione personale</a>
      <a class="btn slate" href="mezzi.php">Gestione mezzi</a>
    <?php endif; ?>
  <?php else: ?>
    <div class="box info" style="margin:12px 0 0">Bottoni storico admin nascosti: <b>ruolo non autorizzato</b></div>
  <?php endif; ?>
</div>

<a class="btn slate" href="password.php" style="margin-top:0;margin-bottom:12px">Cambia password</a>
<form method="post" action="logout.php">
  <?= csrf_field() ?>
  <button class="btn red" type="submit" style="margin-top:0">Logout</button>
</form>
<?php page_foot();
