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

function v(?string $x): string
{
    $x = trim((string)$x);
    return $x === '' ? '<span class="val empty">—</span>' : '<span class="val">' . e($x) . '</span>';
}

function cell(string $label, string $htmlValue, bool $full = false): string
{
    return '<div class="cell' . ($full ? ' full' : '') . '"><span class="lbl">' . e($label) . '</span>' . $htmlValue . '</div>';
}

function tags($json, array $map = []): string
{
    $arr = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($arr) || !$arr) {
        return '<span class="val empty">—</span>';
    }
    $h = '<div class="tags">';
    foreach ($arr as $x) {
        $h .= '<span>' . e($map[$x] ?? $x) . '</span>';
    }
    return $h . '</div>';
}

$crew = is_array($s['equipaggio'] ?? null) ? $s['equipaggio'] : [];
$gcsTxt = $s['gcs_tot'] !== null ? $s['gcs_tot'] . ' (E' . $s['gcs_e'] . ' V' . $s['gcs_v'] . ' M' . $s['gcs_m'] . ')' : '';

page_head('Scheda ' . $s['numero'], 'wide');
?>
<div class="noprint" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
  <button class="btn teal small" type="button" id="btn-pdf">Scarica PDF</button>
  <button class="btn blue small" type="button" onclick="window.print()">Stampa</button>
  <a class="btn slate small" href="schede.php<?= can('vedi_tutte', $user) && (string)$s['autore_id'] !== (string)$user['id'] ? '?scope=tutte' : '' ?>">Elenco schede</a>
  <a class="btn teal small" href="dashboard.php">Dashboard</a>
</div>

