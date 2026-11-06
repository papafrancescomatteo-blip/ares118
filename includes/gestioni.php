<?php
/** Gestioni: provvedimenti (promozioni, degradi, avvertimenti, note di merito, licenziamenti), avvisi a schermo e messaggi del bot. */

const TIPI_PROVV = [
    'promozione' => 'Promozione', 'degrado' => 'Degrado', 'avvertimento' => 'Avvertimento',
    'merito' => 'Nota di merito', 'licenziamento' => 'Licenziamento',
];

/** Rigo "pubblico" (per canale e riepilogo): terza persona. $u = persona, $p = ['tipo','testo','grado'] */
function riga_pubblica(array $u, array $p): string
{
    $nome = full_name($u) . (!empty($u['matricola']) ? ' (' . $u['matricola'] . ')' : '');
    $m = !empty($u['discord_id']) ? '<@' . $u['discord_id'] . '>' : '**' . $nome . '**';
    $t = trim((string)($p['testo'] ?? ''));
    return match ($p['tipo']) {
        'promozione'    => '⬆️ ' . $m . ' ' . $nome . ' promosso a **' . $p['grado'] . '**' . ($t !== '' ? ' — ' . $t : ''),
        'degrado'       => '⬇️ ' . $m . ' ' . $nome . ' degradato a **' . $p['grado'] . '**' . ($t !== '' ? ' — ' . $t : ''),
        'avvertimento'  => '⚠️ ' . $m . ' ' . $nome . ' · avvertimento: ' . $t,
        'merito'        => '🏅 ' . $m . ' ' . $nome . ' · nota di merito: ' . $t,
        'licenziamento' => '🚫 ' . $nome . ' · licenziato: ' . $t,
        default         => '',
    };
}

/** Rigo "personale" (seconda persona), mostrato nella schermata dell'interessato */
function riga_personale(array $p): string
{
    $t = trim((string)($p['testo'] ?? ''));
    return match ($p['tipo']) {
        'promozione'   => 'Sei stato promosso a: ' . $p['grado'] . ($t !== '' ? ' (' . $t . ')' : ''),
        'degrado'      => 'Sei stato degradato a: ' . $p['grado'] . ($t !== '' ? ' (' . $t . ')' : ''),
        'avvertimento' => 'Hai ricevuto un avvertimento: ' . $t,
        'merito'       => 'Hai ricevuto una nota di merito: ' . $t,
        default        => '',
    };
}

/** Mette in coda un messaggio che il bot scrive nel canale #gestioni (spezzato sotto i 1900 caratteri) */
function bot_messaggio(array $linee, string $titolo = ''): void
{
    $msgs = [];
    $cur = $titolo !== '' ? '📋 **' . $titolo . '**' : '';
    foreach ($linee as $l) {
        if (mb_strlen($cur) + mb_strlen($l) + 1 > 1800) {
            $msgs[] = $cur;
            $cur = '';
        }
        $cur .= ($cur === '' ? '' : "\n") . $l;
    }
    if (trim($cur) !== '') {
        $msgs[] = $cur;
    }
    if ($msgs) {
        col('jobs')->insertOne(['tipo' => 'messaggio', 'canale' => 'gestioni', 'messaggi' => $msgs, 'stato' => 'in_attesa', 'creato_at' => time(), 'titolo' => $titolo]);
    }
}

/** Crea l'avviso a schermo per una persona */
function avviso_crea(string $uid, string $titolo, array $righe, string $da, array $riepilogo = [], ?string $gid = null): void
{
    col('avvisi')->insertOne([
        'user_id' => $uid, 'titolo' => $titolo, 'righe' => array_values($righe), 'da' => $da,
        'riepilogo' => array_values($riepilogo), 'gestione_id' => $gid, 'letto' => false, 'at' => time(),
    ]);
}

function avviso_da_leggere(string $uid): ?array
{
    return find_all('avvisi', ['user_id' => $uid, 'letto' => false], ['sort' => ['at' => 1], 'limit' => 1])[0] ?? null;
}

