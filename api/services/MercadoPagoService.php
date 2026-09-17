<?php

/**
 * Cliente de la API de Mercado Pago (Checkout Pro + pagos).
 *
 *  - crearPreferencia(): arma el link de pago del pedido.
 *  - getPayment() / buscarPagosPorPedido(): leen pagos.
 *  - reembolsar(): devolucion total o parcial.
 *  - cuotas(): que cuotas (y cuales sin interes) ofrece la cuenta para un monto,
 *    para mostrarlas en la tienda antes de ir a pagar.
 *  - validarFirma(): comprueba que un webhook venga de Mercado Pago.
 *
 * Las cuotas sin interes NO se configuran por API: la tienda las activa en su
 * cuenta (Tu negocio > Costos > Cuotas sin interes) y Checkout Pro las ofrece
 * solo. Desde aca solo se limita el maximo de cuotas y los medios excluidos.
 */
class MercadoPagoService {
    private string $accessToken;
    private string $publicKey;
    private string $baseUrl = 'https://api.mercadopago.com';

    /** BIN generico (Visa credito Argentina) para consultar cuotas sin tarjeta del cliente. */
    private const BIN_CONSULTA = '450995';

    public function __construct() {
        $this->accessToken = trim((string)MP_ACCESS_TOKEN);
        $this->publicKey   = trim((string)MP_PUBLIC_KEY);
        if (defined('MP_BASE_URL') && trim((string)MP_BASE_URL) !== '') {
            $this->baseUrl = rtrim(trim((string)MP_BASE_URL), '/');
        }
    }

    public function configurado(): bool {
        return $this->accessToken !== '' && strpos($this->accessToken, 'your-access-token') === false;
    }

    public function esProduccion(): bool {
        return strpos($this->accessToken, 'APP_USR-') === 0;
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    private function request(string $method, string $endpoint, ?array $body = null, bool $conToken = true): array {
        $url = $this->baseUrl . $endpoint;

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($conToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        if ($method === 'POST') {
            // Requerido por MP para POST: evita duplicar preferencias/reembolsos
            // si el request se reintenta.
            $headers[] = 'X-Idempotency-Key: kabodhi-' . bin2hex(random_bytes(8));
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException("Error cURL al conectar con MercadoPago: {$curlError}");
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Respuesta inválida de MercadoPago ({$httpCode}).");
        }

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Error desconocido de MercadoPago';
            if (!empty($decoded['cause'][0]['description'])) {
                $msg .= ' — ' . $decoded['cause'][0]['description'];
            }
            error_log("MercadoPago {$method} {$endpoint} -> {$httpCode}: {$msg}");
            throw new RuntimeException("MercadoPago error ({$httpCode}): {$msg}");
        }

        return $decoded;
    }

    // -----------------------------------------------------------------
    // Checkout Pro
    // -----------------------------------------------------------------

    public function crearPreferencia(array $pedido, array $items): array {
        $config = (new ConfigService())->getPagoConfig();

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

        // El sitio vive en la raiz de APP_URL y usa URLs limpias.
        $appUrl    = rtrim(APP_URL, '/');
        $resultado = $appUrl . '/checkout-resultado?pedido_id=' . (int)$pedido['id'] . '&status=';

        $excluidos = [];
        if ($config['mp_excluir_efectivo']) {
            $excluidos[] = ['id' => 'ticket'];
        }

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
                'success' => $resultado . 'success',
                'failure' => $resultado . 'failure',
                'pending' => $resultado . 'pending',
            ],
            'auto_return'          => 'approved',
            'notification_url'     => $appUrl . '/api/index.php/mp/webhook',
            'external_reference'   => (string)$pedido['id'],
            'statement_descriptor' => mb_substr($config['mp_descriptor'], 0, 22),
            'metadata'             => ['pedido_id' => (int)$pedido['id']],
            'payment_methods'      => [
                'installments'             => $config['mp_cuotas_max'],
                'default_installments'     => 1,
                'excluded_payment_types'   => $excluidos,
                'excluded_payment_methods' => [],
            ],
        ];

