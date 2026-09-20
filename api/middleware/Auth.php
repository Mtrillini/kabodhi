<?php

class Auth {

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

    public static function requireAdmin(): void {
        if (self::devSinLogin()) {
            return;
        }
        if (empty($_SESSION['admin_id'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'No autorizado. Debes iniciar sesión como administrador.'
            ]);
            exit;
        }
    }

    public static function isAdmin(): bool {
        return self::devSinLogin() || !empty($_SESSION['admin_id']);
    }

    /** Rol de la sesion actual: 'super' | 'admin' | null. */
    public static function rol(): ?string {
        if (self::devSinLogin() && empty($_SESSION['admin_id'])) {
            return 'super';
        }

        if (isset($_SESSION['admin_rol'])) {
            return $_SESSION['admin_rol'];
        }

        // Sesion abierta ANTES de que existieran los roles: no tiene el dato
        // guardado. Sin esto, quien ya estaba logueado al desplegar quedaba
        // sin rol y se autoexcluia de sus propias pantallas. Se resuelve
        // leyendolo de la base una vez.
        if (empty($_SESSION['admin_id'])) {
            return null;
        }

        try {
            $stmt = Database::getInstance()->prepare("SELECT rol FROM admin_users WHERE id = :id");
            $stmt->execute([':id' => $_SESSION['admin_id']]);
            $rol = $stmt->fetchColumn();
            if ($rol !== false) {
                $_SESSION['admin_rol'] = $rol;
                return $rol;
            }
        } catch (Throwable $e) {
            error_log('Auth::rol: ' . $e->getMessage());
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
