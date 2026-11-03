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
            $users->updateOne(id_filter($c['id']), ['$set' => ['cred_sent_at' => time()]]);   // la password la consegni tu a mano
            flash('Account creato. Username: <b>' . e($c['username']) . '</b> · password temporanea: <code>' . e($pw) . '</code> (mostrata una sola volta).');
        }
        redirect('personale.php');
    }

    if (in_array($action, ['cred_nuovi', 'cred_tutti'], true)) {
        if (col('jobs')->countDocuments(['tipo' => 'credenziali', 'stato' => ['$in' => ['in_attesa', 'in_corso']]]) > 0) {
            flash('C\'è già un invio in corso: attendi che il bot lo completi.', 'err');
        } else {
            col('jobs')->insertOne(['tipo' => 'credenziali', 'target' => $action === 'cred_tutti' ? 'tutti' : 'nuovi', 'stato' => 'in_attesa', 'creato_at' => time(), 'creato_da' => full_name($me)]);
            flash('Richiesta inviata al bot: i messaggi privati partono entro un minuto circa.');
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
    } elseif ($action === 'cred_uno') {
        if (empty($t['discord_id'])) {
            flash('Questa persona non ha un ID Discord: usa "Reset pw" e consegna la password a mano.', 'err');
        } elseif (col('jobs')->countDocuments(['tipo' => 'credenziali', 'stato' => ['$in' => ['in_attesa', 'in_corso']]]) > 0) {
            flash('C\'è già un invio in corso: attendi che il bot lo completi.', 'err');
        } else {
            col('jobs')->insertOne(['tipo' => 'credenziali', 'target' => $t['id'], 'stato' => 'in_attesa', 'creato_at' => time(), 'creato_da' => full_name($me)]);
            flash('Credenziali di <b>' . e(full_name($t)) . '</b> in invio dal bot.');
        }
    } elseif ($action === 'conferma_ruolo' || $action === 'rifiuta_ruolo') {
        $req = (string)($t['ruolo_richiesto'] ?? '');
        if (!can('conferma', $me)) {
            flash('Solo l\'amministratore del sito può confermare i ruoli.', 'err');
        } elseif (!isset(ROLES[$req])) {
            flash('Nessuna richiesta di ruolo da gestire per questa persona.', 'err');
        } elseif ($action === 'conferma_ruolo') {
            $users->updateOne(id_filter($t['id']), ['$set' => ['ruolo' => $req, 'ruolo_richiesto' => null, 'ruolo_rifiutato' => null]]);
            flash('<b>' . e(full_name($t)) . '</b> ora è <b>' . e(ROLES[$req]['label']) . '</b>.');
        } else {
            $users->updateOne(id_filter($t['id']), ['$set' => ['ruolo_richiesto' => null, 'ruolo_rifiutato' => $req]]);
            flash('Richiesta di <b>' . e(ROLES[$req]['label']) . '</b> per ' . e(full_name($t)) . ' rifiutata: resta ' . e(role_info($t['ruolo'])['label']) . '.');
        }
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

$richieste = array_values(array_filter(find_all('users', ['ruolo_richiesto' => ['$ne' => null]]), static fn($u) => !empty($u['ruolo_richiesto']) && isset(ROLES[$u['ruolo_richiesto']])));
$lastJob = find_all('jobs', ['tipo' => 'credenziali'], ['sort' => ['creato_at' => -1], 'limit' => 1])[0] ?? null;
$botDoc = (array)(col('settings')->findOne(['_id' => 'bot']) ?? []);
$botSeen = (int)($botDoc['seen_at'] ?? 0);
$botUnauth = (int)($botDoc['unauth_at'] ?? 0);
$nuoviSenzaCred = 0;
foreach ($list as $u) {
    if (!empty($u['discord_id']) && !empty($u['attivo']) && empty($u['cred_sent_at']) && !empty($u['must_change_pw'])) {
        $nuoviSenzaCred++;
    }
}

page_head('Gestione personale', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Gestione personale</h1>
  <p class="sub" style="margin:0">Nome, matricola e grado li imposti tu da qui: il bot non li sovrascrive. Le modifiche a ruolo e stato mettono la persona in gestione manuale.</p></div>
</div>

<?php if ($richieste && can('conferma', $me)): ?>
<div class="sec crit">
  <h2>Ruoli da confermare (<?= count($richieste) ?>)</h2>
  <p class="locknote">Discord assegna a queste persone un ruolo con poteri di gestione. Finché non lo confermi hanno il ruolo attuale.</p>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>Persona</th><th>Ruolo attuale</th><th>Ruolo richiesto da Discord</th><th>Azioni</th></tr>
    <?php foreach ($richieste as $u): $fid = 'r' . $u['id']; ?>
    <tr>
      <td><b><?= e(full_name($u)) ?></b><br><small style="color:#6b7a90"><?= e($u['grado'] ?? '') ?: e($u['username']) ?></small></td>
      <td><?= e(role_info($u['ruolo'])['label']) ?></td>
      <td><b><?= e(ROLES[$u['ruolo_richiesto']]['label']) ?></b></td>
      <td><form id="<?= e($fid) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>">
        <button class="btn teal small" name="action" value="conferma_ruolo">Conferma</button>
        <button class="btn slate small" name="action" value="rifiuta_ruolo" onclick="return confirm('Rifiutare la richiesta?')">Rifiuta</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php elseif ($richieste): ?>
<div class="box info"><b><?= count($richieste) ?></b> ruoli dirigenziali attendono la conferma dell'amministratore del sito.</div>
<?php endif; ?>

<div class="box">
  <h3>Credenziali di accesso</h3>
  <p>Il bot crea gli account da Discord ma non scrive a nessuno finché non lo chiedi qui. Ogni persona riceve in messaggio privato username e una <b>password temporanea</b> da cambiare al primo accesso.</p>
  <?php if ($botSeen && time() - $botSeen < 240): ?>
    <p class="botst ok">Bot collegato · ultimo contatto <?= e(date('H:i:s', $botSeen)) ?></p>
  <?php elseif ($botSeen): ?>
    <p class="botst warn">Il bot non si fa sentire dalle <?= e(date('H:i', $botSeen)) ?> (<?= (int)((time() - $botSeen) / 60) ?> minuti fa): controlla che sia acceso.</p>
  <?php elseif ($botUnauth): ?>
    <p class="botst warn">Il bot contatta il sito ma il token non coincide: <b>ARES_BOT_TOKEN</b> sul bot deve essere uguale a <b>BOT_TOKEN</b> su Render.</p>
  <?php else: ?>
    <p class="botst warn">Il bot non si è mai collegato: controlla che <code>require('./ares_sync')(client)</code> sia in index.js e che le variabili ARES_* siano nel .env.</p>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px"><?= csrf_field() ?>
    <button class="btn teal small" name="action" value="cred_nuovi" onclick="return confirm('Inviare le credenziali a chi non le ha ancora ricevute?')">Invia credenziali a chi non le ha ricevute (<?= (int)$nuoviSenzaCred ?>)</button>
    <button class="btn slate small" name="action" value="cred_tutti" onclick="return confirm('Genera una NUOVA password per TUTTI e la invia in DM, anche a chi ha già accesso. Continuare?')">Reinvia a tutti (nuove password)</button>
  </form>
  <?php if ($lastJob): ?>
  <p style="margin-top:10px"><small>Ultimo invio, <?= e(date('d/m/Y H:i', (int)$lastJob['creato_at'])) ?>:
    <?php if (($lastJob['stato'] ?? '') === 'completato'): ?>
      <b><?= (int)($lastJob['inviati'] ?? 0) ?></b> inviati<?php if (!empty($lastJob['falliti'])): ?>, <b>non consegnati</b>: <?= e(implode(', ', (array)$lastJob['falliti'])) ?>. Per loro usa "Reset pw" e consegna la password a mano.<?php endif; ?>
    <?php else: ?><b>in corso</b> (il bot lo prende entro un minuto)<?php endif; ?></small></p>
  <?php endif; ?>
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
    <td><?= e(role_info($u['ruolo'])['label']) ?><?= !empty($u['ruolo_richiesto']) && isset(ROLES[$u['ruolo_richiesto']]) ? '<br><small style="color:#7a4b00">in attesa: ' . e(ROLES[$u['ruolo_richiesto']]['label']) . '</small>' : '' ?><?= !empty($u['co']) ? ' <span class="badge busy" title="Abilitato alla Centrale Operativa">CO</span>' : '' ?></td>
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
        <?php if (!empty($u['discord_id'])): ?><button form="<?= e($fid) ?>" class="btn blue small" name="action" value="cred_uno" title="Il bot invia le credenziali in DM"><?= !empty($u['cred_sent_at']) ? 'Reinvia DM' : 'Invia DM' ?></button><?php endif; ?>
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
