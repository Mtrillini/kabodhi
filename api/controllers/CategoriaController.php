<?php

class CategoriaController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** Publico: la tienda usa las categorias para los filtros del catalogo. */
    public function index(): void {
        $stmt = $this->db->query(
            "SELECT c.id, c.nombre, c.slug, COUNT(p.id) AS total_productos
             FROM categorias c
             LEFT JOIN productos p ON p.categoria_id = c.id
             GROUP BY c.id
             ORDER BY c.nombre ASC"
        );
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    public function store(): void {
        Auth::requireAdmin();

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $nombre = trim($body['nombre'] ?? '');

        if ($nombre === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio.']);
            return;
        }

        $slug = $this->slugify($body['slug'] ?? $nombre);
        if ($this->slugExiste($slug)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Ya existe una categoría con el slug \"{$slug}\"."]);
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO categorias (nombre, slug) VALUES (:nombre, :slug)");
        $stmt->execute([':nombre' => $nombre, ':slug' => $slug]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data'    => ['id' => (int)$this->db->lastInsertId(), 'nombre' => $nombre, 'slug' => $slug],
            'message' => 'Categoría creada.',
        ]);
    }

    public function update(int $id): void {
        Auth::requireAdmin();

        if (!$this->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Categoría #{$id} no encontrada."]);
            return;
        }

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $nombre = trim($body['nombre'] ?? '');

        if ($nombre === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio.']);
            return;
        }

        $slug = $this->slugify($body['slug'] ?? $nombre);
        if ($this->slugExiste($slug, $id)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Ya existe otra categoría con el slug \"{$slug}\"."]);
            return;
        }

        $stmt = $this->db->prepare("UPDATE categorias SET nombre = :nombre, slug = :slug WHERE id = :id");
        $stmt->execute([':nombre' => $nombre, ':slug' => $slug, ':id' => $id]);

        echo json_encode([
            'success' => true,
            'data'    => ['id' => $id, 'nombre' => $nombre, 'slug' => $slug],
            'message' => 'Categoría actualizada.',
        ]);
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();

        if (!$this->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Categoría #{$id} no encontrada."]);
            return;
        }

        // productos.categoria_id es NOT NULL: si hay productos colgando, borrar la
        // categoria los dejaria huerfanos (o romperia la FK). Mejor avisar.
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = :id");
        $stmt->execute([':id' => $id]);
        $enUso = (int)$stmt->fetchColumn();

        if ($enUso > 0) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "No se puede eliminar: hay {$enUso} producto(s) en esta categoría. Movelos a otra categoría primero.",
            ]);
            return;
        }

        $this->db->prepare("DELETE FROM categorias WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Categoría eliminada.']);
    }

    // ---- Helpers ----

    private function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT id, nombre, slug FROM categorias WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function slugExiste(string $slug, ?int $exceptoId = null): bool {
        $sql    = "SELECT COUNT(*) FROM categorias WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($exceptoId !== null) {
            $sql .= " AND id <> :id";
            $params[':id'] = $exceptoId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function slugify(string $texto): string {
        // iconv //TRANSLIT convierte "e" en "'e" segun el locale, y deja slugs
        // como "t-es" en vez de "tes". Mapeamos los acentos a mano.
        $acentos = [
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n','ç'=>'c',
        ];
        $slug = mb_strtolower($texto, 'UTF-8');
        $slug = strtr($slug, $acentos);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
