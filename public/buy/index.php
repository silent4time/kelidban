<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/bootstrap.php';
kb_force_https();
kb_headers(false);
kb_session_start();
header('Content-Type: text/html; charset=utf-8');

$cfg = ls_config();
$product = (string) $cfg['product_name'];
$msg = '';
$err = '';
$issuedKey = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!kb_csrf_ok()) {
            throw new InvalidArgumentException('فرم منقضی شده بود؛ دوباره ارسال کنید.');
        }
        $ip = kb_client_ip();
        $rk = 'buy:' . hash('sha256', $ip);
        $r = json_decode((string) ls_meta_get($rk), true);
        $r = (is_array($r) && time() - (int) $r['t'] < 3600) ? $r : ['n' => 0, 't' => time()];
        if ((int) $r['n'] >= 5) {
            throw new InvalidArgumentException('تعداد درخواست‌های شما زیاد بوده است؛ یک ساعت دیگر امتحان کنید.');
        }
        $domain = ls_normalize_domain((string) ($_POST['domain'] ?? ''));
        $months = max(1, min(12, (int) ($_POST['months'] ?? 1)));
        $name = mb_substr(trim((string) ($_POST['customer_name'] ?? '')), 0, 120);
        $contact = mb_substr(trim((string) ($_POST['customer_contact'] ?? '')), 0, 190);
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            throw new InvalidArgumentException('دامنهٔ معتبر وارد کنید (مثل bot.example.com)');
        }
        if ($contact === '') {
            throw new InvalidArgumentException('راه ارتباطی الزامی است');
        }
        $r['n'] = (int) $r['n'] + 1;
        ls_meta_set($rk, json_encode($r));
        $now = ls_now();
        if (!empty($cfg['buy_requires_admin_approve'])) {
            $st = ls_db()->prepare('INSERT INTO orders (domain, months, customer_name, customer_contact, status, created_at, updated_at) VALUES (:d,:m,:n,:c,\'pending\',:t,:t)');
            $st->execute([':d' => $domain, ':m' => $months, ':n' => $name, ':c' => $contact, ':t' => $now]);
            $msg = 'درخواست ثبت شد. پس از بررسی و پرداخت، لایسنس برای شما صادر می‌شود.';
            ls_notify_owner("سفارش لایسنس جدید\nدامنه: {$domain}\nمدت: {$months} ماه\nتماس: {$contact}");
        } else {
            $lic = ls_create_license($domain, $months, [
                'customer_name' => $name,
                'customer_contact' => $contact,
                'notes' => 'auto-buy',
            ]);
            $issuedKey = $lic['license_key'];
            $msg = 'لایسنس صادر شد. کلید را در جای امن نگه دارید.';
        }
    } catch (Throwable $e) {
        $err = $e instanceof InvalidArgumentException ? $e->getMessage() : 'ثبت نشد؛ کمی بعد دوباره امتحان کنید.';
    }
}
$old = function (string $k): string {
    return ls_h((string) ($_POST[$k] ?? ''));
};
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0D0D0D">
<title>درخواست لایسنس <?= ls_h($product) ?> · <?= KB_NAME ?></title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/kelidban.css?v=<?= KB_VERSION ?>">
<script src="/assets/kelidban.js?v=<?= KB_VERSION ?>" defer></script>
</head>
<body class="kb-public">
<header class="pub-head">
  <div class="topbar-in">
    <div class="brand-row small">
      <img class="brand-mark" src="/assets/logo.svg" alt="" width="38" height="38">
      <div class="brand-name"><strong><?= KB_NAME ?></strong><span>سرویس صدور و تأیید لایسنس</span></div>
    </div>
  </div>
</header>
<main class="pub-main">
  <div class="card">
    <h1>درخواست لایسنس <?= ls_h($product) ?></h1>
    <p class="lead">لایسنس به <strong>یک دامنه</strong> قفل می‌شود. مدت را از ۱ تا ۱۲ ماه انتخاب کنید. پس از تأیید، کلید را در تنظیمات ربات وارد می‌کنید.</p>
    <?php if ($msg): ?><div class="alert ok"><?= ls_h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert bad"><?= ls_h($err) ?></div><?php endif; ?>
    <?php if ($issuedKey): ?><div class="keyout"><?= ls_h($issuedKey) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= kb_csrf_field() ?>
      <div class="row">
        <label>دامنهٔ ربات<input name="domain" dir="ltr" required placeholder="bot.example.com" value="<?= $old('domain') ?>"></label>
        <label>مدت اشتراک<select name="months">
          <?php for ($i = 1; $i <= 12; $i++): ?>
            <option value="<?= $i ?>"<?= ((int) ($_POST['months'] ?? 1) === $i) ? ' selected' : '' ?>><?= ls_fa((string) $i) ?> ماه</option>
          <?php endfor; ?>
        </select></label>
      </div>
      <div class="row">
        <label>نام<input name="customer_name" value="<?= $old('customer_name') ?>"></label>
        <label>راه تماس (تلگرام / ایمیل / واتساپ)<input name="customer_contact" required value="<?= $old('customer_contact') ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">ثبت درخواست</button>
    </form>
    <?php if (!empty($cfg['support_email']) || !empty($cfg['support_telegram'])): ?>
      <p class="hint">پشتیبانی:
        <?php if (!empty($cfg['support_email'])): ?><a href="mailto:<?= ls_h($cfg['support_email']) ?>" dir="ltr"><?= ls_h($cfg['support_email']) ?></a><?php endif; ?>
        <?php if (!empty($cfg['support_telegram'])): ?> · <span dir="ltr"><?= ls_h($cfg['support_telegram']) ?></span><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</main>
<footer class="pub-foot"><?= KB_NAME ?></footer>
</body>
</html>
