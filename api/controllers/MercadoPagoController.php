<?php

class MercadoPagoController {
    private MercadoPagoService $mpService;
    private PedidoService $pedidoService;

    public function __construct() {
        $this->mpService     = new MercadoPagoService();
        $this->pedidoService = new PedidoService();
    }

    public function webhook(): void {
        // MercadoPago sends a POST with JSON body
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $topic   = $_GET['topic']   ?? ($body['type']   ?? '');
        $id      = $_GET['id']      ?? ($body['data']['id'] ?? '');

        // Only handle payment notifications
        if ($topic !== 'payment' && $topic !== 'merchant_order') {
            echo json_encode(['success' => true, 'message' => 'Notificación ignorada.']);
            return;
        }

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID de pago no recibido.']);
            return;
        }

        try {
            $paymentData    = $this->mpService->procesarWebhook((string)$id);
            $externalRef    = $paymentData['external_reference'] ?? null;
            $status         = $paymentData['status']             ?? null;

            if (!$externalRef) {
                echo json_encode(['success' => true, 'message' => 'Sin referencia externa.']);
                return;
            }

            $pedidoId = (int)$externalRef;
            $pedido   = $this->pedidoService->getById($pedidoId);

            if (!$pedido) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => "Pedido #{$pedidoId} no encontrado."]);
                return;
            }

            // Update mp_payment_id
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE pedidos SET mp_payment_id = :mp_id WHERE id = :id");
            $stmt->execute([':mp_id' => $id, ':id' => $pedidoId]);

            // Map MP status to our estado
            switch ($status) {
                case 'approved':
                    $newEstado = 'aprobado';
                    break;
                case 'rejected':
                case 'cancelled':
                    $newEstado = 'rechazado';
                    break;
                default:
                    $newEstado = 'pendiente';
                    break;
            }

            $currentEstado = $pedido['estado'];

            // If payment was rejected and order was pending, restore stock
            if ($newEstado === 'rechazado' && $currentEstado === 'pendiente') {
                $this->pedidoService->cancelar($pedidoId);
            } elseif ($newEstado !== $currentEstado) {
                $this->pedidoService->actualizarEstado($pedidoId, $newEstado);
            }

            echo json_encode(['success' => true, 'message' => 'Webhook procesado correctamente.']);

        } catch (Throwable $e) {
            http_response_code(500);
            error_log('MP Webhook error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error procesando webhook.']);
        }
    }

    public function getPayment(string $paymentId): void {
        try {
            $payment = $this->mpService->getPayment($paymentId);
            echo json_encode(['success' => true, 'data' => $payment]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
