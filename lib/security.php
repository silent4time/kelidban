<?php
declare(strict_types=1);

function kb_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return !empty(ls_config()['trust_proxy']) && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function kb_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty(ls_config()['trust_proxy'])) {
        $fwd = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($fwd === '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $fwd = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        if (filter_var($fwd, FILTER_VALIDATE_IP)) {
            $ip = $fwd;
        }
    }
    return $ip;
}

/** Development on 127.0.0.1 / localhost runs without https */
function kb_is_local_request(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function kb_force_https(): void
{
    if (PHP_SAPI === 'cli' || empty(ls_config()['force_https']) || kb_is_https() || kb_is_local_request()) {
        return;
    }
    $host = (string) parse_url((string) ls_config()['base_url'], PHP_URL_HOST);
    if ($host === '') {
        $host = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    }
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

/** $private: panel pages (never cached, never indexed, no referrer) */
function kb_headers(bool $private): void
{
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: ' . ($private ? 'no-referrer' : 'strict-origin-when-cross-origin'));
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (kb_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if ($private) {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

function kb_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = ls_config();
    $dir = dirname((string) $cfg['db_path']) . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string) max(300, (int) $cfg['session_idle_minutes'] * 60));
    ini_set('session.use_strict_mode', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('kb_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => kb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function kb_epoch(): string
{
    return (string) (ls_meta_get('session_epoch') ?? '0');
}

/** Ends every open panel session (password change, sudo kelidban logout-all) */
function kb_bump_epoch(): void
{
    ls_meta_set('session_epoch', bin2hex(random_bytes(8)));
}

function kb_ua_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** @return string '' when logged in, else 'none' or 'expired' */
function kb_session_state(): string
{
    $s = $_SESSION['kb'] ?? null;
    if (!is_array($s)) {
        return 'none';
    }
    $cfg = ls_config();
    $now = time();
    $idle = max(5, (int) $cfg['session_idle_minutes']) * 60;
    $max = max(1, (int) $cfg['session_max_hours']) * 3600;
    if ($now - (int) $s['seen'] > $idle || $now - (int) $s['at'] > $max) {
        return 'expired';
    }
    if (!hash_equals((string) $s['ua'], kb_ua_hash()) || !hash_equals((string) $s['epoch'], kb_epoch())) {
        return 'none';
    }
    $_SESSION['kb']['seen'] = $now;
    return '';
}

function kb_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }
}

function kb_csrf_token(): string
{
    if (empty($_SESSION['kb_csrf'])) {
        $_SESSION['kb_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['kb_csrf'];
}

function kb_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . kb_csrf_token() . '">';
}

/** Token match plus same-origin check for every POST */
function kb_csrf_ok(): bool
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : ''));
        if ($host !== strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            return false;
        }
    }
    $given = (string) ($_POST['_csrf'] ?? '');
    return $given !== '' && !empty($_SESSION['kb_csrf']) && hash_equals((string) $_SESSION['kb_csrf'], $given);
}

function kb_flash(string $type, string $text): void
{
    $_SESSION['kb_flash'][] = [$type, $text];
}

/** @return list<array{0:string,1:string}> */
function kb_flashes(): array
{
    $out = $_SESSION['kb_flash'] ?? [];
    unset($_SESSION['kb_flash']);
    return is_array($out) ? $out : [];
}

// ---------- password ----------

function kb_password_algo()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function kb_password_is_set(): bool
{
    return ls_meta_get('admin_pass_hash') !== null || kb_legacy_password() !== '';
}

/** Old plaintext admin_password from config.php, if any (accepted until it is hashed) */
function kb_legacy_password(): string
{
    if (ls_meta_get('admin_pass_hash') !== null) {
        return '';
    }
    $p = (string) (ls_config()['admin_password'] ?? '');
    return ($p === '' || stripos($p, 'CHANGE_ME') === 0) ? '' : $p;
}

function kb_password_set(string $password): void
{
    ls_meta_set('admin_pass_hash', password_hash($password, kb_password_algo()));
    ls_meta_set('admin_pass_changed', ls_now());
    if (mb_strlen($password) >= 12) {
        ls_meta_delete('admin_pass_weak');
    } else {
        ls_meta_set('admin_pass_weak', '1');
    }
}

function kb_password_check(string $password): bool
{
    $hash = ls_meta_get('admin_pass_hash');
    if ($hash === null) {
        $legacy = kb_legacy_password();
        // keep timing similar to a real hash check
        password_verify($password, '$2y$10$Oc0e2IJtapyf7Mv6nw5/sulo.oHBqpp21FqTqIJokByX95CpcgJKO');
        if ($legacy === '' || !hash_equals($legacy, $password)) {
            return false;
        }
        kb_password_set($password);
        ls_meta_set('admin_pass_legacy', '1');
        return true;
    }
    if (!password_verify($password, $hash)) {
        return false;
    }
    if (password_needs_rehash($hash, kb_password_algo())) {
        ls_meta_set('admin_pass_hash', password_hash($password, kb_password_algo()));
    }
    return true;
}

