<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);

$assignable = assignable_roles($me);
$all = array_values(array_filter(sort_by_grade(find_all('users', ['licenziato' => null])), static fn($u) => manageable($me, $u)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mat = is_array($_POST['matricola'] ?? null) ? $_POST['matricola'] : [];
    $rol = is_array($_POST['ruolo'] ?? null) ? $_POST['ruolo'] : [];
    $changed = 0;
    foreach ($all as $u) {
        $id = (string)$u['id'];
        $set = [];
        if (array_key_exists($id, $mat) && is_string($mat[$id])) {
            $m = mb_substr(trim($mat[$id]), 0, 20);
            if ($m !== (string)($u['matricola'] ?? '')) {
                $set['matricola'] = $m;
            }
        }
        if (isset($rol[$id]) && is_string($rol[$id]) && isset($assignable[$rol[$id]]) && $rol[$id] !== $u['ruolo']) {
            $set['ruolo'] = $rol[$id];
            $set['locked'] = true;            // cambio manuale: il bot non lo sovrascrive
            $set['ruolo_richiesto'] = null;
        }
        if ($set) {
            col('users')->updateOne(id_filter($id), ['$set' => $set]);
            $changed++;
        }
    }
    flash($changed ? 'Aggiornate <b>' . $changed . '</b> persone.' : 'Nessuna modifica da salvare.');
    redirect('personale.php');
}

page_head('Modifica di tutti', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Modifica di tutti</h1><p class="sub" style="margin:0">Cambia matricola e ruolo di più persone e salva una volta sola. Vengono aggiornate solo le righe che hai modificato.</p></div>
</div>
<form method="post" data-once autocomplete="off">
  <?= csrf_field() ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <tr><th>Persona</th><th>Matricola</th><th>Ruolo sul sito</th></tr>
    <?php foreach ($all as $u): $id = (string)$u['id']; ?>
    <tr>
      <td><b><?= e(full_name($u)) ?></b><br><small style="color:#6b7a90"><?= e($u['grado'] ?? '') ?: 'grado non assegnato' ?></small></td>
      <td><input type="text" name="matricola[<?= e($id) ?>]" maxlength="20" placeholder="AS-00" value="<?= e($u['matricola'] ?? '') ?>"></td>
      <td><select name="ruolo[<?= e($id) ?>]"><?php foreach ($assignable as $k => $r): ?><option value="<?= e($k) ?>"<?= $u['ruolo'] === $k ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="locknote">Chi cambia ruolo passa a gestione <b>Manuale</b>: il bot non gli modifica più ruolo e stato finché non lo riaffidi al bot.</p>
  <div style="position:sticky;bottom:0;padding:12px 0">
    <button class="btn teal" type="submit">Salva tutte le modifiche</button>
    <a class="btn slate" href="personale.php">Annulla</a>
  </div>
</form>
<?php page_foot();
