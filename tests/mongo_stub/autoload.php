<?php
/**
 * SOLO PER TEST LOCALI: finto driver MongoDB in memoria (con salvataggio su file JSON) che imita
 * la parte della libreria mongodb/mongodb usata dal sito. Viene copiato come vendor/autoload.php
 * in una copia temporanea del progetto; non fa parte del sito.
 */

namespace MongoDB\BSON {
    class ObjectId
    {
        private string $v;
        public function __construct(?string $v = null)
        {
            $GLOBALS['__oid'] = ($GLOBALS['__oid'] ?? (int)(microtime(true) * 1000)) + 1;
            $v ??= sprintf('%024x', $GLOBALS['__oid']);
            if (!preg_match('/^[a-f0-9]{24}$/', $v)) {
                throw new \InvalidArgumentException('ObjectId non valido');
            }
            $this->v = $v;
        }
        public function __toString(): string { return $this->v; }
    }

    class Regex
    {
        public function __construct(public string $pattern, public string $flags = '') {}
    }
}

namespace MongoDB\Operation {
    class FindOneAndUpdate
    {
        const RETURN_DOCUMENT_BEFORE = 1;
        const RETURN_DOCUMENT_AFTER = 2;
    }
}

namespace MongoDB {
    class Result
    {
        public function __construct(public $inserted = null, public int $matched = 0, public int $modified = 0, public int $deleted = 0) {}
        public function getInsertedId() { return $this->inserted; }
        public function getMatchedCount(): int { return $this->matched; }
        public function getModifiedCount(): int { return $this->modified; }
        public function getDeletedCount(): int { return $this->deleted; }
    }

    class Store
    {
        public static array $data = [];
        public static function load(): void
        {
            $f = getenv('FAKE_MONGO_FILE') ?: '/tmp/ares_fake_mongo.json';
            self::$data = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
            register_shutdown_function(static fn() => file_put_contents($f, json_encode(self::$data)));
        }
    }
    Store::load();

    class Client
    {
        public function __construct(string $uri = '', array $uriOptions = [], array $driverOptions = []) {}
        public function selectDatabase(string $name): Database { return new Database(); }
    }

    class Database
    {
        public function selectCollection(string $name): Collection { return new Collection($name); }
    }

    class Collection
    {
        public function __construct(private string $n) {}

        private static function norm($v)
        {
            return $v instanceof BSON\ObjectId ? (string)$v : $v;
        }

        private static function has(array $d, string $k): bool { return array_key_exists($k, $d); }

        private static function match(array $d, array $f): bool
        {
            foreach ($f as $k => $c) {
                if ($k === '$or') {
                    $ok = false;
                    foreach ($c as $sub) { if (self::match($d, $sub)) { $ok = true; break; } }
                    if (!$ok) { return false; }
                    continue;
                }
                $v = $d[$k] ?? null;
                $c = self::norm($c);
                if ($c instanceof BSON\Regex) {
                    if (!is_string($v) || !preg_match('/' . $c->pattern . '/u' . (str_contains($c->flags, 'i') ? 'i' : ''), $v)) { return false; }
                } elseif (is_array($c) && $c && is_string(array_key_first($c)) && str_starts_with((string)array_key_first($c), '$')) {
                    foreach ($c as $op => $arg) {
                        $ok = match ($op) {
                            '$in'   => in_array($v, array_map([self::class, 'norm'], $arg), true),
                            '$nin'  => !in_array($v, array_map([self::class, 'norm'], $arg), true),
                            '$gte'  => $v !== null && $v >= $arg,
                            '$lte'  => $v !== null && $v <= $arg,
                            '$gt'   => $v !== null && $v > $arg,
                            '$lt'   => $v !== null && $v < $arg,
                            '$ne'   => $v !== $arg,
                            '$type' => $arg === 'string' ? is_string($v) : throw new \RuntimeException('type non supportato'),
                            default => throw new \RuntimeException("operatore non supportato: $op"),
                        };
                        if (!$ok) { return false; }
                    }
                } elseif (is_array($v) && !is_array($c)) {
                    if (!in_array($c, $v, true)) { return false; }
                } elseif ($c === null) {
                    if ($v !== null) { return false; }
                } elseif ($v !== $c) {
                    return false;
                }
            }
            return true;
        }

