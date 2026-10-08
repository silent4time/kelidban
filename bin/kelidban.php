<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib/bootstrap.php';

const KB_ROOT = __DIR__ . '/..';

function out(string $s = ''): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function fail(string $s, int $code = 1): void
{
    fwrite(STDERR, '[x] ' . $s . PHP_EOL);
    exit($code);
}

function ask_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    if ($tty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim((string) $line, "\r\n");
}

/** @return list<string> panel folder names under public/ */
function panel_dirs(): array
{
    $out = [];
    foreach (glob(KB_ROOT . '/public/kb-*', GLOB_ONLYDIR) ?: [] as $d) {
        if (is_file($d . '/index.php')) {
            $out[] = basename($d);
        }
    }
    return $out;
}

function panel_url(string $dir): string
{
    return rtrim((string) ls_config()['base_url'], '/') . '/' . $dir . '/';
}

function new_panel_dir(): string
{
    foreach (glob(KB_ROOT . '/public/kb-*', GLOB_ONLYDIR) ?: [] as $d) {
        @unlink($d . '/index.php');
        @rmdir($d);
    }
    $name = 'kb-' . bin2hex(random_bytes(8));
    $dir = KB_ROOT . '/public/' . $name;
    if (!mkdir($dir, 0755) || file_put_contents($dir . '/index.php', "<?php\nrequire dirname(__DIR__, 2) . '/app/admin.php';\n") === false) {
        fail('could not create ' . $dir);
    }
    chmod($dir . '/index.php', 0644);
    return $name;
}

/** Blanks admin_password in config.php (the hash in the database replaces it) */
function clear_config_password(): bool
{
    $file = KB_ROOT . '/config.php';
    if (!is_file($file)) {
        return false;
    }
    $src = (string) file_get_contents($file);
    $new = preg_replace("/('admin_password'\s*=>\s*)(['\"])(?:\\\\.|(?!\\2).)*\\2/s", "\$1''", $src, 1, $n);
    if ($new === null || $n === 0 || $new === $src) {
        return false;
    }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, $new);
    @chmod($tmp, fileperms($file) & 0777);
    @chown($tmp, fileowner($file));
    @chgrp($tmp, filegroup($file));
    return rename($tmp, $file);
}

function cmd_password(): void
{
    $p1 = ask_hidden('New panel password (min 10 chars): ');
    if (mb_strlen($p1) < 10) {
        fail('too short — use at least 10 characters (12+ recommended)');
    }
    $p2 = ask_hidden('Repeat password: ');
    if (!hash_equals($p1, $p2)) {
        fail('passwords do not match');
    }
    kb_password_set($p1);
    ls_meta_delete('admin_pass_legacy');
    kb_bump_epoch();
    clear_config_password();
    out('[OK] Password saved (hashed). All open panel sessions were signed out.');
    if (mb_strlen($p1) < 12) {
        out('[!] Works, but 12+ characters is recommended.');
    }
}

function cmd_migrate(): void
{
    $legacy = kb_legacy_password();
    if ($legacy !== '') {
        kb_password_set($legacy);
        ls_meta_set('admin_pass_legacy', '1');
        out('[OK] Old plaintext password moved to a hash in the database.');
        if (mb_strlen($legacy) < 12) {
            out('[!] That password is short (' . mb_strlen($legacy) . ' chars). Change it:  sudo kelidban password');
        }
    }
    if (ls_meta_get('admin_pass_hash') !== null && clear_config_password()) {
        out('[OK] admin_password removed from config.php');
    }
    if (!panel_dirs()) {
        $d = new_panel_dir();
        out('[OK] Private panel address created: ' . panel_url($d));
    }
    if (!kb_password_is_set()) {
        out('[!] No panel password yet. Run:  sudo kelidban password');
    }
}

function cmd_url(): void
{
    $dirs = panel_dirs();
    if (!$dirs) {
        fail('no panel address yet — run:  sudo kelidban new-url');
    }
    foreach ($dirs as $d) {
        out(panel_url($d));
    }
}

function cmd_new_url(): void
{
    $d = new_panel_dir();
    kb_bump_epoch();
    out('[OK] New panel address (the old one now returns 404):');
    out(panel_url($d));
}

function cmd_status(): void
{
    $cfg = ls_config();
    $pdo = ls_db();
    $last = json_decode((string) ls_meta_get('last_login'), true);
    $locks = (int) $pdo->query("SELECT COUNT(*) FROM meta WHERE k LIKE 'lfail:%'")->fetchColumn();
    out('Kelidban ' . KB_VERSION . '  (' . realpath(KB_ROOT) . ')');
    out('base_url      ' . $cfg['base_url']);
    out('panel         ' . (panel_dirs() ? panel_url(panel_dirs()[0]) : '(none — sudo kelidban new-url)'));
    out('password      ' . (ls_meta_get('admin_pass_hash') !== null ? 'hashed' . (ls_meta_get('admin_pass_weak') !== null ? ' (short!)' : '') : (kb_legacy_password() !== '' ? 'PLAINTEXT in config.php — run: sudo kelidban migrate' : 'NOT SET — run: sudo kelidban password')));
    out('api key       ' . ls_api_key_source());
    out('licenses      ' . $pdo->query('SELECT COUNT(*) FROM licenses')->fetchColumn());
    out('last login    ' . ($last ? $last['at'] . ' UTC from ' . $last['ip'] : '-'));
    out('lock records  ' . $locks);
    out('force_https   ' . (empty($cfg['force_https']) ? 'off' : 'on'));
}

function cmd_backup(array $args): void
{
    $dir = $args[0] ?? '/root/kelidban-backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fail('cannot create ' . $dir);
    }
    $dest = rtrim($dir, '/') . '/licenses_' . gmdate('Ymd_His') . '.sqlite';
    ls_db()->exec('VACUUM INTO ' . ls_db()->quote($dest));
    chmod($dest, 0600);
    out('[OK] ' . $dest);
}

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'help';
switch ($cmd) {
    case 'password':
        cmd_password();
        break;
    case 'migrate':
        cmd_migrate();
        break;
    case 'url':
        cmd_url();
        break;
    case 'new-url':
        cmd_new_url();
        break;
    case 'unlock':
        out('[OK] Login lockouts cleared: ' . kb_unlock_all());
        break;
    case 'logout-all':
        kb_bump_epoch();
        out('[OK] All panel sessions signed out.');
        break;
    case 'status':
        cmd_status();
        break;
    case 'backup':
        cmd_backup($args);
        break;
    case 'pw-ok':
        exit(ls_meta_get('admin_pass_hash') !== null && ls_meta_get('admin_pass_weak') === null ? 0 : 1);
    default:
        out('Kelidban ' . KB_VERSION . ' — usage: sudo kelidban <command>');
        out('  url          show the private panel address');
        out('  password     set / change the panel password');
        out('  new-url      make a new panel address (old one stops working)');
        out('  unlock       clear login lockouts');
        out('  logout-all   sign out every open panel session');
        out('  status       version, panel address, password state, counts');
        out('  backup [dir] copy the database (default /root/kelidban-backups)');
        out('  update       pull the latest code and run the installer');
        exit($cmd === 'help' ? 0 : 1);
}
