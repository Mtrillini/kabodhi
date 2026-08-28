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
        // El WhatsApp, el mail de contacto y el umbral de envio gratis los
        // define el dueño de la tienda, no quien la opera dia a dia.
        Auth::requireSuper();

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

        $config = $this->service->saveMany($body);
        echo json_encode(['success' => true, 'data' => $config, 'message' => 'Configuración guardada.']);
    }
}
