<?php

class Auth {
    public static function requireAdmin(): void {
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
        return !empty($_SESSION['admin_id']);
    }
}
