<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
kb_require_admin();

$pdo = ls_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!kb_csrf_ok()) {
        kb_flash('bad', 'درخواست تأیید نشد (فرم قدیمی یا از جای دیگر). دوباره امتحان کنید.');
        header('Location: ./', true, 303);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $row = ls_create_license(
                (string) ($_POST['domain'] ?? ''),
                (int) ($_POST['months'] ?? 1),
                [
                    'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
                    'customer_contact' => trim((string) ($_POST['customer_contact'] ?? '')),
                    'notes' => trim((string) ($_POST['notes'] ?? '')),
                ]
            );
            $_SESSION['kb_reveal'] = ['label' => 'کلید لایسنس تازه برای ' . $row['domain'], 'value' => $row['license_key']];
            kb_flash('ok', 'لایسنس ساخته شد.');
        } elseif ($action === 'revoke') {
            $pdo->prepare("UPDATE licenses SET status='revoked', updated_at=:u WHERE id=:id")->execute([':u' => ls_now(), ':id' => (int) $_POST['id']]);
            kb_flash('ok', 'لایسنس باطل شد.');
        } elseif ($action === 'extend') {
            $id = (int) $_POST['id'];
            $add = max(1, min(12, (int) $_POST['months']));
            $st = $pdo->prepare('SELECT * FROM licenses WHERE id=:id');
            $st->execute([':id' => $id]);
            $row = $st->fetch();
            if ($row) {
                $base = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                if ($base < $now) {
                    $base = $now;
                }
                $newExp = $base->modify('+' . $add . ' months')->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE licenses SET expires_at=:e, status='active', months=months+:m, updated_at=:u WHERE id=:id")
                    ->execute([':e' => $newExp, ':m' => $add, ':u' => ls_now(), ':id' => $id]);
                kb_flash('ok', 'تمدید شد تا ' . $newExp . ' (UTC).');
            }
        } elseif ($action === 'approve_order') {
            $oid = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM orders WHERE id=:id AND status='pending'");
            $st->execute([':id' => $oid]);
            $ord = $st->fetch();
            if ($ord) {
                $lic = ls_create_license($ord['domain'], (int) $ord['months'], [
                    'customer_name' => $ord['customer_name'],
                    'customer_contact' => $ord['customer_contact'],
                    'notes' => 'order#' . $oid,
                ]);
                $pdo->prepare("UPDATE orders SET status='approved', license_id=:lid, updated_at=:u WHERE id=:id")
                    ->execute([':lid' => $lic['id'], ':u' => ls_now(), ':id' => $oid]);
                $_SESSION['kb_reveal'] = ['label' => 'کلید سفارش ' . $oid . ' (' . $lic['domain'] . ')', 'value' => $lic['license_key']];
                kb_flash('ok', 'سفارش تأیید و لایسنس صادر شد.');
            }
        } elseif ($action === 'reject_order') {
            $pdo->prepare("UPDATE orders SET status='rejected', updated_at=:u WHERE id=:id")->execute([':u' => ls_now(), ':id' => (int) $_POST['id']]);
            kb_flash('ok', 'سفارش رد شد.');
        } elseif ($action === 'api_key_create') {
            $_SESSION['kb_reveal'] = ['label' => 'کلید API تازه (فقط همین یک بار نمایش داده می‌شود)', 'value' => ls_api_key_create(), 'api' => true];
            kb_flash('ok', 'کلید API ساخته شد. همین حالا کپی کنید.');
        } elseif ($action === 'api_key_delete') {
            ls_api_key_delete();
            kb_flash('ok', 'کلید API حذف شد. وردپرس تا کلید تازه نگیرد به سرور وصل نمی‌شود.');
        } elseif ($action === 'backup') {
            $src = (string) ls_config()['db_path'];
            $dest = dirname($src) . '/backup_' . gmdate('Ymd_His') . '.sqlite';
            if (is_file($src)) {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
                kb_flash('ok', 'بکاپ روی سرور ذخیره شد: storage/' . basename($dest));
            }
        } elseif ($action === 'logout') {
            kb_logout();
            header('Location: ./', true, 303);
            exit;
        }
    } catch (Throwable $e) {
        kb_flash('bad', $e instanceof InvalidArgumentException ? $e->getMessage() : 'خطا: ' . $e->getMessage());
    }
    header('Location: ./', true, 303);
    exit;
}

