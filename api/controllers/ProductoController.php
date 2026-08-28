<?php

class ProductoController {
    private ProductoService $service;

    public function __construct() {
        $this->service = new ProductoService();
    }

    public function index(): void {
        $categoria = $_GET['categoria'] ?? null;
        $tipo      = $_GET['tipo']      ?? null;
        $search    = $_GET['search']    ?? null;
        $destacado = isset($_GET['destacado']) ? (bool)(int)$_GET['destacado'] : null;

        // Los inactivos solo se listan para un admin logueado (panel de gestion).
        $incluirInactivos = Auth::isAdmin() && !empty($_GET['incluir_inactivos']);

        $productos = $this->service->getAll($categoria, $tipo, $search, $destacado, $incluirInactivos);
        echo json_encode(['success' => true, 'data' => $productos]);
    }

    public function show(int $id): void {
        $producto = $this->service->getById($id);
        if (!$producto) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Producto #{$id} no encontrado."]);
            return;
        }
        echo json_encode(['success' => true, 'data' => $producto]);
    }

    public function store(): void {
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Validations
        $required = ['categoria_id', 'nombre', 'precio'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "El campo '{$field}' es obligatorio."]);
                return;
            }
        }

        $tiposValidos = ['enfoque', 'energia', 'equilibrio', 'defensas', 'bienestar'];
        if (!in_array($body['tipo'] ?? 'enfoque', $tiposValidos, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Objetivo inválido. Debe ser: " . implode(', ', $tiposValidos) . "."]);
            return;
        }

        try {
            $producto = $this->service->create($body);
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => $producto, 'message' => 'Producto creado correctamente.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update(int $id): void {
        Auth::requireAdmin();

        $producto = $this->service->getById($id);
        if (!$producto) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Producto #{$id} no encontrado."]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            $updated = $this->service->update($id, $body);
            echo json_encode(['success' => true, 'data' => $updated, 'message' => 'Producto actualizado correctamente.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function destroy(int $id): void {
        Auth::requireAdmin();

        $producto = $this->service->getById($id);
        if (!$producto) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Producto #{$id} no encontrado."]);
            return;
        }

        $pedidos   = $this->service->vecesVendido($id);
        $resultado = $this->service->delete($id);

        echo json_encode([
            'success'   => true,
            'resultado' => $resultado,
            'message'   => $resultado === 'eliminado'
                ? 'Producto eliminado.'
                : "El producto ya figura en {$pedidos} pedido(s), así que no se puede borrar sin perder ese historial. Quedó desactivado y fuera de la tienda.",
        ]);
    }
}
