<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$id = (string)(is_string($_GET['id'] ?? null) ? $_GET['id'] : '');

$s = find_one_by('schede', id_filter($id));
if (!$s || ((string)$s['autore_id'] !== (string)$user['id'] && !can('vedi_tutte', $user))) {
    http_response_code(404);
    exit('Scheda non trovata.');
}

// ---- Aiuti per il "modulo" (stesso aspetto del PDF del vecchio sito) ----
function bx(?string $x, bool $big = false): string
{
    $x = trim((string)$x);
    return '<span class="in' . ($big ? ' big' : '') . '">' . ($x === '' ? '&nbsp;' : e($x)) . '</span>';
}

function pair(string $label, string $inner, int $w = 1, string $cls = ''): string
{
    return '<div class="mp ' . $cls . '" style="flex:' . $w . '"><div class="ml">' . e($label) . '</div><div class="mv">' . $inner . '</div></div>';
}

function mrow(string ...$pairs): string
{
    return '<div class="mrow">' . implode('', $pairs) . '</div>';
}

function radios(array $opts, ?string $cur, string $cls = ''): string
{
    $h = '<span class="opts ' . $cls . '">';
    foreach ($opts as $k => $label) {
        $h .= '<span class="o"><i class="rd' . ((string)$cur === (string)$k && $cur !== null && $cur !== '' ? ' on' : '') . '"></i>' . e($label) . '</span>';
    }
    return $h . '</span>';
}

function checks(array $opts, $cur, string $cls = ''): string
{
    $arr = is_array($cur) ? $cur : (array)json_decode((string)$cur, true);
    $h = '<span class="opts ' . $cls . '">';
    foreach ($opts as $k => $label) {
        $h .= '<span class="o"><i class="ck' . (in_array((string)$k, array_map('strval', $arr), true) ? ' on' : '') . '"></i>' . e($label) . '</span>';
    }
    return $h . '</span>';
}

function msec(string $title, string $body, bool $crit = false): string
{
    return '<div class="msec"><div class="mh' . ($crit ? ' crit' : '') . '">' . e($title) . '</div>' . $body . '</div>';
}

function chips($json): string
{
    $arr = is_array($json) ? $json : (array)json_decode((string)$json, true);
    if (!$arr) {
        return '<span class="in">&nbsp;</span>';
    }
    $h = '<span class="chips-v">';
    foreach ($arr as $x) {
        $h .= '<span>' . e($x) . '</span>';
    }
    return $h . '</span>';
}

$crew = is_array($s['equipaggio'] ?? null) ? $s['equipaggio'] : [];
$words = preg_match_all('/\S+/u', (string)($s['note'] ?? ''));

page_head('Scheda ' . $s['numero'], 'wide');
?>
<div class="noprint" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
  <button class="btn teal small" type="button" id="btn-pdf">Scarica PDF</button>
  <button class="btn blue small" type="button" onclick="window.print()">Stampa</button>
  <a class="btn slate small" href="schede.php<?= can('vedi_tutte', $user) && (string)$s['autore_id'] !== (string)$user['id'] ? '?scope=tutte' : '' ?>">Elenco schede</a>
  <a class="btn teal small" href="dashboard.php">Dashboard</a>
</div>

