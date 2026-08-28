<?php

/**
 * Gestion de usuarios del panel. Todo requiere sesion admin: cualquiera que
 * entre aca ya puede administrar la tienda entera, asi que no hay roles.
 */
class UsuarioController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function index(): void {
        Auth::requireSuper();
        $stmt = $this->db->query(
            "SELECT id, username, email, rol, created_at FROM admin_users
             ORDER BY (rol = 'super') DESC, username ASC"
        );
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    public function store(): void {
        Auth::requireSuper();

        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($body['username'] ?? '');
        $email    = trim($body['email']    ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $email === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuario, email y contraseña son obligatorios.']);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El email no es válido.']);
            return;
        }
        if ($error = self::validarPassword($password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM admin_users WHERE username = :username OR email = :email"
        );
        $stmt->execute([':username' => $username, ':email' => $email]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Ya existe un usuario con ese nombre o email.']);
            return;
        }

        // Se resuelve el valor UNA vez: el ternario anterior evaluaba
        // $body['rol'] en la rama verdadera, asi que sin esa clave devolvia
        // null y el INSERT reventaba contra una columna NOT NULL.
        $rol = $body['rol'] ?? 'admin';
        if (!in_array($rol, ['super', 'admin'], true)) $rol = 'admin';

        $stmt = $this->db->prepare(
            "INSERT INTO admin_users (username, email, rol, password_hash) VALUES (:username, :email, :rol, :hash)"
        );
        $stmt->execute([
            ':username' => $username,
            ':email'    => $email,
            ':rol'      => $rol,
            ':hash'     => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data'    => ['id' => (int)$this->db->lastInsertId(), 'username' => $username, 'email' => $email, 'rol' => $rol],
            'message' => 'Usuario creado.',
        ]);
    }

    /** Cambio de contraseña. Requiere la actual si es la del propio usuario. */
    public function updatePassword(int $id): void {
        Auth::requireAdmin();

        // Cambiar la contrasena de OTRO usuario es cosa del super; la propia
        // la cambia cualquiera.
        if ((int)($_SESSION['admin_id'] ?? 0) !== $id && !Auth::isSuper()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo podés cambiar tu propia contraseña.']);
            return;
        }

        $usuario = $this->getById($id);
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Usuario #{$id} no encontrado."]);
            return;
        }

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $nueva   = $body['password']        ?? '';
        $actual  = $body['password_actual'] ?? '';
        $esPropio = (int)$_SESSION['admin_id'] === $id;

        if ($error = self::validarPassword($nueva)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            return;
        }

        if ($esPropio) {
            $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (!password_verify($actual, (string)$stmt->fetchColumn())) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'La contraseña actual es incorrecta.']);
                return;
            }
        }

        $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id");
        $stmt->execute([
            ':hash' => password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),
            ':id'   => $id,
        ]);

        echo json_encode(['success' => true, 'message' => 'Contraseña actualizada.']);
    }

    public function destroy(int $id): void {
        Auth::requireSuper();

        if (!$this->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Usuario #{$id} no encontrado."]);
            return;
        }

        if ((int)$_SESSION['admin_id'] === $id) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'No podés eliminar tu propio usuario.']);
            return;
        }

        // Nunca dejar el panel sin ningun usuario capaz de entrar.
        if ((int)$this->db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() <= 1) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'No se puede eliminar el único usuario del panel.']);
            return;
        }

        // Ni sin ningun super: sin el nadie podria volver a gestionar usuarios.
        $stmt = $this->db->prepare("SELECT rol FROM admin_users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() === 'super'
            && (int)$this->db->query("SELECT COUNT(*) FROM admin_users WHERE rol = 'super'")->fetchColumn() <= 1) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Es el único administrador principal. Nombrá otro antes de eliminarlo.',
            ]);
            return;
        }

        $this->db->prepare("DELETE FROM admin_users WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Usuario eliminado.']);
    }

    // ---- Helpers ----

    private function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT id, username, email, rol FROM admin_users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Igual que validarPassword, accesible desde InvitacionController. */
    public static function validarPasswordPublica(string $password): ?string {
        return self::validarPassword($password);
    }

    /** Devuelve el mensaje de error, o null si la contraseña sirve. */
    private static function validarPassword(string $password): ?string {
        if (strlen($password) < 8) {
            return 'La contraseña debe tener al menos 8 caracteres.';
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
            return 'La contraseña debe combinar letras y números.';
        }
        return null;
    }
}