/** Licenzia: elimina subito le credenziali; il motivo resta da mostrare nella schermata di login finche' la persona preme OK */
function licenzia(array $u, string $motivo, string $da, ?string $gid = null): void
{
    col('users')->updateOne(id_filter($u['id']), ['$set' => [
        'attivo' => false, 'password_hash' => null, 'must_change_pw' => false, 'co' => false,
        'licenziato' => ['motivo' => $motivo, 'at' => time(), 'da' => $da, 'ack' => false],
    ]]);
    col('avvisi')->updateMany(['user_id' => $u['id'], 'letto' => false], ['$set' => ['letto' => true]]);
}

/**
 * Applica un provvedimento. Ritorna ['tipo','testo','grado'] oppure una stringa d'errore.
 * Non crea avvisi: li compone chi chiama (uno solo per persona nelle gestioni settimanali).
 */
function applica_provvedimento(array $u, string $tipo, string $testo, ?string $gradoNuovo, array $by, ?string $gid)
{
    $testo = mb_substr(trim($testo), 0, 300);
    $col = col('users');
    $log = ['user_id' => $u['id'], 'nome' => full_name($u), 'tipo' => $tipo, 'testo' => $testo, 'da' => full_name($by), 'da_id' => $by['id'], 'gestione_id' => $gid, 'at' => time()];
    $out = ['tipo' => $tipo, 'testo' => $testo, 'grado' => ''];

    if ($tipo === 'promozione' || $tipo === 'degrado') {
        if ($gradoNuovo === null || !in_array($gradoNuovo, GRADI, true)) {
            return 'Scegli il nuovo grado per ' . full_name($u) . '.';
        }
        if ($gradoNuovo === (string)($u['grado'] ?? '')) {
            return 'Il nuovo grado di ' . full_name($u) . ' è uguale a quello attuale.';
        }
        $col->updateOne(id_filter($u['id']), ['$set' => ['grado' => $gradoNuovo]]);
        $out['grado'] = $gradoNuovo;
        $log += ['grado_da' => (string)($u['grado'] ?? ''), 'grado_a' => $gradoNuovo];
    } elseif ($tipo === 'avvertimento' || $tipo === 'merito' || $tipo === 'licenziamento') {
        if ($testo === '') {
            return ($tipo === 'licenziamento' ? 'Scrivi il motivo del licenziamento di ' : 'Scrivi il testo per ') . full_name($u) . '.';
        }
        if ($tipo === 'avvertimento') {
            $col->updateOne(id_filter($u['id']), ['$inc' => ['avvertimenti' => 1]]);
        } elseif ($tipo === 'merito') {
            $col->updateOne(id_filter($u['id']), ['$inc' => ['note_merito' => 1]]);
        } else {
            licenzia($u, $testo, full_name($by), $gid);
        }
    } else {
        return 'Provvedimento non valido.';
    }
    col('provvedimenti')->insertOne($log);
    return $out;
}

/** Persone che $me puo' gestire, dal grado piu' alto al piu' basso */
function persone_gestibili(array $me): array
{
    $l = array_filter(find_all('users', ['licenziato' => null, 'attivo' => true]), static fn($u) => manageable($me, $u));
    return array_values(sort_by_grade($l));
}

/** Ore nette di servizio per persona dall'ultimo reset (tutti i turni ancora in 'shifts') */
function ore_per_persona(): array
{
    $h = [];
    foreach (find_all('shifts') as $s) {
        $h[$s['user_id']] = ($h[$s['user_id']] ?? 0) + shift_net_seconds($s);
    }
    return $h;
}

