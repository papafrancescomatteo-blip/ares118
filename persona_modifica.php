<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);

$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : post_str('id');
$t = $id !== '' ? find_one_by('users', id_filter($id)) : null;
if (!$t || !manageable($me, $t)) {
    flash('Non puoi modificare questa persona.', 'err');
    redirect('personale.php');
}
$assignable = assignable_roles($me);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nome = in_str($_POST, 'nome', 60);
    $cognome = in_str($_POST, 'cognome', 60) ?? '';
    $username = in_str($_POST, 'username', 120);
    $matr = in_str($_POST, 'matricola', 20);
    $grado = in_str($_POST, 'grado', 80);
    $ruolo = in_pick($_POST, 'ruolo', array_keys($assignable));
    $co = !empty($_POST['co']);

    if (!$nome || !$username || !$ruolo) {
        $error = 'Nome, username e ruolo sono obbligatori.';
    } elseif (mb_strtolower($username) !== ($t['username_lc'] ?? '') && col('users')->countDocuments(['username_lc' => mb_strtolower($username)]) > 0) {
        $error = 'Questo username è già usato da un\'altra persona.';
    } else {
        // cambiare ruolo o abilitazione CO a mano mette la persona in "Manuale": il bot non la sovrascrive
        $lock = ($ruolo !== $t['ruolo'] || $co !== !empty($t['co'])) ? true : !empty($t['locked']);
        col('users')->updateOne(id_filter($t['id']), ['$set' => [
            'nome' => $nome, 'cognome' => $cognome, 'username' => $username, 'username_lc' => mb_strtolower($username),
            'matricola' => $matr, 'grado' => $grado, 'ruolo' => $ruolo, 'co' => $co, 'locked' => $lock,
            'ruolo_richiesto' => $ruolo !== $t['ruolo'] ? null : ($t['ruolo_richiesto'] ?? null),
        ]]);
        flash('Dati di <b>' . e($nome . ' ' . $cognome) . '</b> aggiornati.');
        redirect('personale.php');
    }
    $t = array_merge($t, ['nome' => (string)$nome, 'cognome' => $cognome, 'username' => (string)$username, 'matricola' => $matr, 'grado' => $grado, 'ruolo' => (string)$ruolo, 'co' => $co]);
}

page_head('Modifica persona', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Modifica persona</h1><p class="sub" style="margin:0">Questi dati sono quelli che la persona vede nella sua dashboard.</p></div>
</div>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" data-once autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
  <div class="sec"><h2>Profilo</h2>
    <div class="cells" style="--cols:2">
      <div class="cell"><label class="lbl" for="nome">Nome</label><input type="text" id="nome" name="nome" required maxlength="60" value="<?= e($t['nome']) ?>"></div>
      <div class="cell"><label class="lbl" for="cognome">Cognome</label><input type="text" id="cognome" name="cognome" maxlength="60" value="<?= e($t['cognome']) ?>"></div>
      <div class="cell"><label class="lbl" for="matricola">Matricola</label><input type="text" id="matricola" name="matricola" maxlength="20" placeholder="AS-00" value="<?= e($t['matricola'] ?? '') ?>"></div>
      <div class="cell"><label class="lbl" for="grado">Grado</label><input type="text" id="grado" name="grado" list="gradi" maxlength="80" value="<?= e($t['grado'] ?? '') ?>"><?= gradi_datalist() ?></div>
    </div>
  </div>
  <div class="sec"><h2>Accesso e permessi</h2>
    <div class="cells" style="--cols:2">
      <div class="cell"><label class="lbl" for="username">Username di accesso</label><input type="text" id="username" name="username" required maxlength="120" value="<?= e($t['username']) ?>"></div>
      <div class="cell"><label class="lbl" for="ruolo">Ruolo sul sito (permessi)</label>
        <select id="ruolo" name="ruolo"><?php foreach ($assignable as $k => $r): ?><option value="<?= e($k) ?>"<?= $t['ruolo'] === $k ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select></div>
      <div class="cell full"><div class="opts"><label><input type="checkbox" name="co" value="1"<?= !empty($t['co']) ? ' checked' : '' ?>> Abilitato alla Centrale Operativa (può gestire le chiamate)</label></div></div>
    </div>
    <p class="locknote">Se cambi il ruolo o l'abilitazione CO la persona passa a gestione <b>Manuale</b>: il bot smette di modificarle ruolo e stato finché non la riaffidi al bot.</p>
  </div>
  <button class="btn teal" type="submit">Salva</button>
  <a class="btn slate" href="personale.php">Annulla</a>
</form>
<?php page_foot();