<div class="sheet" id="sheet">
  <div class="head">
    <?= brand_block() ?>
    <div><h1>Relazione di Soccorso</h1>
    <p class="sub" style="margin:0">N° <b><?= e($s['numero']) ?></b> · compilata il <?= e(date('d/m/Y H:i', (int)$s['created_at'])) ?><?= !empty($s['mezzo']) ? ' · Mezzo: <b>' . e($s['mezzo']) . '</b>' : '' ?><?= !empty($s['chiamata_numero']) ? ' · Chiamata: <b>' . e($s['chiamata_numero']) . '</b>' : '' ?></p></div>
  </div>

  <div class="sec"><h2>1. Intestazione</h2>
    <div class="cells" style="--cols:1"><?= cell('Matricola compilatore', v($s['matricola_comp'])) ?></div></div>

  <div class="sec"><h2>2. Informazioni della missione</h2>
    <div class="cells" style="--cols:3">
      <?= cell('Codice di partenza', $s['codice_partenza'] ? code_badge($s['codice_partenza']) : v('')) ?>
      <?= cell('Data', v(fmt_date($s['data_intervento']))) ?>
      <?= cell('Ora attivazione', v(fmt_time($s['ora_attivazione']))) ?>
      <?= cell('Ora sul posto', v(fmt_time($s['ora_posto']))) ?>
      <?= cell('Numero civico', v($s['numero_civico'])) ?>
      <?= cell('Luogo riferito', v(LUOGHI[$s['luogo_riferito']] ?? '')) ?>
      <?= cell('Tipo chiamata', tags($s['tipo_chiamata'], TIPI_CHIAMATA), true) ?>
      <?= cell('Condizione riferita', v($s['condizione_riferita']), true) ?>
      <?= cell('Mezzi attivati', tags($s['mezzi_attivati'], MEZZI), true) ?>
    </div></div>

  <div class="sec"><h2>3. Dati paziente</h2>
    <div class="cells" style="--cols:4">
      <?= cell('Nome', v($s['pz_nome'])) ?>
      <?= cell('Cognome', v($s['pz_cognome'])) ?>
      <?= cell('Data di nascita', v(fmt_date($s['pz_data_nascita']))) ?>
      <?= cell('Età', v($s['pz_eta'] !== null ? (string)$s['pz_eta'] : '')) ?>
      <?= cell('Sesso', v($s['pz_sesso'])) ?>
      <?= cell('Cittadinanza', v($s['pz_cittadinanza'])) ?>
      <?= cell('Indirizzo', v($s['pz_indirizzo']), true) ?>
    </div></div>

  <div class="sec"><h2>4. Evento</h2>
    <div class="cells" style="--cols:3">
      <?= cell("Patologia riscontrata all'arrivo", v($s['patologia'])) ?>
      <?= cell('Dinamica riscontrata', v($s['dinamica'])) ?>
      <?= cell('Codice patologia', v($s['codice_patologia'])) ?>
      <?= cell("Posizione paziente all'arrivo", v(POSIZIONI[$s['posizione']] ?? ''), true) ?>
    </div></div>

  <div class="sec crit"><h2>5. Valutazione parametri</h2>
    <div class="cells" style="--cols:3">
      <?= cell('Respiro', v($s['respiro'])) ?>
      <?= cell('Polso', v($s['polso'])) ?>
      <?= cell('Pupille', v($s['pupille'])) ?>
      <?= cell('Pressione arteriosa', v($s['pressione'])) ?>
      <?= cell('Freq. respiratoria', v($s['freq_resp'])) ?>
      <?= cell('Saturazione', v($s['saturazione'])) ?>
      <?= cell('Glasgow Coma Scale', v($gcsTxt)) ?>
      <?= cell('Glicemia', v($s['glicemia'])) ?>
      <?= cell('Colorito cute', v($s['colorito_cute'])) ?>
      <?= cell('Temperatura', v($s['temperatura'])) ?>
      <?= cell('AVPU', v(AVPU[$s['avpu']] ?? ''), true) ?>
    </div></div>

  <div class="sec"><h2>6. Prestazioni effettuate</h2>
    <div class="cells" style="--cols:1">
      <?= cell('Trattamenti', tags($s['trattamenti'])) ?>
      <?= cell('Farmaci / note', v($s['farmaci_note'])) ?>
    </div></div>

  <div class="sec crit"><h2>7. Eventi critici</h2>
    <div class="cells" style="--cols:2">
      <?= cell('Decesso', v($s['decesso'])) ?>
      <?= cell('Ora decesso', v(fmt_time($s['ora_decesso']))) ?>
      <?= cell('Data decesso', v(fmt_date($s['data_decesso']))) ?>
      <?= cell('Medico che constata', v($s['medico_constata'])) ?>
      <?= cell('Firma', v($s['firma_decesso'])) ?>
      <?= cell('Arresto cardiaco', v($s['arresto_cardiaco'])) ?>
      <?= cell('Scariche DAE', v($s['scariche_dae']), true) ?>
    </div></div>

  <div class="sec"><h2>8. Rifiuti</h2>
    <div class="cells" style="--cols:2">
      <?= cell('Rifiuto trasporto', v($s['rifiuto_trasporto'])) ?>
      <?= cell('Firma rifiuto', v($s['firma_rifiuto'])) ?>
      <?= cell('Rifiuto presidi', v($s['rifiuto_presidi'])) ?>
      <?= cell('Presidi rifiutati', v($s['presidi_rifiutati'])) ?>
    </div></div>

  <div class="sec"><h2>9. Stato trasporto / codice</h2>
    <div class="cells" style="--cols:2">
      <?= cell('Codice rientro', $s['codice_rientro'] ? code_badge($s['codice_rientro']) : v('')) ?>
      <?= cell('Destinazione', v($s['destinazione'])) ?>
      <?= cell('Ora inizio trasporto PS', v(fmt_time($s['ora_inizio_trasporto']))) ?>
      <?= cell('Ora arrivo PS', v(fmt_time($s['ora_arrivo_ps']))) ?>
    </div></div>

  <div class="sec"><h2>10. Annotazioni / Anamnesi</h2>
    <div class="cells" style="--cols:1"><?= cell('Note', v($s['note'])) ?></div></div>

  <div class="sec"><h2>11. Dati compilatore e firme</h2>
    <div class="cells" style="--cols:3">
      <?= cell('Nome', v($s['comp_nome'])) ?>
      <?= cell('Cognome', v($s['comp_cognome'])) ?>
      <?= cell('Ruolo', v($s['comp_ruolo'])) ?>
    </div>
    <div class="crew">
      <?php foreach (EQUIPAGGIO as $k => $label): ?>
        <?= cell($label, v($crew[$k] ?? '')) ?>
      <?php endforeach; ?>
    </div></div>
</div>

<script>
(function () {
  function download() {
    var el = document.getElementById('sheet');
    if (!window.html2pdf) { window.print(); return; }
    html2pdf().set({
      margin: 8,
      filename: 'scheda-<?= e($s['numero']) ?>.pdf',
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['css', 'legacy'], avoid: '.sec' }
    }).from(el).save();
  }
  document.getElementById('btn-pdf').addEventListener('click', download);
  <?php if (!empty($_GET['pdf'])): ?>window.addEventListener('load', function () { setTimeout(download, 400); });<?php endif; ?>
})();
</script>
<?php page_foot(['https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js']);
