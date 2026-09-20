<?php

class PedidoController {
    private PedidoService $pedidoService;
    private MercadoPagoService $mpService;

    public function __construct() {
        $this->pedidoService = new PedidoService();
        $this->mpService     = new MercadoPagoService();
    }

    public function store(): void {
        // Limite por IP: un script no puede crear pedidos sin parar y dejar el
        // catalogo sin stock ni usar el mail de confirmacion para spamear.
        if (!RateLimiter::permitir('pedido', 10, 60)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Hiciste demasiados pedidos en poco tiempo. Esperá unos minutos o escribinos.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Validate required fields
        $requiredCliente = ['nombre', 'email'];
        foreach ($requiredCliente as $field) {
            if (empty($body[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "El campo '{$field}' es obligatorio."]);
                return;
            }
        }

        if (!filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El email no es válido.']);
            return;
        }

        // DNI argentino: 7 u 8 digitos. Llega ya sin puntos desde el checkout,
        // pero se vuelve a limpiar por si el pedido entra por otra via.
        $dni = preg_replace('/\D+/', '', (string)($body['dni'] ?? ''));
        if ($dni === '' || strlen($dni) < 7 || strlen($dni) > 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El DNI tiene que tener 7 u 8 números.']);
            return;
        }

        if (empty($body['items']) || !is_array($body['items'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El carrito está vacío.']);
            return;
        }

        $clienteData = [
            'nombre'    => trim($body['nombre']),
            'email'     => trim($body['email']),
            'telefono'  => trim($body['telefono']  ?? ''),
            'direccion' => trim($body['direccion']  ?? ''),
            'dni'       => $dni,
        ];

        // Del envio, el cliente solo elige: CP, la opcion cotizada, la sucursal
        // (si retira) y la direccion. El costo lo vuelve a calcular EnvioService.
        $envioIn   = is_array($body['envio'] ?? null) ? $body['envio'] : [];
        $envioData = [
            'cp'              => (string)($envioIn['cp'] ?? $body['envio_cp'] ?? ''),
            'opcion_id'       => (string)($envioIn['opcion_id']       ?? ''),
            'sucursal_codigo' => (string)($envioIn['sucursal_codigo'] ?? ''),
            'sucursal_nombre' => (string)($envioIn['sucursal_nombre'] ?? ''),
            'calle'           => trim((string)($body['calle']      ?? '')),
            'numero'          => trim((string)($body['numero']     ?? '')),
            'piso_depto'      => trim((string)($body['piso_depto'] ?? '')),
            'ciudad'          => trim((string)($body['ciudad']     ?? '')),
            'provincia'       => trim((string)($body['provincia']  ?? '')),
        ];

        // Forma de pago: Mercado Pago (default) o transferencia bancaria.
        $metodoPago = (string)($body['metodo_pago'] ?? 'mercadopago');

        try {
            $pedido = $this->pedidoService->crear($clienteData, $body['items'], $envioData, $metodoPago);

            // Mercado Pago: se crea la preferencia (link de pago). Con
            // transferencia no hay nada que crear: el cliente paga y se confirma
            // desde el panel.
            $mpData  = null;
            $mpError = null;
            if ($metodoPago === 'mercadopago') {
                try {
                    $mpData = $this->mpService->crearPreferencia($pedido, $pedido['items']);
                    $db = Database::getInstance();
                    $stmt = $db->prepare("UPDATE pedidos SET mp_preference_id = :pref_id WHERE id = :id");
                    $stmt->execute([':pref_id' => $mpData['preference_id'], ':id' => $pedido['id']]);
                } catch (Throwable $mpEx) {
                    error_log('MercadoPago preference error: ' . $mpEx->getMessage());
                    // El detalle va al log; al cliente no se le muestra la
                    // respuesta cruda de la API de MP.
                    $mpError = 'No se pudo generar el link de pago. Escribinos y lo resolvemos.';
                }
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Pedido creado correctamente.',
                'data'    => [
                    'pedido'            => $pedido,
                    'metodo_pago'       => $metodoPago,
                    'mp_preference_id'  => $mpData['preference_id']  ?? null,
                    'init_point'        => $mpData['init_point']       ?? null,
                    'sandbox_init_point'=> $mpData['sandbox_init_point'] ?? null,
                    'mp_error'          => $mpError,
                ],
            ]);

        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error interno al crear el pedido.']);
            error_log('PedidoController::store error: ' . $e->getMessage());
        }
    }

    public function index(): void {
        Auth::requireAdmin();

        $estado  = $_GET['estado'] ?? null;
        $pedidos = $this->pedidoService->getAll($estado ?: null);
        echo json_encode(['success' => true, 'data' => $pedidos]);
    }

    public function show(int $id): void {
        Auth::requireAdmin();

        $pedido = $this->pedidoService->getById($id);
        if (!$pedido) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Pedido #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $pedido]);
    }

    public function mails(int $id): void {
        Auth::requireAdmin();
        echo json_encode(['success' => true, 'data' => $this->pedidoService->getMails($id)]);
    }

    // ---------------------------------------------------------------
    // Envio del pedido (registro, estado, importacion a Correo Argentino)
    // ---------------------------------------------------------------

    /** GET /pedidos/{id}/envio — registro del envio con su historial. */
    public function envio(int $id): void {
        Auth::requireAdmin();

        $envio = (new EnvioService())->getByPedido($id);
        if (!$envio) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "El pedido #{$id} no tiene envío registrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $envio, 'estados' => EnvioService::ESTADOS]);
    }

    /** PUT /pedidos/{id}/envio/estado — {estado, detalle?} */
    public function updateEnvioEstado(int $id): void {
        Auth::requireAdmin();

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $estado  = trim((string)($body['estado'] ?? ''));
        $detalle = trim((string)($body['detalle'] ?? ''));

        if ($estado === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo 'estado' es obligatorio."]);
            return;
        }

        $this->responder(function () use ($id, $estado, $detalle) {
            $envio = (new EnvioService())->cambiarEstado(
                $id, $estado, $detalle !== '' ? $detalle : null, 'panel', self::usuarioId()
            );
            return ['data' => $envio, 'message' => 'Estado del envío actualizado.'];
        });
    }

    /** PUT /pedidos/{id}/envio/destino — direccion estructurada / sucursal / bulto. */
    public function updateEnvioDestino(int $id): void {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->responder(function () use ($id, $body) {
            $envio = (new EnvioService())->actualizarDestino($id, $body);
            return ['data' => $envio, 'message' => 'Datos de destino guardados.'];
        });
    }

    /** POST /pedidos/{id}/envio/importar — crea la orden en MiCorreo. */
    public function importarEnvio(int $id): void {
        Auth::requireAdmin();

        $this->responder(function () use ($id) {
            $envio = (new EnvioService())->importarACorreo($id, self::usuarioId());
            return ['data' => $envio, 'message' => 'Orden de envío creada en Correo Argentino.'];
        });
    }

    // ---------------------------------------------------------------
    // Pagos del pedido
    // ---------------------------------------------------------------

    /** GET /pedidos/{id}/pagos */
    public function pagos(int $id): void {
        Auth::requireAdmin();
        echo json_encode([
            'success' => true,
            'data'    => (new PagoService())->getByPedido($id),
            'estados' => PagoService::ESTADOS_LABEL,
        ]);
    }

    /** POST /pedidos/{id}/pagos/confirmar-transferencia — {monto?, referencia?} */
    public function confirmarTransferencia(int $id): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->responder(function () use ($id, $body) {
            $monto = isset($body['monto']) && $body['monto'] !== '' ? (float)$body['monto'] : null;
            $r = (new PagoService())->confirmarTransferencia($id, $monto, (string)($body['referencia'] ?? ''), self::usuarioId());
            return ['data' => $r, 'message' => 'Transferencia confirmada: el pedido quedó aprobado.'];
        });
    }

    /** POST /pedidos/{id}/pagos/sincronizar — trae los pagos desde Mercado Pago. */
    public function sincronizarPagos(int $id): void {
        Auth::requireAdmin();
        $this->responder(function () use ($id) {
            $r = (new PagoService())->sincronizarConMP($id);
            $msg = $r['encontrados'] > 0
                ? "Se sincronizaron {$r['encontrados']} pago(s) desde Mercado Pago."
                : 'Mercado Pago no tiene pagos para este pedido.';
            return ['data' => $r, 'message' => $msg];
        });
    }

    /** POST /pedidos/{id}/pagos/reembolsar — {monto?} (vacio = total) */
    public function reembolsar(int $id): void {
        // Devolver plata es cosa del administrador principal.
        Auth::requireSuper();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->responder(function () use ($id, $body) {
            $monto = isset($body['monto']) && $body['monto'] !== '' ? (float)$body['monto'] : null;
            $r = (new PagoService())->reembolsar($id, $monto, self::usuarioId());
            return ['data' => $r, 'message' => $monto === null ? 'Reembolso total realizado.' : 'Reembolso parcial realizado.'];
        });
    }

    /** Ejecuta y traduce excepciones a respuestas JSON con el codigo que corresponde. */
    private function responder(callable $accion): void {
        try {
            $r = $accion();
            echo json_encode(['success' => true] + $r);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (MiCorreoException $e) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('PedidoController envio: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error interno.']);
        }
    }

    private static function usuarioId(): ?int {
        return !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    }

    public function updateTracking(int $id): void {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            $pedido = $this->pedidoService->actualizarTracking($id, $body);
            echo json_encode(['success' => true, 'data' => $pedido, 'message' => 'Seguimiento guardado.']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error interno.']);
        }
    }

    public function updateEstado(int $id): void {
        Auth::requireAdmin();

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $estado = $body['estado'] ?? '';

        if ($estado === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo 'estado' es obligatorio."]);
            return;
        }

        try {
            if ($estado === 'cancelado') {
                $pedido = $this->pedidoService->cancelar($id);
            } else {
                $pedido = $this->pedidoService->actualizarEstado($id, $estado);
            }
            echo json_encode(['success' => true, 'data' => $pedido, 'message' => 'Estado actualizado correctamente.']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error interno.']);
        }
    }
}
