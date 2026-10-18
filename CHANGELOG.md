# تغییرات کلیدبان

## 2.1.0
- Fix: the installer printed an `https://` panel URL and forced the https redirect even when no SSL certificate was installed (port 443 then answers plain HTTP: "wrong version number"), so the panel never opened. `base_url` now stays `http://` with `force_https` off until certbot really succeeds; existing installs in that state are repaired by Update.
- Domain input is normalised (`https://`, paths, ports, upper case removed); if the domain does not resolve to this server the IP is offered instead.
- Opens ports 80/443 in ufw/firewalld, disables Apache's stock default site, checks that port 80 is not taken by nginx, checks PHP is loaded in Apache.
- Post-install self-check (`sudo kelidban check`): services, config test, ports, storage permissions, local requests to the exact panel URL, certificate, DNS, firewall.
- Interactive management menu (`sudo kelidban menu` / one-liner): install, update, SSL obtain/renew, help, status, panel URL & password reset, restart, logs, backup/restore, uninstall. All script text in English.
- A random panel password is generated if none is set and saved in `/root/kelidban-admin.txt` (600).
- The one-liner now fast-forwards an existing `/opt/kelidban-src` instead of running a stale copy.
- New CLI commands: `config-get`, `config-set`, `pw-state`, `password-stdin`, `reset-password`, `menu`, `ssl`, `check`.

## 2.0.1
- پنل دیگر دامنهٔ سرور را در راهنماها نشان نمی‌دهد (کارت «اتصال وردپرس» و کنار «صدور لایسنس»).
- `config.sample.php` و README فقط نمونهٔ `license.example.com` دارند.

## 2.0.0
- نام تازه: **کلیدبان** (قبلاً «License Server میرزا ولی پرو»)؛ مسیر نصب `/home/kelidban`.
- صفحهٔ ورود و پنل با طراحی تازه (فارسی، راست‌چین، دسکتاپ/تبلت/موبایل)، آمار لایسنس‌ها و کارت «امنیت ورود».
- آدرس مخفی پنل (`/kb-…/`)؛ `/admin/` حذف شد.
- رمز پنل هش‌شده در دیتابیس؛ تبدیل خودکار رمز متنی قدیمی.
- قفل ورود (۵ تلاش برای هر IP، ۴۰ تلاش کلی)، CSRF روی همهٔ فرم‌ها، کوکی امن، پایان نشست بعد از بی‌فعالیتی، هدرهای امنیتی، HTTPS اجباری.
- رمز پنل دیگر به‌جای کلید API پذیرفته نمی‌شود.
- صفحهٔ خرید: CSRF و محدودیت ۵ درخواست در ساعت.
- `install.sh` برای نصب تازه، آپدیت و انتقال از `/home/license_server` با بکاپ و برگشت خودکار.
- فرمان سرور `kelidban` (رمز، آدرس پنل، باز کردن قفل، بکاپ، آپدیت).
- کرون سلامت و بکاپ شبانه در `/etc/cron.d/kelidban`.

## 1.1.0
- API مدیریت `api/manage.php` برای افزونهٔ وردپرس و ساخت کلید API از پنل.

## 1.0.x
- صدور، تمدید، ابطال و تأیید لایسنس با قفل دامنه.