// ---------- login lockout ----------

function kb_fail_key(string $ip): string
{
    return 'lfail:' . hash('sha256', $ip);
}

function kb_fail_read(string $key): array
{
    $j = json_decode((string) ls_meta_get($key), true);
    return is_array($j) ? $j + ['n' => 0, 't' => 0, 'until' => 0] : ['n' => 0, 't' => 0, 'until' => 0];
}

/** Seconds until login opens again for this IP (0 = open). $global set when everyone is locked out. */
function kb_lock_left(string $ip, ?bool &$global = null): int
{
    $now = time();
    $all = kb_fail_read('lfail:all');
    $global = (int) $all['until'] > $now;
    if ($global) {
        return (int) $all['until'] - $now;
    }
    $mine = kb_fail_read(kb_fail_key($ip));
    return max(0, (int) $mine['until'] - $now);
}

function kb_fail_note(string $ip): void
{
    $cfg = ls_config();
    $now = time();
    $window = max(1, (int) $cfg['login_lock_minutes']) * 60;
    foreach ([[kb_fail_key($ip), max(1, (int) $cfg['login_max_attempts'])], ['lfail:all', max(5, (int) $cfg['login_global_max'])]] as [$key, $limit]) {
        $f = kb_fail_read($key);
        if ($now - (int) $f['t'] > $window) {
            $f = ['n' => 0, 't' => $now, 'until' => 0];
        }
        $f['n'] = (int) $f['n'] + 1;
        if ($f['n'] >= $limit) {
            $f['until'] = $now + $window;
        }
        ls_meta_set($key, json_encode($f));
    }
}

function kb_fail_clear(string $ip): void
{
    ls_meta_delete(kb_fail_key($ip));
}

/** Wipes every lockout (sudo kelidban unlock) */
function kb_unlock_all(): int
{
    $st = ls_db()->prepare("DELETE FROM meta WHERE k LIKE 'lfail:%'");
    $st->execute();
    return $st->rowCount();
}

/** Attempts left for this IP before lockout */
function kb_attempts_left(string $ip): int
{
    $cfg = ls_config();
    $f = kb_fail_read(kb_fail_key($ip));
    if (time() - (int) $f['t'] > max(1, (int) $cfg['login_lock_minutes']) * 60) {
        return (int) $cfg['login_max_attempts'];
    }
    return max(0, (int) $cfg['login_max_attempts'] - (int) $f['n']);
}

function kb_minutes_fa(int $seconds): string
{
    return ls_fa((string) max(1, (int) ceil($seconds / 60))) . ' دقیقه';
}

/**
 * Panel gate: returns when the admin is logged in; otherwise handles the login form and exits.
 */
function kb_require_admin(): void
{
    kb_force_https();
    kb_headers(true);
    kb_session_start();

    $state = kb_session_state();
    if ($state === '') {
        return;
    }
    if ($state === 'expired') {
        $_SESSION = [];
        session_regenerate_id(true);
        $notice = 'نشست شما به‌خاطر بی‌فعالیتی بسته شد؛ دوباره وارد شوید.';
    }

    $ip = kb_client_ip();
    $error = '';
    $global = false;
    $left = kb_lock_left($ip, $global);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
        if (!kb_csrf_ok()) {
            $error = 'فرم منقضی شده بود؛ صفحه را تازه کردیم، دوباره وارد کنید.';
        } elseif ($left > 0) {
            $error = '';
        } elseif (!kb_password_is_set()) {
            $error = '';
        } elseif (kb_password_check((string) $_POST['password'])) {
            kb_fail_clear($ip);
            session_regenerate_id(true);
            $_SESSION = [
                'kb' => ['at' => time(), 'seen' => time(), 'ua' => kb_ua_hash(), 'epoch' => kb_epoch()],
                'kb_csrf' => bin2hex(random_bytes(32)),
            ];
            ls_meta_set('last_login', json_encode(['at' => ls_now(), 'ip' => $ip]));
            header('Location: ./', true, 303);
            exit;
        } else {
            usleep(random_int(400000, 900000));
            kb_fail_note($ip);
            $left = kb_lock_left($ip, $global);
            if ($left === 0) {
                $n = kb_attempts_left($ip);
                $error = 'رمز درست نیست. ' . ($n <= 2 ? ls_fa((string) $n) . ' تلاش دیگر تا قفل موقت باقی مانده است.' : '');
            }
        }
    }

    $view = [
        'error' => $error,
        'notice' => $notice ?? '',
        'locked' => $left,
        'global' => $global,
        'no_password' => !kb_password_is_set(),
    ];
    kb_render_login($view);
    exit;
}

function kb_render_login(array $v): void
{
    http_response_code($v['locked'] > 0 ? 429 : 200);
    header('Content-Type: text/html; charset=utf-8');
    require dirname(__DIR__) . '/app/login.php';
}
