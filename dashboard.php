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
$mine = my_assignments($user, $shift, $myMezzo);
$lead = $myMezzo ? crew_lead($myMezzo['crew']) : null;
$tablet = $myMezzo !== null;

/** Barra di avanzamento dell'intervento (5 passi: partita, sul posto, trasporto, arrivo PS, libera) */
function avanzamento(string $stato): string
{
    $steps = ['assegnata' => 0, 'partita' => 1, 'sul_posto' => 2, 'in_trasporto' => 3, 'arrivata_ps' => 4];
    $n = $steps[$stato] ?? 0;
    $h = '<div class="prog" aria-hidden="true">';
    for ($i = 0; $i < 5; $i++) {
        $h .= '<i class="' . ($i < $n ? 'done' : ($i === $n ? 'now' : '')) . '"></i>';
    }
    return $h . '</div>';
}

function pannello_interventi(array $mine, ?array $lead, array $user): void
{
    $capo = $mine['capo'];
    foreach ($mine['attive'] as $a):
        $c = $a['call'];
        $next = TRANSIZIONI[$a['stato']] ?? [];
        $nuova = $a['stato'] === 'assegnata'; ?>
  <div class="tcall <?= e($c['codice'] ?? '') ?><?= $nuova ? ' nuova' : '' ?>"<?= $nuova ? ' data-alert-id="' . e($a['id']) . '" data-alert-text="' . e(($c['indirizzo'] ?? '') . (!empty($c['numero_civico']) ? ' ' . $c['numero_civico'] : '')) . '"' : '' ?>>
    <div class="call-h"><b>Intervento <?= e($a['chiamata_numero']) ?></b> <?= code_badge($c['codice'] ?? null) ?> <span class="badge busy"><?= e(STATI_ASSEGNAZIONE[$a['stato']]) ?></span></div>
    <p class="tc-addr"><?= e($c['indirizzo'] ?? '') ?><?= !empty($c['numero_civico']) ? ', ' . e($c['numero_civico']) : '' ?><?= !empty($c['luogo_riferito']) ? ' <small>· ' . e(LUOGHI[$c['luogo_riferito']] ?? '') . '</small>' : '' ?></p>
    <p class="tc-cond"><?= e($c['condizione_riferita'] ?? '') ?></p>
    <?php if (!empty($c['note_co'])): ?><p class="tc-note">Note CO: <?= e($c['note_co']) ?></p><?php endif; ?>
    <p class="tc-meta">Assegnata alle <?= e(hm($a['assegnata_at'])) ?> da <?= e($a['assegnata_da'] ?? '') ?></p>
    <?= avanzamento($a['stato']) ?>
    <?php if ($capo): ?>
      <div class="tc-actions">
      <?php foreach ($next as $i => $to): ?>
        <form method="post" action="missione.php"><?= csrf_field() ?><input type="hidden" name="action" value="avanza"><input type="hidden" name="id" value="<?= e($a['id']) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="back" value="dashboard.php">
          <button class="btn <?= $i === 0 ? 'teal' : 'slate' ?>" type="submit"><?= e(AZIONE_LABEL[$to]) ?><?= $to === 'libera' && $a['stato'] === 'sul_posto' ? ' (senza trasporto)' : '' ?></button></form>
      <?php endforeach; ?>
        <a class="btn blue" href="scheda_nuova.php?a=<?= e($a['id']) ?>">Compila scheda di soccorso</a>
      </div>
    <?php else: ?>
      <p class="tc-info">Questo intervento lo gestisce il capo equipaggio<?= $lead ? ': <b>' . e($lead['nome']) . '</b>' : '' ?>.</p>
    <?php endif; ?>
  </div>
    <?php endforeach;
    if (!$mine['attive']): ?>
  <div class="tidle"><span class="dot"></span><div><b>Mezzo libero, in attesa di chiamate</b><br><small>Quando la Centrale Operativa ti assegna un intervento senti un suono e compare qui.</small></div></div>
    <?php endif;
    foreach ($mine['da_compilare'] as $a): ?>
  <div class="box"><h3>Scheda da compilare</h3>
    <p>Intervento <b><?= e($a['chiamata_numero']) ?></b> concluso alle <?= e(hm($a['libera_at'] ?? null)) ?> con <?= e($a['mezzo_sigla']) ?>.</p>
    <a class="btn blue" href="scheda_nuova.php?a=<?= e($a['id']) ?>">Compila scheda di soccorso</a></div>
    <?php endforeach;
}

