<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);

const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
function titolo_default(): string
{
    $a = strtotime('-6 days');
    $b = time();
    $ma = (int)date('n', $a);
    $mb = (int)date('n', $b);
    return 'Gestioni ' . ($ma === $mb ? date('j', $a) . '-' . date('j', $b) . ' ' . MESI[$mb] : date('j', $a) . ' ' . MESI[$ma] . ' - ' . date('j', $b) . ' ' . MESI[$mb]);
}

$persone = persone_gestibili($me);
$ore = ore_per_persona();
$bozza = (array)(col('settings')->findOne(['_id' => 'gestione_bozza']) ?? []);
$titolo = (string)($bozza['titolo'] ?? titolo_default());
$righe = (array)($bozza['righe'] ?? []);
$error = '';

function leggi_righe(array $persone): array
{
    $in = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
    $out = [];
    foreach ($persone as $u) {
        $r = is_array($in[$u['id']] ?? null) ? $in[$u['id']] : [];
        $g = static fn($k, $max = 300) => is_string($r[$k] ?? null) ? mb_substr(trim($r[$k]), 0, $max) : '';
        $out[$u['id']] = ['azione' => $g('azione', 20), 'grado_nuovo' => $g('grado_nuovo', 80), 'avvertimento' => $g('avvertimento'), 'merito' => $g('merito'), 'motivo' => $g('motivo')];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $titolo = mb_substr(post_str('titolo'), 0, 120);
    $righe = leggi_righe($persone);
    $action = post_str('action');
    col('settings')->updateOne(['_id' => 'gestione_bozza'], ['$set' => ['titolo' => $titolo, 'righe' => $righe, 'by' => $me['id'], 'at' => time()]], ['upsert' => true]);
    if ($action === 'pubblica') {
        [$ok, $res] = pubblica_gestione($me, $titolo, $righe);
        if ($ok) {
            col('settings')->deleteOne(['_id' => 'gestione_bozza']);
            flash('Gestione <b>' . e($titolo) . '</b> pubblicata: ' . (int)$res['provvedimenti'] . ' provvedimenti, ' . (int)$res['shift'] . ' shift azzerati. L\'avviso compare a tutti e il bot scrive nel canale.');
            redirect('gestioni.php');
        }
        $error = $res;
    } else {
        flash('Bozza salvata.');
        redirect('gestioni.php');
    }
}

$storico = find_all('gestioni', [], ['sort' => ['at' => -1], 'limit' => 8]);
$msg = ultimo_messaggio_bot();

page_head('Gestioni', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Gestione settimanale</h1><p class="sub" style="margin:0">Per ogni persona scegli l'esito. Quando le hai gestite tutte puoi pubblicare: i turni si azzerano, tutti vedono l'avviso e il bot scrive nel canale.</p></div>
</div>
<?php if ($error): ?><div class="flash err"><?= $error ?></div><?php endif; ?>
<?php if ($msg): ?>
<p class="sub">Ultimo messaggio nel canale: <b><?= e($msg['titolo'] ?? '') ?></b> ·
  <?= match ($msg['stato']) { 'completato' => 'inviato ✔', 'errore' => 'errore: ' . e($msg['errore'] ?? ''), default => 'in attesa del bot (entro un minuto)' } ?></p>
<?php endif; ?>

<form method="post" id="gform" autocomplete="off" data-total="<?= count($persone) ?>">
  <?= csrf_field() ?>
  <div class="field" style="max-width:520px"><label for="titolo">Titolo della gestione (appare nell'avviso e nel canale)</label>
    <input type="text" id="titolo" name="titolo" maxlength="120" required value="<?= e($titolo) ?>"></div>
  <p class="sub" id="gcount"></p>
  <div class="tbl-wrap">
  <table class="tbl gtbl">
    <tr><th>Matr.</th><th>Persona</th><th>Grado attuale</th><th>Ore</th><th>Esito</th><th>Nuovo grado / motivo</th><th>Avvertimento</th><th>Nota di merito</th></tr>
    <?php foreach ($persone as $u): $id = $u['id']; $r = (array)($righe[$id] ?? []); $az = (string)($r['azione'] ?? ''); ?>
    <tr data-row>
      <td><?= e($u['matricola'] ?? '') ?></td>
      <td><b><?= e(full_name($u)) ?></b><?= !empty($u['avvertimenti']) ? '<br><small style="color:#b45309">' . (int)$u['avvertimenti'] . ' avvert. totali</small>' : '' ?></td>
      <td><?= e($u['grado'] ?? '—') ?></td>
      <td><?= e(fmt_duration((int)($ore[$id] ?? 0))) ?></td>
      <td><select name="r[<?= e($id) ?>][azione]" class="g-az">
        <option value="">— da gestire —</option>
        <?php foreach (['nessuno' => 'Nessun provvedimento', 'promozione' => 'Promozione', 'degrado' => 'Degrado', 'licenziamento' => 'Licenziamento'] as $k => $l): ?><option value="<?= $k ?>"<?= $az === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></td>
      <td>
        <select name="r[<?= e($id) ?>][grado_nuovo]" class="g-grado"><option value="">Nuovo grado…</option><?php foreach (GRADI as $g): ?><option<?= ($r['grado_nuovo'] ?? '') === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select>
        <input type="text" name="r[<?= e($id) ?>][motivo]" class="g-motivo" maxlength="300" placeholder="Motivo del licenziamento" value="<?= e($r['motivo'] ?? '') ?>">
      </td>
      <td><input type="text" name="r[<?= e($id) ?>][avvertimento]" maxlength="300" placeholder="Motivo (facoltativo)" value="<?= e($r['avvertimento'] ?? '') ?>"></td>
      <td><input type="text" name="r[<?= e($id) ?>][merito]" maxlength="300" placeholder="Motivo (facoltativo)" value="<?= e($r['merito'] ?? '') ?>"></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$persone): ?><tr><td class="empty" colspan="8">Nessuna persona da gestire.</td></tr><?php endif; ?>
  </table>
  </div>
  <div style="position:sticky;bottom:0;padding:12px 0;display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn slate" type="submit" name="action" value="salva">Salva bozza</button>
    <button class="btn red" type="submit" name="action" value="pubblica" id="gpub" onclick="return confirm('Pubblicare la gestione?\n\n• vengono applicati promozioni, degradi, avvertimenti e licenziamenti\n• TUTTI gli shift si azzerano, anche quelli attivi\n• tutti vedono l\'avviso\n• il bot scrive nel canale\n\nNon si può annullare.')">Pubblica la gestione</button>
  </div>
</form>

<h2 style="margin-top:28px">Gestioni pubblicate</h2>
<div class="tbl-wrap"><table class="tbl">
  <tr><th>Gestione</th><th>Data</th><th>A cura di</th><th>Provvedimenti</th><th>Shift azzerati</th></tr>
  <?php foreach ($storico as $g): ?>
  <tr><td><b><?= e($g['titolo']) ?></b></td><td><?= e(date('d/m/Y H:i', (int)$g['at'])) ?></td><td><?= e($g['da']) ?></td><td><?= count((array)($g['righe'] ?? [])) ?></td><td><?= (int)($g['shift_archiviati'] ?? 0) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$storico): ?><tr><td class="empty" colspan="5">Ancora nessuna gestione pubblicata.</td></tr><?php endif; ?>
</table></div>
<a class="btn teal small" href="personale.php" style="margin-top:18px">Torna a Gestione personale</a>
<script>
(function () {
  var f = document.getElementById('gform'); if (!f) return;
  var rows = [].slice.call(f.querySelectorAll('[data-row]'));
  function upd() {
    var done = 0;
    rows.forEach(function (tr) {
      var az = tr.querySelector('.g-az').value;
      if (az) done++;
      tr.querySelector('.g-grado').style.display = (az === 'promozione' || az === 'degrado') ? '' : 'none';
      tr.querySelector('.g-motivo').style.display = az === 'licenziamento' ? '' : 'none';
    });
    var tot = rows.length;
    document.getElementById('gcount').innerHTML = 'Gestite <b>' + done + '</b> su <b>' + tot + '</b>' + (done === tot && tot ? ' — puoi pubblicare.' : '');
    document.getElementById('gpub').disabled = !(tot && done === tot);
  }
  f.addEventListener('change', upd); upd();
})();
</script>
<?php page_foot();
