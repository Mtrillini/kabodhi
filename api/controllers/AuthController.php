<?php

class AuthController {
    /** Intentos fallidos permitidos por IP dentro de la ventana. */
    private const MAX_INTENTOS = 8;
    /**
     * Hash de relleno para usuarios inexistentes: se verifica igual para que
     * la respuesta tarde lo mismo exista o no la cuenta (evita enumerar
     * usuarios midiendo tiempos). Es password_hash('x', BCRYPT, cost 12).
     */
    private const HASH_RELLENO = '$2y$12$cFtM/oO7KohgSXf/J.H.2ex7RDbJqBMAikOVFnAWkEF7X54STx2R2';
    /** Ventana en minutos que se mira hacia atras. */
    private const VENTANA_MIN  = 15;

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * IP del visitante. Detras de un proxy o CDN (Hostinger sirve por hcdn)
     * REMOTE_ADDR es siempre la del proxy, igual para todo el mundo, asi que
     * se prefiere el primer valor de X-Forwarded-For, que es el cliente.
     * Solo se usa para el registro: el bloqueo NO se apoya en este dato,
     * porque el encabezado lo controla quien hace la peticion.
     */
    private function ip(): string {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $primera = trim(explode(',', $xff)[0]);
            if (filter_var($primera, FILTER_VALIDATE_IP)) {
                return substr($primera, 0, 45);
            }
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida'), 0, 45);
    }

    /**
     * Fallidos recientes contra ESTE usuario. Se reinicia al entrar bien.
     *
     * Antes se contaba por IP, pero detras del CDN todos comparten la misma:
     * cualquier rafaga de intentos dejaba afuera al administrador legitimo.
     * Contar por usuario es ademas la defensa que importa, porque no se puede
     * esquivar rotando IPs.
     */
    private function intentosFallidos(string $username): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_intentos
             WHERE usuario = :usuario AND exito = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL :ventana MINUTE)"
        );
        $stmt->bindValue(':usuario', mb_substr($username, 0, 200));
        $stmt->bindValue(':ventana', self::VENTANA_MIN, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function registrarIntento(?string $usuario, bool $exito): void {
        try {
            $this->db->prepare(
                "INSERT INTO login_intentos (ip, usuario, exito) VALUES (:ip, :usuario, :exito)"
            )->execute([
                ':ip'      => $this->ip(),
                ':usuario' => $usuario !== null ? mb_substr($usuario, 0, 200) : null,
                ':exito'   => $exito ? 1 : 0,
            ]);

            // Purga de registros viejos: la tabla no tiene otra limpieza y
            // crece con cada intento. Se hace 1 de cada 20 veces para no
            // pagar el DELETE en todos los logins.
            if (random_int(1, 20) === 1) {
                $this->db->exec("DELETE FROM login_intentos WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
            }

            // Un login correcto limpia los fallos acumulados de ese usuario.
            if ($exito && $usuario !== null) {
                $this->db->prepare("DELETE FROM login_intentos WHERE usuario = :usuario AND exito = 0")
                         ->execute([':usuario' => mb_substr($usuario, 0, 200)]);
            }
        } catch (Throwable $e) {
            error_log('AuthController::registrarIntento: ' . $e->getMessage());
        }
    }

    public function login(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        // Un array en lugar de string hacia explotar trim()/password_verify()
        // con un 500; se trata como campo vacio.
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (mb_strlen($username) > 200) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas.']);
            return;
        }

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son obligatorios.']);
            return;
        }

        if ($this->intentosFallidos($username) >= self::MAX_INTENTOS) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Esperá ' . self::VENTANA_MIN . ' minutos antes de volver a probar.',
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id, username, email, rol, password_hash FROM admin_users WHERE username = :username OR email = :email LIMIT 1"
        );
        $stmt->execute([':username' => $username, ':email' => $username]);
        $admin = $stmt->fetch();

        $hash = $admin ? $admin['password_hash'] : self::HASH_RELLENO;
        if (!password_verify($password, $hash) || !$admin) {
            $this->registrarIntento($username, false);
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas.']);
            return;
        }

        $this->registrarIntento($username, true);

        // Regenerate session to prevent fixation
        session_regenerate_id(true);

        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_email']    = $admin['email'];
        $_SESSION['admin_rol']      = $admin['rol'];
        $_SESSION['ultimo_acceso'] = time();

        echo json_encode([
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'admin'   => [
                'id'       => $admin['id'],
                'username' => $admin['username'],
                'email'    => $admin['email'],
                'rol'      => $admin['rol'],
            ],
        ]);
    }

    public function logout(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            return;
        }
        Auth::cerrarSesion();
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada.']);
    }

    public function check(): void {
        // En modo desarrollo el panel entra sin sesion, con un admin ficticio.
        if (Auth::devSinLogin() && empty($_SESSION['admin_id'])) {
            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'modo_dev'      => true,
                'admin'         => ['id' => 0, 'username' => 'dev', 'email' => '', 'rol' => 'super'],
            ]);
            return;
        }

        if (Auth::sesionValida()) {
            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'admin'         => [
                    'id'       => $_SESSION['admin_id'],
                    'username' => $_SESSION['admin_username'] ?? '',
                    'email'    => $_SESSION['admin_email']    ?? '',
                    'rol'      => Auth::rol() ?? 'admin',
                ],
            ]);
        } else {
            echo json_encode([
                'success'       => true,
                'authenticated' => false,
            ]);
        }
    }
}
