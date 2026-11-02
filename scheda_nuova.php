<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$old = [];
$errors = [];

// Se la scheda parte da un intervento della Centrale Operativa (?a=ID) i dati arrivano dalla chiamata
$ass = null;
$aid = post_str('assegnazione_id') !== '' ? post_str('assegnazione_id') : (is_string($_GET['a'] ?? null) ? $_GET['a'] : '');
if ($aid !== '') {
    $ass = find_one_by('assegnazioni', id_filter($aid));
    if (!$ass || !can_access_assegnazione($user, $ass)) {
        flash('Intervento non trovato.', 'err');
        redirect('dashboard.php');
    }
    if (!empty($ass['scheda_id'])) {
        flash('Per l\'intervento ' . e($ass['chiamata_numero']) . ' esiste già una scheda.');
        redirect('scheda_view.php?id=' . $ass['scheda_id']);
    }
}
$myShift = open_shift($user['id']);

// A bordo con altre persone la scheda la compila il capo equipaggio
if ($myShift && !empty($myShift['mezzo_id']) && !can('centralino', $user)) {
    $mz = find_mezzo_in(board_data(), $myShift['mezzo_id']);
    if ($mz && count($mz['crew']) > 1 && !is_crew_lead($user, $mz)) {
        $lead = crew_lead($mz['crew']);
        flash('La scheda di soccorso la compila il capo equipaggio' . ($lead ? ' (<b>' . e($lead['nome']) . '</b>)' : '') . '.', 'err');
        redirect('dashboard.php');
    }
}

// ---------- Helper HTML ----------
function val(string $k): string
{
    global $old;
    return e($old[$k] ?? '');
}

