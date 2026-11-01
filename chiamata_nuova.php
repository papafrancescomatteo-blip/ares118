<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
require_perm('centralino', $user);

$board = board_data();
$liberi = array_values(array_filter($board['mezzi'], static fn($m) => $m['stato'] === 'libero'));
$old = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $p = $_POST;
    $old = $p;
    $tipi = array_values(array_intersect(array_keys(TIPI_CHIAMATA), array_map('strval', (array)($p['tipo_chiamata'] ?? []))));
    $doc = [
        'stato' => 'in_attesa',
        'created_at' => time(),
        'creata_da' => $user['id'],
        'creata_da_nome' => full_name($user),
        'tipo_chiamata' => $tipi,
        'luogo_riferito' => in_pick($p, 'luogo_riferito', array_keys(LUOGHI)),
        'indirizzo' => in_str($p, 'indirizzo', 150),
        'numero_civico' => in_str($p, 'numero_civico', 20),
        'condizione_riferita' => in_str($p, 'condizione_riferita', 250),
        'codice' => in_pick($p, 'codice', array_keys(CODICI_PARTENZA)),
        'chiamante' => in_str($p, 'chiamante', 100),
        'note_co' => in_str($p, 'note_co', 500),
    ];
    if (!$doc['indirizzo']) {
        $errors[] = 'Inserisci l\'indirizzo dell\'evento.';
    }
    if (!$doc['condizione_riferita']) {
        $errors[] = 'Inserisci la condizione riferita.';
    }
    if (!$doc['codice']) {
        $errors[] = 'Scegli il codice di partenza.';
    }
    if (!$errors) {
        $doc['numero'] = next_chiamata_number((int)date('Y'));
        $doc['id'] = (string)col('chiamate')->insertOne($doc)->getInsertedId();
        $problems = [];
        foreach (array_unique(array_map('strval', (array)($p['mezzi'] ?? []))) as $mid) {
            if ($err = assign_mezzo($doc, $mid, $user)) {
                $problems[] = $err;
            }
        }
        $msg = 'Chiamata <b>' . e($doc['numero']) . '</b> registrata.';
        if ($problems) {
            $msg .= ' Attenzione: ' . implode(' ', $problems);
        }
        flash($msg, $problems ? 'err' : 'ok');
        redirect('centralino.php');
    }
}

function chk(array $old, string $k, string $v): string
{
    return in_array($v, array_map('strval', (array)($old[$k] ?? [])), true) ? ' checked' : '';
}

page_head('Nuova chiamata', 'wide');
?>
<div class="head">
  <?= brand_block() ?>
  <div><h1>Nuova chiamata</h1><p class="sub" style="margin:0">Centrale Operativa · registra la richiesta e inoltrala ai mezzi disponibili</p></div>
</div>
<?php if ($errors): ?><div class="flash err"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" data-once autocomplete="off">
<?= csrf_field() ?>
<div class="sec"><h2>1. Richiesta</h2>
  <div class="cells" style="--cols:3">
    <div class="cell" style="grid-column:span 2"><label class="lbl" for="indirizzo">Indirizzo evento</label><input type="text" name="indirizzo" id="indirizzo" maxlength="150" required value="<?= e($old['indirizzo'] ?? '') ?>"></div>
    <div class="cell"><label class="lbl" for="numero_civico">Numero civico</label><input type="text" name="numero_civico" id="numero_civico" maxlength="20" value="<?= e($old['numero_civico'] ?? '') ?>"></div>
    <div class="cell"><label class="lbl" for="luogo_riferito">Luogo riferito</label>
      <select name="luogo_riferito" id="luogo_riferito"><option value="">– Seleziona –</option>
        <?php foreach (LUOGHI as $k => $l): ?><option value="<?= e($k) ?>"<?= ($old['luogo_riferito'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></div>
    <div class="cell"><label class="lbl" for="chiamante">Chiamante</label><input type="text" name="chiamante" id="chiamante" maxlength="100" value="<?= e($old['chiamante'] ?? '') ?>"></div>
    <div class="cell"><span class="lbl">Codice di partenza</span>
      <div class="opts">
        <?php foreach (CODICI_PARTENZA as $k => $l): ?><label><input type="radio" name="codice" value="<?= e($k) ?>" required<?= ($old['codice'] ?? '') === $k ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
      </div></div>
    <div class="cell full"><span class="lbl">Tipo chiamata</span>
      <div class="opts">
        <?php foreach (TIPI_CHIAMATA as $k => $l): ?><label><input type="checkbox" name="tipo_chiamata[]" value="<?= e($k) ?>"<?= chk($old, 'tipo_chiamata', $k) ?>> <?= e($l) ?></label><?php endforeach; ?>
      </div></div>
    <div class="cell full"><label class="lbl" for="condizione_riferita">Condizione riferita</label><input type="text" name="condizione_riferita" id="condizione_riferita" maxlength="250" required value="<?= e($old['condizione_riferita'] ?? '') ?>"></div>
    <div class="cell full"><label class="lbl" for="note_co">Note della Centrale Operativa</label><textarea name="note_co" id="note_co" maxlength="500" style="min-height:80px"><?= e($old['note_co'] ?? '') ?></textarea></div>
  </div>
</div>

<div class="sec"><h2>2. Invia subito a (facoltativo)</h2>
  <div class="cells" style="--cols:1">
    <div class="cell">
      <?php if ($liberi): ?>
        <div class="opts">
          <?php foreach ($liberi as $m): ?><label><input type="checkbox" name="mezzi[]" value="<?= e($m['id']) ?>"<?= chk($old, 'mezzi', $m['id']) ?>> <?= e(mezzo_label($m)) ?> <small>(<?= count($m['crew']) ?> a bordo)</small></label><?php endforeach; ?>
        </div>
        <p class="hint" style="color:var(--muted);font-weight:500">Puoi anche registrare la chiamata e assegnare i mezzi dopo, dal pannello della Centrale Operativa.</p>
      <?php else: ?>
        <p class="hint">Nessun mezzo libero in questo momento: la chiamata resterà in attesa finché non ne assegni uno.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="actions">
  <button class="btn red" type="submit">Registra chiamata</button>
  <a class="btn teal small" href="centralino.php" style="margin-top:16px">Torna alla Centrale Operativa</a>
</div>
</form>
<?php page_foot();
