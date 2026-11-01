<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
$all = (($_GET['scope'] ?? '') === 'tutte');
if ($all) {
    require_perm('vedi_tutte', $user);
}
$page = max(1, (int)($_GET['p'] ?? 1));

/** Parametro GET come testo (ignora array e valori strani) */
function gs(string $k): string
{
    return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
}

$f = [];
if (!$all) {
    $f['autore_id'] = $user['id'];
}
$q = gs('q');
if ($q !== '') {
    $re = new MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
    $f['$or'] = array_map(static fn($k) => [$k => $re], ['numero', 'pz_nome', 'pz_cognome', 'destinazione', 'comp_nome', 'comp_cognome']);
}
$cod = gs('codice');
if (isset(CODICI_RIENTRO[$cod])) {
    $f['codice_rientro'] = $cod;
}
$da = in_date($_GET, 'da');
$a = in_date($_GET, 'a');
if ($da || $a) {
    $range = [];
    if ($da) { $range['$gte'] = $da; }
    if ($a)  { $range['$lte'] = $a; }
    $f['data_intervento'] = $range;
}
$utente = gs('utente');
if ($all && $utente !== '') {
    $f['autore_id'] = $utente;
}

$total = (int)col('schede')->countDocuments($f);
$rows = find_all('schede', $f, [
    'sort' => ['data_intervento' => -1, '_id' => -1],
    'skip' => ($page - 1) * PER_PAGE,
    'limit' => PER_PAGE,
]);

$people = $all ? find_all('users', [], ['sort' => ['nome' => 1, 'cognome' => 1], 'collation' => ['locale' => 'it', 'strength' => 2]]) : [];
$params = array_filter(['scope' => $all ? 'tutte' : null, 'q' => $q, 'codice' => $cod, 'da' => $da, 'a' => $a, 'utente' => $utente], static fn($x) => $x !== null && $x !== '');

page_head('Schede di soccorso', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1><?= $all ? 'Storico schede · tutto il personale' : 'Le mie schede di soccorso' ?></h1>
  <p class="sub" style="margin:0"><?= $total ?> schede trovate</p></div>
</div>

<form class="tools" method="get">
  <?php if ($all): ?><input type="hidden" name="scope" value="tutte"><?php endif; ?>
  <input type="text" name="q" placeholder="Cerca numero, paziente, destinazione…" value="<?= e($q) ?>">
  <select name="codice"><option value="">Tutti i codici</option>
    <?php foreach (CODICI_RIENTRO as $k => $l): ?><option value="<?= e($k) ?>"<?= $cod === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <?php if ($all): ?>
  <select name="utente"><option value="">Tutti gli operatori</option>
    <?php foreach ($people as $p): ?><option value="<?= e($p['id']) ?>"<?= $utente === $p['id'] ? ' selected' : '' ?>><?= e(trim($p['nome'] . ' ' . $p['cognome'])) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <input type="date" name="da" value="<?= e($da) ?>" title="Dal">
  <input type="date" name="a" value="<?= e($a) ?>" title="Al">
  <button class="btn teal small" type="submit">Filtra</button>
</form>

<div class="tbl-wrap">
<table class="tbl">
  <tr><th>N°</th><th>Data</th><th>Paziente</th><th>Luogo</th><th>Codice</th><th>Destinazione</th><?php if ($all): ?><th>Compilatore</th><?php endif; ?><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><b><?= e($r['numero']) ?></b></td>
    <td><?= e(fmt_date($r['data_intervento'])) ?></td>
    <td><?= e(trim(($r['pz_cognome'] ?? '') . ' ' . ($r['pz_nome'] ?? ''))) ?: '—' ?></td>
    <td><?= e(LUOGHI[$r['luogo_riferito'] ?? ''] ?? '—') ?></td>
    <td><?= code_badge($r['codice_rientro'] ?? null) ?: '—' ?></td>
    <td><?= e(($r['destinazione'] ?? '') ?: '—') ?></td>
    <?php if ($all): ?><td><?= e(trim($r['comp_nome'] . ' ' . $r['comp_cognome'])) ?></td><?php endif; ?>
    <td><a class="btn teal small" href="scheda_view.php?id=<?= e($r['id']) ?>">Apri</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td class="empty" colspan="8">Nessuna scheda trovata.</td></tr><?php endif; ?>
</table>
</div>
<?= pager($total, $page, $params) ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
  <a class="btn blue small" href="scheda_nuova.php">Compila nuova scheda</a>
  <a class="btn teal small" href="dashboard.php">Torna alla Dashboard</a>
</div>
<?php page_foot();
