<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);

$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : post_str('id');
$t = $id !== '' ? find_one_by('users', id_filter($id)) : null;
if (!$t || !manageable($me, $t) || !empty($t['licenziato'])) {
    flash('Non puoi gestire questa persona.', 'err');
    redirect('personale.php');
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tipo = in_pick($_POST, 'tipo', array_keys(TIPI_PROVV));
    $testo = in_str($_POST, 'testo', 300) ?? '';
    $grado = in_str($_POST, 'grado_nuovo', 80);
    if (!$tipo) {
        $error = 'Scegli il tipo di provvedimento.';
    } else {
        $p = applica_provvedimento($t, $tipo, $testo, $grado, $me, null);
        if (is_string($p)) {
            $error = $p;
        } else {
            if ($tipo !== 'licenziamento') {
                avviso_crea($t['id'], TIPI_PROVV[$tipo], [riga_personale($p)], full_name($me));
            }
            bot_messaggio([riga_pubblica($t, $p), '', 'Provvedimento di **' . full_name($me) . '**.'], 'Gestione');
            flash(TIPI_PROVV[$tipo] . ' registrato per <b>' . e(full_name($t)) . '</b>: ' . ($tipo === 'licenziamento' ? 'credenziali eliminate' : 'gli compare subito la schermata') . ' e il bot scrive nel canale.');
            redirect('personale.php');
        }
    }
}

page_head('Gestisci ' . full_name($t), 'narrow');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Gestisci <?= e(full_name($t)) ?></h1>
  <p class="sub" style="margin:0"><?= e($t['grado'] ?? 'Grado non assegnato') ?> · <?= e(role_info($t['ruolo'])['label']) ?> · <?= (int)($t['avvertimenti'] ?? 0) ?> avvertimenti · <?= (int)($t['note_merito'] ?? 0) ?> note di merito</p></div>
</div>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" data-once autocomplete="off" id="sform">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
  <div class="sec"><h2>Provvedimento</h2>
    <div class="cells" style="--cols:1">
      <div class="cell"><label class="lbl" for="tipo">Tipo</label>
        <select name="tipo" id="tipo" required><option value="">Scegli…</option>
          <?php foreach (TIPI_PROVV as $k => $l): ?><option value="<?= e($k) ?>"<?= (($_POST['tipo'] ?? '') === $k) ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="cell" id="c-grado"><label class="lbl" for="grado_nuovo">Nuovo grado</label>
        <select name="grado_nuovo" id="grado_nuovo"><option value="">Scegli…</option><?php foreach (GRADI as $g): ?><option<?= (($_POST['grado_nuovo'] ?? '') === $g) ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
      <div class="cell"><label class="lbl" for="testo" id="l-testo">Motivo / testo</label>
        <input type="text" name="testo" id="testo" maxlength="300" value="<?= e($_POST['testo'] ?? '') ?>"></div>
    </div>
    <p class="locknote">Il provvedimento è immediato: la persona vede subito la schermata con il tuo nome e il bot lo scrive nel canale <b>#gestioni</b>. Il licenziamento elimina le credenziali all'istante.</p>
  </div>
  <button class="btn teal" type="submit">Conferma provvedimento</button>
  <a class="btn slate" href="personale.php">Annulla</a>
</form>
<script>
(function () {
  var t = document.getElementById('tipo'), g = document.getElementById('c-grado'), l = document.getElementById('l-testo');
  function u() {
    var v = t.value;
    g.style.display = (v === 'promozione' || v === 'degrado') ? '' : 'none';
    l.textContent = v === 'licenziamento' ? 'Motivo del licenziamento (obbligatorio)' : (v === 'promozione' || v === 'degrado') ? 'Nota (facoltativa)' : 'Motivo (obbligatorio)';
  }
  t.addEventListener('change', u); u();
  document.getElementById('sform').addEventListener('submit', function (e) {
    if (t.value === 'licenziamento' && !confirm('Licenziare questa persona? Le credenziali vengono eliminate subito.')) e.preventDefault();
  });
})();
</script>
<?php page_foot();
