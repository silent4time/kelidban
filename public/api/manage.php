<?php
/**
 * Management API for remote panels (the WordPress license plugin).
 * POST JSON {action, token?, ...}; token may also come as "Authorization: Bearer ...".
 * Key: config api_token, else the key created in the panel (see ls_api_key_source).
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/bootstrap.php';

const LSM_MAX_FAILS = 10;
const LSM_FAIL_WINDOW = 900;

header('X-Robots-Tag: noindex');
header_remove('X-Powered-By');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ls_json(['ok' => false, 'code' => 'method', 'message' => 'POST only'], 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    ls_json(['ok' => false, 'code' => 'bad_request', 'message' => 'JSON body required'], 400);
}

lsm_auth($input);

try {
    ls_json(lsm_dispatch((string) ($input['action'] ?? ''), $input));
} catch (InvalidArgumentException $e) {
    ls_json(['ok' => false, 'code' => 'invalid', 'message' => $e->getMessage()], 422);
} catch (DomainException $e) {
    ls_json(['ok' => false, 'code' => $e->getMessage(), 'message' => lsm_code_message($e->getMessage())], 409);
} catch (Throwable $e) {
    ls_json(['ok' => false, 'code' => 'server_error', 'message' => 'server error'], 500);
}

function lsm_header_token(): string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $h = (string) $v;
            }
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
}

function lsm_auth(array $input): void
{
    if (!ls_api_key_usable()) {
        ls_json(['ok' => false, 'code' => 'not_configured', 'message' => 'create an API key in the admin panel'], 503);
    }
    $ip = kb_client_ip();
    $metaKey = 'mfail:' . hash('sha256', $ip);
    $pdo = ls_db();
    $st = $pdo->prepare('SELECT v FROM meta WHERE k=:k');
    $st->execute([':k' => $metaKey]);
    $fail = json_decode((string) $st->fetchColumn(), true);
    $fail = is_array($fail) ? $fail : ['n' => 0, 't' => 0];
    if (time() - (int) $fail['t'] > LSM_FAIL_WINDOW) {
        $fail = ['n' => 0, 't' => time()];
    }
    if ((int) $fail['n'] >= LSM_MAX_FAILS) {
        ls_json(['ok' => false, 'code' => 'rate_limited', 'message' => 'too many attempts'], 429);
    }

    $given = lsm_header_token();
    if ($given === '') {
        $given = trim((string) ($input['token'] ?? ''));
    }
    if (ls_api_key_check($given)) {
        return;
    }

    $fail = ['n' => (int) $fail['n'] + 1, 't' => (int) $fail['t'] ?: time()];
    $pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (:k, :v)')->execute([':k' => $metaKey, ':v' => json_encode($fail)]);
    ls_json(['ok' => false, 'code' => 'unauthorized', 'message' => 'invalid token'], 401);
}

function lsm_code_message(string $code): string
{
    $map = [
        'not_found' => 'لایسنس یافت نشد',
        'revoked' => 'این لایسنس باطل شده است',
    ];
    return $map[$code] ?? $code;
}

function lsm_dispatch(string $action, array $in): array
{
    switch ($action) {
        case 'ping':
            $total = (int) ls_db()->query('SELECT COUNT(*) FROM licenses')->fetchColumn();
            return ['ok' => true, 'version' => KB_VERSION, 'server_time' => ls_now(), 'total' => $total, 'verify_url' => rtrim((string) ls_config()['base_url'], '/') . '/api/verify.php'];
        case 'stats':
            return ['ok' => true] + lsm_stats(max(0, (int) ($in['warn_days'] ?? ls_config()['warn_days_before'])));
        case 'list':
            return lsm_list((string) ($in['q'] ?? ''), (int) ($in['limit'] ?? 200), (int) ($in['offset'] ?? 0));
        case 'get':
            return ['ok' => true, 'license' => lsm_public(lsm_find($in))];
        case 'create':
            $row = ls_create_license((string) ($in['domain'] ?? ''), (int) ($in['months'] ?? 1), [
                'customer_name' => lsm_text($in['customer_name'] ?? null, 190),
                'customer_contact' => lsm_text($in['customer_contact'] ?? null, 190),
                'notes' => lsm_text($in['notes'] ?? null, 1000),
            ]);
            return ['ok' => true, 'license' => lsm_public($row)];
        case 'extend':
            return ['ok' => true, 'license' => lsm_public(lsm_extend(lsm_find($in), (int) ($in['months'] ?? 1), !empty($in['allow_revoked'])))];
        case 'revoke':
            $row = lsm_find($in);
            ls_db()->prepare("UPDATE licenses SET status='revoked', updated_at=:u WHERE id=:id")->execute([':u' => ls_now(), ':id' => $row['id']]);
            return ['ok' => true, 'license' => lsm_public(lsm_find(['id' => $row['id']]))];
        case 'domain':
            $row = lsm_find($in);
            $domain = lsm_domain((string) ($in['domain'] ?? ''));
            ls_db()->prepare('UPDATE licenses SET domain=:d, updated_at=:u WHERE id=:id')->execute([':d' => $domain, ':u' => ls_now(), ':id' => $row['id']]);
            return ['ok' => true, 'license' => lsm_public(lsm_find(['id' => $row['id']]))];
        case 'export':
            $rows = ls_db()->query('SELECT * FROM licenses ORDER BY id ASC')->fetchAll();
            return ['ok' => true, 'licenses' => array_map('lsm_public', $rows)];
        case 'import':
            return ['ok' => true] + lsm_import(is_array($in['licenses'] ?? null) ? $in['licenses'] : []);
    }
    throw new InvalidArgumentException('unknown action');
}

function lsm_text($v, int $max): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '');
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function lsm_domain(string $domain): string
{
    $domain = ls_normalize_domain($domain);
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        throw new InvalidArgumentException('دامنه نامعتبر است');
    }
    return $domain;
}

function lsm_find(array $in): array
{
    $pdo = ls_db();
    if (!empty($in['id'])) {
        $st = $pdo->prepare('SELECT * FROM licenses WHERE id=:id');
        $st->execute([':id' => (int) $in['id']]);
        $row = $st->fetch();
    } else {
        $row = ls_get_license_by_key((string) ($in['license_key'] ?? ''));
    }
    if (!$row) {
        throw new DomainException('not_found');
    }
    return $row;
}

function lsm_extend(array $row, int $months, bool $allowRevoked): array
{
    if (!$allowRevoked && $row['status'] === 'revoked') {
        throw new DomainException('revoked');
    }
    $months = max(1, min(12, $months));
    $utc = new DateTimeZone('UTC');
    $base = new DateTimeImmutable($row['expires_at'], $utc);
    $now = new DateTimeImmutable('now', $utc);
    if ($base < $now) {
        $base = $now;
    }
    $exp = $base->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
    ls_db()->prepare("UPDATE licenses SET expires_at=:e, status='active', months=months+:m, updated_at=:u WHERE id=:id")
        ->execute([':e' => $exp, ':m' => $months, ':u' => ls_now(), ':id' => $row['id']]);
    $fresh = lsm_find(['id' => $row['id']]);
    $fresh['months_added'] = $months;
    return $fresh;
}

function lsm_stats(int $warnDays): array
{
    $pdo = ls_db();
    $now = ls_now();
    $soon = gmdate('Y-m-d H:i:s', time() + $warnDays * 86400);
    $one = function (string $sql, array $p) use ($pdo): int {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return (int) $st->fetchColumn();
    };
    return [
        'active' => $one("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at > :n", [':n' => $now]),
        'soon' => $one("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at > :n AND expires_at <= :s", [':n' => $now, ':s' => $soon]),
        'expired' => $one("SELECT COUNT(*) FROM licenses WHERE status='expired' OR (status='active' AND expires_at <= :n)", [':n' => $now]),
        'revoked' => $one("SELECT COUNT(*) FROM licenses WHERE status='revoked'", []),
    ];
}

function lsm_list(string $q, int $limit, int $offset): array
{
    $pdo = ls_db();
    $pdo->exec('CREATE INDEX IF NOT EXISTS verify_log_domain ON verify_log (domain, created_at)');
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $where = '';
    $p = [];
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $parts = ['l.license_key = :k'];
        $p = [':k' => $q];
        foreach (['domain', 'customer_name', 'customer_contact', 'notes'] as $i => $col) {
            $parts[] = "l.$col LIKE :q$i ESCAPE '\\'";
            $p[":q$i"] = $like;
        }
        $where = 'WHERE ' . implode(' OR ', $parts);
    }
    $count = $pdo->prepare("SELECT COUNT(*) FROM licenses l $where");
    $count->execute($p);
    $st = $pdo->prepare("SELECT l.*, (SELECT MAX(v.created_at) FROM verify_log v WHERE v.domain = l.domain AND v.result IN ('ok','expired')) AS last_seen
        FROM licenses l $where ORDER BY l.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    return ['ok' => true, 'total' => (int) $count->fetchColumn(), 'licenses' => array_map('lsm_public', $st->fetchAll())];
}

function lsm_import(array $rows): array
{
    $pdo = ls_db();
    $added = $skipped = $invalid = 0;
    $ins = $pdo->prepare('INSERT INTO licenses (license_key, domain, months, starts_at, expires_at, status, customer_name, customer_contact, notes, created_at, updated_at)
        VALUES (:k,:d,:m,:s,:e,:st,:cn,:cc,:n,:c,:u)');
    foreach ($rows as $r) {
        if (!is_array($r)) {
            $invalid++;
            continue;
        }
        $key = trim((string) ($r['license_key'] ?? ''));
        $exp = (string) ($r['expires_at'] ?? '');
        try {
            $domain = lsm_domain((string) ($r['domain'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $invalid++;
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{7,63}$/', $key) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $exp)) {
            $invalid++;
            continue;
        }
        if (ls_get_license_by_key($key)) {
            $skipped++;
            continue;
        }
        $status = in_array($r['status'] ?? '', ['active', 'expired', 'revoked'], true) ? $r['status'] : 'active';
        $start = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($r['starts_at'] ?? '')) ? $r['starts_at'] : ls_now();
        $ins->execute([
            ':k' => $key,
            ':d' => $domain,
            ':m' => max(1, (int) ($r['months'] ?? 1)),
            ':s' => $start,
            ':e' => $exp,
            ':st' => $status,
            ':cn' => lsm_text($r['customer_name'] ?? null, 190),
            ':cc' => lsm_text($r['customer_contact'] ?? null, 190),
            ':n' => lsm_text($r['notes'] ?? null, 1000),
            ':c' => ls_now(),
            ':u' => ls_now(),
        ]);
        $added++;
    }
    return ['added' => $added, 'skipped' => $skipped, 'invalid' => $invalid];
}

function lsm_public(array $row): array
{
    $out = [
        'id' => (int) $row['id'],
        'license_key' => (string) $row['license_key'],
        'domain' => (string) $row['domain'],
        'months' => (int) $row['months'],
        'starts_at' => (string) $row['starts_at'],
        'expires_at' => (string) $row['expires_at'],
        'status' => (string) $row['status'],
        'customer_name' => $row['customer_name'] ?? null,
        'customer_contact' => $row['customer_contact'] ?? null,
        'notes' => $row['notes'] ?? null,
        'created_at' => (string) $row['created_at'],
        'last_seen' => $row['last_seen'] ?? null,
    ];
    if (isset($row['months_added'])) {
        $out['months_added'] = (int) $row['months_added'];
    }
    return $out;
}