page_head('Dashboard', $tablet ? 'wide' : 'narrow');

if ($tablet): ?>
<div class="tablet">
  <div class="tb-bar">
    <div class="tb-id">
      <span class="tb-lbl">Postazione: <b><?= e(mezzo_label($myMezzo)) ?></b></span>
      <span class="badge <?= e(BADGE_MEZZO[$myMezzo['stato']] ?? '') ?>"><?= e(STATI_MEZZO[$myMezzo['stato']] ?? '') ?></span>
    </div>
    <div class="tb-side"><span id="clock" class="tb-clock" aria-label="Ora"></span>
      <button type="button" id="audio-btn" class="btn small slate">Audio</button></div>
  </div>

  <div id="alert-bar" class="alert-bar" hidden>
    <div><b>Nuova chiamata</b> <span id="alert-text"></span></div>
    <button type="button" id="alert-silence" class="btn small orange">Silenzia</button>
  </div>
  <div id="audio-bar" class="audio-bar" hidden>
    <span>Per sentire il suono delle chiamate attiva l'audio su questo dispositivo.</span>
    <button type="button" id="audio-unlock" class="btn small teal">Attiva audio</button>
  </div>

  <div class="tb-crew">
    <?php foreach ($myMezzo['crew'] as $cr): $isLead = $lead && (string)$lead['user']['id'] === (string)$cr['user']['id']; ?>
      <div class="chip-crew<?= $isLead ? ' lead' : '' ?>">
        <small><?= e($cr['ruolo']) ?>: <b><?= e($cr['nome']) ?></b><?= (string)$cr['user']['id'] === (string)$user['id'] ? ' (tu)' : '' ?></small>
        <?php if ($isLead && count($myMezzo['crew']) > 1 && $cr['ruolo'] !== 'Capo Equipaggio'): ?><span class="lead-tag">Capo equipaggio</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <small class="tb-me">Ruolo a bordo: <b><?= e($shift['ruolo_eq'] ?? '—') ?></b> · in servizio dalle <?= e(hm($shift['start_at'])) ?> (<?= e(fmt_duration(shift_net_seconds($shift))) ?>) · stato <b><?= e($state) ?></b></small>
  </div>

  <div id="live-dash" data-live="5">
    <?php pannello_interventi($mine, $lead, $user); ?>
  </div>

  <div class="tb-nav">
    <?php if ($mine['capo'] || count($myMezzo['crew']) === 1): ?><a class="btn blue" href="scheda_nuova.php">Scheda libera</a><?php endif; ?>
    <a class="btn slate" href="schede.php">Le mie schede</a>
    <a class="btn slate" href="shifts.php">Storico shift</a>
    <?php if ($isCo): ?><a class="btn slate" href="centralino.php">Centrale Operativa (chiamate)</a><?php endif; ?>
    <?php if ($isAll): ?><a class="btn slate" href="schede.php?scope=tutte">Schede di tutti</a><?php endif; ?>
    <?php if ($isPers): ?>
      <a class="btn slate" href="shifts.php?scope=tutti">Shift di tutti</a>
      <a class="btn slate" href="personale.php">Gestione personale</a>
      <a class="btn slate" href="mezzi.php">Gestione mezzi</a>
    <?php endif; ?>
    <a class="btn slate" href="password.php">Cambia password</a>
  </div>

  <form method="post" action="servizio.php" class="tb-service" data-once>
    <?= csrf_field() ?>
    <button class="btn orange" name="action" value="pause" type="submit"><?= $state === 'In pausa' ? 'Riprendi servizio' : 'Metti in pausa' ?></button>
    <button class="btn red" name="action" value="end" type="submit">Termina servizio</button>
  </form>
  <p class="tb-foot"><?= e(full_name($user)) ?> · matricola <?= e($user['matricola'] ?? '—') ?> · <?= e($user['grado'] ?? '') ?: 'grado non assegnato' ?></p>
  <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn slate" type="submit">Esci</button></form>
