<?php

class ConfigController {
    private ConfigService $service;

    public function __construct() {
        $this->service = new ConfigService();
    }

    /** Publico: la tienda necesita el WhatsApp, el email y el umbral de envio gratis. */
    public function index(): void {
        echo json_encode(['success' => true, 'data' => $this->service->getAll()]);
    }

    public function update(): void {
        // La configuracion es parte de la operacion diaria: el operador cambia
        // el WhatsApp, los textos de Nosotros o el umbral de envio gratis.
        // Lo que queda reservado al principal es la gestion de usuarios.
        Auth::requireAdmin();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (isset($body['contacto_email']) && $body['contacto_email'] !== ''
            && !filter_var($body['contacto_email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El email de contacto no es válido.']);
            return;
        }

        if (isset($body['whatsapp_numero']) && $body['whatsapp_numero'] !== '') {
            // Solo digitos: el link de wa.me no acepta espacios ni signos.
            $numero = preg_replace('/\D+/', '', (string)$body['whatsapp_numero']);
            if (strlen($numero) < 8) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El número de WhatsApp debe tener al menos 8 dígitos (con código de país, sin el +).']);
                return;
            }
            $body['whatsapp_numero'] = $numero;
        }

        if (isset($body['envio_gratis_desde'])) {
            $umbral = (float)$body['envio_gratis_desde'];
            if ($umbral < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El umbral de envío gratis no puede ser negativo.']);
                return;
            }
            $body['envio_gratis_desde'] = (string)$umbral;
        }

        // El texto de Nosotros es largo: se corta a algo razonable en vez de
        // dejar que crezca sin limite.
        if (isset($body['nosotros_texto']) && mb_strlen($body['nosotros_texto']) > 8000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El texto de Nosotros no puede superar los 8000 caracteres.']);
            return;
        }

        // --- Envios / Correo Argentino ---
        if (isset($body['envio_modo']) && !in_array($body['envio_modo'], ConfigService::ENVIO_MODOS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El modo de envío tiene que ser "tabla" o "correo".']);
            return;
        }
        if (isset($body['correo_ambiente']) && !in_array($body['correo_ambiente'], ConfigService::CORREO_AMBIENTES, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El ambiente de Correo Argentino tiene que ser "test" o "prod".']);
            return;
        }
        if (isset($body['correo_cp_origen'])) {
            $cp = preg_replace('/\D+/', '', (string)$body['correo_cp_origen']);
            if ($cp !== '' && strlen($cp) !== 4) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El CP de origen tiene que tener 4 dígitos.']);
                return;
            }
            $body['correo_cp_origen'] = $cp;
        }
        if (($body['envio_modo'] ?? null) === 'correo' && ($body['correo_cp_origen'] ?? '') === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Para cotizar con Correo Argentino hace falta el CP de origen.']);
            return;
        }
        foreach (['correo_permite_sucursal', 'correo_permite_expreso'] as $flag) {
            if (isset($body[$flag])) {
                $body[$flag] = filter_var($body[$flag], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        }
        if (isset($body['correo_tracking_url']) && $body['correo_tracking_url'] !== '') {
            $url = trim((string)$body['correo_tracking_url']);
            $esquema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($esquema, ['http', 'https'], true) || strpos($url, '{codigo}') === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El link de seguimiento debe ser http(s) y contener {codigo}.']);
                return;
            }
            $body['correo_tracking_url'] = $url;
        }
        foreach (['envio_peso_default_gramos' => 25000, 'envio_alto_default_cm' => 150,
                  'envio_ancho_default_cm' => 150, 'envio_largo_default_cm' => 150] as $clave => $max) {
            if (!isset($body[$clave])) continue;
            $n = (int)$body[$clave];
            if ($n < 1 || $n > $max) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "El valor de {$clave} tiene que estar entre 1 y {$max}."]);
                return;
            }
            $body[$clave] = (string)$n;
        }

        // --- Pagos ---
        if (isset($body['mp_cuotas_max'])) {
            $n = (int)$body['mp_cuotas_max'];
            if ($n < 1 || $n > 24) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Las cuotas máximas tienen que estar entre 1 y 24.']);
                return;
            }
            $body['mp_cuotas_max'] = (string)$n;
        }
        foreach (['mp_excluir_efectivo', 'mp_mostrar_cuotas', 'transferencia_activa'] as $flag) {
            if (isset($body[$flag])) {
                $body[$flag] = filter_var($body[$flag], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        }
        if (isset($body['mp_descriptor'])) {
            // Es lo que ve el cliente en el resumen de la tarjeta: MP lo corta a 22.
            $body['mp_descriptor'] = mb_substr(trim((string)$body['mp_descriptor']), 0, 22);
        }
        if (isset($body['transferencia_descuento'])) {
            $d = (float)$body['transferencia_descuento'];
            if ($d < 0 || $d > 50) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El descuento por transferencia tiene que estar entre 0 y 50 %.']);
                return;
            }
            $body['transferencia_descuento'] = (string)$d;
        }
        if (isset($body['transferencia_cbu']) && $body['transferencia_cbu'] !== '') {
            $cbu = preg_replace('/\D+/', '', (string)$body['transferencia_cbu']);
            if (strlen($cbu) !== 22) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El CBU/CVU tiene 22 dígitos.']);
                return;
            }
            $body['transferencia_cbu'] = $cbu;
        }
        if (isset($body['transferencia_cuit'])) {
            $body['transferencia_cuit'] = preg_replace('/[^\d\-]+/', '', (string)$body['transferencia_cuit']);
        }
        $cbuFinal   = array_key_exists('transferencia_cbu',   $body) ? (string)$body['transferencia_cbu']   : (string)$this->service->get('transferencia_cbu', '');
        $aliasFinal = array_key_exists('transferencia_alias', $body) ? (string)$body['transferencia_alias'] : (string)$this->service->get('transferencia_alias', '');
        if (($body['transferencia_activa'] ?? null) === '1' && trim($cbuFinal) === '' && trim($aliasFinal) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Para activar la transferencia cargá al menos el CBU/CVU o el alias.']);
            return;
        }

        if (isset($body['instagram_usuario'])) {
            // Se guarda solo el usuario, sin @ ni URL: el link se arma aparte.
            $body['instagram_usuario'] = ltrim(trim((string)$body['instagram_usuario']), '@');
        }

        $config = $this->service->saveMany($body);
        echo json_encode(['success' => true, 'data' => $config, 'message' => 'Configuración guardada.']);
    }
}
