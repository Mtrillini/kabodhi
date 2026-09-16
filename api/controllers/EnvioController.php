<?php

class EnvioController {
    private EnvioService $service;

    public function __construct() {
        $this->service = new EnvioService();
    }

    public function index(): void {
        Auth::requireAdmin();
        echo json_encode(['success' => true, 'data' => $this->service->getAll()]);
    }

    /**
     * POST /envios/cotizar — {cp, items:[{id,cantidad}]}
     * Publico: lo usa el carrito. Devuelve las opciones de envio (Correo
     * Argentino o tabla segun la configuracion).
     */
    public function cotizar(): void {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $cp    = (string)($body['cp'] ?? '');
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];

        try {
            $cotizacion = $this->service->cotizar($cp, $items);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            error_log('EnvioController::cotizar: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo calcular el envío.']);
            return;
        }

        if (empty($cotizacion['opciones'])) {
            echo json_encode([
                'success' => false,
                'message' => $cotizacion['aviso'] ?? 'No hay envíos disponibles para ese código postal.',
                'data'    => $cotizacion,
            ]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $cotizacion]);
    }

    /** GET /envios/sucursales?provincia=B — sucursales de Correo Argentino. Publico. */
    public function sucursales(): void {
        $provincia = (string)($_GET['provincia'] ?? '');
        try {
            $lista = $this->service->sucursales($provincia);
            echo json_encode(['success' => true, 'data' => $lista]);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('EnvioController::sucursales: ' . $e->getMessage());
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'No se pudo obtener la lista de sucursales de Correo Argentino.']);
        }
    }

    /** GET /envios/historial?estado=&proveedor=&desde=&hasta=&q= */
    public function historial(): void {
        Auth::requireAdmin();
        $filtros = [
            'estado'    => $_GET['estado']    ?? '',
            'proveedor' => $_GET['proveedor'] ?? '',
            'desde'     => $_GET['desde']     ?? '',
            'hasta'     => $_GET['hasta']     ?? '',
            'q'         => trim((string)($_GET['q'] ?? '')),
        ];
        echo json_encode([
            'success' => true,
            'data'    => $this->service->listar($filtros),
            'resumen' => $this->service->resumen(),
            'estados' => EnvioService::ESTADOS,
        ]);
    }

    /** GET /envios/provincias — codigos que usa Correo Argentino. Publico. */
    public function provincias(): void {
        echo json_encode(['success' => true, 'data' => MiCorreoClient::PROVINCIAS]);
    }

    /** POST /envios/correo/probar — verifica credenciales y customerId. */
    public function probarCorreo(): void {
        Auth::requireAdmin();
        try {
            $r = $this->service->probarConexion();
            echo json_encode(['success' => true, 'data' => $r, 'message' => "Conexión OK ({$r['ambiente']}), customerId {$r['customer_id']}."]);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function calcular(): void {
        $cp = isset($_GET['cp']) ? (int)$_GET['cp'] : 0;
        if ($cp <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Código postal inválido.']);
            return;
        }
        $subtotal = isset($_GET['subtotal']) ? (float)$_GET['subtotal'] : null;
        $tarifa   = $this->service->calcular($cp, $subtotal);
        if (!$tarifa) {
            echo json_encode(['success' => false, 'message' => 'No hay tarifas disponibles para ese código postal.', 'data' => null]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $tarifa]);
    }

    public function store(): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        foreach (['descripcion', 'cp_desde', 'cp_hasta', 'precio'] as $field) {
            if (!isset($body[$field]) || $body[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "El campo '{$field}' es obligatorio."]);
                return;
            }
        }
        if ((int)$body['cp_desde'] > (int)$body['cp_hasta']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El CP desde no puede ser mayor al CP hasta.']);
            return;
        }

        $tarifa = $this->service->create($body);
        http_response_code(201);
        echo json_encode(['success' => true, 'data' => $tarifa, 'message' => 'Tarifa creada correctamente.']);
    }

    public function update(int $id): void {
        Auth::requireAdmin();
        $tarifa = $this->service->getById($id);
        if (!$tarifa) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Tarifa #{$id} no encontrada."]);
            return;
        }
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $updated = $this->service->update($id, $body);
        echo json_encode(['success' => true, 'data' => $updated, 'message' => 'Tarifa actualizada.']);
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();
        if (!$this->service->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Tarifa #{$id} no encontrada."]);
            return;
        }
        $this->service->delete($id);
        echo json_encode(['success' => true, 'message' => 'Tarifa eliminada.']);
    }
}
