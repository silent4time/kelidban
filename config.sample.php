<?php
/**
 * کلیدبان (Kelidban) — license server config.
 * install.sh copies this to config.php and fills the secrets. Never commit config.php.
 * Keys missing from config.php fall back to the values here (see ls_config).
 */
return [
    // Public URL of this server (no trailing slash). Bots verify at {base_url}/api/verify.php
    'base_url' => 'https://license.silentping.ir',

    // The panel password is NOT kept here; set it on the server with:  sudo kelidban password
    // (stored as a hash in the database). An old plaintext value here is converted once, then cleared.
    'admin_password' => '',

    // Key for api/manage.php (WordPress license plugin). Leave empty and use the
    // "ساخت کلید API" button in the panel (recommended). A value here overrides that button.
    'api_token' => '',

    // Long random secret (install.sh generates it)
    'hmac_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64CHARS_MINIMUM',

    // SQLite path (writable by PHP)
    'db_path' => __DIR__ . '/storage/licenses.sqlite',

    // --- Security ---
    // Redirect http to https on the panel, buy and home pages (the bots' API is never redirected)
    'force_https' => true,
    // true only when a proxy in front (Cloudflare, nginx) terminates TLS and sets X-Forwarded-Proto
    'trust_proxy' => false,
    // The panel session closes after this many idle minutes, and always after session_max_hours
    'session_idle_minutes' => 30,
    'session_max_hours' => 12,
    // Wrong passwords from one IP before that IP is locked out for login_lock_minutes
    'login_max_attempts' => 5,
    'login_lock_minutes' => 15,
    // Wrong passwords from all IPs together (within login_lock_minutes) before login closes for everyone;
    // reopen on the server with:  sudo kelidban unlock
    'login_global_max' => 40,

    // Product name on the buy page
    'product_name' => 'سایلنت‌سل',

    // Grace: if this server is unreachable, client bots keep working this many hours
    'client_grace_hours' => 96,

    // Warn customer bot admin this many days before expiry
    'warn_days_before' => 7,

    // Your notification channels (owner alerts when THIS server is down)
    'owner_telegram_bot_token' => '',
    'owner_telegram_chat_id' => '',
    'owner_bale_bot_token' => '',
    'owner_bale_chat_id' => '',
    // WhatsApp: use a gateway that accepts HTTP (e.g. Green-API / Twilio). Leave empty to skip.
    'owner_whatsapp_webhook_url' => '',  // POST JSON {text: "..."}
    'owner_whatsapp_token' => '',

    // Buy page: manual approval (true) or auto-issue after the form (false)
    'buy_requires_admin_approve' => true,

    // Contact shown on the buy page
    'support_email' => 'silentping.vpn@gmail.com',
    'support_telegram' => '',
];
