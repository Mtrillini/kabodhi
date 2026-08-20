<?php

class StockService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function reservar(int $productoId, int $cantidad): void {
        $disponible = $this->getDisponible($productoId);
        if ($disponible === null) {
            throw new RuntimeException("Producto #{$productoId} no encontrado.");
        }
        if ($disponible < $cantidad) {
            throw new RuntimeException("Stock insuficiente para el producto #{$productoId}. Disponible: {$disponible}, solicitado: {$cantidad}.");
        }
        $stmt = $this->db->prepare(
            "UPDATE productos
             SET stock_reservado = stock_reservado + :cantidad
             WHERE id = :id AND (stock - stock_reservado) >= :cantidad2"
        );
        $stmt->execute([
            ':cantidad'  => $cantidad,
            ':id'        => $productoId,
            ':cantidad2' => $cantidad,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("No se pudo reservar stock del producto #{$productoId}.");
        }
    }

    public function liberarReserva(int $productoId, int $cantidad): void {
        $stmt = $this->db->prepare(
            "UPDATE productos
             SET stock_reservado = GREATEST(0, stock_reservado - :cantidad)
             WHERE id = :id"
        );
        $stmt->execute([':cantidad' => $cantidad, ':id' => $productoId]);
    }

    public function confirmar(int $productoId, int $cantidad): void {
        $stmt = $this->db->prepare(
            "UPDATE productos
             SET stock          = stock - :cantidad,
                 stock_reservado = GREATEST(0, stock_reservado - :cantidad2)
             WHERE id = :id AND stock >= :cantidad3"
        );
        $stmt->execute([
            ':cantidad'  => $cantidad,
            ':cantidad2' => $cantidad,
            ':cantidad3' => $cantidad,
            ':id'        => $productoId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("No se pudo confirmar el stock del producto #{$productoId}.");
        }

        // Auto-desactivar cuando el stock llega a 0
        if ($this->getStock($productoId) === 0) {
            $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id")
                     ->execute([':id' => $productoId]);
        }
    }

    public function getDisponible(int $productoId): ?int {
        $stmt = $this->db->prepare("SELECT stock, stock_reservado FROM productos WHERE id = :id");
        $stmt->execute([':id' => $productoId]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return max(0, (int)$row['stock'] - (int)$row['stock_reservado']);
    }

    public function getStock(int $productoId): ?int {
        $stmt = $this->db->prepare("SELECT stock FROM productos WHERE id = :id");
        $stmt->execute([':id' => $productoId]);
        $row = $stmt->fetch();
        return $row !== false ? (int)$row['stock'] : null;
    }

    public function incrementar(int $productoId, int $cantidad): void {
        $stmt = $this->db->prepare(
            "UPDATE productos SET stock = stock + :cantidad WHERE id = :id"
        );
        $stmt->execute([':cantidad' => $cantidad, ':id' => $productoId]);
    }
}