function radio_group(string $name, array $opts, ?string $cur): string
{
    $h = '<div class="opts">';
    foreach ($opts as $k => $label) {
        $h .= '<label><input type="radio" name="' . e($name) . '" value="' . e($k) . '"' . ((string)$cur === (string)$k ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
    return $h . '</div>';
}

function check_group(string $name, array $opts, array $cur): string
{
    $h = '<div class="opts">';
    foreach ($opts as $k => $label) {
        $h .= '<label><input type="checkbox" name="' . e($name) . '[]" value="' . e($k) . '"' . (in_array((string)$k, array_map('strval', $cur), true) ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
    return $h . '</div>';
}

function select_box(string $name, array $opts, $cur): string
{
    $h = '<select name="' . e($name) . '" id="' . e($name) . '"><option value="">– Seleziona –</option>';
    foreach ($opts as $k => $label) {
        $h .= '<option value="' . e($k) . '"' . ((string)$cur === (string)$k && $cur !== null && $cur !== '' ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h . '</select>';
}

function combo(string $name, string $placeholder, array $opts, string $cur, string $id = ''): string
{
    return '<div class="combo" data-options="' . e(json_encode(array_values($opts), JSON_UNESCAPED_UNICODE)) . '">'
        . '<input class="combo-input" type="text" name="' . e($name) . '" id="' . e($id ?: $name) . '" maxlength="200" autocomplete="off" placeholder="' . e($placeholder) . '" value="' . e($cur) . '">'
        . '<div class="combo-list"></div></div>';
}

function text_cell(string $name, string $label, string $type = 'text', string $extra = ''): string
{
    return '<div class="cell"><label class="lbl" for="' . e($name) . '">' . e($label) . '</label>'
        . '<input type="' . e($type) . '" name="' . e($name) . '" id="' . e($name) . '" value="' . val($name) . '" ' . $extra . '></div>';
}

function yesno_cell(string $name, string $label): string
{
    global $old;
    return '<div class="cell"><span class="lbl">' . e($label) . '</span>' . radio_group($name, ['SI' => 'SI', 'NO' => 'NO'], $old[$name] ?? null) . '</div>';
}

// ---------- Salvataggio ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $p = $_POST;
    $old = $p;

    $list = static function (string $k, array $allowed) use ($p): array {
        return array_values(array_intersect(array_map('strval', array_keys($allowed)), array_map('strval', (array)($p[$k] ?? []))));
    };

    $tratt = [];
    foreach ((array)($p['trattamenti'] ?? []) as $t) {
        $t = trim((string)$t);
        if ($t !== '' && count($tratt) < 60) {
            $tratt[mb_substr($t, 0, 200)] = true;
        }
    }
    $crew = [];
    foreach (EQUIPAGGIO as $k => $_) {
        $crew[$k] = in_str($p, $k, 80);
    }

    $e = in_int($p, 'gcs_e', 1, 4);
    $v = in_int($p, 'gcs_v', 1, 5);
    $m = in_int($p, 'gcs_m', 1, 6);

    $row = [
        'autore_id'            => $user['id'],
        'assegnazione_id'      => $ass['id'] ?? null,
        'chiamata_id'          => $ass['chiamata_id'] ?? null,
        'chiamata_numero'      => $ass['chiamata_numero'] ?? null,
        'mezzo'                => $ass['mezzo_sigla'] ?? ($myShift['mezzo_sigla'] ?? null),
        'matricola_comp'       => $user['matricola'],
        'codice_partenza'      => in_pick($p, 'codice_partenza', array_keys(CODICI_PARTENZA)),
        'data_intervento'      => in_date($p, 'data_intervento'),
        'ora_attivazione'      => in_time($p, 'ora_attivazione'),
        'ora_posto'            => in_time($p, 'ora_posto'),
        'numero_civico'        => in_str($p, 'numero_civico', 20),
        'luogo_riferito'       => in_pick($p, 'luogo_riferito', array_keys(LUOGHI)),
        'tipo_chiamata'        => $list('tipo_chiamata', TIPI_CHIAMATA),
        'condizione_riferita'  => in_str($p, 'condizione_riferita', 250),
        'mezzi_attivati'       => $list('mezzi_attivati', MEZZI),
        'pz_nome'              => in_str($p, 'pz_nome', 60),
        'pz_cognome'           => in_str($p, 'pz_cognome', 60),
        'pz_data_nascita'      => in_date($p, 'pz_data_nascita'),
        'pz_eta'               => in_int($p, 'pz_eta', 0, 130),
        'pz_sesso'             => in_pick($p, 'pz_sesso', ['M', 'F']),
        'pz_cittadinanza'      => in_str($p, 'pz_cittadinanza', 60),
        'pz_indirizzo'         => in_str($p, 'pz_indirizzo', 150),
        'patologia'            => in_str($p, 'patologia', 120),
        'dinamica'             => in_str($p, 'dinamica', 120),
        'codice_patologia'     => in_str($p, 'codice_patologia', 120),
        'posizione'            => in_pick($p, 'posizione', array_keys(POSIZIONI)),
        'respiro'              => in_str($p, 'respiro', 60),
        'polso'                => in_str($p, 'polso', 40),
        'pupille'              => in_str($p, 'pupille', 60),
        'pressione'            => in_str($p, 'pressione', 20),
        'freq_resp'            => in_str($p, 'freq_resp', 20),
        'saturazione'          => in_str($p, 'saturazione', 20),
        'gcs_e'                => $e,
        'gcs_v'                => $v,
        'gcs_m'                => $m,
        'gcs_tot'              => ($e && $v && $m) ? $e + $v + $m : null,
        'glicemia'             => in_str($p, 'glicemia', 20),
        'colorito_cute'        => in_str($p, 'colorito_cute', 60),
        'temperatura'          => in_str($p, 'temperatura', 20),
        'avpu'                 => in_pick($p, 'avpu', array_keys(AVPU)),
        'trattamenti'          => array_values(array_map('strval', array_keys($tratt))),
        'farmaci_note'         => in_str($p, 'farmaci_note', 2000),
        'decesso'              => in_pick($p, 'decesso', ['SI', 'NO']),
        'ora_decesso'          => in_time($p, 'ora_decesso'),
        'data_decesso'         => in_date($p, 'data_decesso'),
        'medico_constata'      => in_str($p, 'medico_constata', 100),
        'firma_decesso'        => in_str($p, 'firma_decesso', 100),
        'arresto_cardiaco'     => in_pick($p, 'arresto_cardiaco', ['SI', 'NO']),
        'scariche_dae'         => in_str($p, 'scariche_dae', 100),
        'rifiuto_trasporto'    => in_pick($p, 'rifiuto_trasporto', ['SI', 'NO']),
        'firma_rifiuto'        => in_str($p, 'firma_rifiuto', 100),
        'rifiuto_presidi'      => in_pick($p, 'rifiuto_presidi', ['SI', 'NO']),
        'presidi_rifiutati'    => in_str($p, 'presidi_rifiutati', 150),
        'codice_rientro'       => in_pick($p, 'codice_rientro', array_keys(CODICI_RIENTRO)),
        'destinazione'         => in_str($p, 'destinazione', 120),
        'ora_inizio_trasporto' => in_time($p, 'ora_inizio_trasporto'),
        'ora_arrivo_ps'        => in_time($p, 'ora_arrivo_ps'),
        'note'                 => in_str($p, 'note', 5000),
        // dati del compilatore: sempre presi dall'account, mai dal modulo
        'comp_nome'            => $user['nome'],
        'comp_cognome'         => $user['cognome'],
        'comp_ruolo'           => role_info($user['ruolo'])['label'],
        'equipaggio'           => $crew,
    ];

    if ($row['data_intervento'] === null) {
        $errors[] = 'Inserisci una data valida per la missione.';
    }
    if (NOTE_MIN_OBBLIGATORIO && word_count((string)$row['note']) < NOTE_MIN_WORDS) {
        $errors[] = 'Le note devono contenere almeno ' . NOTE_MIN_WORDS . ' parole.';
    }

    if (!$errors) {
        $year = (int)date('Y', strtotime($row['data_intervento']));
        $row['numero'] = next_scheda_number($year);
        $row['created_at'] = time();
        $id = (string)col('schede')->insertOne($row)->getInsertedId();
        if ($ass) {
            col('assegnazioni')->updateOne(id_filter($ass['id']), ['$set' => ['scheda_id' => $id]]);
        }

        flash('Scheda salvata.');
        redirect('scheda_view.php?id=' . $id . (($p['azione'] ?? '') === 'pdf' ? '&pdf=1' : ''));
    }
} else {
    $old = scheda_prefill($user, $ass);
}

$tratt_sel = array_values(array_filter((array)($old['trattamenti'] ?? []), 'is_string'));

page_head('Scheda di Soccorso', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Relazione di Soccorso</h1><p class="sub" style="margin:0">Scheda completa precompilata con i dati noti<?= $ass ? ' · intervento <b>' . e($ass['chiamata_numero']) . '</b> · ' . e($ass['mezzo_sigla']) : '' ?></p></div>
</div>

<?php if ($errors): ?><div class="flash err"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" data-once data-wizard autocomplete="off">
<?= csrf_field() ?>
<?php if ($ass): ?><input type="hidden" name="assegnazione_id" value="<?= e($ass['id']) ?>"><?php endif; ?>

<div class="sec"><h2>1. Intestazione</h2>
  <div class="cells" style="--cols:1">
    <div class="cell"><label class="lbl">Matricola compilatore</label><input type="text" value="<?= e($user['matricola']) ?>" readonly></div>
  </div>
</div>

<div class="sec"><h2>2. Informazioni della missione</h2>
  <div class="cells" style="--cols:3">
    <div class="cell"><span class="lbl">Codice di partenza</span><?= radio_group('codice_partenza', CODICI_PARTENZA, $old['codice_partenza'] ?? null) ?></div>
    <?= text_cell('data_intervento', 'Data', 'date', 'required') ?>
    <?= text_cell('ora_attivazione', 'Ora attivazione', 'time') ?>
    <?= text_cell('ora_posto', 'Ora sul posto', 'time') ?>
    <?= text_cell('numero_civico', 'Numero civico', 'text', 'maxlength="20"') ?>
    <div class="cell"><label class="lbl" for="luogo_riferito">Luogo riferito</label><?= select_box('luogo_riferito', LUOGHI, $old['luogo_riferito'] ?? null) ?></div>
    <div class="cell full"><span class="lbl">Tipo chiamata</span><?= check_group('tipo_chiamata', TIPI_CHIAMATA, (array)($old['tipo_chiamata'] ?? [])) ?></div>
    <div class="cell full"><label class="lbl" for="condizione_riferita">Condizione riferita</label><input type="text" name="condizione_riferita" id="condizione_riferita" maxlength="250" value="<?= val('condizione_riferita') ?>"></div>
    <div class="cell full"><span class="lbl">Mezzi attivati</span><?= check_group('mezzi_attivati', MEZZI, (array)($old['mezzi_attivati'] ?? [])) ?></div>
  </div>
</div>

<div class="sec"><h2>3. Dati paziente</h2>
  <div class="cells" style="--cols:4">
    <?= text_cell('pz_nome', 'Nome', 'text', 'maxlength="60"') ?>
    <?= text_cell('pz_cognome', 'Cognome', 'text', 'maxlength="60"') ?>
    <?= text_cell('pz_data_nascita', 'Data di nascita', 'date') ?>
    <?= text_cell('pz_eta', 'Età', 'number', 'min="0" max="130"') ?>
    <div class="cell"><span class="lbl">Sesso</span><?= radio_group('pz_sesso', ['M' => 'M', 'F' => 'F'], $old['pz_sesso'] ?? null) ?></div>
    <?= text_cell('pz_cittadinanza', 'Cittadinanza', 'text', 'maxlength="60"') ?>
    <div class="cell" style="grid-column:span 2;background:#f8fafc"></div>
    <div class="cell full"><label class="lbl" for="pz_indirizzo">Indirizzo</label><input type="text" name="pz_indirizzo" id="pz_indirizzo" maxlength="150" value="<?= val('pz_indirizzo') ?>"></div>
  </div>
</div>

<div class="sec"><h2>4. Evento</h2>
  <div class="cells" style="--cols:3">
    <div class="cell"><label class="lbl" for="patologia">Patologia riscontrata all'arrivo</label><?= combo('patologia', 'Scrivi per filtrare (es: cardio…)', PATOLOGIE, (string)($old['patologia'] ?? '')) ?></div>
    <div class="cell"><label class="lbl" for="dinamica">Dinamica riscontrata</label><?= combo('dinamica', 'Scrivi per filtrare (es: incidente…)', DINAMICHE, (string)($old['dinamica'] ?? '')) ?></div>
    <div class="cell"><label class="lbl" for="codice_patologia">Codice patologia riscontrata</label><?= combo('codice_patologia', 'Scrivi per filtrare (es: trauma, STEMI…)', CODICI_PATOLOGIA, (string)($old['codice_patologia'] ?? '')) ?></div>
    <div class="cell full"><span class="lbl">Posizione paziente all'arrivo</span><?= radio_group('posizione', POSIZIONI, $old['posizione'] ?? null) ?></div>
  </div>
</div>

<div class="sec crit"><h2>5. Valutazione parametri</h2>
  <div class="cells" style="--cols:3">
    <?= text_cell('respiro', 'Respiro', 'text', 'maxlength="60"') ?>
    <?= text_cell('polso', 'Polso', 'text', 'maxlength="40"') ?>
    <?= text_cell('pupille', 'Pupille', 'text', 'maxlength="60"') ?>
    <?= text_cell('pressione', 'Pressione arteriosa', 'text', 'maxlength="20" placeholder="es. 120/80"') ?>
    <?= text_cell('freq_resp', 'Freq. respiratoria', 'text', 'maxlength="20"') ?>
    <?= text_cell('saturazione', 'Saturazione', 'text', 'maxlength="20"') ?>
    <div class="cell full">
      <p class="sub-lbl">Glasgow Coma Scale (sanitari)</p>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px">
        <div><label class="lbl" for="gcs_e">Apertura occhi (E)</label><?= select_box('gcs_e', GCS_E, $old['gcs_e'] ?? null) ?></div>
        <div><label class="lbl" for="gcs_v">Risposta verbale (V)</label><?= select_box('gcs_v', GCS_V, $old['gcs_v'] ?? null) ?></div>
        <div><label class="lbl" for="gcs_m">Risposta motoria (M)</label><?= select_box('gcs_m', GCS_M, $old['gcs_m'] ?? null) ?></div>
      </div>
    </div>
    <div class="cell"><label class="lbl" for="gcs_tot">Punteggio GCS totale</label><input class="gcs-tot" type="text" id="gcs_tot" readonly tabindex="-1"></div>
    <?= text_cell('glicemia', 'Glicemia', 'text', 'maxlength="20"') ?>
    <?= text_cell('colorito_cute', 'Colorito cute', 'text', 'maxlength="60"') ?>
    <?= text_cell('temperatura', 'Temperatura', 'text', 'maxlength="20"') ?>
    <div class="cell" style="grid-column:span 2;background:#f8fafc"></div>
    <div class="cell full"><span class="lbl">AVPU</span><?= radio_group('avpu', AVPU, $old['avpu'] ?? null) ?></div>
  </div>
</div>

<div class="sec"><h2>6. Prestazioni effettuate</h2>
  <div class="cells" style="--cols:1">
    <div class="cell">
      <label class="lbl" for="tratt-input">Trattamenti</label>
      <?= combo('', 'Scrivi per filtrare (es: EM93, ventilazione, saturazione…)', TRATTAMENTI, '', 'tratt-input') ?>
      <button type="button" class="btn teal" id="add-tratt" style="margin-top:12px">+ Aggiungi trattamento</button>
      <div class="chips" id="tratt-chips" data-selected="<?= e(json_encode($tratt_sel, JSON_UNESCAPED_UNICODE)) ?>"></div>
    </div>
    <div class="cell"><label class="lbl" for="farmaci_note">Farmaci / note</label><textarea name="farmaci_note" id="farmaci_note" style="min-height:90px" maxlength="2000"><?= val('farmaci_note') ?></textarea></div>
  </div>
</div>

<div class="sec crit"><h2>7. Eventi critici</h2>
  <div class="cells" style="--cols:2">
    <?= yesno_cell('decesso', 'Decesso') ?>
    <?= text_cell('ora_decesso', 'Ora decesso', 'time') ?>
    <?= text_cell('data_decesso', 'Data decesso', 'date') ?>
    <?= text_cell('medico_constata', 'Medico che constata', 'text', 'maxlength="100"') ?>
    <?= text_cell('firma_decesso', 'Firma', 'text', 'maxlength="100"') ?>
    <?= yesno_cell('arresto_cardiaco', 'Arresto cardiaco') ?>
    <div class="cell full"><label class="lbl" for="scariche_dae">Scariche DAE</label><input type="text" name="scariche_dae" id="scariche_dae" maxlength="100" value="<?= val('scariche_dae') ?>"></div>
  </div>
</div>

<div class="sec"><h2>8. Rifiuti</h2>
  <div class="cells" style="--cols:2">
    <?= yesno_cell('rifiuto_trasporto', 'Rifiuto trasporto') ?>
    <?= text_cell('firma_rifiuto', 'Firma rifiuto', 'text', 'maxlength="100"') ?>
    <?= yesno_cell('rifiuto_presidi', 'Rifiuto presidi') ?>
    <?= text_cell('presidi_rifiutati', 'Presidi rifiutati', 'text', 'maxlength="150"') ?>
  </div>
</div>

<div class="sec"><h2>9. Stato trasporto / codice</h2>
  <div class="cells" style="--cols:2">
    <div class="cell"><span class="lbl">Codice rientro</span><?= radio_group('codice_rientro', CODICI_RIENTRO, $old['codice_rientro'] ?? null) ?></div>
    <?= text_cell('destinazione', 'Destinazione', 'text', 'maxlength="120"') ?>
    <?= text_cell('ora_inizio_trasporto', 'Ora inizio trasporto PS', 'time') ?>
    <?= text_cell('ora_arrivo_ps', 'Ora arrivo PS', 'time') ?>
  </div>
</div>

<div class="sec"><h2>10. Annotazioni / Anamnesi</h2>
  <div class="cells" style="--cols:1">
    <div class="cell">
      <label class="lbl" for="note">Note</label>
      <textarea name="note" id="note" maxlength="5000" data-min="<?= (int)NOTE_MIN_WORDS ?>"><?= val('note') ?></textarea>
      <p class="hint" id="note-count">Requisito consigliato: almeno <?= (int)NOTE_MIN_WORDS ?> parole per il salvataggio.</p>
    </div>
  </div>
</div>

<div class="sec"><h2>11. Dati compilatore e firme</h2>
  <div class="cells" style="--cols:3">
    <div class="cell"><label class="lbl">Nome</label><input type="text" value="<?= e($user['nome']) ?>" readonly></div>
    <div class="cell"><label class="lbl">Cognome</label><input type="text" value="<?= e($user['cognome']) ?>" readonly></div>
    <div class="cell" style="background:#f8fafc"></div>
    <div class="cell full"><label class="lbl">Ruolo</label><input type="text" value="<?= e(role_info($user['ruolo'])['label']) ?>" readonly></div>
  </div>
  <p class="locknote">I dati del compilatore sono precompilati automaticamente e non modificabili per garantire la tracciabilità.</p>
  <div class="crew">
    <?php foreach (EQUIPAGGIO as $k => $label): ?>
      <div class="cell"><label class="lbl" for="<?= e($k) ?>"><?= e($label) ?></label><input type="text" name="<?= e($k) ?>" id="<?= e($k) ?>" maxlength="80" value="<?= val($k) ?>"></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="actions">
  <button class="btn teal" type="submit" name="azione" value="pdf">Scarica PDF</button>
  <button class="btn blue" type="submit" name="azione" value="salva">Salva senza scaricare</button>
  <a class="btn teal small" href="dashboard.php" style="margin-top:16px">Torna alla Dashboard</a>
</div>
</form>
<?php page_foot();
