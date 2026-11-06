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

page_head('Dashboard', $tablet ? 'wide' : 'wide', $tablet ? 'dark flat tabletmode' : 'dark flat');

if ($tablet): ?>
<div id="sound-gate" class="sound-gate" hidden>
  <div><h2>Tablet di bordo</h2><p>Tocca per attivare il suono delle chiamate, lo schermo intero e lo schermo sempre acceso.</p><button type="button" id="gate-go" class="btn red">Attiva audio e continua</button></div>
</div>
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
    <span>Audio non ancora attivo su questo dispositivo: senza, le chiamate non suonano.</span>
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

  <form method="post" action="servizio.php" class="tb-service" data-once>
    <?= csrf_field() ?>
    <button class="btn orange" name="action" value="pause" type="submit"><?= $state === 'In pausa' ? 'Riprendi servizio' : 'Metti in pausa' ?></button>
    <button class="btn red" name="action" value="end" type="submit">Termina servizio</button>
  </form>
  <p class="tb-foot"><?= e(full_name($user)) ?> · matricola <?= e($user['matricola'] ?? '—') ?> · <?= e($user['grado'] ?? '') ?: 'grado non assegnato' ?></p>
</div>
<?php page_foot(); exit; endif; ?>

<?php
$ini = mb_strtoupper(mb_substr((string)($user['nome'] ?? $user['username'] ?? '?'), 0, 1) . mb_substr((string)($user['cognome'] ?? ''), 0, 1));
$pend = (!empty($user['ruolo_richiesto']) && isset(ROLES[$user['ruolo_richiesto']])) ? ROLES[$user['ruolo_richiesto']]['label'] : '';
?>
<div class="home">
  <section class="hero">
    <div class="hero-id">
      <div class="avatar"><?= e($ini) ?></div>
      <div>
        <p>Buon servizio,</p>
        <h1><?= e(full_name($user)) ?></h1>
      </div>
    </div>
    <div class="hero-tags">
      <span><?= e($role['label']) ?></span>
      <span>Matricola <?= !empty($user['matricola']) ? e($user['matricola']) : 'non assegnata' ?></span>
      <span><?= !empty($user['grado']) ? e($user['grado']) : 'Grado non assegnato' ?></span>
      <?php if (!empty($user['co'])): ?><span>Abilitato Centrale Operativa</span><?php endif; ?>
      <?php if (!empty($user['avvertimenti'])): ?><span class="pend">Avvertimenti: <?= (int)$user['avvertimenti'] ?></span><?php endif; ?>
      <?php if (!empty($user['note_merito'])): ?><span>Note di merito: <?= (int)$user['note_merito'] ?></span><?php endif; ?>
      <?php if ($pend): ?><span class="pend">Ruolo richiesto: <?= e($pend) ?> · in attesa di conferma</span><?php endif; ?>
    </div>
  </section>

  <div class="home-grid">
    <section class="card">
      <h2>Servizio</h2>
      <div class="state-line"><span class="badge <?= $shift ? ($state === 'In pausa' ? 'man' : 'on') : 'idle' ?>"><?= e($state) ?></span>
        <?php if ($shift): ?><span class="sub" style="margin:0">dalle <?= e(hm($shift['start_at'])) ?> · <?= e(fmt_duration(shift_net_seconds($shift))) ?> effettive</span><?php endif; ?></div>
      <?php if ($shift && !$myMezzo): ?>
        <div class="box info"><b>Sei in Centrale Operativa.</b> Ruolo: <?= e($shift['ruolo_eq'] ?? '—') ?></div>
        <a class="btn red" href="centralino.php">Apri la Centrale Operativa</a>
      <?php elseif (!$shift): ?>
        <p class="sub">Scegli mezzo e ruolo: all'avvio si apre il tablet di bordo con chiamate e schede da compilare.</p>
      <?php endif; ?>
      <form method="post" action="servizio.php" data-once>
        <?= csrf_field() ?>
        <?php if (!$shift): ?>
        <div class="field"><label for="mezzo">Equipaggio / mezzo</label>
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
    </section>

    <section class="card">
      <h2>Schede e storico</h2>
      <p class="sub">Le tue relazioni di soccorso e i turni svolti.</p>
      <div class="tiles">
        <a class="tile" href="scheda_nuova.php"><b>Compila scheda di soccorso</b><small>Relazione guidata a schermate</small></a>
        <a class="tile" href="schede.php"><b>Le mie schede</b><small>Storico e PDF</small></a>
        <a class="tile" href="shifts.php"><b>Storico shift</b><small>Ore e turni</small></a>
        <?php if ($isCo): ?><a class="tile red" href="centralino.php"><b>Centrale Operativa</b><small>Chiamate e mezzi</small></a><?php endif; ?>
      </div>
    </section>
  </div>

  <?php if ($isAll || $isPers): ?>
  <section class="card">
    <h2>Coordinamento</h2>
    <p class="sub">Strumenti per chi gestisce personale e mezzi.</p>
    <div class="tiles">
      <?php if ($isAll): ?><a class="tile" href="schede.php?scope=tutte"><b>Schede di tutti</b><small>Tutto il personale</small></a><?php endif; ?>
      <?php if ($isPers): ?>
        <a class="tile" href="shifts.php?scope=tutti"><b>Shift di tutti</b><small>Turni e ore</small></a>
        <a class="tile" href="personale.php"><b>Gestione personale</b><small>Ruoli, matricole, credenziali</small></a>
        <a class="tile" href="mezzi.php"><b>Gestione mezzi</b><small>Elenco mezzi e postazioni</small></a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="home-foot">
    <a class="btn slate" href="password.php">Cambia password</a>
    <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn red" type="submit">Esci</button></form>
  </div>
</div>
<?php page_foot();
