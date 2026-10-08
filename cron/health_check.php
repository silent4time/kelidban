<?php
/**
 * Run every 15 minutes (install.sh adds it to /etc/cron.d/kelidban):
 * if the verify endpoint fails, notify the owner (Telegram + Bale + WhatsApp webhook).
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

$cfg = ls_config();
$url = rtrim($cfg['base_url'], '/') . '/api/verify.php';
$stateFile = dirname((string) $cfg['db_path']) . '/health_state.json';

$ch = curl_init($url . '?license_key=health&domain=health.test');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

// Endpoint should respond (even 403 for bad key) — connection failure = down
$up = ($body !== false && $code > 0 && $code < 500);

$prev = ['up' => true, 'down_since' => null, 'last_alert' => 0];
if (is_file($stateFile)) {
    $j = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($j)) {
        $prev = array_merge($prev, $j);
    }
}

$now = time();
if (!$up) {
    if (!empty($prev['up'])) {
        $prev['down_since'] = $now;
        $prev['up'] = false;
        ls_notify_owner(
            "⚠️ سرور لایسنس کلیدبان در دسترس نیست\n" .
            "URL: {$url}\nHTTP: {$code}\nCurl: {$err}\nزمان UTC: " . gmdate('c')
        );
        $prev['last_alert'] = $now;
    } elseif ($now - (int) $prev['last_alert'] > 3600) {
        ls_notify_owner("⚠️ هنوز سرور لایسنس کلیدبان قطع است\nURL: {$url}\nاز: " . gmdate('c', (int) $prev['down_since']));
        $prev['last_alert'] = $now;
    }
} else {
    if (isset($prev['up']) && $prev['up'] === false) {
        ls_notify_owner("✅ سرور لایسنس کلیدبان دوباره آنلاین شد\nURL: {$url}");
    }
    $prev['up'] = true;
    $prev['down_since'] = null;
}

file_put_contents($stateFile, json_encode($prev, JSON_PRETTY_PRINT));
echo $up ? "UP\n" : "DOWN\n";
