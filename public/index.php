<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
kb_force_https();
kb_headers(false);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="generator" content="kelidban">
<meta name="theme-color" content="#0D0D0D">
<title><?= KB_NAME ?> · سرویس لایسنس</title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/kelidban.css?v=<?= KB_VERSION ?>">
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
    <h1>سرویس لایسنس فعال است</h1>
    <p class="status-line"><span class="dot"></span>ربات‌ها می‌توانند لایسنس خود را از این سرور تأیید کنند.</p>
    <p class="lead">برای تهیه یا تمدید لایسنس از صفحهٔ درخواست استفاده کنید.</p>
    <a class="btn btn-primary" href="/buy/">درخواست لایسنس</a>
  </div>
</main>
<footer class="pub-foot"><?= KB_NAME ?></footer>
</body>
</html>
