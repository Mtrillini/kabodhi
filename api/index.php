<?php
declare(strict_types=1);

// ---------------------------------------------------------------
// 1. CORS Headers
// ---------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------------------------------
// 1b. Errores: nunca mostrar trazas al cliente
// ---------------------------------------------------------------
// Un fatal sin capturar imprimia el stack trace con rutas absolutas del
// servidor en medio de la respuesta JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('API sin capturar: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('API fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
});

// ---------------------------------------------------------------
// 2. Session
// ---------------------------------------------------------------
// La cookie de sesion no debe ser legible por JS (si un XSS se cuela, que no
// se lleve la sesion), no debe viajar en requests cross-site, y sobre HTTPS
// debe ir marcada como secure.
$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $esHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_name('nuve_admin_session');
session_start();

// ---------------------------------------------------------------
// 3. Load Config & Classes
// ---------------------------------------------------------------
require_once __DIR__ . '/config/Config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/middleware/Auth.php';
require_once __DIR__ . '/services/ConfigService.php';
require_once __DIR__ . '/services/StockService.php';
require_once __DIR__ . '/services/Mailer.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/ProductoService.php';
require_once __DIR__ . '/services/EnvioService.php';
require_once __DIR__ . '/services/PedidoService.php';
require_once __DIR__ . '/services/MercadoPagoService.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProductoController.php';
require_once __DIR__ . '/controllers/PedidoController.php';
require_once __DIR__ . '/controllers/MercadoPagoController.php';

// ---------------------------------------------------------------
// 4. Parse PATH_INFO into segments
// ---------------------------------------------------------------
$pathInfo = $_SERVER['PATH_INFO'] ?? '/';
$pathInfo = '/' . trim($pathInfo, '/');
$segments = array_values(array_filter(explode('/', $pathInfo)));
// e.g. /productos/5 → ['productos', '5']
//      /pedidos/3/estado → ['pedidos', '3', 'estado']

$method   = strtoupper($_SERVER['REQUEST_METHOD']);
$seg0     = $segments[0] ?? '';
$seg1     = $segments[1] ?? '';
$seg2     = $segments[2] ?? '';

// ---------------------------------------------------------------
// 5. Router
// ---------------------------------------------------------------

