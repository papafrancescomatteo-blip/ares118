<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = require_login();
require_perm('personale', $me);
$users = col('users');
$assignable = assignable_roles($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    if ($action === 'add') {
        $nome = in_str($_POST, 'nome', 60);
        $cognome = in_str($_POST, 'cognome', 60) ?? '';
        $ruolo = in_pick($_POST, 'ruolo', array_keys($assignable));
        $matr = in_str($_POST, 'matricola', 20);
        $grado = in_str($_POST, 'grado', 80);
        $co = !empty($_POST['co']);
        $did = preg_replace('/\D/', '', post_str('discord_id'));
        if (!$nome || !$ruolo) {
            flash('Nome e ruolo sono obbligatori.', 'err');
        } elseif ($did !== '' && $users->countDocuments(['discord_id' => $did]) > 0) {
            flash('Esiste già una persona con questo ID Discord.', 'err');
        } else {
            $pw = random_password();
            $c = create_user($nome, $cognome, $ruolo, $matr, $did !== '' ? $did : null, $pw, false, $grado, $co);
            flash('Account creato. Username: <b>' . e($c['username']) . '</b> · password temporanea: <code>' . e($pw) . '</code> (mostrata una sola volta).');
        }
        redirect('personale.php');
    }

    $t = post_str('id') !== '' ? find_one_by('users', id_filter(post_str('id'))) : null;

    if (!$t || !manageable($me, $t)) {
        flash('Non puoi modificare questa persona.', 'err');
    } elseif ($action === 'toggle') {
        $was = !empty($t['attivo']);
        $users->updateOne(id_filter($t['id']), ['$set' => ['attivo' => !$was, 'locked' => true]]);
        flash('Account di <b>' . e(full_name($t)) . '</b> ' . ($was ? 'disattivato' : 'riattivato') . '.');
    } elseif ($action === 'reset') {
        $pw = random_password();
        $users->updateOne(id_filter($t['id']), ['$set' => ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_pw' => true]]);
        flash('Nuova password per <b>' . e($t['username']) . '</b>: <code>' . e($pw) . '</code> (mostrata una sola volta).');
    } elseif ($action === 'unlock') {
        $users->updateOne(id_filter($t['id']), ['$set' => ['locked' => false]]);
        flash('<b>' . e(full_name($t)) . '</b> torna sotto la gestione del bot.');
    }
    redirect('personale.php' . (isset($_GET['q']) && is_string($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$f = [];
if ($q !== '') {
    $re = new MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
    $f['$or'] = [['username' => $re], ['matricola' => $re], ['grado' => $re], ['discord_id' => $re]];
}
$list = find_all('users', $f, ['sort' => ['attivo' => -1, 'nome' => 1, 'cognome' => 1], 'collation' => ['locale' => 'it', 'strength' => 2]]);

// numero di schede per persona (una sola query)
$counts = [];
foreach (col('schede')->aggregate([['$group' => ['_id' => '$autore_id', 'n' => ['$sum' => 1]]]]) as $r) {
    $counts[(string)$r['_id']] = (int)$r['n'];
}

page_head('Gestione personale', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Gestione personale</h1>
  <p class="sub" style="margin:0">Nome, matricola e grado li imposti tu da qui: il bot non li sovrascrive. Le modifiche a ruolo e stato mettono la persona in gestione manuale.</p></div>
</div>

<form class="tools" method="get">
  <input type="text" name="q" placeholder="Cerca nome, matricola, grado o ID…" value="<?= e($q) ?>">
  <button class="btn teal small" type="submit">Cerca</button>
  <a class="btn slate small" href="mezzi.php">Gestione mezzi</a>
</form>

<div class="tbl-wrap">
<table class="tbl">
  <tr><th>Persona</th><th>Matricola</th><th>Grado</th><th>Ruolo</th><th>Stato</th><th>Schede</th><th>Ultimo accesso</th><th>Azioni</th></tr>
  <?php foreach ($list as $u): $ok = manageable($me, $u); $n = $counts[$u['id']] ?? 0; $fid = 'f' . $u['id']; ?>
  <tr>
    <td><b><?= e(full_name($u)) ?></b><br><small style="color:#6b7a90">login: <?= e($u['username']) ?><?= !empty($u['discord_id']) ? ' · ID ' . e($u['discord_id']) : '' ?></small></td>
    <td><?= e($u['matricola'] ?? '') ?: '—' ?></td>
    <td><?= e($u['grado'] ?? '') ?: '—' ?></td>
    <td><?= e(role_info($u['ruolo'])['label']) ?><?= !empty($u['co']) ? ' <span class="badge busy" title="Abilitato alla Centrale Operativa">CO</span>' : '' ?></td>
    <td>
      <span class="badge <?= !empty($u['attivo']) ? 'on' : 'off' ?>"><?= !empty($u['attivo']) ? 'Attivo' : 'Disattivo' ?></span>
      <?php if (!empty($u['locked'])): ?><span class="badge man" title="Gestito a mano: il bot non modifica ruolo e stato">Manuale</span><?php endif; ?>
    </td>
    <td><?= $n ?><?php if ($n): ?> · <a href="schede.php?scope=tutte&utente=<?= e($u['id']) ?>">vedi</a><?php endif; ?></td>
    <td><?= !empty($u['last_login']) ? e(date('d/m/Y H:i', (int)$u['last_login'])) : '—' ?></td>
    <td>
      <?php if ($ok): ?>
      <form id="<?= e($fid) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>"></form>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn teal small" href="persona_modifica.php?id=<?= e($u['id']) ?>">Modifica</a>
        <button form="<?= e($fid) ?>" class="btn orange small" name="action" value="reset" onclick="return confirm('Generare una nuova password per questa persona?')">Reset pw</button>
        <button form="<?= e($fid) ?>" class="btn <?= !empty($u['attivo']) ? 'red' : 'blue' ?> small" name="action" value="toggle"><?= !empty($u['attivo']) ? 'Disattiva' : 'Riattiva' ?></button>
        <?php if (!empty($u['locked']) && !empty($u['discord_id'])): ?><button form="<?= e($fid) ?>" class="btn slate small" name="action" value="unlock" title="Il bot tornerà a gestire ruolo e stato">Affida al bot</button><?php endif; ?>
      </div>
      <?php else: ?><span style="color:#8a97ab">—</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td class="empty" colspan="8">Nessuna persona trovata.</td></tr><?php endif; ?>
</table>
</div>

<div class="sec add-user">
  <h2>Aggiungi persona a mano</h2>
  <form method="post" data-once>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="cells">
      <div class="cell"><label class="lbl">Nome</label><input type="text" name="nome" required maxlength="60"></div>
      <div class="cell"><label class="lbl">Cognome</label><input type="text" name="cognome" maxlength="60"></div>
      <div class="cell"><label class="lbl">Matricola</label><input type="text" name="matricola" maxlength="20" placeholder="AS-00"></div>
      <div class="cell"><label class="lbl">Grado</label><input type="text" name="grado" list="gradi" maxlength="80"><?= gradi_datalist() ?></div>
      <div class="cell"><label class="lbl">Ruolo sul sito</label><select name="ruolo" required>
        <?php foreach ($assignable as $k => $r): ?><option value="<?= e($k) ?>"><?= e($r['label']) ?></option><?php endforeach; ?></select></div>
      <div class="cell"><label class="lbl">ID Discord (facoltativo)</label><input type="text" name="discord_id" inputmode="numeric" maxlength="25"></div>
      <div class="cell"><span class="lbl">Centrale Operativa</span><div class="opts"><label><input type="checkbox" name="co" value="1"> Abilitato al centralino</label></div></div>
    </div>
    <div style="padding:14px 16px"><button class="btn teal small" type="submit">Crea account</button></div>
  </form>
</div>
<a class="btn teal small" href="dashboard.php" style="margin-top:18px">Torna alla Dashboard</a>
<?php page_foot();