$flashes = kb_flashes();
$reveal = $_SESSION['kb_reveal'] ?? null;
unset($_SESSION['kb_reveal']);

$licenses = $pdo->query('SELECT * FROM licenses ORDER BY id DESC LIMIT 200')->fetchAll();
$orders = $pdo->query("SELECT * FROM orders WHERE status='pending' ORDER BY id DESC LIMIT 50")->fetchAll();
$cfg = ls_config();
$base = rtrim((string) $cfg['base_url'], '/');
$apiSource = ls_api_key_source();
$apiCreated = ls_meta_get('api_key_created');
$lastLogin = json_decode((string) ls_meta_get('last_login'), true);
$weak = ls_meta_get('admin_pass_weak') !== null;
$legacy = ls_meta_get('admin_pass_legacy') !== null;

$now = ls_now();
$soon = gmdate('Y-m-d H:i:s', time() + (int) $cfg['warn_days_before'] * 86400);
$count = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
};
$stats = [
    ['فعال', $count("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at > :n", [':n' => $now]), 'ok'],
    ['نزدیک انقضا', $count("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at > :n AND expires_at <= :s", [':n' => $now, ':s' => $soon]), 'warn'],
    ['منقضی', $count("SELECT COUNT(*) FROM licenses WHERE status='expired' OR (status='active' AND expires_at <= :n)", [':n' => $now]), 'muted'],
    ['باطل', $count("SELECT COUNT(*) FROM licenses WHERE status='revoked'"), 'bad'],
];
$statusFa = ['active' => 'فعال', 'expired' => 'منقضی', 'revoked' => 'باطل'];
$monthOptions = '';
for ($i = 1; $i <= 12; $i++) {
    $monthOptions .= '<option value="' . $i . '">' . ls_fa((string) $i) . ' ماه</option>';
}
$csrf = kb_csrf_field();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0D0D0D">
<title>پنل مدیریت · <?= KB_NAME ?></title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/kelidban.css?v=<?= KB_VERSION ?>">
<script src="/assets/kelidban.js?v=<?= KB_VERSION ?>" defer></script>
</head>
<body class="kb-panel" data-idle="<?= (int) $cfg['session_idle_minutes'] * 60 ?>">
<header class="topbar">
  <div class="topbar-in">
    <div class="brand-row small">
      <img class="brand-mark" src="/assets/logo.svg" alt="" width="38" height="38">
      <div class="brand-name"><strong><?= KB_NAME ?></strong><span>پنل مدیریت لایسنس</span></div>
    </div>
    <div class="top-actions">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="backup"><button type="submit" class="btn btn-ghost">بکاپ دیتابیس</button></form>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="logout"><button type="submit" class="btn btn-dark">خروج</button></form>
    </div>
  </div>
</header>

