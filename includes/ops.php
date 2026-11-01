<?php
declare(strict_types=1);

/**
 * Centrale Operativa: mezzi, equipaggi, chiamate, assegnazioni.
 * Collezioni: mezzi, chiamate, assegnazioni (+ shifts con mezzo_id / ruolo_eq).
 * Tutti gli orari sono timestamp Unix; gli id di riferimento sono stringhe.
 */

const ATTIVE = ['assegnata', 'partita', 'sul_posto', 'in_trasporto', 'arrivata_ps'];

function mezzo_label(array $m): string
{
    return (string)$m['nome'] . ' [' . (string)$m['tipo'] . ']';
}

function allowed_crew_roles(array $u): array
{
    return (array)(role_info($u['ruolo'])['equip'] ?? []);
}

function hm($ts): string
{
    return $ts ? date('H:i', (int)$ts) : '—';
}

// ---------------------------------------------------------------- mezzi
function mezzi_list(): array
{
    $rows = find_all('mezzi');
    usort($rows, static fn($a, $b) => [(int)($a['ordine'] ?? 999), (string)$a['nome']] <=> [(int)($b['ordine'] ?? 999), (string)$b['nome']]);
    return $rows;
}

/** Crea un mezzo. Ritorna un messaggio di errore oppure null se ok. */
function create_mezzo(string $nome, string $tipo, string $gruppo): ?string
{
    $nome = trim(mb_substr($nome, 0, 40));
    if ($nome === '') {
        return 'Inserisci il nome del mezzo.';
    }
    if (!in_array($tipo, TIPI_MEZZO, true) || !in_array($gruppo, GRUPPI_MEZZO, true)) {
        return 'Tipo o gruppo non valido.';
    }
    if (col('mezzi')->countDocuments(['nome_lc' => mb_strtolower($nome)]) > 0) {
        return 'Esiste già un mezzo con questo nome.';
    }
    $max = 0;
    foreach (mezzi_list() as $m) {
        $max = max($max, (int)($m['ordine'] ?? 0));
    }
    col('mezzi')->insertOne([
        'nome' => $nome, 'nome_lc' => mb_strtolower($nome), 'tipo' => $tipo, 'gruppo' => $gruppo,
        'attivo' => true, 'ordine' => $max + 1,
    ]);
    return null;
}

/** Crea i mezzi iniziali se non ce n'e' nessuno */
function seed_mezzi(): int
{
    if (col('mezzi')->countDocuments([]) > 0) {
        return 0;
    }
    $n = 0;
    foreach (MEZZI_INIT as [$nome, $tipo, $gruppo]) {
        if (create_mezzo($nome, $tipo, $gruppo) === null) {
            $n++;
        }
    }
    return $n;
}

