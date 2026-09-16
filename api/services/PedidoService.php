<?php

class PedidoService {
    private PDO $db;
    private StockService $stockService;

    public function __construct() {
        $this->db           = Database::getInstance();
        $this->stockService = new StockService();
    }

    public function crear(array $clienteData, array $items, array $envioData = []): array {
        $total          = 0.0;
        $validatedItems = [];
        $productoService = new ProductoService();

        foreach ($items as $item) {
            $id       = (int)($item['id'] ?? 0);
            $cantidad = (int)($item['cantidad'] ?? 1);

            if ($id <= 0 || $cantidad <= 0) {
                throw new InvalidArgumentException("Item inválido en el carrito.");
            }

            // Solo activos: un producto dado de baja no se vende, aunque el
            // cliente mande su id a mano.
            $producto = $productoService->getById($id, false);
            if (!$producto) {
                throw new RuntimeException("El producto #{$id} no está disponible.");
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

        // El costo de envio NO se toma del cliente: se vuelve a cotizar aca
        // (Correo Argentino o tabla segun la configuracion) a partir del CP,
        // la opcion elegida y los items reales, para que nadie pueda mandar
        // envio_costo: 0 ni forzar la bonificacion por monto.
        $envioService     = new EnvioService();
        $envio            = $envioService->resolverParaPedido($envioData, $items);
        $envioCosto       = (float)$envio['costo_cobrado'];
        $envioDescripcion = $envio['descripcion'];
        $total           += $envioCosto;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO pedidos (cliente_nombre, cliente_email, cliente_telefono, cliente_dni, cliente_direccion, envio_costo, envio_descripcion, transporte, total, estado)
                 VALUES (:nombre, :email, :telefono, :dni, :direccion, :envio_costo, :envio_descripcion, :transporte, :total, 'pendiente')"
            );
            $stmt->execute([
                ':nombre'            => $clienteData['nombre'],
                ':email'             => $clienteData['email'],
                ':telefono'          => $clienteData['telefono']  ?? null,
                ':dni'               => $clienteData['dni']       ?? null,
                ':direccion'         => $clienteData['direccion'] ?? null,
                ':envio_costo'       => $envioCosto,
                ':envio_descripcion' => $envioDescripcion,
                ':transporte'        => $envio['proveedor'] === 'correo' ? 'Correo Argentino' : null,
                ':total'             => $total,
            ]);
            $pedidoId = (int)$this->db->lastInsertId();

            // Registro del envio: que se cotizo, que se cobro y a donde va.
            $envioService->crearRegistro($pedidoId, $envio);

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
        $sql = "SELECT p.*, COUNT(pi.id) AS total_items,
                       e.estado AS envio_estado, e.proveedor AS envio_proveedor,
                       e.tipo_entrega AS envio_tipo_entrega, e.importado_at AS envio_importado_at
                FROM pedidos p
                LEFT JOIN pedido_items pi ON pi.pedido_id = p.id
                LEFT JOIN envios e ON e.pedido_id = p.id";
        $params = [];

        if ($estado !== null && $estado !== '') {
            $sql .= " WHERE p.estado = :estado";
            $params[':estado'] = $estado;
        }

        $sql .= " GROUP BY p.id, e.id ORDER BY p.created_at DESC";

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

        // Registro del envio (sin historial: eso lo pide el panel aparte).
        $pedido['envio'] = (new EnvioService())->getByPedido($id, false);

        return $pedido;
    }

    /**
     * @param bool $sincronizarEnvio false cuando el cambio viene de
     *                               EnvioService (evita el ida y vuelta).
     */
    public function actualizarEstado(int $id, string $estado, bool $sincronizarEnvio = true): ?array {
        $allowed = ['pendiente', 'aprobado', 'enviado', 'entregado', 'rechazado', 'cancelado'];
        if (!in_array($estado, $allowed, true)) {
            throw new InvalidArgumentException("Estado inválido: {$estado}");
        }

        $pedido = $this->getById($id);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$id} no encontrado.");
        }

        // Idempotente: repetir el estado actual no vuelve a tocar el stock.
        // (El panel permite reelegir el mismo estado, y el webhook de MP puede
        //  llegar duplicado sobre un pedido ya aprobado a mano.)
        if ($pedido['estado'] === $estado) {
            return $pedido;
        }

        $this->db->beginTransaction();
        try {
            // enviado_at se sella la primera vez que el pedido sale.
            $sql = $estado === 'enviado'
                ? "UPDATE pedidos SET estado = :estado, enviado_at = COALESCE(enviado_at, NOW()) WHERE id = :id"
                : "UPDATE pedidos SET estado = :estado WHERE id = :id";
            $this->db->prepare($sql)->execute([':estado' => $estado, ':id' => $id]);

            $this->aplicarEfectoStock($pedido['estado'], $estado, $pedido['items']);

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Ya esta confirmado: lo que sigue no debe deshacer el cambio de estado.
        if ($sincronizarEnvio) {
            $this->sincronizarEnvio($id, $estado);
        }

        $pedidoActualizado = $this->getById($id);
        self::notificar($estado, $pedidoActualizado);

        return $pedidoActualizado;
    }

