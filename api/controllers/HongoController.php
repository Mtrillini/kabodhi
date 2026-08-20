<?php

class HongoController {
    private HongoService $service;

    public function __construct() {
        $this->service = new HongoService();
    }

    public function index(): void {
        // Público: solo activos. Admin (?all=1): todos.
        $soloActivos = !(isset($_GET['all']) && $_GET['all'] === '1' && Auth::isAdmin());
        $hongos = $this->service->getAll($soloActivos);
        echo json_encode(['success' => true, 'data' => $hongos]);
    }

    public function show(int $id): void {
        $hongo = $this->service->getById($id);
        if (!$hongo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Hongo #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $hongo]);
    }

    public function store(): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($body['nombre'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo 'nombre' es obligatorio."]);
            return;
        }

        try {
            $hongo = $this->service->create($body);
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => $hongo, 'message' => 'Hongo creado correctamente.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update(int $id): void {
        Auth::requireAdmin();
        if (!$this->service->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Hongo #{$id} no encontrado."]);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $updated = $this->service->update($id, $body);
            echo json_encode(['success' => true, 'data' => $updated, 'message' => 'Hongo actualizado correctamente.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();
        if (!$this->service->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Hongo #{$id} no encontrado."]);
            return;
        }
        $this->service->delete($id);
        echo json_encode(['success' => true, 'message' => 'Hongo eliminado correctamente.']);
    }
}
