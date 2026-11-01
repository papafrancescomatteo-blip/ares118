<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    if (col('login_log')->countDocuments(['ip' => $ip, 'ts' => ['$gt' => time() - 900]]) >= 8) {
        $error = 'Troppi tentativi falliti. Riprova tra qualche minuto.';
    } else {
        $u = find_one_by('users', ['username_lc' => mb_strtolower($username)]);
        // verifica sempre un hash, anche se l'utente non esiste (tempi uniformi)
        $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuAbCdEfGhIjKlMnOpQrStUvWxYz012346';
        $ok = password_verify($password, $hash) && $u && !empty($u['attivo']);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            $_SESSION['last'] = time();
            col('users')->updateOne(id_filter($u['id']), ['$set' => ['last_login' => time()]]);
            col('login_log')->deleteMany(['ip' => $ip]);
            redirect($u['must_change_pw'] ? 'password.php' : 'dashboard.php');
        }
        col('login_log')->insertOne(['ip' => $ip, 'username' => mb_substr($username, 0, 120), 'ts' => time()]);
        col('login_log')->deleteMany(['ts' => ['$lt' => time() - 86400]]);
        $error = 'Credenziali non valide o account disattivato.';
    }
}

page_head('Login');
?>
<h1>Accedi al turno</h1>
<p class="sub">Dashboard operativa ARES 118: schede di soccorso, mezzi e Centrale Operativa.</p>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="login" autocomplete="on" data-once>
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">Username</label>
    <input id="username" name="username" type="text" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
  </div>
  <button class="btn teal" type="submit">Entra</button>
</form>
<?php page_foot(['assets/js/app.js?v=2']);
