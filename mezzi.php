<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    if ($action === 'add') {
        $err = create_mezzo(post_str('nome'), post_str('tipo'), post_str('gruppo'));
        $err ? flash(e($err), 'err') : flash('Mezzo aggiunto.');
        redirect('mezzi.php');
    }

    $m = post_str('id') !== '' ? find_one_by('mezzi', id_filter(post_str('id'))) : null;
    if (!$m) {
        flash('Mezzo non trovato.', 'err');
    } elseif ($action === 'save') {
        $nome = trim(mb_substr(post_str('nome'), 0, 40));
        $tipo = post_str('tipo');
        $gruppo = post_str('gruppo');
        if ($nome === '' || !in_array($tipo, TIPI_MEZZO, true) || !in_array($gruppo, GRUPPI_MEZZO, true)) {
            flash('Nome, tipo o gruppo non validi.', 'err');
        } elseif (mb_strtolower($nome) !== $m['nome_lc'] && col('mezzi')->countDocuments(['nome_lc' => mb_strtolower($nome)]) > 0) {
            flash('Esiste già un mezzo con questo nome.', 'err');
        } else {
            col('mezzi')->updateOne(id_filter($m['id']), ['$set' => ['nome' => $nome, 'nome_lc' => mb_strtolower($nome), 'tipo' => $tipo, 'gruppo' => $gruppo]]);
            flash('Mezzo <b>' . e($nome) . '</b> aggiornato.');
        }
    } elseif ($action === 'toggle') {
        $busy = col('shifts')->countDocuments(['mezzo_id' => $m['id'], 'end_at' => null]) + col('assegnazioni')->countDocuments(['mezzo_id' => $m['id'], 'stato' => ['$in' => ATTIVE]]);
        if (!empty($m['attivo']) && $busy > 0) {
            flash('<b>' . e($m['nome']) . '</b> ha un equipaggio in servizio o un intervento in corso: non si può disattivare ora.', 'err');
        } else {
            col('mezzi')->updateOne(id_filter($m['id']), ['$set' => ['attivo' => empty($m['attivo'])]]);
            flash('<b>' . e($m['nome']) . '</b> ' . (!empty($m['attivo']) ? 'disattivato' : 'riattivato') . '.');
        }
    } elseif ($action === 'delete') {
        $used = col('shifts')->countDocuments(['mezzo_id' => $m['id']]) + col('assegnazioni')->countDocuments(['mezzo_id' => $m['id']]);
        if ($used > 0) {
            flash('<b>' . e($m['nome']) . '</b> ha uno storico (turni o interventi): non si elimina, ma si può disattivare.', 'err');
        } else {
            col('mezzi')->deleteOne(id_filter($m['id']));
            flash('Mezzo eliminato.');
        }
    }
    redirect('mezzi.php');
}

$board = board_data();
page_head('Gestione mezzi', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Gestione mezzi</h1><p class="sub" style="margin:0">I mezzi attivi compaiono all'avvio del servizio e nella Centrale Operativa.</p></div>
</div>

<div class="tbl-wrap">
<table class="tbl">
  <tr><th>Nome</th><th>Tipo</th><th>Gruppo</th><th>Stato</th><th>A bordo ora</th><th>Azioni</th></tr>
  <?php foreach ($board['mezzi'] as $m): $f = 'm' . $m['id']; ?>
  <tr>
    <td><form id="<?= e($f) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($m['id']) ?>"></form>
      <input form="<?= e($f) ?>" type="text" name="nome" value="<?= e($m['nome']) ?>" maxlength="40" style="min-width:130px"></td>
    <td><select form="<?= e($f) ?>" name="tipo"><?php foreach (TIPI_MEZZO as $t): ?><option<?= $m['tipo'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
    <td><select form="<?= e($f) ?>" name="gruppo"><?php foreach (GRUPPI_MEZZO as $g): ?><option<?= $m['gruppo'] === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></td>
    <td><span class="badge <?= e(BADGE_MEZZO[$m['stato']]) ?>"><?= e(STATI_MEZZO[$m['stato']]) ?></span></td>
    <td><?= count($m['crew']) ?: '—' ?></td>
    <td><div style="display:flex;gap:6px;flex-wrap:wrap">
      <button form="<?= e($f) ?>" class="btn teal small" name="action" value="save">Salva</button>
      <button form="<?= e($f) ?>" class="btn <?= !empty($m['attivo']) ? 'orange' : 'blue' ?> small" name="action" value="toggle"><?= !empty($m['attivo']) ? 'Disattiva' : 'Riattiva' ?></button>
      <button form="<?= e($f) ?>" class="btn red small" name="action" value="delete" onclick="return confirm('Eliminare questo mezzo?')">Elimina</button>
    </div></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$board['mezzi']): ?><tr><td class="empty" colspan="6">Nessun mezzo. Aggiungine uno qui sotto.</td></tr><?php endif; ?>
</table>
</div>

<div class="sec add-user">
  <h2>Aggiungi mezzo</h2>
  <form method="post" data-once>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="cells" style="--cols:3">
      <div class="cell"><label class="lbl">Nome (es. Bravo 04)</label><input type="text" name="nome" required maxlength="40"></div>
      <div class="cell"><label class="lbl">Tipo</label><select name="tipo"><?php foreach (TIPI_MEZZO as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="cell"><label class="lbl">Gruppo</label><select name="gruppo"><?php foreach (GRUPPI_MEZZO as $g): ?><option><?= e($g) ?></option><?php endforeach; ?></select></div>
    </div>
    <div style="padding:14px 16px"><button class="btn teal small" type="submit">Aggiungi</button></div>
  </form>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
  <a class="btn slate small" href="personale.php">Gestione personale</a>
  <a class="btn teal small" href="dashboard.php">Torna alla Dashboard</a>
</div>
<?php page_foot();
