<?php

class ContactoController {

    public function enviar(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
            return;
        }

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $nombre  = trim($body['nombre']  ?? '');
        $email   = trim($body['email']   ?? '');
        $asunto  = trim($body['asunto']  ?? '');
        $mensaje = trim($body['mensaje'] ?? '');

        if ($nombre === '' || $email === '' || $asunto === '' || $mensaje === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios.']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ingresá un email válido.']);
            return;
        }

        $ok = MailService::enviarContacto($nombre, $email, $asunto, $mensaje);

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Mensaje enviado correctamente.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo enviar el mensaje. Intentá nuevamente más tarde.']);
        }
    }
}
