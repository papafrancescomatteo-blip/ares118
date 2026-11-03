<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login(true);
$forced = (bool)$user['must_change_pw'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $rep = (string)($_POST['repeat'] ?? '');
    if (!$forced && !password_verify($cur, $user['password_hash'])) {
        $error = 'La password attuale non è corretta.';
    } elseif (mb_strlen($new) < 8) {
        $error = 'La nuova password deve avere almeno 8 caratteri.';
    } elseif ($new !== $rep) {
        $error = 'Le due password non coincidono.';
    } elseif (password_verify($new, $user['password_hash'])) {
        $error = 'Scegli una password diversa da quella attuale.';
    } else {
        col('users')->updateOne(id_filter($user['id']), ['$set' => [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'must_change_pw' => false,
        ]]);
        session_regenerate_id(true);
        flash('Password aggiornata.');
        redirect('dashboard.php');
    }
}

page_head('Cambia password', 'narrow', 'dark');
?>
<h1>Cambia password</h1>
<p class="sub"><?= $forced ? 'Prima di continuare scegli una password personale.' : 'Aggiorna la tua password di accesso.' ?></p>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <?php if (!$forced): ?>
  <div class="field"><label for="current">Password attuale</label><input id="current" name="current" type="password" required autocomplete="current-password"></div>
  <?php endif; ?>
  <div class="field"><label for="new">Nuova password (min. 8 caratteri)</label><input id="new" name="new" type="password" required minlength="8" autocomplete="new-password"></div>
  <div class="field"><label for="repeat">Ripeti nuova password</label><input id="repeat" name="repeat" type="password" required minlength="8" autocomplete="new-password"></div>
  <button class="btn teal" type="submit">Salva password</button>
  <?php if (!$forced): ?><a class="btn slate" href="dashboard.php">Annulla</a><?php endif; ?>
</form>
<?php page_foot();
