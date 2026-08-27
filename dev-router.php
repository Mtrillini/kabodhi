<?php
// Router del servidor PHP embebido para desarrollo.
// Sirve el proyecto bajo /hongos (el mismo base que usa js/config.js en local)
// y arma PATH_INFO para /hongos/api/index.php/<ruta>.

$root = __DIR__;

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// La raiz redirige al home del sitio
if ($uri === '/' || $uri === '') {
    header('Location: /hongos/index.html');
    exit;
}

// Todo lo que sirve el sitio cuelga de /hongos
if (strpos($uri, '/hongos') !== 0) {
    http_response_code(404);
    echo 'Not found. Usa /hongos/';
    exit;
}
$path = substr($uri, strlen('/hongos'));
if ($path === '' ) { header('Location: /hongos/index.html'); exit; }

// Mismas protecciones que el .htaccess de produccion: el dev server no debe
// servir credenciales, dumps de la base ni los mails guardados.
if (preg_match('#(^|/)\.(env|git)|^/(database|storage)/|\.(sql|log)$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// API: /hongos/api/index.php/<ruta>  ->  PATH_INFO = /<ruta>
if (preg_match('#^/api/index\.php(/.*)?$#', $path, $m)) {
    $_SERVER['PATH_INFO']       = $m[1] ?? '';
    $_SERVER['SCRIPT_NAME']     = '/hongos/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/index.php';
    require $root . '/api/index.php';
    exit;
}

$file = $root . $path;
if ($path === '/' ) $file = $root . '/index.html';

if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.html';
}

// URLs sin extension: /hongos/productos -> productos.html
if (!is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === '' && is_file($file . '.html')) {
    $file .= '.html';
}

if (is_file($file)) {
    if (substr($file, -4) === '.php') { require $file; exit; }
    $mimes = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',  'js'  => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8', 'svg' => 'image/svg+xml',
        'png'  => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'mp4'  => 'video/mp4', 'webm' => 'video/webm', 'txt' => 'text/plain; charset=UTF-8',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: no-store');
    readfile($file);
    exit;
}

http_response_code(404);
echo 'Not found: ' . htmlspecialchars($path);
