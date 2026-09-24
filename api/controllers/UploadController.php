<?php

class UploadController {
    public function upload(): void {
        Auth::requireAdmin();

        if (empty($_FILES['imagen'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo.']);
            return;
        }

        $file = $_FILES['imagen'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo.']);
            return;
        }

        // La extension se deriva del MIME real, nunca del nombre que mando el
        // cliente: un GIF valido con codigo PHP adentro, subido como "x.php",
        // quedaria ejecutable dentro de /uploads/.
        $extPorMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        $mime = mime_content_type($file['tmp_name']);

        if (!isset($extPorMime[$mime])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tipo de archivo no permitido. Solo JPG, PNG, WEBP o GIF.']);
            return;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El archivo supera el máximo de 5MB.']);
            return;
        }

        $uploadDir = dirname(__DIR__, 2) . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext      = $extPorMime[$mime];
        $filename = uniqid('prod_', true) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudo guardar el archivo.']);
            return;
        }

        // Se guarda achicada y en WebP: un banner de 2 MB queda en ~150 KB y
        // el visitante deja de esperarlo. Si el hosting no puede convertir,
        // queda el archivo original y todo sigue igual.
        require_once __DIR__ . '/../services/ImagenOptimizer.php';
        $webp = ImagenOptimizer::aWebp($destPath, $mime);
        if ($webp !== null) {
            @unlink($destPath);
            $filename = basename($webp);
        }

        // Ruta relativa a la raiz del sitio, igual que el resto de las imagenes.
        // Guardar APP_URL absoluta ataba las imagenes al dominio del .env.
        $url = 'uploads/' . $filename;
        echo json_encode(['success' => true, 'url' => $url]);
    }
}
