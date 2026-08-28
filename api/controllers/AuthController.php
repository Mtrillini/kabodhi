<?php

class AuthController {
    /** Intentos fallidos permitidos por IP dentro de la ventana. */
    private const MAX_INTENTOS = 8;
    /** Ventana en minutos que se mira hacia atras. */
    private const VENTANA_MIN  = 15;

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function ip(): string {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida'), 0, 45);
    }

    /** Fallidos recientes de esta IP. Se reinicia con un login exitoso. */
    private function intentosFallidos(): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_intentos
             WHERE ip = :ip AND exito = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL :ventana MINUTE)"
        );
        $stmt->bindValue(':ip', $this->ip());
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

            // Un login correcto limpia el historial de fallos de esa IP.
            if ($exito) {
                $this->db->prepare("DELETE FROM login_intentos WHERE ip = :ip AND exito = 0")
                         ->execute([':ip' => $this->ip()]);
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
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son obligatorios.']);
            return;
        }

        if ($this->intentosFallidos() >= self::MAX_INTENTOS) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Esperá ' . self::VENTANA_MIN . ' minutos antes de volver a probar.',
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id, username, email, password_hash FROM admin_users WHERE username = :username OR email = :email LIMIT 1"
        );
        $stmt->execute([':username' => $username, ':email' => $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
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

        echo json_encode([
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'admin'   => [
                'id'       => $admin['id'],
                'username' => $admin['username'],
                'email'    => $admin['email'],
            ],
        ]);
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada.']);
    }

    public function check(): void {
        // En modo desarrollo el panel entra sin sesion, con un admin ficticio.
        if (Auth::devSinLogin() && empty($_SESSION['admin_id'])) {
            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'modo_dev'      => true,
                'admin'         => ['id' => 0, 'username' => 'dev', 'email' => ''],
            ]);
            return;
        }

        if (!empty($_SESSION['admin_id'])) {
            echo json_encode([
                'success'       => true,
                'authenticated' => true,
                'admin'         => [
                    'id'       => $_SESSION['admin_id'],
                    'username' => $_SESSION['admin_username'] ?? '',
                    'email'    => $_SESSION['admin_email']    ?? '',
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