</div>
<?php page_foot(); exit; endif; ?>

<h1>Dashboard ARES 118</h1>
<p class="sub">Benvenuto, <b><?= e(full_name($user)) ?></b></p>

<div class="box">
  <h3>Il tuo profilo</h3>
  <p>Nome e cognome: <b><?= e(full_name($user)) ?></b></p>
  <p>Matricola: <?= !empty($user['matricola']) ? '<b>' . e($user['matricola']) . '</b>' : '<i>non ancora assegnata</i>' ?></p>
  <p>Grado: <?= !empty($user['grado']) ? '<b>' . e($user['grado']) . '</b>' : '<i>non ancora assegnato</i>' ?></p>
  <p>Ruolo sul sito: <b><?= e($role['label']) ?></b><?= !empty($user['co']) ? ' · abilitato Centrale Operativa' : '' ?></p>
  <?php if (!empty($user['ruolo_richiesto']) && isset(ROLES[$user['ruolo_richiesto']])): ?>
  <p>Ruolo richiesto da Discord: <b><?= e(ROLES[$user['ruolo_richiesto']]['label']) ?></b> · <i>in attesa di conferma dell'amministratore</i></p>
  <?php endif; ?>
</div>

<?php if ($shift && !$myMezzo): ?>
<div class="box info">
  <h3>Sei in Centrale Operativa</h3>
  <p>Postazione: <b>Centrale Operativa</b> · in servizio dalle <?= e(hm($shift['start_at'])) ?>.</p>
  <a class="btn red" href="centralino.php">Apri la Centrale Operativa (chiamate)</a>
</div>
<?php endif; ?>

<div class="box">
  <h3>Firma Servizio</h3>
  <p>Stato attuale: <b><?= e($state) ?></b><?php if ($shift): ?> · dalle <?= e(hm($shift['start_at'])) ?> (<?= e(fmt_duration(shift_net_seconds($shift))) ?> effettive)<?php endif; ?></p>
  <?php if ($shift): ?>
    <p>Postazione: <b>Centrale Operativa</b> · Ruolo: <b><?= e($shift['ruolo_eq'] ?? '—') ?></b></p>
  <?php else: ?>
    <p>Scegli mezzo e ruolo: dopo l'avvio si apre il tablet di bordo con le chiamate del tuo equipaggio.</p>
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
      <button class="btn teal" name="action" value="start" type="submit"<?= $shift ? ' disabled' : '' ?>>Avvia Servizio</button>
      <button class="btn orange" name="action" value="pause" type="submit"<?= $shift ? '' : ' disabled' ?>><?= $state === 'In pausa' ? 'Riprendi Servizio' : 'Metti in Pausa' ?></button>
      <button class="btn red" name="action" value="end" type="submit"<?= $shift ? '' : ' disabled' ?>>Termina Servizio</button>
    </div>
  </form>
  <a class="btn slate" href="shifts.php">Storico Shift</a>
  <a class="btn slate" href="schede.php">Le mie schede di soccorso</a>
  <a class="btn blue" href="scheda_nuova.php">Compila Scheda di Soccorso</a>
  <?php if ($isCo): ?><a class="btn red" href="centralino.php">Centrale Operativa (chiamate)</a><?php endif; ?>
  <?php if ($isAll): ?><a class="btn slate" href="schede.php?scope=tutte">Storico schede (tutto il personale)</a><?php endif; ?>
  <?php if ($isPers): ?>
    <a class="btn slate" href="shifts.php?scope=tutti">Storico shift (tutto il personale)</a>
    <a class="btn slate" href="personale.php">Gestione personale</a>
    <a class="btn slate" href="mezzi.php">Gestione mezzi</a>
  <?php endif; ?>
</div>

<a class="btn slate" href="password.php" style="margin-top:0;margin-bottom:12px">Cambia password</a>
<form method="post" action="logout.php">
  <?= csrf_field() ?>
  <button class="btn red" type="submit" style="margin-top:0">Logout</button>
</form>
<?php page_foot();
