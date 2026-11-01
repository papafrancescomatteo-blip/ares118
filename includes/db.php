<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Client;

$__autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__autoload)) {
    require_once $__autoload;
}

/** Database MongoDB (connessione creata una sola volta per richiesta) */
function mdb(): MongoDB\Database
{
    static $db = null;
    if ($db === null) {
        $client = new Client(
            MONGODB_URI,
            ['retryWrites' => true, 'serverSelectionTimeoutMS' => 8000, 'connectTimeoutMS' => 8000],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
        );
        $db = $client->selectDatabase(MONGODB_DB);
    }
    return $db;
}

function col(string $name): MongoDB\Collection
{
    return mdb()->selectCollection($name);
}

function oid($v): ?ObjectId
{
    try {
        return new ObjectId((string)$v);
    } catch (Throwable $e) {
        return null;
    }
}

/** Filtro per _id partendo da una stringa (se non valida non trova nulla) */
function id_filter($id): array
{
    $o = oid($id);
    return ['_id' => $o ?? '__invalid__'];
}

/** Aggiunge la chiave 'id' (stringa) al documento */
function with_id(?array $d): ?array
{
    if ($d === null) {
        return null;
    }
    $d['id'] = (string)$d['_id'];
    return $d;
}

function find_one_by(string $collection, array $filter, array $opts = []): ?array
{
    $d = col($collection)->findOne($filter, $opts);
    return $d === null ? null : with_id((array)$d);
}

function find_all(string $collection, array $filter = [], array $opts = []): array
{
    $out = [];
    foreach (col($collection)->find($filter, $opts) as $d) {
        $out[] = with_id((array)$d);
    }
    return $out;
}

function free_username(string $base): string
{
    $name = $base;
    $i = 1;
    while (col('users')->countDocuments(['username_lc' => mb_strtolower($name)]) > 0) {
        $name = $base . ' ' . (++$i);
    }
    return $name;
}

/** Crea un utente. Ritorna ['id' => ..., 'username' => ...] */
function create_user(string $nome, string $cognome, string $ruolo, ?string $matricola, ?string $discordId, string $password, bool $locked = false, ?string $grado = null, bool $co = false): array
{
    $username = free_username(trim($nome . ' ' . $cognome));
    $res = col('users')->insertOne([
        'discord_id'     => $discordId,
        'username'       => $username,
        'username_lc'    => mb_strtolower($username),
        'nome'           => $nome,
        'cognome'        => $cognome,
        'matricola'      => $matricola,
        'grado'          => $grado,
        'co'             => $co,
        'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
        'ruolo'          => $ruolo,
        'attivo'         => true,
        'locked'         => $locked,
        'must_change_pw' => true,
        'last_login'     => null,
        'created_at'     => time(),
    ]);
    return ['id' => (string)$res->getInsertedId(), 'username' => $username];
}

/** Numero progressivo scheda per anno: ARES-2026-00001 */
function next_scheda_number(int $year): string
{
    $doc = col('counters')->findOneAndUpdate(
        ['_id' => 'schede-' . $year],
        ['$inc' => ['seq' => 1]],
        ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
    );
    return sprintf('ARES-%d-%05d', $year, (int)$doc['seq']);
}

function ensure_indexes(): void
{
    col('users')->createIndex(['username_lc' => 1], ['unique' => true]);
    col('users')->createIndex(['discord_id' => 1], ['unique' => true, 'partialFilterExpression' => ['discord_id' => ['$type' => 'string']]]);
    col('schede')->createIndex(['autore_id' => 1, 'data_intervento' => -1]);
    col('schede')->createIndex(['data_intervento' => -1]);
    col('shifts')->createIndex(['user_id' => 1, 'start_at' => -1]);
    col('login_log')->createIndex(['ip' => 1, 'ts' => -1]);
    col('mezzi')->createIndex(['nome_lc' => 1], ['unique' => true]);
    col('chiamate')->createIndex(['created_at' => -1]);
    col('chiamate')->createIndex(['stato' => 1]);
    col('assegnazioni')->createIndex(['mezzo_id' => 1, 'stato' => 1]);
    col('assegnazioni')->createIndex(['chiamata_id' => 1]);
    col('assegnazioni')->createIndex(['crew_ids' => 1, 'stato' => 1]);
}