/** Azzera gli shift (anche quelli attivi): vengono chiusi e archiviati. Ritorna quanti. */
function reset_shifts(string $gid): int
{
    $n = 0;
    $now = time();
    foreach (find_all('shifts') as $s) {
        if (empty($s['end_at'])) {
            $pause = (int)$s['pause_seconds'] + ($s['pause_started'] ? max(0, $now - (int)$s['pause_started']) : 0);
            $s['pause_seconds'] = $pause;
            $s['pause_started'] = null;
            $s['end_at'] = $now;
            $s['chiuso_da_reset'] = true;
        }
        $id = $s['id'];
        unset($s['id']);
        col('shifts_archivio')->insertOne($s + ['gestione_id' => $gid, 'archiviato_at' => $now]);
        col('shifts')->deleteOne(id_filter($id));
        $n++;
    }
    return $n;
}

/**
 * Pubblica la gestione settimanale. $righe: [user_id => ['azione','grado_nuovo','avvertimento','merito','motivo']]
 * Ritorna [true, riepilogo] oppure [false, errore]. Controlla tutto PRIMA di cambiare qualcosa.
 */
function pubblica_gestione(array $me, string $titolo, array $righe): array
{
    $persone = persone_gestibili($me);
    $err = [];
    foreach ($persone as $u) {
        $r = $righe[$u['id']] ?? [];
        $az = (string)($r['azione'] ?? '');
        $nome = full_name($u);
        if (!in_array($az, ['nessuno', 'promozione', 'degrado', 'licenziamento'], true)) {
            $err[] = 'Gestisci <b>' . e($nome) . '</b> prima di pubblicare.';
            continue;
        }
        if (($az === 'promozione' || $az === 'degrado')) {
            $g = (string)($r['grado_nuovo'] ?? '');
            if (!in_array($g, GRADI, true) || $g === (string)($u['grado'] ?? '')) {
                $err[] = 'Scegli un nuovo grado valido per <b>' . e($nome) . '</b>.';
            }
        }
        if ($az === 'licenziamento' && trim((string)($r['motivo'] ?? '')) === '') {
            $err[] = 'Scrivi il motivo del licenziamento di <b>' . e($nome) . '</b>.';
        }
    }
    if (!$persone) {
        $err[] = 'Non ci sono persone da gestire.';
    }
    if (trim($titolo) === '') {
        $err[] = 'Scrivi il titolo della gestione.';
    }
    if ($err) {
        return [false, implode('<br>', $err)];
    }

    $titolo = mb_substr(trim($titolo), 0, 120);
    $res = col('gestioni')->insertOne(['titolo' => $titolo, 'da' => full_name($me), 'da_id' => $me['id'], 'at' => time(), 'righe' => []]);
    $gid = (string)$res->getInsertedId();
    $ore = ore_per_persona();

    $personali = [];   // user_id => [righe]
    $pubbliche = ['promozione' => [], 'degrado' => [], 'avvertimento' => [], 'merito' => [], 'licenziamento' => []];
    $fired = [];
    $storico = [];
    $storico_ore = [];
    foreach ($persone as $u) {
        $r = $righe[$u['id']];
        $az = (string)$r['azione'];
        $items = [];
        if ($az === 'promozione' || $az === 'degrado') {
            $items[] = ['promozione' === $az ? 'promozione' : 'degrado', '', (string)$r['grado_nuovo']];
        }
        if (trim((string)($r['avvertimento'] ?? '')) !== '') {
            $items[] = ['avvertimento', (string)$r['avvertimento'], null];
        }
        if (trim((string)($r['merito'] ?? '')) !== '') {
            $items[] = ['merito', (string)$r['merito'], null];
        }
        if ($az === 'licenziamento') {
            $items[] = ['licenziamento', (string)$r['motivo'], null];
        }
        foreach ($items as [$tipo, $testo, $g]) {
            $p = applica_provvedimento($u, $tipo, $testo, $g, $me, $gid);
            if (is_string($p)) {
                continue;   // gia' validato sopra
            }
            $pubbliche[$tipo][] = riga_pubblica($u, $p);
            $storico[] = ['user_id' => $u['id'], 'nome' => full_name($u), 'matricola' => $u['matricola'] ?? '', 'tipo' => $tipo, 'testo' => $testo, 'grado' => $p['grado'], 'grado_da' => (string)($u['grado'] ?? '')];
            if ($tipo === 'licenziamento') {
                $fired[$u['id']] = true;
            } else {
                $personali[$u['id']][] = riga_personale($p);
            }
        }
        $storico_ore[$u['id']] = (int)($ore[$u['id']] ?? 0);
    }

    $archiviati = reset_shifts($gid);

    // riepilogo uguale per tutti (testo semplice, senza menzioni)
    $riepilogo = [];
    foreach ($storico as $s) {
        $riepilogo[] = match ($s['tipo']) {
            'promozione'    => $s['nome'] . ' · promosso a ' . $s['grado'],
            'degrado'       => $s['nome'] . ' · degradato a ' . $s['grado'],
            'avvertimento'  => $s['nome'] . ' · avvertimento: ' . $s['testo'],
            'merito'        => $s['nome'] . ' · nota di merito: ' . $s['testo'],
            'licenziamento' => $s['nome'] . ' · licenziato: ' . $s['testo'],
        };
    }
    col('gestioni')->updateOne(id_filter($gid), ['$set' => ['righe' => $storico, 'ore' => $storico_ore, 'riepilogo' => $riepilogo, 'shift_archiviati' => $archiviati]]);

    foreach (find_all('users', ['licenziato' => null, 'attivo' => true]) as $u) {
        $mine = $personali[$u['id']] ?? [];
        avviso_crea($u['id'], $titolo, $mine ?: ['Nessun provvedimento a tuo carico in questa gestione.'], full_name($me), $riepilogo, $gid);
    }

    // messaggio del bot nel canale
    $linee = [];
    $labels = ['promozione' => 'Promozioni', 'degrado' => 'Degradi', 'avvertimento' => 'Avvertimenti', 'merito' => 'Note di merito', 'licenziamento' => 'Licenziamenti'];
    foreach ($pubbliche as $tipo => $rr) {
        if ($rr) {
            $linee[] = '';
            $linee[] = '**' . $labels[$tipo] . '**';
            array_push($linee, ...$rr);
        }
    }
    if (!$riepilogo) {
        $linee[] = 'Nessun provvedimento in questa gestione.';
    }
    $linee[] = '';
    $linee[] = 'Gestione a cura di **' . full_name($me) . '** · turni azzerati.';
    bot_messaggio($linee, $titolo);

    return [true, ['gid' => $gid, 'provvedimenti' => count($storico), 'licenziati' => count($fired), 'shift' => $archiviati]];
}

