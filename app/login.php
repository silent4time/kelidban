<?php
/** @var array{error:string,notice:string,locked:int,global:bool,no_password:bool} $v */
$cfg = ls_config();
$blocked = $v['locked'] > 0 || $v['no_password'];
$maxTry = ls_fa((string) (int) $cfg['login_max_attempts']);
$idle = ls_fa((string) (int) $cfg['session_idle_minutes']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0D0D0D">
<title>ورود · <?= KB_NAME ?></title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/kelidban.css?v=<?= KB_VERSION ?>">
<script src="/assets/kelidban.js?v=<?= KB_VERSION ?>" defer></script>
</head>
<body class="kb-auth">
<main class="auth">
  <section class="auth-brand" aria-hidden="true">
    <div class="brand-row">
      <img class="brand-mark" src="/assets/logo.svg" alt="" width="52" height="52">
      <div class="brand-name"><strong><?= KB_NAME ?></strong><span>سامانهٔ صدور و مدیریت لایسنس</span></div>
    </div>
    <div class="brand-mid">
      <h2>هر کلید، فقط در دست صاحبش.</h2>
      <p>صدور، تمدید و ابطال لایسنس ربات‌ها با قفل دامنه؛ همه از یک پنل خصوصی.</p>
      <ul class="brand-points">
        <li><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/></svg>اتصال رمزنگاری‌شده و هدرهای امنیتی</li>
        <li><svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5z"/></svg>قفل خودکار پس از <?= $maxTry ?> تلاش ناموفق</li>
        <li><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>خروج خودکار پس از <?= $idle ?> دقیقه بی‌فعالیتی</li>
      </ul>
    </div>
    <div class="brand-foot"><?= KB_NAME ?> · نسخهٔ <?= ls_fa(KB_VERSION) ?></div>
  </section>

  <section class="auth-side">
    <form class="auth-card" method="post" action="./" autocomplete="off">
      <div class="auth-mini">
        <img src="/assets/logo.svg" alt="" width="40" height="40">
        <div><strong><?= KB_NAME ?></strong><span>سامانهٔ صدور و مدیریت لایسنس</span></div>
      </div>
      <h1>ورود به پنل مدیریت</h1>
      <p class="auth-sub">این بخش فقط برای مدیر سامانه است.</p>

      <?php if ($v['notice'] !== ''): ?>
        <div class="alert info" role="status"><?= ls_h($v['notice']) ?></div>
      <?php endif; ?>
      <?php if ($v['no_password']): ?>
        <div class="alert warn" role="alert">رمز پنل هنوز تعیین نشده است. روی سرور اجرا کنید:<code dir="ltr">sudo kelidban password</code></div>
      <?php elseif ($v['global']): ?>
        <div class="alert bad" role="alert">به‌خاطر تلاش‌های ناموفق زیاد، ورود برای <?= kb_minutes_fa($v['locked']) ?> بسته شد. برای باز کردن فوری روی سرور:<code dir="ltr">sudo kelidban unlock</code></div>
      <?php elseif ($v['locked'] > 0): ?>
        <div class="alert bad" role="alert">ورود از این دستگاه موقتاً قفل شد. <?= kb_minutes_fa($v['locked']) ?> دیگر دوباره امتحان کنید.</div>
      <?php elseif ($v['error'] !== ''): ?>
        <div class="alert bad" role="alert"><?= ls_h($v['error']) ?></div>
      <?php endif; ?>

      <?= kb_csrf_field() ?>
      <label class="field-label" for="kb-pass">رمز عبور</label>
      <div class="pass">
        <svg class="pass-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5z"/></svg>
        <input id="kb-pass" type="password" name="password" dir="ltr" autocomplete="current-password" required<?= $blocked ? ' disabled' : ' autofocus' ?>>
        <button type="button" class="pass-eye" data-toggle-pass="kb-pass" aria-label="نمایش رمز"<?= $blocked ? ' disabled' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <p class="caps" data-caps hidden>Caps Lock روشن است.</p>

      <button type="submit" class="btn btn-primary btn-block"<?= $blocked ? ' disabled' : '' ?>>
        ورود امن
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l-6 6 6 6"/></svg>
      </button>

      <div class="auth-meta">
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/></svg><?= kb_is_https() ? 'اتصال امن' : 'اتصال محلی' ?></span>
        <span>تلاش‌های ورود با IP ثبت می‌شوند</span>
      </div>
    </form>
  </section>
</main>
</body>
</html>