function next_chiamata_number(int $year): string
{
    $doc = col('counters')->findOneAndUpdate(
        ['_id' => 'chiamate-' . $year],
        ['$inc' => ['seq' => 1]],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return sprintf('CH-%d-%05d', $year, (int)$doc['seq']);
}

// ---------------------------------------------------------------- turni
/** Chiude i turni rimasti aperti oltre SHIFT_MAX_HOURS (altrimenti un mezzo resterebbe "equipaggiato" per sempre) */
function expire_old_shifts(): void
{
    $limit = SHIFT_MAX_HOURS * 3600;
    foreach (find_all('shifts', ['end_at' => null, 'start_at' => ['$lt' => time() - $limit]]) as $s) {
        col('shifts')->updateOne(id_filter($s['id']), ['$set' => [
            'end_at' => (int)$s['start_at'] + $limit, 'pause_started' => null, 'auto_chiuso' => true,
        ]]);
    }
}

/**
 * Fotografia di mezzi, equipaggi e Centrale Operativa in questo momento.
 * 'mezzi' => mezzi con 'crew' (ruolo, nome, user, shift), 'assegnazioni' attive e 'stato'.
 */
function board_data(): array
{
    expire_old_shifts();
    $shifts = find_all('shifts', ['end_at' => null], ['sort' => ['start_at' => 1]]);
    $users = [];
    $uids = array_values(array_unique(array_map(static fn($s) => (string)$s['user_id'], $shifts)));
    if ($uids) {
        $oids = array_values(array_filter(array_map('oid', $uids)));
        foreach (find_all('users', ['_id' => ['$in' => $oids]]) as $u) {
            $users[$u['id']] = $u;
        }
    }
    $actBy = [];
    foreach (find_all('assegnazioni', ['stato' => ['$in' => ATTIVE]], ['sort' => ['assegnata_at' => 1]]) as $a) {
        $actBy[$a['mezzo_id']][] = $a;
    }

    $roleOrder = array_flip(array_keys(RUOLI_EQUIPAGGIO));
    $out = [];
    foreach (mezzi_list() as $m) {
        $crew = [];
        foreach ($shifts as $s) {
            if (($s['mezzo_id'] ?? null) !== $m['id'] || empty($users[$s['user_id']])) {
                continue;
            }
            $u = $users[$s['user_id']];
            $crew[] = ['ruolo' => (string)($s['ruolo_eq'] ?? ''), 'nome' => full_name($u), 'user' => $u, 'shift' => $s];
        }
        usort($crew, static fn($a, $b) => ($roleOrder[$a['ruolo']] ?? 99) <=> ($roleOrder[$b['ruolo']] ?? 99));
        $m['crew'] = $crew;
        $m['assegnazioni'] = $actBy[$m['id']] ?? [];
        $attivi = count(array_filter($crew, static fn($c) => empty($c['shift']['pause_started'])));
        if (empty($m['attivo'])) {
            $m['stato'] = 'disattivato';
        } elseif (!$crew) {
            $m['stato'] = 'fuori_servizio';
        } elseif ($m['assegnazioni']) {
            $m['stato'] = 'occupato';
        } elseif ($attivi === 0) {
            $m['stato'] = 'in_pausa';
        } else {
            $m['stato'] = 'libero';
        }
        $out[] = $m;
    }

    $co = [];
    foreach ($shifts as $s) {
        if (empty($s['mezzo_id']) && !empty($users[$s['user_id']])) {
            $co[] = ['nome' => full_name($users[$s['user_id']]), 'in_pausa' => !empty($s['pause_started'])];
        }
    }
    return ['mezzi' => $out, 'co' => $co];
}

function find_mezzo_in(array $board, string $id): ?array
{
    foreach ($board['mezzi'] as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

/** Equipaggio -> campi della scheda di soccorso (sezione 11). Voci: ['ruolo' => .., 'nome' => ..] */
function crew_to_slots(array $crew): array
{
    $slots = [];
    $used = [];
    foreach ($crew as $c) {
        $places = RUOLI_EQUIPAGGIO[$c['ruolo']] ?? null;
        if (!$places) {
            continue;
        }
        $i = $used[$c['ruolo']] ?? 0;
        if (isset($places[$i])) {
            $slots[$places[$i]] = (string)$c['nome'];
            $used[$c['ruolo']] = $i + 1;
        }
    }
    return $slots;
}

/** Avvia il turno. Ritorna [ok, messaggio (gia' escapato)]. $sel = id mezzo oppure 'CO'. */
function try_start_shift(array $user, string $sel, string $ruolo): array
{
    expire_old_shifts();
    if (open_shift($user['id'])) {
        return [false, 'Sei già in servizio.'];
    }
    $base = ['user_id' => $user['id'], 'start_at' => time(), 'end_at' => null, 'pause_started' => null, 'pause_seconds' => 0];

    if ($sel === 'CO') {
        if (!can('centralino', $user)) {
            return [false, 'Non sei abilitato alla Centrale Operativa.'];
        }
        col('shifts')->insertOne($base + ['mezzo_id' => null, 'mezzo_sigla' => 'Centrale Operativa', 'ruolo_eq' => 'Centralino']);
        return [true, 'Servizio avviato in Centrale Operativa.'];
    }

    $m = $sel !== '' ? find_one_by('mezzi', id_filter($sel)) : null;
    if (!$m || empty($m['attivo'])) {
        return [false, 'Seleziona il mezzo su cui sei in servizio.'];
    }
    if (!isset(RUOLI_EQUIPAGGIO[$ruolo]) || !in_array($ruolo, allowed_crew_roles($user), true)) {
        return [false, 'Il ruolo a bordo scelto non è consentito per il tuo ruolo.'];
    }
    $same = find_all('shifts', ['mezzo_id' => $m['id'], 'end_at' => null, 'ruolo_eq' => $ruolo]);
    if (count($same) >= count(RUOLI_EQUIPAGGIO[$ruolo])) {
        $who = $same ? find_one_by('users', id_filter((string)$same[0]['user_id'])) : null;
        return [false, 'Su ' . e(mezzo_label($m)) . ' il ruolo ' . e($ruolo) . ' è già occupato' . ($who ? ' da ' . e(full_name($who)) : '') . '.'];
    }
    col('shifts')->insertOne($base + ['mezzo_id' => $m['id'], 'mezzo_sigla' => mezzo_label($m), 'ruolo_eq' => $ruolo]);
    return [true, 'Servizio avviato su ' . e(mezzo_label($m)) . ' come ' . e($ruolo) . '.'];
}

/** Messaggio di errore se non si puo' terminare il turno, altrimenti null */
function can_end_shift(array $shift): ?string
{
    $mid = $shift['mezzo_id'] ?? null;
    if (!$mid) {
        return null;
    }
    $others = col('shifts')->countDocuments(['mezzo_id' => $mid, 'end_at' => null]) - 1;
    $busy = col('assegnazioni')->countDocuments(['mezzo_id' => $mid, 'stato' => ['$in' => ATTIVE]]) > 0;
    if ($busy && $others < 1) {
        return 'Il mezzo ha un intervento in corso: chiudilo prima di terminare il servizio.';
    }
    return null;
}

// ---------------------------------------------------------------- chiamate e assegnazioni
/** Assegna un mezzo libero a una chiamata. Ritorna un messaggio di errore (escapato) oppure null. */
function assign_mezzo(array $call, string $mezzoId, array $by): ?string
{
    if (!in_array($call['stato'], ['in_attesa', 'in_corso'], true)) {
        return 'La chiamata ' . e($call['numero']) . ' non è più attiva.';
    }
    $m = find_mezzo_in(board_data(), $mezzoId);
    if (!$m) {
        return 'Mezzo non trovato.';
    }
    if ($m['stato'] !== 'libero') {
        return e(mezzo_label($m)) . ' non è disponibile (' . e(STATI_MEZZO[$m['stato']]) . ').';
    }
    col('assegnazioni')->insertOne([
        'chiamata_id' => $call['id'], 'chiamata_numero' => $call['numero'],
        'mezzo_id' => $m['id'], 'mezzo_sigla' => mezzo_label($m), 'mezzo_tipo' => $m['tipo'],
        'stato' => 'assegnata', 'assegnata_at' => time(), 'assegnata_da' => full_name($by),
        'crew_ids' => array_values(array_map(static fn($c) => (string)$c['user']['id'], $m['crew'])),
        'equipaggio' => array_values(array_map(static fn($c) => ['ruolo' => $c['ruolo'], 'nome' => $c['nome']], $m['crew'])),
        'scheda_id' => null,
    ]);
    col('chiamate')->updateOne(id_filter($call['id']), ['$set' => ['stato' => 'in_corso']]);
    return null;
}

/** Ricalcola lo stato della chiamata dalle sue assegnazioni */
function refresh_call(string $id): void
{
    $c = find_one_by('chiamate', id_filter($id));
    if (!$c || $c['stato'] === 'annullata') {
        return;
    }
    $all = find_all('assegnazioni', ['chiamata_id' => $id]);
    $active = array_filter($all, static fn($a) => in_array($a['stato'], ATTIVE, true));
    $done = array_filter($all, static fn($a) => $a['stato'] === 'libera');
    $new = $active ? 'in_corso' : ($done ? 'chiusa' : 'in_attesa');
    if ($new !== $c['stato']) {
        $set = ['stato' => $new];
        if ($new === 'chiusa') {
            $set['chiusa_at'] = time();
        }
        col('chiamate')->updateOne(id_filter($id), ['$set' => $set]);
    }
}

function can_access_assegnazione(array $user, array $a): bool
{
    if (can('centralino', $user) || in_array($user['id'], (array)($a['crew_ids'] ?? []), true)) {
        return true;
    }
    $s = open_shift($user['id']);
    return $s && !empty($s['mezzo_id']) && $s['mezzo_id'] === ($a['mezzo_id'] ?? null);
}

/** Interventi dell'equipaggio: 'attive' (sul mio mezzo adesso) e 'da_compilare' (concluse senza scheda, ultime 12 ore) */
function my_assignments(array $user, ?array $shift): array
{
    $attive = [];
    $mid = $shift['mezzo_id'] ?? null;
    if ($mid) {
        $attive = find_all('assegnazioni', ['mezzo_id' => $mid, 'stato' => ['$in' => ATTIVE]], ['sort' => ['assegnata_at' => 1]]);
    }
    $or = [['crew_ids' => $user['id']]];
    if ($mid) {
        $or[] = ['mezzo_id' => $mid];
    }
    $da = find_all('assegnazioni', [
        'stato' => 'libera', 'scheda_id' => null, 'libera_at' => ['$gt' => time() - 43200], '$or' => $or,
    ], ['sort' => ['libera_at' => -1]]);
    foreach ($attive as &$a) {
        $a['call'] = find_one_by('chiamate', id_filter($a['chiamata_id'])) ?? [];
    }
    unset($a);
    foreach ($da as &$a) {
        $a['call'] = find_one_by('chiamate', id_filter($a['chiamata_id'])) ?? [];
    }
    unset($a);
    return ['attive' => $attive, 'da_compilare' => $da];
}

/** Valori iniziali della scheda di soccorso: dati della chiamata, orari dell'intervento ed equipaggio */
function scheda_prefill(array $user, ?array $ass): array
{
    $old = ['data_intervento' => date('Y-m-d')];
    $shift = open_shift($user['id']);
    $mezzoId = $ass['mezzo_id'] ?? ($shift['mezzo_id'] ?? null);

    $crew = [];
    if ($mezzoId && ($m = find_mezzo_in(board_data(), $mezzoId))) {
        $crew = $m['crew'];
    }
    if (!$crew && $ass) {
        $crew = (array)($ass['equipaggio'] ?? []);   // fotografia presa al momento dell'assegnazione
    }
    $slots = crew_to_slots($crew);
    if ($slots) {
        $old += $slots;
    } else {
        $map = ['soccorritore' => 'soccorritore1', 'autista' => 'soccorritore1', 'recluta' => 'soccorritore3', 'infermiere' => 'infermiere1', 'medico' => 'medico1'];
        if (isset($map[$user['ruolo']])) {
            $old[$map[$user['ruolo']]] = full_name($user);
        }
    }

    if ($ass) {
        $call = find_one_by('chiamate', id_filter($ass['chiamata_id']));
        if ($call) {
            $old['numero_civico'] = $call['numero_civico'] ?? '';
            $old['luogo_riferito'] = $call['luogo_riferito'] ?? '';
            $old['tipo_chiamata'] = (array)($call['tipo_chiamata'] ?? []);
            $old['condizione_riferita'] = $call['condizione_riferita'] ?? '';
            $old['codice_partenza'] = $call['codice'] ?? '';
        }
        $old['data_intervento'] = date('Y-m-d', (int)$ass['assegnata_at']);
        $old['ora_attivazione'] = date('H:i', (int)$ass['assegnata_at']);
        foreach (['sul_posto_at' => 'ora_posto', 'in_trasporto_at' => 'ora_inizio_trasporto', 'arrivata_ps_at' => 'ora_arrivo_ps'] as $from => $to) {
            if (!empty($ass[$from])) {
                $old[$to] = date('H:i', (int)$ass[$from]);
            }
        }
        $mezzi = [];
        foreach (find_all('assegnazioni', ['chiamata_id' => $ass['chiamata_id']]) as $x) {
            $k = TIPO_MEZZO_SCHEDA[$x['mezzo_tipo'] ?? ''] ?? null;
            if ($k && $x['stato'] !== 'annullata') {
                $mezzi[$k] = true;
            }
        }
        $old['mezzi_attivati'] = array_keys($mezzi);
    }
    return $old;
}
