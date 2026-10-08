<?php
declare(strict_types=1);

const KB_VERSION = '2.0.0';
const KB_NAME = 'کلیدبان';

function ls_config(): array
{
    static $cfg;
    if ($cfg !== null) {
        return $cfg;
    }
    $root = dirname(__DIR__);
    $cfg = require $root . '/config.sample.php';
    if (is_file($root . '/config.php')) {
        $cfg = array_merge($cfg, (array) require $root . '/config.php');
    }
    return $cfg;
}

function ls_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = ls_config();
    $dbPath = $cfg['db_path'];
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        license_key TEXT NOT NULL UNIQUE,
        domain TEXT NOT NULL,
        months INTEGER NOT NULL,
        starts_at TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        customer_name TEXT,
        customer_contact TEXT,
        notes TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        months INTEGER NOT NULL,
        customer_name TEXT,
        customer_contact TEXT,
        status TEXT NOT NULL DEFAULT 'pending',
        license_id INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS verify_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        license_key TEXT,
        domain TEXT,
        result TEXT,
        ip TEXT,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
        k TEXT PRIMARY KEY,
        v TEXT
    )");
    return $pdo;
}

function ls_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ls_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ls_normalize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = rtrim($domain, '.');
    return $domain;
}

function ls_random_key(int $bytes = 16): string
{
    return 'MVP-' . bin2hex(random_bytes($bytes));
}

function ls_sign_payload(array $payload): string
{
    $cfg = ls_config();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash_hmac('sha256', $body, (string) $cfg['hmac_secret']);
}

/**
 * Create license row and return full row
 */
function ls_create_license(string $domain, int $months, array $extra = []): array
{
    $months = max(1, min(12, $months));
    $domain = ls_normalize_domain($domain);
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        throw new InvalidArgumentException('دامنه نامعتبر است');
    }
    $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $start->modify('+' . $months . ' months');
    $key = ls_random_key(12);
    $now = ls_now();
    $pdo = ls_db();
    $stmt = $pdo->prepare('INSERT INTO licenses (license_key, domain, months, starts_at, expires_at, status, customer_name, customer_contact, notes, created_at, updated_at)
        VALUES (:k,:d,:m,:s,:e,:st,:cn,:cc,:n,:c,:u)');
    $stmt->execute([
        ':k' => $key,
        ':d' => $domain,
        ':m' => $months,
        ':s' => $start->format('Y-m-d H:i:s'),
        ':e' => $expires->format('Y-m-d H:i:s'),
        ':st' => 'active',
        ':cn' => $extra['customer_name'] ?? null,
        ':cc' => $extra['customer_contact'] ?? null,
        ':n' => $extra['notes'] ?? null,
        ':c' => $now,
        ':u' => $now,
    ]);
    return ls_get_license_by_key($key);
}

