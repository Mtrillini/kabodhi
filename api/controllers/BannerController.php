<?php

/**
 * Banners del carrusel del home.
 *
 * Cada banner lleva dos imagenes: la de escritorio y la de celular. El recorte
 * que funciona en una no funciona en la otra, asi que se cargan por separado y
 * la pagina elige con un <picture>.
 */
class BannerController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** Publico: el home necesita leerlos. Solo los activos. */
    public function index(): void {
        $todos = Auth::isAdmin() && !empty($_GET['all']);

        $sql = "SELECT id, titulo, imagen_desktop, imagen_mobile, link, orden, activo
                FROM banners"
             . ($todos ? '' : ' WHERE activo = 1')
             . ' ORDER BY orden ASC, id ASC';

        echo json_encode(['success' => true, 'data' => $this->db->query($sql)->fetchAll()]);
    }

    public function show(int $id): void {
        Auth::requireAdmin();

        $stmt = $this->db->prepare("SELECT * FROM banners WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $banner = $stmt->fetch();

        if (!$banner) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Banner #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $banner]);
    }

    public function store(): void {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($error = $this->validar($body, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            return;
        }

        // Va al final salvo que se indique otra posicion.
        $orden = isset($body['orden'])
            ? (int)$body['orden']
            : (int)$this->db->query("SELECT COALESCE(MAX(orden), 0) + 1 FROM banners")->fetchColumn();

        $stmt = $this->db->prepare(
            "INSERT INTO banners (titulo, imagen_desktop, imagen_mobile, link, orden, activo)
             VALUES (:titulo, :desktop, :mobile, :link, :orden, :activo)"
        );
        $stmt->execute([
            ':titulo'  => trim($body['titulo']),
            ':desktop' => trim($body['imagen_desktop']),
            ':mobile'  => self::nullSiVacio($body['imagen_mobile'] ?? ''),
            ':link'    => self::nullSiVacio($body['link'] ?? ''),
            ':orden'   => $orden,
            ':activo'  => isset($body['activo']) ? (int)$body['activo'] : 1,
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data'    => ['id' => (int)$this->db->lastInsertId()],
            'message' => 'Banner creado.',
        ]);
    }

    public function update(int $id): void {
        Auth::requireAdmin();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM banners WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Banner #{$id} no encontrado."]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Un PUT parcial (por ejemplo solo activo) no tiene que traer todo.
        $completo = array_key_exists('titulo', $body) || array_key_exists('imagen_desktop', $body);
        if ($completo && ($error = $this->validar($body, false))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            return;
        }

        $campos = [];
        $params = [':id' => $id];
        $mapa = [
            'titulo'         => 'titulo',
            'imagen_desktop' => 'imagen_desktop',
            'imagen_mobile'  => 'imagen_mobile',
            'link'           => 'link',
            'orden'          => 'orden',
            'activo'         => 'activo',
        ];

        foreach ($mapa as $clave => $columna) {
            if (!array_key_exists($clave, $body)) continue;
            $campos[] = "`{$columna}` = :{$columna}";
            $params[":{$columna}"] = in_array($clave, ['orden', 'activo'], true)
                ? (int)$body[$clave]
                : (in_array($clave, ['imagen_mobile', 'link'], true)
                    ? self::nullSiVacio($body[$clave])
                    : trim((string)$body[$clave]));
        }

        if ($campos) {
            $this->db->prepare("UPDATE banners SET " . implode(', ', $campos) . " WHERE id = :id")
                     ->execute($params);
        }

        echo json_encode(['success' => true, 'message' => 'Banner actualizado.']);
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();

        $stmt = $this->db->prepare("DELETE FROM banners WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Banner #{$id} no encontrado."]);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Banner eliminado.']);
    }

    /** Reordenar por arrastre: llega el array de ids en el orden nuevo. */
    public function reordenar(): void {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids  = $body['orden'] ?? [];

        if (!is_array($ids) || !$ids) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Falta el orden.']);
            return;
        }

        $stmt = $this->db->prepare("UPDATE banners SET orden = :orden WHERE id = :id");
        foreach (array_values($ids) as $i => $id) {
            $stmt->execute([':orden' => $i + 1, ':id' => (int)$id]);
        }

        echo json_encode(['success' => true, 'message' => 'Orden actualizado.']);
    }

    private function validar(array $body, bool $exigirImagen): ?string {
        if (trim($body['titulo'] ?? '') === '') {
            return 'Poné una descripción del banner. Se usa como texto alternativo de la imagen.';
        }
        if ($exigirImagen && trim($body['imagen_desktop'] ?? '') === '') {
            return 'Falta la imagen de escritorio.';
        }
        $link = trim($body['link'] ?? '');
        if ($link !== '' && !preg_match('#^(/|https?://)#i', $link)) {
            return 'El link tiene que empezar con / o con http.';
        }
        return null;
    }

    private static function nullSiVacio($valor): ?string {
        $texto = trim((string)$valor);
        return $texto === '' ? null : $texto;
    }
}