    /** El envio sigue al pedido; si falla, se loguea y el pedido queda igual. */
    private function sincronizarEnvio(int $id, string $estado): void {
        try {
            (new EnvioService())->sincronizarDesdePedido($id, $estado);
        } catch (Throwable $e) {
            error_log("PedidoService: no se pudo sincronizar el envío del pedido #{$id}: " . $e->getMessage());
        }
    }

    /** Estados en los que las unidades ya salieron del stock. */
    private const ESTADOS_DESCONTADOS = ['aprobado', 'enviado', 'entregado'];

    /** Manda el mail que corresponde al nuevo estado del pedido. */
    private static function notificar(string $estado, array $pedido): void {
        switch ($estado) {
            case 'aprobado':  MailService::enviarPedidoAprobado($pedido);  break;
            case 'enviado':   MailService::enviarPedidoEnviado($pedido);   break;
            case 'entregado': MailService::enviarPedidoEntregado($pedido); break;
            case 'rechazado': MailService::enviarPedidoRechazado($pedido); break;
            case 'cancelado': MailService::enviarPedidoCancelado($pedido); break;
        }
    }

    /**
     * Ajusta el stock segun la transicion de estado del pedido.
     *
     * Que significa cada estado para el inventario:
     *   pendiente                        -> unidades reservadas, stock intacto
     *   aprobado / enviado / entregado   -> unidades descontadas, sin reserva
     *   rechazado / cancelado            -> sin reserva y sin descuento
     */
    private function aplicarEfectoStock(string $anterior, string $nuevo, array $items): void {
        if ($anterior === $nuevo) return;

        $estabaReservado  = $anterior === 'pendiente';
        $estabaDescontado = in_array($anterior, self::ESTADOS_DESCONTADOS, true);
        $quedaDescontado  = in_array($nuevo,    self::ESTADOS_DESCONTADOS, true);

        // Moverse entre aprobado / enviado / entregado no toca el inventario.
        if ($estabaDescontado && $quedaDescontado) return;

        foreach ($items as $item) {
            $productoId = (int)$item['producto_id'];
            $cantidad   = (int)$item['cantidad'];

            if ($quedaDescontado) {
                // confirmar() descuenta del stock y limpia la reserva si existia.
                $this->stockService->confirmar($productoId, $cantidad);

            } elseif ($nuevo === 'rechazado' || $nuevo === 'cancelado') {
                if ($estabaReservado) {
                    $this->stockService->liberarReserva($productoId, $cantidad);
                } elseif ($estabaDescontado) {
                    // Ya se habia descontado: devolvemos las unidades al stock.
                    $this->stockService->incrementar($productoId, $cantidad);
                }

            } elseif ($nuevo === 'pendiente') {
                // Vuelve a quedar reservado.
                if ($estabaDescontado) {
                    $this->stockService->incrementar($productoId, $cantidad);
                }
                $this->stockService->reservar($productoId, $cantidad);
            }
        }
    }

    public function cancelar(int $id): ?array {
        $pedido = $this->getById($id);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$id} no encontrado.");
        }
        // Idempotente, igual que actualizarEstado(): reelegir "cancelado" en el
        // panel no es un error, simplemente no hay nada que hacer.
        if ($pedido['estado'] === 'cancelado') {
            return $pedido;
        }

        $this->db->beginTransaction();
        try {
            $this->aplicarEfectoStock($pedido['estado'], 'cancelado', $pedido['items']);

            $stmt = $this->db->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->sincronizarEnvio($id, 'cancelado');

        $pedidoActualizado = $this->getById($id);
        self::notificar('cancelado', $pedidoActualizado);

        return $pedidoActualizado;
    }

    /** Mails que se le enviaron al cliente por este pedido. */
    public function getMails(int $id): array {
        $stmt = $this->db->prepare(
            "SELECT tipo, destino, asunto, exito, error, created_at
             FROM mail_log WHERE pedido_id = :id ORDER BY id ASC"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }

    /** Guarda los datos de seguimiento del envio. */
    public function actualizarTracking(int $id, array $data): ?array {
        $pedido = $this->getById($id);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$id} no encontrado.");
        }

        // FILTER_VALIDATE_URL acepta esquemas como javascript://x%0aalert(1),
        // y este link termina como href en el mail del cliente.
        $url = trim((string)($data['tracking_url'] ?? ''));
        if ($url !== '') {
            $esquema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($esquema, ['http', 'https'], true)) {
                throw new InvalidArgumentException('El link de seguimiento debe ser una URL http:// o https://.');
            }
        }

        $stmt = $this->db->prepare(
            "UPDATE pedidos
             SET transporte = :transporte, tracking_codigo = :codigo, tracking_url = :url
             WHERE id = :id"
        );
        $stmt->execute([
            ':transporte' => self::nullSiVacio($data['transporte']      ?? ''),
            ':codigo'     => self::nullSiVacio($data['tracking_codigo'] ?? ''),
            ':url'        => self::nullSiVacio($url),
            ':id'         => $id,
        ]);

        // El registro del envio guarda lo mismo (es lo que ve el historial).
        (new EnvioService())->actualizarTracking($id, $data['tracking_codigo'] ?? null, $url);

        return $this->getById($id);
    }

    private static function nullSiVacio($valor): ?string {
        $texto = trim((string)$valor);
        return $texto === '' ? null : $texto;
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