/** Ultimo messaggio al canale (per mostrare l'esito sul sito) */
function ultimo_messaggio_bot(): ?array
{
    return find_all('jobs', ['tipo' => 'messaggio'], ['sort' => ['creato_at' => -1], 'limit' => 1])[0] ?? null;
}

/** Schermata a tutta pagina con l'avviso da leggere (si chiude solo con OK) */
function render_avviso(array $a): void
{
    ?>
<div class="avviso-gate" role="dialog" aria-modal="true" aria-label="<?= e($a['titolo']) ?>">
  <div class="avviso-box">
    <h2><?= e($a['titolo']) ?></h2>
    <?php foreach ((array)$a['righe'] as $r): ?><p class="av-riga"><?= e($r) ?></p><?php endforeach; ?>
    <p class="av-da">Da: <b><?= e($a['da']) ?></b></p>
    <?php if (!empty($a['riepilogo'])): ?>
    <details class="av-riep"><summary>Riepilogo della gestione (<?= count((array)$a['riepilogo']) ?>)</summary>
      <ul><?php foreach ((array)$a['riepilogo'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
    <form method="post" action="avviso_ok.php"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($a['id']) ?>">
      <input type="hidden" name="back" value="<?= e(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'))) ?>">
      <button class="btn red" type="submit" autofocus>OK</button>
    </form>
  </div>
</div>
<?php
}
