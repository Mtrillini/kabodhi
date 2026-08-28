<?php

/**
 * Invitaciones para sumar gente al panel.
 *
 * No hay registro abierto: un formulario de alta publico en un panel de
 * administracion deja que cualquiera se haga administrador. En cambio un
 * super genera un link con un token de un solo uso y vencimiento, se lo pasa
 * a quien corresponda, y esa persona elige su propia contrasena.
 */
class InvitacionController {
    /** Dias que dura el link antes de vencer. */
    private const DIAS_VALIDEZ = 7;

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ---------------------------------------------------------------
    // Administracion (solo super)
    // ---------------------------------------------------------------

    public function index(): void {
        Auth::requireSuper();

        $stmt = $this->db->query(
            "SELECT i.id, i.email, i.rol, i.expira_at, i.usado_at, i.created_at,
                    u.username AS creada_por
             FROM admin_invitaciones i
             LEFT JOIN admin_users u ON u.id = i.creado_por
             WHERE i.usado_at IS NULL AND i.expira_at > NOW()
             ORDER BY i.created_at DESC"
        );

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    public function store(): void {
        Auth::requireSuper();

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email'] ?? '');
        $rol   = $body['rol'] ?? 'admin';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ingresá un email válido.']);
            return;
        }
        if (!in_array($rol, ['super', 'admin'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Rol inválido.']);
            return;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Ya hay un usuario con ese email.']);
            return;
        }

        // Una invitacion vigente por email: generar otra invalida la anterior.
        $this->db->prepare(
            "DELETE FROM admin_invitaciones WHERE email = :email AND usado_at IS NULL"
        )->execute([':email' => $email]);

        $token = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            "INSERT INTO admin_invitaciones (token, email, rol, creado_por, expira_at)
             VALUES (:token, :email, :rol, :creado_por, DATE_ADD(NOW(), INTERVAL :dias DAY))"
        );
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':rol', $rol);
        $stmt->bindValue(':creado_por', $_SESSION['admin_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':dias', self::DIAS_VALIDEZ, PDO::PARAM_INT);
        $stmt->execute();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Invitación creada.',
            'data'    => [
                'id'    => (int)$this->db->lastInsertId(),
                'email' => $email,
                'rol'   => $rol,
                'link'  => rtrim(APP_URL, '/') . '/admin/registro.html?token=' . $token,
                'dias'  => self::DIAS_VALIDEZ,
            ],
        ]);
    }

    public function destroy(int $id): void {
        Auth::requireSuper();

        $stmt = $this->db->prepare("DELETE FROM admin_invitaciones WHERE id = :id AND usado_at IS NULL");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'La invitación no existe o ya fue usada.']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Invitación anulada.']);
    }

    // ---------------------------------------------------------------
    // Uso del link (publico, protegido por el token)
    // ---------------------------------------------------------------

    /** Datos minimos para que la pantalla de registro sepa a quien invita. */
    public function verificar(): void {
        $invitacion = $this->buscarVigente($_GET['token'] ?? '');

        if (!$invitacion) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'El link no es válido o ya venció.']);
            return;
        }

        echo json_encode([
            'success' => true,
            'data'    => ['email' => $invitacion['email'], 'rol' => $invitacion['rol']],
        ]);
    }

    /** Alta definitiva: quien recibe el link elige usuario y contraseña. */
    public function registrar(): void {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = $body['token']    ?? '';
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $invitacion = $this->buscarVigente($token);
        if (!$invitacion) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'El link no es válido o ya venció.']);
            return;
        }

        if ($username === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Elegí un nombre de usuario.']);
            return;
        }
        if ($error = UsuarioController::validarPasswordPublica($password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            return;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Ese nombre de usuario ya está tomado.']);
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO admin_users (username, email, rol, password_hash)
                 VALUES (:username, :email, :rol, :hash)"
            );
            $stmt->execute([
                ':username' => $username,
                ':email'    => $invitacion['email'],
                ':rol'      => $invitacion['rol'],
                ':hash'     => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);

            // El token se quema: el link sirve una sola vez.
            $this->db->prepare("UPDATE admin_invitaciones SET usado_at = NOW() WHERE id = :id")
                     ->execute([':id' => $invitacion['id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo completar el registro.']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Listo, ya podés ingresar al panel.']);
    }

    private function buscarVigente(string $token): ?array {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, email, rol FROM admin_invitaciones
             WHERE token = :token AND usado_at IS NULL AND expira_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
