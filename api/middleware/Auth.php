<?php

class Auth {

    /**
     * Bypass de login para desarrollo. Exige DOS condiciones a la vez:
     *   1. DEV_ADMIN_SIN_LOGIN=true en el .env (que esta en .gitignore)
     *   2. que la peticion venga de la misma maquina
     * La segunda es la que importa: si el flag queda prendido por descuido en
     * un hosting, el panel igual no se abre a internet.
     */
    public static function devSinLogin(): bool {
        if (!defined('DEV_ADMIN_SIN_LOGIN') || strtolower((string)DEV_ADMIN_SIN_LOGIN) !== 'true') {
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
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
}