        $response = $this->request('POST', '/checkout/preferences', $payload);

        // En producción (token APP_USR-) no exponemos la URL de sandbox, así
        // ningún front (aunque esté cacheado) puede redirigir al entorno de prueba.
        $isProd = $this->esProduccion();

        return [
            'preference_id'      => $response['id']         ?? null,
            'init_point'         => $response['init_point'] ?? null,
            'sandbox_init_point' => $isProd ? null : ($response['sandbox_init_point'] ?? null),
        ];
    }

    // -----------------------------------------------------------------
    // Pagos
    // -----------------------------------------------------------------

    public function getPayment(string $paymentId): array {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    /** Todos los pagos que MP tiene para un pedido (external_reference), el mas nuevo primero. */
    public function buscarPagosPorPedido(int $pedidoId): array {
        $res = $this->request('GET', '/v1/payments/search?sort=date_created&criteria=desc&external_reference=' . $pedidoId);
        return is_array($res['results'] ?? null) ? $res['results'] : [];
    }

    /**
     * Reembolso total (monto null) o parcial. Devuelve la respuesta de MP.
     */
    public function reembolsar(string $paymentId, ?float $monto = null): array {
        $body = $monto !== null ? ['amount' => round($monto, 2)] : [];
        return $this->request('POST', '/v1/payments/' . rawurlencode($paymentId) . '/refunds', $body ?: null);
    }

    /** Mantiene la firma vieja (webhook). */
    public function procesarWebhook(string $paymentId): array {
        return $this->getPayment($paymentId);
    }

    /**
     * Resume un pago de MP en la forma que guarda la tabla `pagos`.
     */
    public static function resumirPago(array $p): array {
        $comision = 0.0;
        foreach ((array)($p['fee_details'] ?? []) as $fee) {
            $comision += (float)($fee['amount'] ?? 0);
        }
        $detalles = $p['transaction_details'] ?? [];

        return [
            'referencia'     => (string)($p['id'] ?? ''),
            'estado'         => (string)($p['status'] ?? 'pending'),
            'estado_detalle' => isset($p['status_detail']) ? mb_substr((string)$p['status_detail'], 0, 100) : null,
            'medio'          => isset($p['payment_method_id']) ? mb_substr((string)$p['payment_method_id'], 0, 50) : null,
            'tipo'           => isset($p['payment_type_id'])   ? mb_substr((string)$p['payment_type_id'], 0, 50)   : null,
            'cuotas'         => max(1, (int)($p['installments'] ?? 1)),
            'monto_cuota'    => isset($detalles['installment_amount']) ? (float)$detalles['installment_amount'] : null,
            'monto'          => (float)($p['transaction_amount'] ?? 0),
            'comision'       => round($comision, 2),
            'neto'           => isset($detalles['net_received_amount']) ? (float)$detalles['net_received_amount'] : null,
            'reembolsado'    => (float)($p['transaction_amount_refunded'] ?? 0),
            'moneda'         => (string)($p['currency_id'] ?? 'ARS'),
            'aprobado_at'    => !empty($p['date_approved']) ? date('Y-m-d H:i:s', strtotime($p['date_approved'])) : null,
            'pedido_id'      => (int)($p['external_reference'] ?? 0),
            'payload'        => json_encode($p, JSON_UNESCAPED_UNICODE),
        ];
    }

    // -----------------------------------------------------------------
    // Cuotas
    // -----------------------------------------------------------------

    /**
     * Cuotas disponibles para un monto con la cuenta de la tienda: cantidad,
     * valor de cada cuota, total y si son sin interes. Usa un BIN generico de
     * Visa; con otra tarjeta los valores pueden variar un poco, pero las
     * promociones de cuotas sin interes de la cuenta salen iguales.
     *
     * @return array{max_sin_interes:int, opciones: array<int, array>}
     */
    public function cuotas(float $monto): array {
        if ($this->publicKey === '' || strpos($this->publicKey, 'your-public-key') !== false) {
            return ['max_sin_interes' => 0, 'opciones' => []];
        }
        $monto = round($monto, 2);
        if ($monto <= 0) return ['max_sin_interes' => 0, 'opciones' => []];

        // Cache de 30 minutos por monto: la tienda muestra esto en cada producto.
        $dir   = dirname(__DIR__, 2) . '/storage/mp';
        $cache = $dir . '/cuotas-' . md5($this->publicKey . '|' . $monto) . '.json';
        if (is_file($cache) && filemtime($cache) > time() - 30 * 60) {
            $data = json_decode((string)file_get_contents($cache), true);
            if (is_array($data)) return $data;
        }

        $res = $this->request(
            'GET',
            '/v1/payment_methods/installments?public_key=' . rawurlencode($this->publicKey)
                . '&bin=' . self::BIN_CONSULTA . '&amount=' . $monto . '&locale=es-AR',
            null,
            false
        );

        $opciones = [];
        $maxSin   = 0;
        foreach ((array)($res[0]['payer_costs'] ?? []) as $pc) {
            $n = (int)($pc['installments'] ?? 0);
            if ($n <= 0) continue;
            $sinInteres = (float)($pc['installment_rate'] ?? 0) == 0.0;
            if ($sinInteres) $maxSin = max($maxSin, $n);
            $opciones[] = [
                'cuotas'      => $n,
                'monto_cuota' => round((float)($pc['installment_amount'] ?? $monto / $n), 2),
                'total'       => round((float)($pc['total_amount'] ?? $monto), 2),
                'sin_interes' => $sinInteres,
                'mensaje'     => (string)($pc['recommended_message'] ?? ''),
            ];
        }
        usort($opciones, fn($a, $b) => $a['cuotas'] <=> $b['cuotas']);

        $data = ['max_sin_interes' => $maxSin, 'opciones' => $opciones];
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $data;
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /**
     * Verifica la firma x-signature de una notificacion.
     *
     * Manifest: "id:{data.id};request-id:{x-request-id};ts:{ts};" firmado con
     * HMAC-SHA256 y la clave secreta de la aplicacion. data.id se toma de la
     * query string (no del body) y, si es alfanumerico, en minusculas.
     *
     * Sin clave configurada devuelve true (desarrollo) y lo deja en el log.
     */
    public static function validarFirma(array $headers, array $query): bool {
        $secreto = trim((string)MP_WEBHOOK_SECRET);
        if ($secreto === '') {
            error_log('MP webhook: MP_WEBHOOK_SECRET vacío, firma no verificada.');
            return true;
        }

        $headers  = array_change_key_case($headers, CASE_LOWER);
        $firma    = (string)($headers['x-signature']  ?? '');
        $reqId    = (string)($headers['x-request-id'] ?? '');
        $dataId   = (string)($query['data.id'] ?? $query['id'] ?? '');
        if ($firma === '' || $dataId === '') return false;

        $ts = ''; $v1 = '';
        foreach (explode(',', $firma) as $parte) {
            [$k, $v] = array_map('trim', explode('=', $parte, 2) + ['', '']);
            if ($k === 'ts') $ts = $v;
            if ($k === 'v1') $v1 = $v;
        }
        if ($ts === '' || $v1 === '') return false;

        if (ctype_alnum($dataId) && !ctype_digit($dataId)) {
            $dataId = strtolower($dataId);
        }

        $manifest = "id:{$dataId};" . ($reqId !== '' ? "request-id:{$reqId};" : '') . "ts:{$ts};";
        $esperado = hash_hmac('sha256', $manifest, $secreto);

        return hash_equals($esperado, strtolower($v1));
    }
}
