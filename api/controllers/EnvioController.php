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

    public function calcular(): void {
        $cp = isset($_GET['cp']) ? (int)$_GET['cp'] : 0;
        if ($cp <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Código postal inválido.']);
            return;
        }
        $tarifa = $this->service->calcular($cp);
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
