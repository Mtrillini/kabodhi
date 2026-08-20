<?php

class PedidoService {
    private PDO $db;
    private StockService $stockService;

    public function __construct() {
        $this->db           = Database::getInstance();
        $this->stockService = new StockService();
    }

    public function crear(array $clienteData, array $items, array $envioData = []): array {
        $envioCosto       = (float)($envioData['costo']       ?? 0);
        $envioDescripcion = $envioData['descripcion'] ?? null;

        $total          = $envioCosto;
        $validatedItems = [];
        $productoService = new ProductoService();

        foreach ($items as $item) {
            $id       = (int)($item['id'] ?? 0);
            $cantidad = (int)($item['cantidad'] ?? 1);

            if ($id <= 0 || $cantidad <= 0) {
                throw new InvalidArgumentException("Item inválido en el carrito.");
            }

            $producto = $productoService->getById($id);
            if (!$producto) {
                throw new RuntimeException("Producto #{$id} no encontrado.");
            }

            $disponible = $this->stockService->getDisponible($id);
            if ($disponible < $cantidad) {
                throw new RuntimeException("Stock insuficiente para \"{$producto['nombre']}\". Disponible: {$disponible}.");
            }

            $precio = (float)$producto['precio'];
            $total += $precio * $cantidad;

            $validatedItems[] = [
                'producto_id'     => $id,
                'cantidad'        => $cantidad,
                'precio_unitario' => $precio,
                'nombre'          => $producto['nombre'],
            ];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO pedidos (cliente_nombre, cliente_email, cliente_telefono, cliente_direccion, envio_costo, envio_descripcion, total, estado)
                 VALUES (:nombre, :email, :telefono, :direccion, :envio_costo, :envio_descripcion, :total, 'pendiente')"
            );
            $stmt->execute([
                ':nombre'            => $clienteData['nombre'],
                ':email'             => $clienteData['email'],
                ':telefono'          => $clienteData['telefono']  ?? null,
                ':direccion'         => $clienteData['direccion'] ?? null,
                ':envio_costo'       => $envioCosto,
                ':envio_descripcion' => $envioDescripcion,
                ':total'             => $total,
            ]);
            $pedidoId = (int)$this->db->lastInsertId();

            $stmtItem = $this->db->prepare(
                "INSERT INTO pedido_items (pedido_id, producto_id, cantidad, precio_unitario)
                 VALUES (:pedido_id, :producto_id, :cantidad, :precio_unitario)"
            );
            foreach ($validatedItems as $item) {
                $stmtItem->execute([
                    ':pedido_id'       => $pedidoId,
                    ':producto_id'     => $item['producto_id'],
                    ':cantidad'        => $item['cantidad'],
                    ':precio_unitario' => $item['precio_unitario'],
                ]);
                $this->stockService->reservar($item['producto_id'], $item['cantidad']);
            }

            $this->db->commit();

            $pedido = $this->getById($pedidoId);
            MailService::enviarPedidoCreado($pedido);
            return $pedido;

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getAll(?string $estado = null): array {
        $sql = "SELECT p.*, COUNT(pi.id) AS total_items
                FROM pedidos p
                LEFT JOIN pedido_items pi ON pi.pedido_id = p.id";
        $params = [];

        if ($estado !== null && $estado !== '') {
            $sql .= " WHERE p.estado = :estado";
            $params[':estado'] = $estado;
        }

        $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM pedidos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch();
        if (!$pedido) return null;

        $stmtItems = $this->db->prepare(
            "SELECT pi.*, pr.nombre AS producto_nombre, pr.imagen_url AS producto_imagen
             FROM pedido_items pi
             INNER JOIN productos pr ON pr.id = pi.producto_id
             WHERE pi.pedido_id = :pedido_id"
        );
        $stmtItems->execute([':pedido_id' => $id]);
        $pedido['items'] = $stmtItems->fetchAll();

        return $pedido;
    }

    public function actualizarEstado(int $id, string $estado): ?array {
        $allowed = ['pendiente', 'aprobado', 'rechazado', 'cancelado'];
        if (!in_array($estado, $allowed, true)) {
            throw new InvalidArgumentException("Estado inválido: {$estado}");
        }

        $pedido = $this->getById($id);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$id} no encontrado.");
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE pedidos SET estado = :estado WHERE id = :id");
            $stmt->execute([':estado' => $estado, ':id' => $id]);

            if ($estado === 'aprobado') {
                foreach ($pedido['items'] as $item) {
                    $this->stockService->confirmar((int)$item['producto_id'], (int)$item['cantidad']);
                }
            } elseif (in_array($estado, ['rechazado', 'cancelado'], true) && $pedido['estado'] === 'pendiente') {
                foreach ($pedido['items'] as $item) {
                    $this->stockService->liberarReserva((int)$item['producto_id'], (int)$item['cantidad']);
                }
            }

            $this->db->commit();

            $pedidoActualizado = $this->getById($id);

            if ($estado === 'aprobado') {
                MailService::enviarPedidoAprobado($pedidoActualizado);
            } elseif ($estado === 'rechazado') {
                MailService::enviarPedidoRechazado($pedidoActualizado);
            }

            return $pedidoActualizado;

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancelar(int $id): ?array {
        $pedido = $this->getById($id);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$id} no encontrado.");
        }
        if ($pedido['estado'] === 'cancelado') {
            throw new RuntimeException("El pedido ya está cancelado.");
        }

        $this->db->beginTransaction();
        try {
            if ($pedido['estado'] === 'pendiente') {
                foreach ($pedido['items'] as $item) {
                    $this->stockService->liberarReserva((int)$item['producto_id'], (int)$item['cantidad']);
                }
            }
            $stmt = $this->db->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $this->db->commit();
            return $this->getById($id);

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateMercadoPago(int $id, string $mpPaymentId, string $mpPreferenceId): void {
        $stmt = $this->db->prepare(
            "UPDATE pedidos SET mp_payment_id = :mp_payment_id, mp_preference_id = :mp_preference_id WHERE id = :id"
        );
        $stmt->execute([
            ':mp_payment_id'    => $mpPaymentId,
            ':mp_preference_id' => $mpPreferenceId,
            ':id'               => $id,
        ]);
    }
}