        private function rows(array $f): array
        {
            return array_values(array_filter(Store::$data[$this->n] ?? [], fn($d) => self::match($d, $f)));
        }

        public function find(array $f = [], array $o = []): array
        {
            $rows = $this->rows($f);
            if (!empty($o['sort'])) {
                usort($rows, function ($a, $b) use ($o) {
                    foreach ($o['sort'] as $k => $dir) {
                        $r = ($a[$k] ?? null) <=> ($b[$k] ?? null);
                        if ($r !== 0) { return $r * $dir; }
                    }
                    return 0;
                });
            }
            return array_slice($rows, (int)($o['skip'] ?? 0), isset($o['limit']) ? (int)$o['limit'] : null);
        }

        public function findOne(array $f = [], array $o = []): ?array { return $this->find($f, $o + ['limit' => 1])[0] ?? null; }
        public function countDocuments(array $f = []): int { return count($this->rows($f)); }

        public function insertOne(array $doc): Result
        {
            $doc = json_decode(json_encode($doc), true);
            $id = (string)($doc['_id'] ?? new BSON\ObjectId());
            $doc = ['_id' => $id] + $doc;
            Store::$data[$this->n][] = $doc;
            return new Result($id);
        }

        private function apply(array $d, array $u): array
        {
            foreach ($u['$set'] ?? [] as $k => $v) { $d[$k] = $v; }
            foreach ($u['$inc'] ?? [] as $k => $v) { $d[$k] = ($d[$k] ?? 0) + $v; }
            return $d;
        }

        public function updateOne(array $f, array $u): Result { return $this->upd($f, $u, false); }
        public function updateMany(array $f, array $u): Result { return $this->upd($f, $u, true); }

        private function upd(array $f, array $u, bool $many): Result
        {
            $m = 0;
            foreach (Store::$data[$this->n] ?? [] as $i => $d) {
                if (!self::match($d, $f)) { continue; }
                Store::$data[$this->n][$i] = $this->apply($d, $u);
                $m++;
                if (!$many) { break; }
            }
            return new Result(null, $m, $m);
        }

        public function deleteOne(array $f): Result
        {
            foreach (Store::$data[$this->n] ?? [] as $i => $d) {
                if (self::match($d, $f)) {
                    array_splice(Store::$data[$this->n], $i, 1);
                    return new Result(null, 0, 0, 1);
                }
            }
            return new Result();
        }

        public function deleteMany(array $f): Result
        {
            $before = count(Store::$data[$this->n] ?? []);
            Store::$data[$this->n] = array_values(array_filter(Store::$data[$this->n] ?? [], fn($d) => !self::match($d, $f)));
            return new Result(null, 0, 0, $before - count(Store::$data[$this->n]));
        }

        public function findOneAndUpdate(array $f, array $u, array $o = []): ?array
        {
            $d = $this->findOne($f);
            if ($d === null) {
                if (empty($o['upsert'])) { return null; }
                $d = [];
                foreach ($f as $k => $v) { if (!is_array($v)) { $d[$k] = self::norm($v); } }
                $new = $this->apply($d, $u);
                Store::$data[$this->n][] = $new;
                return $new;
            }
            foreach (Store::$data[$this->n] as $i => $row) {
                if ($row['_id'] === $d['_id']) { Store::$data[$this->n][$i] = $this->apply($row, $u); return Store::$data[$this->n][$i]; }
            }
            return null;
        }

        public function aggregate(array $p): array
        {
            $g = $p[0]['$group'];
            $field = ltrim($g['_id'], '$');
            $out = [];
            foreach (Store::$data[$this->n] ?? [] as $d) {
                $k = (string)($d[$field] ?? '');
                $out[$k] = ($out[$k] ?? 0) + 1;
            }
            $res = [];
            foreach ($out as $k => $n) { $res[] = ['_id' => (string)$k, 'n' => $n]; }
            return $res;
        }

        public function createIndex(array $keys, array $opts = []): string { return 'idx'; }
    }
}
