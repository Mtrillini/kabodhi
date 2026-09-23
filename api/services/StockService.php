<?php

/**
 * Stock de productos y de variantes.
 *
 * Todos los metodos aceptan un $varianteId opcional al final: cuando viene,
 * las unidades se mueven en `producto_variantes`; cuando no, en `productos`,
 * igual que siempre. Un producto con variantes solo se toca por variante, asi
 * el stock no se descuenta dos veces.
 */
class StockService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function reservar(int $productoId, int $cantidad, ?int $varianteId = null): void {
        $disponible = $this->getDisponible($productoId, $varianteId);
        if ($disponible === null) {
            throw new RuntimeException('No se encontró ' . self::etiqueta($productoId, $varianteId) . '.');
        }
        if ($disponible < $cantidad) {
            throw new RuntimeException(
                'Stock insuficiente para ' . self::etiqueta($productoId, $varianteId) .
                ". Disponible: {$disponible}, solicitado: {$cantidad}."
            );
        }
        $tabla = self::tabla($varianteId);
        $stmt = $this->db->prepare(
            "UPDATE {$tabla}
             SET stock_reservado = stock_reservado + :cantidad
             WHERE id = :id AND (stock - stock_reservado) >= :cantidad2"
        );
        $stmt->execute([
            ':cantidad'  => $cantidad,
            ':id'        => self::fila($productoId, $varianteId),
            ':cantidad2' => $cantidad,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No se pudo reservar stock para ' . self::etiqueta($productoId, $varianteId) . '.');
        }
    }

    public function liberarReserva(int $productoId, int $cantidad, ?int $varianteId = null): void {
        $tabla = self::tabla($varianteId);
        $stmt = $this->db->prepare(
            "UPDATE {$tabla}
             SET stock_reservado = GREATEST(0, stock_reservado - :cantidad)
             WHERE id = :id"
        );
        $stmt->execute([':cantidad' => $cantidad, ':id' => self::fila($productoId, $varianteId)]);
    }

    public function confirmar(int $productoId, int $cantidad, ?int $varianteId = null): void {
        $tabla = self::tabla($varianteId);
        $stmt = $this->db->prepare(
            "UPDATE {$tabla}
             SET stock          = stock - :cantidad,
                 stock_reservado = GREATEST(0, stock_reservado - :cantidad2)
             WHERE id = :id AND stock >= :cantidad3"
        );
        $stmt->execute([
            ':cantidad'  => $cantidad,
            ':cantidad2' => $cantidad,
            ':cantidad3' => $cantidad,
            ':id'        => self::fila($productoId, $varianteId),
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No se pudo confirmar el stock para ' . self::etiqueta($productoId, $varianteId) . '.');
        }

        $this->desactivarSiSinStock($productoId, $varianteId);
    }

    /**
     * Descuenta stock de un pedido que NO tenia reserva (por ejemplo uno
     * cancelado que se vuelve a aprobar). confirmar() no sirve aca: resta de
     * stock_reservado y le quitaria la reserva a otro pedido pendiente. Solo
     * descuenta si hay unidades disponibles mas alla de lo reservado.
     */
    public function descontar(int $productoId, int $cantidad, ?int $varianteId = null): void {
        $tabla = self::tabla($varianteId);
        $stmt = $this->db->prepare(
            "UPDATE {$tabla}
             SET stock = stock - :cantidad
             WHERE id = :id AND (stock - stock_reservado) >= :cantidad2"
        );
        $stmt->execute([
            ':cantidad'  => $cantidad,
            ':cantidad2' => $cantidad,
            ':id'        => self::fila($productoId, $varianteId),
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Stock insuficiente para volver a aprobar ' . self::etiqueta($productoId, $varianteId) . '.');
        }
        $this->desactivarSiSinStock($productoId, $varianteId);
    }

    public function getDisponible(int $productoId, ?int $varianteId = null): ?int {
        $tabla = self::tabla($varianteId);
        $stmt  = $this->db->prepare("SELECT stock, stock_reservado FROM {$tabla} WHERE id = :id");
        $stmt->execute([':id' => self::fila($productoId, $varianteId)]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return max(0, (int)$row['stock'] - (int)$row['stock_reservado']);
    }

    public function getStock(int $productoId, ?int $varianteId = null): ?int {
        $tabla = self::tabla($varianteId);
        $stmt  = $this->db->prepare("SELECT stock FROM {$tabla} WHERE id = :id");
        $stmt->execute([':id' => self::fila($productoId, $varianteId)]);
        $row = $stmt->fetch();
        return $row !== false ? (int)$row['stock'] : null;
    }

    public function incrementar(int $productoId, int $cantidad, ?int $varianteId = null): void {
        $tabla = self::tabla($varianteId);
        $stmt  = $this->db->prepare("UPDATE {$tabla} SET stock = stock + :cantidad WHERE id = :id");
        $stmt->execute([':cantidad' => $cantidad, ':id' => self::fila($productoId, $varianteId)]);
    }

    /**
     * Se agoto: sale de la tienda. En un producto con variantes se da de baja
     * la variante, y el producto solo cuando ya no le queda ninguna activa
     * (si no, la ficha quedaria publicada sin una sola opcion para elegir).
     */
    private function desactivarSiSinStock(int $productoId, ?int $varianteId): void {
        if ($this->getStock($productoId, $varianteId) !== 0) {
            return;
        }

        if ($varianteId === null) {
            $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id")
                     ->execute([':id' => $productoId]);
            return;
        }

        $this->db->prepare("UPDATE producto_variantes SET activo = 0 WHERE id = :id")
                 ->execute([':id' => $varianteId]);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM producto_variantes WHERE producto_id = :id AND activo = 1"
        );
        $stmt->execute([':id' => $productoId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id")
                     ->execute([':id' => $productoId]);
        }
    }

    /** Tabla sobre la que se mueven las unidades. */
    private static function tabla(?int $varianteId): string {
        return $varianteId !== null ? 'producto_variantes' : 'productos';
    }

    /** Fila a actualizar dentro de esa tabla. */
    private static function fila(int $productoId, ?int $varianteId): int {
        return $varianteId ?? $productoId;
    }

    /** Para los mensajes de error. */
    private static function etiqueta(int $productoId, ?int $varianteId): string {
        return $varianteId !== null
            ? "la opción #{$varianteId} del producto #{$productoId}"
            : "el producto #{$productoId}";
    }
}