// --- AUTH ---
if ($seg0 === 'auth') {
    $ctrl = new AuthController();
    switch ($seg1) {
        case 'login':
            $ctrl->login();
            break;
        case 'logout':
            $ctrl->logout();
            break;
        case 'check':
            $ctrl->check();
            break;
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- PRODUCTOS ---
if ($seg0 === 'productos') {
    $ctrl = new ProductoController();

    if ($seg1 === '' || $seg1 === null) {
        // GET /productos | POST /productos
        if ($method === 'GET') {
            $ctrl->index();
        } elseif ($method === 'POST') {
            $ctrl->store();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
        }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        // GET /productos/{id} | PUT /productos/{id} | DELETE /productos/{id}
        if ($method === 'GET') {
            $ctrl->show($id);
        } elseif ($method === 'PUT') {
            $ctrl->update($id);
        } elseif ($method === 'DELETE') {
            $ctrl->destroy($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
        }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- HONGOS PRINCIPALES ---
if ($seg0 === 'hongos') {
    require_once __DIR__ . '/services/HongoService.php';
    require_once __DIR__ . '/controllers/HongoController.php';
    $ctrl = new HongoController();

    if ($seg1 === '' || $seg1 === null) {
        if ($method === 'GET')       $ctrl->index();
        elseif ($method === 'POST')  $ctrl->store();
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        if ($method === 'GET')         $ctrl->show($id);
        elseif ($method === 'PUT')     $ctrl->update($id);
        elseif ($method === 'DELETE')  $ctrl->destroy($id);
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- CONFIGURACION ---
if ($seg0 === 'configuracion') {
    require_once __DIR__ . '/controllers/ConfigController.php';
    $ctrl = new ConfigController();
    if ($method === 'GET')       $ctrl->index();
    elseif ($method === 'PUT')   $ctrl->update();
    else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    exit;
}

// --- CATEGORIAS ---
if ($seg0 === 'categorias') {
    require_once __DIR__ . '/controllers/CategoriaController.php';
    $ctrl = new CategoriaController();

    if ($seg1 === '' || $seg1 === null) {
        if ($method === 'GET')       $ctrl->index();
        elseif ($method === 'POST')  $ctrl->store();
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        if ($method === 'PUT')         $ctrl->update($id);
        elseif ($method === 'DELETE')  $ctrl->destroy($id);
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- USUARIOS ADMIN ---
if ($seg0 === 'usuarios') {
    require_once __DIR__ . '/controllers/UsuarioController.php';
    $ctrl = new UsuarioController();

    if ($seg1 === '' || $seg1 === null) {
        if ($method === 'GET')       $ctrl->index();
        elseif ($method === 'POST')  $ctrl->store();
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        if ($seg2 === 'password') {
            if ($method === 'PUT') $ctrl->updatePassword($id);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
        } elseif ($method === 'DELETE') {
            $ctrl->destroy($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
        }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- PEDIDOS ---
if ($seg0 === 'pedidos') {
    $ctrl = new PedidoController();

    if ($seg1 === '' || $seg1 === null) {
        // GET /pedidos | POST /pedidos
        if ($method === 'GET') {
            $ctrl->index();
        } elseif ($method === 'POST') {
            $ctrl->store();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
        }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        if ($seg2 === 'mails') {
            // GET /pedidos/{id}/mails
            if ($method === 'GET') {
                $ctrl->mails($id);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            }
        } elseif ($seg2 === 'tracking') {
            // PUT /pedidos/{id}/tracking
            if ($method === 'PUT') {
                $ctrl->updateTracking($id);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            }
        } elseif ($seg2 === 'estado') {
            // PUT /pedidos/{id}/estado
            if ($method === 'PUT') {
                $ctrl->updateEstado($id);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            }
        } else {
            // GET /pedidos/{id}
            if ($method === 'GET') {
                $ctrl->show($id);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            }
        }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- MERCADOPAGO ---
if ($seg0 === 'mp') {
    $ctrl = new MercadoPagoController();

    if ($seg1 === 'webhook') {
        $ctrl->webhook();
    } elseif ($seg1 === 'payment' && $seg2 !== '') {
        $ctrl->getPayment($seg2);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- ENVIOS ---
if ($seg0 === 'envios') {
    require_once __DIR__ . '/controllers/EnvioController.php';
    $ctrl = new EnvioController();

    if ($seg1 === 'calcular') {
        $ctrl->calcular();
    } elseif ($seg1 === '' || $seg1 === null) {
        if ($method === 'GET')       $ctrl->index();
        elseif ($method === 'POST')  $ctrl->store();
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } elseif (is_numeric($seg1)) {
        $id = (int)$seg1;
        if ($method === 'PUT')         $ctrl->update($id);
        elseif ($method === 'DELETE')  $ctrl->destroy($id);
        else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método no permitido.']); }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ruta no encontrada.']);
    }
    exit;
}

// --- UPLOAD ---
if ($seg0 === 'upload') {
    require_once __DIR__ . '/controllers/UploadController.php';
    $ctrl = new UploadController();
    if ($method === 'POST') {
        $ctrl->upload();
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    }
    exit;
}

// --- CONTACTO ---
if ($seg0 === 'contacto') {
    require_once __DIR__ . '/controllers/ContactoController.php';
    $ctrl = new ContactoController();
    if ($method === 'POST') {
        $ctrl->enviar();
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    }
    exit;
}

// --- 404 ---
http_response_code(404);
echo json_encode(['success' => false, 'message' => "Ruta '{$pathInfo}' no encontrada."]);
