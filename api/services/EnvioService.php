<?php

class EnvioService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        $stmt = $this->db->query(
            "SELECT * FROM tarifas_envio ORDER BY cp_desde ASC"
        );
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM tarifas_envio WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Tarifa que corresponde a un CP. Si se pasa el subtotal del carrito y
     * supera el umbral de envio gratis configurado, el precio queda en 0.
     */
    public function calcular(int $cp, ?float $subtotal = null): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM tarifas_envio
             WHERE activo = 1 AND cp_desde <= :cp1 AND cp_hasta >= :cp2
             ORDER BY cp_desde ASC
             LIMIT 1"
        );
        $stmt->execute([':cp1' => $cp, ':cp2' => $cp]);
        $row = $stmt->fetch();
        if ($row === false) return null;

        $row['precio_lista'] = (float)$row['precio'];
        $row['bonificado']   = false;

        $umbral = (new ConfigService())->getEnvioGratisDesde();
        if ($umbral > 0 && $subtotal !== null && $subtotal >= $umbral) {
            $row['precio']      = 0.0;
            $row['bonificado']  = true;
            $row['descripcion'] = $row['descripcion'] . ' (envío bonificado)';
        }

        return $row;
    }

    public function hayTarifasActivas(): bool {
        return (int)$this->db->query("SELECT COUNT(*) FROM tarifas_envio WHERE activo = 1")->fetchColumn() > 0;
    }

    public function create(array $data): array {
        $stmt = $this->db->prepare(
            "INSERT INTO tarifas_envio (descripcion, cp_desde, cp_hasta, precio, activo)
             VALUES (:descripcion, :cp_desde, :cp_hasta, :precio, :activo)"
        );
        $stmt->execute([
            ':descripcion' => $data['descripcion'],
            ':cp_desde'    => (int)$data['cp_desde'],
            ':cp_hasta'    => (int)$data['cp_hasta'],
            ':precio'      => (float)$data['precio'],
            ':activo'      => isset($data['activo']) ? (int)$data['activo'] : 1,
        ]);
        return $this->getById((int)$this->db->lastInsertId());
    }

    public function update(int $id, array $data): ?array {
        $allowed = ['descripcion', 'cp_desde', 'cp_hasta', 'precio', 'activo'];
        $fields  = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[]         = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) return $this->getById($id);

        $this->db->prepare("UPDATE tarifas_envio SET " . implode(', ', $fields) . " WHERE id = :id")
                 ->execute($params);

        return $this->getById($id);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM tarifas_envio WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
