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

// CORS allowed origins (comma-separated in .env, or wildcard)
if (!defined('CORS_ORIGIN'))        define('CORS_ORIGIN',        '*');

// Mail
if (!defined('MAIL_FROM'))          define('MAIL_FROM',          'noreply@nuve.com');
if (!defined('MAIL_FROM_NAME'))     define('MAIL_FROM_NAME',     'NÜVE Perfumería');
if (!defined('MAIL_SMTP_HOST'))     define('MAIL_SMTP_HOST',     '');
if (!defined('MAIL_SMTP_PORT'))     define('MAIL_SMTP_PORT',     '587');
if (!defined('MAIL_SMTP_USER'))     define('MAIL_SMTP_USER',     '');
if (!defined('MAIL_SMTP_PASS'))     define('MAIL_SMTP_PASS',     '');

// Email que recibe los mensajes del formulario de contacto
if (!defined('CONTACT_EMAIL'))      define('CONTACT_EMAIL',      'diegobrunetti33@gmail.com');
