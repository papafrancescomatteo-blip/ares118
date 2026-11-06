<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$fired = null;   // licenziato che deve ancora premere OK
if (!empty($_SESSION['fired_uid'])) {
    $fu = find_one_by('users', id_filter((string)$_SESSION['fired_uid']));
    if ($fu && !empty($fu['licenziato']) && empty($fu['licenziato']['ack'])) {
        $fired = $fu;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ack_licenziato') {
    csrf_check();
    $fid = (string)(is_string($_POST['fid'] ?? null) ? $_POST['fid'] : '');
    $fu = $fid !== '' ? find_one_by('users', id_filter($fid)) : null;
    if ($fu && !empty($fu['licenziato']) && (string)($_SESSION['fired_uid'] ?? '') === (string)$fu['id']) {
        col('users')->updateOne(id_filter($fu['id']), ['$set' => ['licenziato' => array_merge((array)$fu['licenziato'], ['ack' => true])]]);
        unset($_SESSION['fired_uid']);
        $fired = null;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    if (col('login_log')->countDocuments(['ip' => $ip, 'ts' => ['$gt' => time() - 900]]) >= 8) {
        $error = 'Troppi tentativi falliti. Riprova tra qualche minuto.';
    } else {
        $lc = mb_strtolower($username);
        $u = find_one_by('users', ['$or' => [['username_lc' => $lc], ['old_login_lc' => $lc]]]);
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
        if ($u && !empty($u['licenziato']) && empty($u['licenziato']['ack'])) {
            $_SESSION['fired_uid'] = $u['id'];   // tentativo di accesso di chi e' stato licenziato: mostra il motivo
            $fired = $u;
        } else {
            $error = 'Credenziali non valide o account disattivato.';
        }
    }
}

page_head('Login', 'narrow', 'dark flat');
?>
<div class="card login-card">
<img class="logo-big" src="<?= e(logo_url()) ?>" alt="ARES 118">
<h1>Accedi al turno</h1>
<p class="sub">Dashboard operativa ARES 118: schede di soccorso, mezzi e Centrale Operativa.</p>
<?php if ($fired): ?>
<div class="fired-box">
  <b>Sei stato licenziato per il seguente motivo:</b>
  <p><?= e($fired['licenziato']['motivo']) ?></p>
  <small>Da <?= e($fired['licenziato']['da']) ?> · <?= e(date('d/m/Y', (int)$fired['licenziato']['at'])) ?> · le credenziali sono state eliminate.</small>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ack_licenziato"><input type="hidden" name="fid" value="<?= e($fired['id']) ?>"><button class="btn red" type="submit">OK</button></form>
</div>
<?php endif; ?>
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
</div>
<?php page_foot(['assets/js/app.js?v=4']);
