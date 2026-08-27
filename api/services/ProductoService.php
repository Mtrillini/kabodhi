<?php

class ProductoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // $incluirInactivos: solo lo usa el panel admin, para poder ver y reactivar
    // productos dados de baja. La tienda publica siempre recibe activo = 1.
    public function getAll(?string $categoria = null, ?string $tipo = null, ?string $search = null, ?bool $destacado = null, bool $incluirInactivos = false): array {
        $sql = "SELECT p.*, c.nombre AS categoria_nombre, c.slug AS categoria_slug,
                       GREATEST(0, p.stock - p.stock_reservado) AS stock_disponible
                FROM productos p
                INNER JOIN categorias c ON p.categoria_id = c.id
                WHERE 1 = 1";
        $params = [];

        if (!$incluirInactivos) {
            $sql .= " AND p.activo = 1";
        }

        if ($categoria !== null && $categoria !== '') {
            $sql .= " AND c.slug = :categoria";
            $params[':categoria'] = $categoria;
        }
        if ($tipo !== null && $tipo !== '') {
            $sql .= " AND p.tipo = :tipo";
            $params[':tipo'] = $tipo;
        }
        if ($search !== null && $search !== '') {
            $sql .= " AND (p.nombre LIKE :search OR p.descripcion LIKE :search OR p.nota_olfativa LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if ($destacado !== null) {
            $sql .= " AND p.destacado = :destacado";
            $params[':destacado'] = $destacado ? 1 : 0;
        }

        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll();

        // Attach imagenes to each product
        if (!empty($productos)) {
            $ids = implode(',', array_column($productos, 'id'));
            $imgStmt = $this->db->query(
                "SELECT producto_id, id, url, orden FROM producto_imagenes
                 WHERE producto_id IN ($ids) ORDER BY orden ASC, id ASC"
            );
            $allImgs = $imgStmt->fetchAll();

            $imgMap = [];
            foreach ($allImgs as $img) {
                $imgMap[$img['producto_id']][] = ['id' => $img['id'], 'url' => $img['url']];
            }
            foreach ($productos as &$p) {
                $p['imagenes'] = $imgMap[$p['id']] ?? [];
            }
        }

        return $productos;
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria_nombre, c.slug AS categoria_slug,
                    GREATEST(0, p.stock - p.stock_reservado) AS stock_disponible
             FROM productos p
             INNER JOIN categorias c ON p.categoria_id = c.id
             WHERE p.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result === false) return null;

        $imgStmt = $this->db->prepare(
            "SELECT id, url, orden FROM producto_imagenes WHERE producto_id = :id ORDER BY orden ASC, id ASC"
        );
        $imgStmt->execute([':id' => $id]);
        $result['imagenes'] = $imgStmt->fetchAll();

        return $result;
    }

    public function create(array $data): array {
        $imagenes  = $data['imagenes'] ?? [];
        $imagenUrl = !empty($imagenes) ? $imagenes[0] : ($data['imagen_url'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO productos (categoria_id, marca, nombre, descripcion, nota_olfativa, precio, stock, imagen_url, tipo, activo, destacado)
             VALUES (:categoria_id, :marca, :nombre, :descripcion, :nota_olfativa, :precio, :stock, :imagen_url, :tipo, :activo, :destacado)"
        );
        $stmt->execute([
            ':categoria_id'  => $data['categoria_id'],
            ':marca'         => $data['marca']         ?? null,
            ':nombre'        => $data['nombre'],
            ':descripcion'   => $data['descripcion']   ?? null,
            ':nota_olfativa' => $data['nota_olfativa'] ?? null,
            ':precio'        => $data['precio'],
            ':stock'         => $data['stock']         ?? 0,
            ':imagen_url'    => $imagenUrl,
            ':tipo'          => $data['tipo']          ?? 'enfoque',
            ':activo'        => isset($data['activo'])    ? (int)$data['activo']    : 1,
            ':destacado'     => isset($data['destacado']) ? (int)$data['destacado'] : 0,
        ]);
        $id = (int)$this->db->lastInsertId();

        if (!empty($imagenes)) {
            $this->syncImagenes($id, $imagenes);
        }

        return $this->getById($id);
    }

    public function update(int $id, array $data): ?array {
        $fields = [];
        $params = [':id' => $id];

        $allowed = ['categoria_id','marca','nombre','descripcion','nota_olfativa','precio','stock','imagen_url','tipo','activo','destacado'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (array_key_exists('imagenes', $data) && !empty($data['imagenes'])) {
            $fields[] = '`imagen_url` = :imagen_url_primary';
            $params[':imagen_url_primary'] = $data['imagenes'][0];
        }

        if (!empty($fields)) {
            $sql = "UPDATE productos SET " . implode(', ', $fields) . " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }

        if (array_key_exists('imagenes', $data)) {
            $this->syncImagenes($id, $data['imagenes']);
        }

        return $this->getById($id);
    }

    private function syncImagenes(int $productoId, array $urls): void {
        $this->db->prepare("DELETE FROM producto_imagenes WHERE producto_id = :id")
                 ->execute([':id' => $productoId]);

        if (empty($urls)) return;

        $stmt = $this->db->prepare(
            "INSERT INTO producto_imagenes (producto_id, url, orden) VALUES (:pid, :url, :orden)"
        );
        foreach ($urls as $i => $url) {
            if ($url) $stmt->execute([':pid' => $productoId, ':url' => $url, ':orden' => $i]);
        }

        // Keep imagen_url in sync with first image
        $this->db->prepare("UPDATE productos SET imagen_url = :url WHERE id = :id")
                 ->execute([':url' => $urls[0], ':id' => $productoId]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function checkStock(int $id, int $cantidad): bool {
        $stmt = $this->db->prepare("SELECT stock FROM productos WHERE id = :id AND activo = 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return false;
        return (int)$row['stock'] >= $cantidad;
    }
}
