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

    /** Rol de la sesion actual: 'super' | 'admin' | null. */
    public static function rol(): ?string {
        if (self::devSinLogin() && empty($_SESSION['admin_id'])) {
            return 'super';
        }
        return $_SESSION['admin_rol'] ?? null;
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
