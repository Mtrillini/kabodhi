<?php

class HongoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(?bool $soloActivos = null): array {
        $sql = "SELECT * FROM hongos_principales";
        if ($soloActivos) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY orden ASC, id ASC";
        return $this->db->query($sql)->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM hongos_principales WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(array $data): array {
        $stmt = $this->db->prepare(
            "INSERT INTO hongos_principales (nombre, subtitulo, descripcion, imagen_url, orden, activo)
             VALUES (:nombre, :subtitulo, :descripcion, :imagen_url, :orden, :activo)"
        );
        $stmt->execute([
            ':nombre'      => $data['nombre'],
            ':subtitulo'   => $data['subtitulo']   ?? null,
            ':descripcion' => $data['descripcion'] ?? null,
            ':imagen_url'  => $data['imagen_url']  ?? null,
            ':orden'       => isset($data['orden'])  ? (int)$data['orden']  : 0,
            ':activo'      => isset($data['activo']) ? (int)$data['activo'] : 1,
        ]);
        return $this->getById((int)$this->db->lastInsertId());
    }

    public function update(int $id, array $data): ?array {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['nombre', 'subtitulo', 'descripcion', 'imagen_url', 'orden', 'activo'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        if (!empty($fields)) {
            $sql = "UPDATE hongos_principales SET " . implode(', ', $fields) . " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }
        return $this->getById($id);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM hongos_principales WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
