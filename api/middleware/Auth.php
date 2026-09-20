<?php

class Auth {

    /** Minutos de inactividad tras los cuales la sesion del panel vence. */
    public const INACTIVIDAD_MIN = 480;

    /**
     * Bypass de login para desarrollo. Exige todas estas condiciones:
     *   1. DEV_ADMIN_SIN_LOGIN=true en el .env (que esta en .gitignore)
     *   2. APP_URL apuntando a localhost o una IP de loopback
     *   3. REMOTE_ADDR de loopback, sin encabezados de proxy y host local
     * APP_URL es la condicion principal porque no depende de la peticion.
     * Las condiciones de la peticion son defensa en profundidad.
     */
    public static function devSinLogin(): bool {
        if (!defined('DEV_ADMIN_SIN_LOGIN') || strtolower((string)DEV_ADMIN_SIN_LOGIN) !== 'true') {
            return false;
        }
        if (!defined('APP_URL')) {
            return false;
        }
        $appHost = strtolower((string)parse_url(APP_URL, PHP_URL_HOST));
        if (!in_array($appHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_VIA'] as $header) {
            if (array_key_exists($header, $_SERVER)) {
                return false;
            }
        }
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/^(\[[^\]]+\]|[^:]+):[0-9]+$/', '$1', $host);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Sesion de admin valida: existe, no vencio por inactividad y el usuario
     * sigue en la base. Antes solo se miraba admin_id, asi que borrar un
     * usuario o cambiarle el rol no afectaba sus sesiones abiertas.
     * Refresca admin_rol desde la base para que un cambio de rol aplique al
     * momento, y renueva la marca de ultimo acceso.
     */
    public static function sesionValida(): bool {
        if (empty($_SESSION['admin_id'])) {
            return false;
        }
        $ultimo = (int)($_SESSION['ultimo_acceso'] ?? 0);
        if ($ultimo > 0 && time() - $ultimo > self::INACTIVIDAD_MIN * 60) {
            self::cerrarSesion();
            return false;
        }
        try {
            $stmt = Database::getInstance()->prepare("SELECT rol FROM admin_users WHERE id = :id");
            $stmt->execute([':id' => $_SESSION['admin_id']]);
            $rol = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Auth::sesionValida: ' . $e->getMessage());
            return false;
        }
        if ($rol === false) {
            self::cerrarSesion();
            return false;
        }
        $_SESSION['admin_rol']     = $rol;
        $_SESSION['ultimo_acceso'] = time();
        return true;
    }

    /** Borra la sesion y su cookie. Lo usan logout y el vencimiento. */
    public static function cerrarSesion(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireAdmin(): void {
        if (self::devSinLogin()) {
            return;
        }
        if (!self::sesionValida()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'No autorizado. Iniciá sesión de nuevo.'
            ]);
            exit;
        }
    }

    public static function isAdmin(): bool {
        return self::devSinLogin() || self::sesionValida();
    }

    /** Rol de la sesion actual: 'super' | 'admin' | null. */
    public static function rol(): ?string {
        if (self::devSinLogin() && empty($_SESSION['admin_id'])) {
            return 'super';
        }

        if (isset($_SESSION['admin_rol'])) {
            return $_SESSION['admin_rol'];
        }

        return null;
    }

    public static function isSuper(): bool {
        return self::rol() === 'super';
    }

    /**
     * Para lo que solo maneja el dueño de la tienda: usuarios, invitaciones y
     * configuracion. Un admin operativo trabaja con el catalogo y los pedidos.
     */
    public static function requireSuper(): void {
        self::requireAdmin();

        if (!self::isSuper()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Esta sección es solo para el administrador principal.',
            ]);
            exit;
        }
    }
}