<main class="wrap">
  <?php foreach ($flashes as [$type, $text]): ?>
    <div class="alert <?= $type === 'ok' ? 'ok' : 'bad' ?>" role="status"><?= ls_h($text) ?></div>
  <?php endforeach; ?>

  <?php if ($reveal): ?>
    <div class="card reveal">
      <h2><?= ls_h($reveal['label']) ?></h2>
      <div class="keybox">
        <input id="kb-reveal" class="mono" dir="ltr" readonly value="<?= ls_h($reveal['value']) ?>">
        <button type="button" class="btn btn-primary" data-copy="kb-reveal">کپی</button>
      </div>
      <?php if (!empty($reveal['api'])): ?>
        <p class="hint">روی سرور فقط اثر (هش) این کلید نگه داشته می‌شود؛ اگر گمش کنید باید کلید تازه بسازید.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($weak || $legacy): ?>
    <div class="alert warn">
      <?= $legacy ? 'رمز قدیمی پنل از config.php خوانده و به‌صورت هش ذخیره شد. ' : '' ?>
      <?= $weak ? 'رمز فعلی کوتاه است (کمتر از ۱۲ حرف). ' : '' ?>
      برای رمز تازه روی سرور اجرا کنید: <code dir="ltr">sudo kelidban password</code>
    </div>
  <?php endif; ?>

  <section class="stats">
    <?php foreach ($stats as [$label, $n, $tone]): ?>
      <div class="stat <?= $tone ?>"><span><?= $label ?></span><strong><?= ls_fa((string) $n) ?></strong></div>
    <?php endforeach; ?>
  </section>

  <div class="grid-2">
    <section class="card">
      <h2>صدور لایسنس تازه</h2>
      <form method="post" class="form">
        <?= $csrf ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
          <label>دامنهٔ ربات مشتری<input name="domain" dir="ltr" required placeholder="bot.example.com"></label>
          <label>مدت<select name="months"><?= $monthOptions ?></select></label>
        </div>
        <div class="row">
          <label>نام مشتری<input name="customer_name"></label>
          <label>راه تماس<input name="customer_contact" placeholder="تلگرام / ایمیل"></label>
        </div>
        <label>یادداشت<textarea name="notes" rows="2"></textarea></label>
        <div class="form-foot">
          <button type="submit" class="btn btn-primary">صدور لایسنس</button>
          <span class="hint">ربات‌ها از <span class="mono" dir="ltr"><?= ls_h($base) ?>/api/verify.php</span> می‌پرسند.</span>
        </div>
      </form>
    </section>

    <div class="stack">
      <section class="card">
        <h2>اتصال وردپرس (کلید API)</h2>
        <p class="hint">افزونهٔ لایسنس وردپرس با این کلید روی همین سرور لایسنس می‌سازد و فهرست می‌گیرد. در وردپرس: «تنظیمات» › «سرور لایسنس» › «سرور لایسنس جداگانه»؛ آدرس <span class="mono" dir="ltr"><?= ls_h($base) ?></span> و همین کلید.</p>
        <?php if ($apiSource === 'config'): ?>
          <p><span class="badge ok">فعال</span> کلید از <span class="mono">api_token</span> در <span class="mono">config.php</span> خوانده می‌شود.</p>
        <?php elseif ($apiSource === 'panel'): ?>
          <p><span class="badge ok">فعال</span> کلید ساخته شده<?= $apiCreated ? ' در ' . ls_h($apiCreated) . ' (UTC)' : '' ?>.</p>
        <?php else: ?>
          <p><span class="badge warn">ساخته نشده</span> تا کلید نسازید وردپرس وصل نمی‌شود.</p>
        <?php endif; ?>
        <?php if ($apiSource !== 'config'): ?>
          <div class="actions">
            <form method="post"<?= $apiSource === 'panel' ? ' data-confirm="کلید فعلی از کار می‌افتد و وردپرس تا کلید تازه را نگیرد وصل نمی‌شود. ادامه؟"' : '' ?>>
              <?= $csrf ?><input type="hidden" name="action" value="api_key_create">
              <button type="submit" class="btn btn-primary"><?= $apiSource === 'panel' ? 'ساخت کلید تازه' : 'ساخت کلید API' ?></button>
            </form>
            <?php if ($apiSource === 'panel'): ?>
              <form method="post" data-confirm="کلید API حذف شود؟">
                <?= $csrf ?><input type="hidden" name="action" value="api_key_delete">
                <button type="submit" class="btn btn-danger">حذف کلید</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2>امنیت ورود</h2>
        <ul class="facts">
          <li><span>آخرین ورود</span><strong dir="ltr"><?= $lastLogin ? ls_h($lastLogin['at'] . ' UTC · ' . $lastLogin['ip']) : '—' ?></strong></li>
          <li><span>قفل ورود</span><strong><?= ls_fa((string) (int) $cfg['login_max_attempts']) ?> تلاش · <?= ls_fa((string) (int) $cfg['login_lock_minutes']) ?> دقیقه</strong></li>
          <li><span>خروج خودکار</span><strong><?= ls_fa((string) (int) $cfg['session_idle_minutes']) ?> دقیقه بی‌فعالیتی</strong></li>
        </ul>
        <p class="hint">تغییر رمز: <code dir="ltr">sudo kelidban password</code> · آدرس تازهٔ پنل: <code dir="ltr">sudo kelidban new-url</code></p>
      </section>
    </div>
  </div>

  <?php if ($orders): ?>
  <section class="card">
    <h2>سفارش‌های در انتظار</h2>
    <div class="table">
      <table>
        <thead><tr><th>#</th><th>دامنه</th><th>مدت</th><th>مشتری</th><th>زمان (UTC)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td data-label="#"><?= ls_fa((string) (int) $o['id']) ?></td>
            <td data-label="دامنه" dir="ltr" class="mono"><?= ls_h($o['domain']) ?></td>
            <td data-label="مدت"><?= ls_fa((string) (int) $o['months']) ?> ماه</td>
            <td data-label="مشتری"><?= ls_h(trim($o['customer_name'] . ' · ' . $o['customer_contact'], ' ·')) ?></td>
            <td data-label="زمان" dir="ltr"><?= ls_h($o['created_at']) ?></td>
            <td class="cell-actions">
              <form method="post"><?= $csrf ?><input type="hidden" name="action" value="approve_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">تأیید و صدور</button></form>
              <form method="post" data-confirm="سفارش رد شود؟"><?= $csrf ?><input type="hidden" name="action" value="reject_order"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">رد</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>لایسنس‌ها</h2>
    <?php if (!$licenses): ?>
      <p class="hint">هنوز لایسنسی صادر نشده است.</p>
    <?php else: ?>
    <div class="table">
      <table>
        <thead><tr><th>کلید</th><th>دامنه</th><th>انقضا (UTC)</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($licenses as $L): ?>
          <tr>
            <td data-label="کلید" class="mono" dir="ltr"><?= ls_h($L['license_key']) ?></td>
            <td data-label="دامنه" class="mono" dir="ltr"><?= ls_h($L['domain']) ?></td>
            <td data-label="انقضا" dir="ltr"><?= ls_h(substr((string) $L['expires_at'], 0, 16)) ?></td>
            <td data-label="وضعیت"><span class="badge <?= $L['status'] === 'active' ? 'ok' : ($L['status'] === 'revoked' ? 'bad' : 'muted') ?>"><?= ls_h($statusFa[$L['status']] ?? $L['status']) ?></span></td>
            <td class="cell-actions">
              <form method="post" class="inline-extend">
                <?= $csrf ?><input type="hidden" name="action" value="extend"><input type="hidden" name="id" value="<?= (int) $L['id'] ?>">
                <select name="months" aria-label="مدت تمدید"><?= $monthOptions ?></select>
                <button type="submit" class="btn btn-ghost btn-sm">تمدید</button>
              </form>
              <?php if ($L['status'] !== 'revoked'): ?>
                <form method="post" data-confirm="این لایسنس باطل شود؟ ربات مشتری دیگر فروش نخواهد داشت.">
                  <?= $csrf ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $L['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">ابطال</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <footer class="foot"><?= KB_NAME ?> · نسخهٔ <?= ls_fa(KB_VERSION) ?></footer>
</main>
</body>
</html>
