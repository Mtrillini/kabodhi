<?php

/**
 * "Arma tu combo": el cliente elige N productos y paga un precio fijo.
 * Ver PromoService para la logica.
 */
class PromoController {
    private PromoService $service;

    public function __construct() {
        $this->service = new PromoService();
    }

    /** Publico: la tienda necesita los combos activos. El panel pide todos con ?all=1. */
    public function index(): void {
        $todas = Auth::isAdmin() && !empty($_GET['all']);
        echo json_encode(['success' => true, 'data' => $this->service->getAll($todas)]);
    }

    /** Publico: la pagina del combo necesita verlo aunque no sea admin. */
    public function show(int $id): void {
        $esAdmin = Auth::isAdmin();
        $promo = $this->service->getById($id, $esAdmin);
        if (!$promo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Combo #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $promo]);
    }

    public function store(): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $promo = $this->service->create($body);
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => $promo, 'message' => 'Combo creado correctamente.']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update(int $id): void {
        Auth::requireAdmin();
        if (!$this->service->getById($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Combo #{$id} no encontrado."]);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $promo = $this->service->update($id, $body);
            echo json_encode(['success' => true, 'data' => $promo, 'message' => 'Combo actualizado correctamente.']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();
        if (!$this->service->delete($id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Combo #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'message' => 'Combo eliminado.']);
    }
}
