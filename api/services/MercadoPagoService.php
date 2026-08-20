<?php

class MercadoPagoService {
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct() {
        $this->accessToken = MP_ACCESS_TOKEN;
    }

    private function request(string $method, string $endpoint, ?array $body = null): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-Idempotency-Key: nuve-' . uniqid('', true),
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("Error cURL al conectar con MercadoPago: {$curlError}");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new RuntimeException("Respuesta inválida de MercadoPago: {$response}");
        }

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Error desconocido de MercadoPago';
            throw new RuntimeException("MercadoPago error ({$httpCode}): {$msg}");
        }

        return $decoded;
    }

    public function crearPreferencia(array $pedido, array $items): array {
        $mpItems = [];
        foreach ($items as $item) {
            $mpItems[] = [
                'id'          => (string)($item['producto_id'] ?? $item['id'] ?? '0'),
                'title'       => $item['producto_nombre'] ?? $item['nombre'] ?? 'Producto',
                'quantity'    => (int)$item['cantidad'],
                'currency_id' => 'ARS',
                'unit_price'  => (float)$item['precio_unitario'],
            ];
        }

        $envioCosto = (float)($pedido['envio_costo'] ?? 0);
        if ($envioCosto > 0) {
            $mpItems[] = [
                'id'          => 'envio',
                'title'       => $pedido['envio_descripcion'] ?? 'Costo de envío',
                'quantity'    => 1,
                'currency_id' => 'ARS',
                'unit_price'  => $envioCosto,
            ];
        }

        $appUrl    = APP_URL;
        $frontendUrl = rtrim($appUrl, '/') . '/frontend';

        $payload = [
            'items'    => $mpItems,
            'payer'    => [
                'name'  => $pedido['cliente_nombre'] ?? '',
                'email' => $pedido['cliente_email']  ?? '',
                'phone' => [
                    'number' => $pedido['cliente_telefono'] ?? '',
                ],
            ],
            'back_urls' => [
                'success' => $frontendUrl . '/checkout-resultado.html?status=success&pedido_id=' . $pedido['id'],
                'failure' => $frontendUrl . '/checkout-resultado.html?status=failure&pedido_id=' . $pedido['id'],
                'pending' => $frontendUrl . '/checkout-resultado.html?status=pending&pedido_id=' . $pedido['id'],
            ],
            'auto_return'        => 'approved',
            'notification_url'   => rtrim($appUrl, '/') . '/api/index.php/mp/webhook',
            'external_reference' => (string)$pedido['id'],
            'statement_descriptor' => 'NUVE Perfumería',
            'payment_methods'    => [
                // Habilitar cuotas: hasta 12 cuotas, sin forzar ningún medio
                'installments'         => 12,
                'default_installments' => 1,
                'excluded_payment_types'   => [],
                'excluded_payment_methods' => [],
            ],
        ];

        $response = $this->request('POST', '/checkout/preferences', $payload);

        // En producción (token APP_USR-) no exponemos la URL de sandbox, así
        // ningún front (aunque esté cacheado) puede redirigir al entorno de prueba.
        $isProd = strpos($this->accessToken, 'APP_USR-') === 0;

        return [
            'preference_id'      => $response['id']         ?? null,
            'init_point'         => $response['init_point'] ?? null,
            'sandbox_init_point' => $isProd ? null : ($response['sandbox_init_point'] ?? null),
        ];
    }

    public function getPayment(string $paymentId): array {
        return $this->request('GET', '/v1/payments/' . $paymentId);
    }

    public function procesarWebhook(string $paymentId): array {
        $payment = $this->getPayment($paymentId);
        return [
            'payment_id'         => $payment['id']                 ?? null,
            'status'             => $payment['status']              ?? null,
            'status_detail'      => $payment['status_detail']       ?? null,
            'external_reference' => $payment['external_reference']  ?? null,
            'transaction_amount' => $payment['transaction_amount']  ?? null,
            'payer'              => $payment['payer']               ?? [],
        ];
    }
}
