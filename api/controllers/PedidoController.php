<?php

class PedidoController {
    private PedidoService $pedidoService;
    private MercadoPagoService $mpService;

    public function __construct() {
        $this->pedidoService = new PedidoService();
        $this->mpService     = new MercadoPagoService();
    }

    public function store(): void {
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
        ];

        // Solo el CP es dato del cliente; el costo lo calcula PedidoService.
        $envioData = ['cp' => (int)($body['envio_cp'] ?? 0)];

        try {
            $pedido = $this->pedidoService->crear($clienteData, $body['items'], $envioData);

            // Create MercadoPago preference
            $mpData  = null;
            $mpError = null;
            try {
                $mpData = $this->mpService->crearPreferencia($pedido, $pedido['items']);
                // Save preference id in DB
                $db = Database::getInstance();
                $stmt = $db->prepare("UPDATE pedidos SET mp_preference_id = :pref_id WHERE id = :id");
                $stmt->execute([':pref_id' => $mpData['preference_id'], ':id' => $pedido['id']]);
            } catch (Throwable $mpEx) {
                error_log('MercadoPago preference error: ' . $mpEx->getMessage());
                $mpError = $mpEx->getMessage();
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Pedido creado correctamente.',
                'data'    => [
                    'pedido'            => $pedido,
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