function ls_get_license_by_key(string $key): ?array
{
    $stmt = ls_db()->prepare('SELECT * FROM licenses WHERE license_key = :k LIMIT 1');
    $stmt->execute([':k' => trim($key)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ls_verify(string $key, string $domain): array
{
    $cfg = ls_config();
    $domain = ls_normalize_domain($domain);
    $key = trim($key);
    $row = ls_get_license_by_key($key);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $log = function (string $result) use ($key, $domain, $ip) {
        try {
            $st = ls_db()->prepare('INSERT INTO verify_log (license_key, domain, result, ip, created_at) VALUES (:k,:d,:r,:i,:c)');
            $st->execute([':k' => $key, ':d' => $domain, ':r' => $result, ':i' => $ip, ':c' => ls_now()]);
        } catch (Throwable $e) {
        }
    };

    if (!$row) {
        $log('not_found');
        return ['ok' => false, 'code' => 'not_found', 'message' => 'لایسنس یافت نشد'];
    }
    if (($row['status'] ?? '') === 'revoked') {
        $log('revoked');
        return ['ok' => false, 'code' => 'revoked', 'message' => 'لایسنس باطل شده است'];
    }
    if (ls_normalize_domain($row['domain']) !== $domain) {
        $log('domain_mismatch');
        return ['ok' => false, 'code' => 'domain_mismatch', 'message' => 'دامنه با لایسنس مطابقت ندارد', 'licensed_domain' => $row['domain']];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $exp = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
    $daysLeft = (int) floor(($exp->getTimestamp() - $now->getTimestamp()) / 86400);
    $expired = $now > $exp;

    // Sales stop when expired; bot itself stays up (client enforces sales_disabled)
    $sales_ok = !$expired && ($row['status'] ?? '') === 'active';
    $warn = !$expired && $daysLeft <= (int) $cfg['warn_days_before'];

    if ($expired && ($row['status'] ?? '') === 'active') {
        $st = ls_db()->prepare("UPDATE licenses SET status='expired', updated_at=:u WHERE id=:id");
        $st->execute([':u' => ls_now(), ':id' => $row['id']]);
        $row['status'] = 'expired';
    }

    $log($expired ? 'expired' : 'ok');

    return [
        'ok' => true,
        'code' => $expired ? 'expired' : 'active',
        'sales_ok' => $sales_ok,
        'expired' => $expired,
        'warn' => $warn,
        'days_left' => $daysLeft,
        'domain' => $row['domain'],
        'expires_at' => $row['expires_at'],
        'starts_at' => $row['starts_at'],
        'months' => (int) $row['months'],
        'status' => $row['status'],
        'grace_hours' => (int) $cfg['client_grace_hours'],
        'warn_days_before' => (int) $cfg['warn_days_before'],
        'server_time' => ls_now(),
    ];
}

function ls_notify_owner(string $text): void
{
    $cfg = ls_config();
    // Telegram
    if (!empty($cfg['owner_telegram_bot_token']) && !empty($cfg['owner_telegram_chat_id'])) {
        $url = 'https://api.telegram.org/bot' . $cfg['owner_telegram_bot_token'] . '/sendMessage';
        ls_http_post($url, ['chat_id' => $cfg['owner_telegram_chat_id'], 'text' => $text]);
    }
    // Bale
    if (!empty($cfg['owner_bale_bot_token']) && !empty($cfg['owner_bale_chat_id'])) {
        $url = 'https://tapi.bale.ai/bot' . $cfg['owner_bale_bot_token'] . '/sendMessage';
        ls_http_post($url, ['chat_id' => $cfg['owner_bale_chat_id'], 'text' => $text]);
    }
    // WhatsApp gateway webhook
    if (!empty($cfg['owner_whatsapp_webhook_url'])) {
        $headers = ['Content-Type: application/json'];
        if (!empty($cfg['owner_whatsapp_token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfg['owner_whatsapp_token'];
        }
        ls_http_post_raw($cfg['owner_whatsapp_webhook_url'], json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), $headers);
    }
}

function ls_http_post(string $url, array $fields): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function ls_http_post_raw(string $url, string $body, array $headers = []): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function ls_meta_get(string $k): ?string
{
    $st = ls_db()->prepare('SELECT v FROM meta WHERE k=:k');
    $st->execute([':k' => $k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function ls_meta_set(string $k, string $v): void
{
    ls_db()->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (:k, :v)')->execute([':k' => $k, ':v' => $v]);
}

function ls_meta_delete(string $k): void
{
    ls_db()->prepare('DELETE FROM meta WHERE k=:k')->execute([':k' => $k]);
}

/**
 * Where the WordPress API key comes from: config api_token, else the key made in the panel
 * (only its SHA-256 is stored), else none (the panel password is never accepted as an API key).
 */
function ls_api_key_source(): string
{
    $cfg = ls_config();
    if (trim((string) ($cfg['api_token'] ?? '')) !== '') {
        return 'config';
    }
    if (ls_meta_get('api_key_hash') !== null) {
        return 'panel';
    }
    return 'none';
}

function ls_api_key_check(string $given): bool
{
    $given = trim($given);
    if ($given === '') {
        return false;
    }
    switch (ls_api_key_source()) {
        case 'config':
            $expected = trim((string) ls_config()['api_token']);
            return strlen($expected) >= 12 && hash_equals($expected, $given);
        case 'panel':
            return hash_equals((string) ls_meta_get('api_key_hash'), hash('sha256', $given));
    }
    return false;
}

function ls_api_key_usable(): bool
{
    $source = ls_api_key_source();
    if ($source === 'config') {
        return strlen(trim((string) ls_config()['api_token'])) >= 12;
    }
    return $source === 'panel';
}

/** @return string the new key, shown to the admin once */
function ls_api_key_create(): string
{
    $key = 'mvk_' . bin2hex(random_bytes(24));
    ls_meta_set('api_key_hash', hash('sha256', $key));
    ls_meta_set('api_key_created', ls_now());
    return $key;
}

function ls_api_key_delete(): void
{
    ls_meta_delete('api_key_hash');
    ls_meta_delete('api_key_created');
}

function ls_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Latin digits to Persian, for display only */
function ls_fa(string $s): string
{
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

require __DIR__ . '/security.php';
