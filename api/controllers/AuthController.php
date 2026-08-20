<?php

class AuthController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
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

        $stmt = $this->db->prepare(
            "SELECT id, username, email, password_hash FROM admin_users WHERE username = :username OR email = :email LIMIT 1"
        );
        $stmt->execute([':username' => $username, ':email' => $username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas.']);
            return;
        }

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