<div class="modulo-wrap"><div class="modulo" id="sheet">
  <div class="msec">
    <div class="mh">1. INTESTAZIONE</div>
    <div class="mhead">
      <div class="mlogo"><img src="<?= e(logo_url()) ?>" alt="ARES 118"><b>ARES 118 LAZIO</b><small>Unità di Emergenza</small></div>
      <div class="mtitle">RELAZIONE DI SOCCORSO</div>
      <?= pair('Matricola compilatore', bx($s['matricola_comp']), 1, 'last') ?>
    </div>
    <div class="mmeta">N° <b><?= e($s['numero']) ?></b> · compilata il <?= e(date('d/m/Y H:i', (int)$s['created_at'])) ?><?= !empty($s['mezzo']) ? ' · Mezzo: <b>' . e($s['mezzo']) . '</b>' : '' ?><?= !empty($s['chiamata_numero']) ? ' · Chiamata: <b>' . e($s['chiamata_numero']) . '</b>' : '' ?></div>
  </div>

  <?= msec('2. INFORMAZIONI DELLA MISSIONE',
      mrow(pair('Codice di partenza', radios(CODICI_PARTENZA, $s['codice_partenza'], 'two'), 2), pair('Data', bx(fmt_date($s['data_intervento']))), pair('Ora attivazione', bx(fmt_time($s['ora_attivazione'])))) .
      mrow(pair('Ora sul posto', bx(fmt_time($s['ora_posto'])), 2), pair('Numero civico', bx($s['numero_civico'])), pair('Luogo riferito', bx(LUOGHI[$s['luogo_riferito']] ?? ''))) .
      mrow(pair('Tipo chiamata', checks(TIPI_CHIAMATA, $s['tipo_chiamata']))) .
      mrow(pair('Condizione riferita', bx($s['condizione_riferita']))) .
      mrow(pair('Mezzi attivati', checks(MEZZI, $s['mezzi_attivati'])))) ?>

  <?= msec('3. DATI PAZIENTE',
      mrow(pair('Nome', bx($s['pz_nome'])), pair('Cognome', bx($s['pz_cognome'])), pair('Data di nascita', bx(fmt_date($s['pz_data_nascita']))), pair('Età', bx($s['pz_eta'] !== null ? (string)$s['pz_eta'] : '')), pair('Sesso', radios(['M' => 'M', 'F' => 'F'], $s['pz_sesso']))) .
      mrow(pair('Cittadinanza', bx($s['pz_cittadinanza'])), pair('Indirizzo', bx($s['pz_indirizzo']), 3))) ?>

  <?= msec('4. EVENTO',
      mrow(pair('Patologia riscontrata all\'arrivo', bx($s['patologia'])), pair('Dinamica riscontrata', bx($s['dinamica'])), pair('Codice patologia riscontrata', bx($s['codice_patologia']))) .
      mrow(pair('Posizione paziente all\'arrivo', radios(POSIZIONI, $s['posizione'], 'wide')))) ?>

  <?= msec('5. VALUTAZIONE PARAMETRI',
      mrow(pair('Respiro', bx($s['respiro']), 2), pair('Polso', bx($s['polso'])), pair('Pupille', bx($s['pupille']))) .
      mrow(pair('Pressione arteriosa', bx($s['pressione']), 2), pair('Freq. respiratoria', bx($s['freq_resp'])), pair('Saturazione', bx($s['saturazione']))) .
      mrow(pair('Glasgow Coma Scale (sanitari)', '<span class="gcs">' . bx(GCS_E[$s['gcs_e']] ?? '', true) . bx(GCS_V[$s['gcs_v']] ?? '', true) . bx(GCS_M[$s['gcs_m']] ?? '', true) . '</span>')) .
      mrow(pair('Punteggio GCS totale', bx($s['gcs_tot'] !== null ? (string)$s['gcs_tot'] : '') . '<small class="hn">13-15 lieve · 9-12 moderata · ≤ 8 grave (considerare vie aeree)</small>', 2), pair('Glicemia', bx($s['glicemia'])), pair('Colorito cute', bx($s['colorito_cute']))) .
      mrow(pair('Temperatura', bx($s['temperatura']), 2), pair('AVPU', radios(AVPU, $s['avpu']), 2)), true) ?>

  <?= msec('6. PRESTAZIONI EFFETTUATE',
      mrow(pair('Trattamenti', chips($s['trattamenti']))) .
      mrow(pair('Farmaci / note intervento', bx($s['farmaci_note'])))) ?>

  <?= msec('7. EVENTI CRITICI',
      mrow(pair('Decesso', radios(['SI' => 'SI', 'NO' => 'NO'], $s['decesso'])), pair('Ora decesso', bx(fmt_time($s['ora_decesso']))), pair('Data decesso', bx(fmt_date($s['data_decesso'])))) .
      mrow(pair('Medico che constata', bx($s['medico_constata']), 2), pair('Firma', bx($s['firma_decesso']))) .
      mrow(pair('Arresto cardiaco', radios(['SI' => 'SI', 'NO' => 'NO'], $s['arresto_cardiaco'])), pair('Scariche DAE', bx($s['scariche_dae']), 2)), true) ?>

  <?= msec('8. RIFIUTI',
      mrow(pair('Rifiuto trasporto', radios(['SI' => 'SI', 'NO' => 'NO'], $s['rifiuto_trasporto'])), pair('Firma rifiuto', bx($s['firma_rifiuto']))) .
      mrow(pair('Rifiuto presidi', radios(['SI' => 'SI', 'NO' => 'NO'], $s['rifiuto_presidi'])), pair('Presidi rifiutati', bx($s['presidi_rifiutati']))), true) ?>

  <?= msec('9. STATO TRASPORTO / CODICE',
      mrow(pair('Codice rientro', radios(CODICI_RIENTRO, $s['codice_rientro']), 2), pair('Destinazione', bx($s['destinazione']))) .
      mrow(pair('Ora inizio trasporto PS', bx(fmt_time($s['ora_inizio_trasporto'])), 2), pair('Ora arrivo PS', bx(fmt_time($s['ora_arrivo_ps']))))) ?>

  <?= msec('10. ANNOTAZIONI / ANAMNESI',
      mrow(pair('Note', bx($s['note'], true))) . '<div class="mfoot"><b>Parole inserite: ' . (int)$words . '</b></div>') ?>

  <?php
  $firme = '';
  foreach (EQUIPAGGIO as $k => $label) {
      $firme .= '<div class="fc"><small>' . e($label) . '</small>' . bx($crew[$k] ?? '') . '</div>';
  }
  echo msec('11. DATI COMPILATORE E FIRME',
      mrow(pair('Nome', bx($s['comp_nome'])), pair('Cognome', bx($s['comp_cognome'])), pair('Ruolo', bx($s['comp_ruolo']), 2)) .
      '<div class="mfoot">I dati del compilatore sono precompilati automaticamente e non modificabili per garantire la tracciabilità.</div>' .
      '<div class="mfoot up">Nominativi dei firmatari dell\'equipaggio</div><div class="firme">' . $firme . '</div>');
  ?>
</div></div>

<script>
(function () {
  function download() {
    var el = document.getElementById('sheet');
    if (!window.html2pdf) { window.print(); return; }
    var w = 1240;                      // larghezza fissa: il PDF e' sempre uguale su qualsiasi schermo
    el.style.width = w + 'px';
    var h = el.offsetHeight;
    html2pdf().set({
      margin: 0,
      filename: 'scheda-<?= e($s['numero']) ?>.pdf',
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, useCORS: true, width: w, windowWidth: w, scrollX: 0, scrollY: 0 },
      jsPDF: { unit: 'px', format: [w, h], orientation: 'portrait', hotfixes: ['px_scaling'] },
      pagebreak: { mode: [] }
    }).from(el).save().then(function () { el.style.width = ''; }, function () { el.style.width = ''; });
  }
  document.getElementById('btn-pdf').addEventListener('click', download);
  <?php if (!empty($_GET['pdf'])): ?>window.addEventListener('load', function () { setTimeout(download, 400); });<?php endif; ?>
})();
</script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js']);
