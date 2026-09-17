<?php

class MercadoPagoController {
    private MercadoPagoService $mpService;

    public function __construct() {
        $this->mpService = new MercadoPagoService();
    }

    /**
     * POST /mp/webhook — notificaciones de Mercado Pago.
     *
     * Llegan como {type:"payment", action:"payment.updated", data:{id}} con
     * ?data.id=...&type=payment en la query, o en el formato viejo
     * ?topic=payment&id=... Solo se atienden pagos: el resto se responde 200
     * para que MP no reintente. Se responde rapido y siempre 2xx salvo error
     * real, porque MP reintenta las que fallan.
     */
    public function webhook(): void {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        // PHP convierte "data.id" en "data_id" dentro de $_GET: se lee la query
        // cruda, que ademas es lo que firma MP.
        $query = self::queryRaw();
        $topic = (string)($query['topic'] ?? $query['type'] ?? ($body['type'] ?? ''));
        $id    = (string)($query['data.id'] ?? $query['id'] ?? ($body['data']['id'] ?? ''));

        if ($topic !== 'payment') {
            echo json_encode(['success' => true, 'message' => 'Notificación ignorada.']);
            return;
        }
        if ($id === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID de pago no recibido.']);
            return;
        }

        // Solo se aceptan avisos firmados por MP (con MP_WEBHOOK_SECRET
        // configurado). Sin eso cualquiera podria "aprobar" un pedido.
        if (!MercadoPagoService::validarFirma(self::headers(), $query)) {
            error_log("MP webhook: firma inválida para el pago {$id}.");
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Firma inválida.']);
            return;
        }

        try {
            $payment = $this->mpService->getPayment($id);
            if (empty($payment['external_reference'])) {
                echo json_encode(['success' => true, 'message' => 'Pago sin referencia a un pedido.']);
                return;
            }

            $r = (new PagoService())->registrarPagoMP($payment);
            echo json_encode([
                'success' => true,
                'message' => 'Webhook procesado.',
                'pedido'  => (int)$payment['external_reference'],
                'estado'  => $r['pago']['estado'] ?? null,
                'cambio'  => $r['cambio'],
            ]);

        } catch (Throwable $e) {
            http_response_code(500);
            error_log('MP Webhook error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error procesando webhook.']);
        }
    }

    /** GET /mp/payment/{id} — detalle crudo del pago, para el panel. */
    public function getPayment(string $paymentId): void {
        Auth::requireAdmin();
        try {
            $payment = $this->mpService->getPayment($paymentId);
            echo json_encode(['success' => true, 'data' => $payment]);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /mp/cuotas?monto=12345 — publico: cuotas (y cuales sin interes) que
     * ofrece la cuenta para ese monto. Vacio si la tienda lo desactivo o MP no
     * esta configurado.
     */
    public function cuotas(): void {
        $monto = (float)($_GET['monto'] ?? 0);
        if ($monto <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Monto inválido.']);
            return;
        }

        $config = (new ConfigService())->getPagoConfig();
        if (!$config['mp_mostrar_cuotas']) {
            echo json_encode(['success' => true, 'data' => ['max_sin_interes' => 0, 'opciones' => []]]);
            return;
        }

        try {
            $data = $this->mpService->cuotas($monto);
            // Nunca se ofrece mas de lo que la preferencia va a permitir.
            $data['opciones'] = array_values(array_filter($data['opciones'], fn($o) => $o['cuotas'] <= $config['mp_cuotas_max']));
            $data['max_sin_interes'] = min($data['max_sin_interes'], $config['mp_cuotas_max']);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            error_log('MP cuotas: ' . $e->getMessage());
            echo json_encode(['success' => true, 'data' => ['max_sin_interes' => 0, 'opciones' => []]]);
        }
    }

    /** POST /mp/probar — admin: verifica el access token contra la API. */
    public function probar(): void {
        Auth::requireAdmin();
        if (!$this->mpService->configurado()) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Falta MP_ACCESS_TOKEN en el .env.']);
            return;
        }
        try {
            // Buscar pagos con un filtro imposible valida el token sin tocar nada.
            $this->mpService->buscarPagosPorPedido(0);
            $ambiente = $this->mpService->esProduccion() ? 'producción' : 'pruebas (sandbox)';
            $firma    = trim((string)MP_WEBHOOK_SECRET) !== '' ? 'con firma de webhooks' : 'SIN clave de webhooks (MP_WEBHOOK_SECRET vacío)';
            echo json_encode(['success' => true, 'message' => "Mercado Pago OK — {$ambiente}, {$firma}."]);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Query string sin el mangling de PHP (data.id se conserva tal cual). */
    private static function queryRaw(): array {
        $out = [];
        foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $par) {
            if ($par === '') continue;
            [$k, $v] = array_pad(explode('=', $par, 2), 2, '');
            $out[urldecode($k)] = urldecode($v);
        }
        return $out;
    }

    private static function headers(): array {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $h[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
        return $h;
    }
}
