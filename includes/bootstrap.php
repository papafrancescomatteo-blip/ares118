<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/options.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/gestioni.php';

date_default_timezone_set('Europe/Rome');

if (!defined('NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('ares118sid');
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Sessione scaduta: ricarica la pagina e riprova.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    if (TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // l'ultimo valore e' quello aggiunto dal proxy di Render (i precedenti possono essere falsificati dal client)
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $ip = end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}

function role_info(?string $role): array
{
    return ROLES[$role ?? ''] ?? ['label' => ucfirst((string)$role), 'level' => 0, 'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => []];
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    if (time() - (int)($_SESSION['last'] ?? 0) > SESSION_TIMEOUT_MIN * 60) {
        $_SESSION = [];
        return null;
    }
    $u = find_one_by('users', id_filter($_SESSION['uid']));
    if ($u && !empty($u['licenziato']) && empty($u['licenziato']['ack'])) {
        $_SESSION = ['fired_uid' => $u['id']];   // la schermata di login mostrera' il motivo finche' non preme OK
        return null;
    }
    if (!$u || empty($u['attivo'])) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['last'] = time();
    $cache = $u;
    return $u;
}

function require_login(bool $allowPwChange = false): array
{
    $u = current_user();
    if (!$u) {
        redirect('index.php');
    }
    if ($u['must_change_pw'] && !$allowPwChange) {
        redirect('password.php');
    }
    return $u;
}

/** Permessi: 'personale' | 'vedi_tutte' */
function can(string $perm, array $u): bool
{
    if ($perm === 'centralino' && !empty($u['co'])) {
        return true;   // abilitazione Centrale Operativa data a mano o dal bot
    }
    return !empty(role_info($u['ruolo'])[$perm]);
}

function require_perm(string $perm, array $u): void
{
    if (!can($perm, $u)) {
        http_response_code(403);
        exit('Non hai i permessi per questa pagina.');
    }
}

function full_name(array $u): string
{
    return trim($u['nome'] . ' ' . $u['cognome']);
}

function word_count(string $s): int
{
    return preg_match_all('/\S+/u', $s);
}

function random_password(int $len = 10): string
{
    $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return $out;
}

function fmt_date(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '';
}

function fmt_time(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

function code_badge(?string $c): string
{
    if (!$c) {
        return '';
    }
    if (isset(CODICI_COLORI[$c])) {   // schede vecchie con codice a colore
        $txt = in_array($c, ['bianco', 'giallo'], true) ? '#111' : '#fff';
        return '<span class="badge" style="background:' . CODICI_COLORI[$c] . ';color:' . $txt . '">' . e(ucfirst($c)) . '</span>';
    }
    if (!isset(CODICI_RIENTRO[$c])) {
        return '';
    }
    return '<span class="badge" style="background:#0b2a4a;color:#fff">' . e(CODICI_RIENTRO[$c]) . '</span>';
}

// ---------- Validazione input della scheda ----------
function in_str(array $src, string $k, int $max): ?string
{
    $v = trim((string)($src[$k] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function in_date(array $src, string $k): ?string
{
    $v = (string)($src[$k] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function in_time(array $src, string $k): ?string
{
    $v = (string)($src[$k] ?? '');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
}

function in_int(array $src, string $k, int $min, int $max): ?int
{
    $v = $src[$k] ?? '';
    if ($v === '' || !is_numeric($v)) {
        return null;
    }
    $n = (int)$v;
    return ($n >= $min && $n <= $max) ? $n : null;
}

function in_pick(array $src, string $k, array $allowed): ?string
{
    $v = (string)($src[$k] ?? '');
    return in_array($v, $allowed, true) ? $v : null;
}

// ---------- Turni (firma servizio) ----------
function open_shift(string $uid): ?array
{
    return find_one_by('shifts', ['user_id' => $uid, 'end_at' => null], ['sort' => ['start_at' => -1]]);
}

function shift_state(?array $s): string
{
    if (!$s) {
        return 'Non in servizio';
    }
    return $s['pause_started'] ? 'In pausa' : 'In servizio';
}

/** Durata netta (senza pause) in secondi. Gli orari sono timestamp Unix. */
function shift_net_seconds(array $s): int
{
    $end = $s['end_at'] ?: time();
    $pause = (int)$s['pause_seconds'];
    if ($s['pause_started'] && !$s['end_at']) {
        $pause += time() - (int)$s['pause_started'];
    }
    return max(0, (int)$end - (int)$s['start_at'] - $pause + (int)($s['adjust_seconds'] ?? 0));
}

function fmt_duration(int $sec): string
{
    return sprintf('%dh %02dm', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}

function is_admin_personale(array $u): bool
{
    return can('personale', $u);
}

function pager(int $total, int $page, array $params): string
{
    $pages = (int)ceil($total / PER_PAGE);
    if ($pages <= 1) {
        return '';
    }
    $out = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q = http_build_query(array_merge($params, ['p' => $i]));
        $out .= '<a class="' . ($i === $page ? 'on' : '') . '" href="?' . e($q) . '">' . $i . '</a>';
    }
    return $out . '</div>';
}

/** Valore POST come testo (ignora array e altri tipi) */
function post_str(string $k): string
{
    return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
}

/** Posso gestire questa persona? (non me stesso, solo livello pari o inferiore) */
function manageable(array $me, array $target): bool
{
    return (string)$me['id'] !== (string)$target['id']
        && role_info($target['ruolo'])['level'] <= role_info($me['ruolo'])['level'];
}

/** Ruoli che posso assegnare: fino al mio livello */
function assignable_roles(array $me): array
{
    $lvl = role_info($me['ruolo'])['level'];
    return array_filter(ROLES, static fn($r) => $r['level'] <= $lvl);
}

/** Posizione del grado (0 = piu' alto). Chi non ha un grado noto va in fondo. */
function grade_rank(array $u): int
{
    $i = array_search((string)($u['grado'] ?? ''), GRADI, true);
    return $i === false ? 999 : (int)$i;
}

/** Ordina dal grado piu' alto al piu' basso; a parita' livello del ruolo e poi cognome/nome. */
function sort_by_grade(array $list): array
{
    usort($list, static function ($a, $b) {
        $aa = !empty($a['attivo']);
        $bb = !empty($b['attivo']);
        if ($aa !== $bb) {
            return $bb <=> $aa;
        }
        return grade_rank($a) <=> grade_rank($b)
            ?: role_info($b['ruolo'] ?? '')['level'] <=> role_info($a['ruolo'] ?? '')['level']
            ?: strcasecmp(full_name($a), full_name($b));
    });
    return $list;
}

function gradi_datalist(): string
{
    $h = '<datalist id="gradi">';
    foreach (GRADI as $g) {
        $h .= '<option value="' . e($g) . '">';
    }
    return $h . '</datalist>';
}
