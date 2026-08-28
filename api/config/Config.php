<?php
// Load .env from project root
$envFile = dirname(__DIR__, 2) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // Strip surrounding quotes
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

// Defaults if .env not present
if (!defined('DB_HOST'))            define('DB_HOST',            'localhost');
if (!defined('DB_NAME'))            define('DB_NAME',            'nuve_ecommerce');
if (!defined('DB_USER'))            define('DB_USER',            'root');
if (!defined('DB_PASS'))            define('DB_PASS',            '');
if (!defined('MP_ACCESS_TOKEN'))    define('MP_ACCESS_TOKEN',    '');
if (!defined('MP_PUBLIC_KEY'))      define('MP_PUBLIC_KEY',      '');
if (!defined('APP_URL'))            define('APP_URL',            'https://nuvearg.com');
if (!defined('SESSION_SECRET'))     define('SESSION_SECRET',     'nuve_secret_2024');

// Acceso al panel sin login, SOLO para desarrollo. Ver Auth::devSinLogin():
// ademas de este flag, la peticion tiene que venir de localhost, asi que aunque
// quede prendido por error en un hosting real no abre el panel a internet.
if (!defined('DEV_ADMIN_SIN_LOGIN')) define('DEV_ADMIN_SIN_LOGIN', 'false');

// CORS allowed origins (comma-separated in .env, or wildcard)
if (!defined('CORS_ORIGIN'))        define('CORS_ORIGIN',        '*');

// Mail
if (!defined('MAIL_FROM'))          define('MAIL_FROM',          'noreply@kabodhi.com');
if (!defined('MAIL_FROM_NAME'))     define('MAIL_FROM_NAME',     'KABODHI');
if (!defined('MAIL_SMTP_HOST'))     define('MAIL_SMTP_HOST',     '');
if (!defined('MAIL_SMTP_PORT'))     define('MAIL_SMTP_PORT',     '587');
if (!defined('MAIL_SMTP_USER'))     define('MAIL_SMTP_USER',     '');
if (!defined('MAIL_SMTP_PASS'))     define('MAIL_SMTP_PASS',     '');
// tls (STARTTLS, puerto 587) | ssl (puerto 465) | none
if (!defined('MAIL_SMTP_SECURE'))   define('MAIL_SMTP_SECURE',   'tls');

// Email que recibe los mensajes del formulario de contacto
if (!defined('CONTACT_EMAIL'))      define('CONTACT_EMAIL',      '');
