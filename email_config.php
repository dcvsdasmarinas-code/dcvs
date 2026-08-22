<?php
/**
 * Email Configuration for DCVS Notification System
 *
 * Values are loaded from the .env file (or real environment variables).
 * Credentials are NEVER committed to source code.
 *
 * For production, recommended provider: Brevo SMTP relay.
 * See .env.example for a full list of variables.
 */

require_once __DIR__ . '/env.php';
loadDcvsEnv();

// ── Sending Method ──
define('USE_SMTP', filter_var(env('DCVS_SMTP_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN));
define('SMTP_HOST', env('DCVS_SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)env('DCVS_SMTP_PORT', 587));
define('SMTP_USER', env('DCVS_SMTP_USER', ''));
define('SMTP_PASS', env('DCVS_SMTP_PASS', ''));
define('SMTP_SECURE', env('DCVS_SMTP_SECURE', 'tls'));

// ── Sender Info ──
define('MAIL_FROM', env('DCVS_MAIL_FROM', SMTP_USER));
define('MAIL_FROM_NAME', env('DCVS_MAIL_FROM_NAME', 'DCVS Dasmariñas'));

// ── DCVS Branding ──
define('DCVS_LOGO_URL', env('DCVS_LOGO_URL', 'uploads/site/logo.png'));
define('DCVS_PORTAL_URL', env('DCVS_PORTAL_URL', 'http://localhost/dcvs'));