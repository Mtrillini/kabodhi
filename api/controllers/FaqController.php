<?php

/**
 * Preguntas frecuentes de la pagina de Ayuda.
 *
 * Son pocas y se editan todas juntas desde una sola pantalla, asi que en vez
 * de un ABM por fila el panel manda la lista completa y aca se sincroniza:
 * las que traen id se actualizan, las nuevas se insertan y las que faltan se
 * borran. El orden es el de la lista.
 */
class FaqController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** Publico: la pagina de Ayuda las necesita. Solo las activas. */
    public function index(): void {
        $todas = Auth::isAdmin() && !empty($_GET['all']);

        $sql = "SELECT id, pregunta, respuesta, orden, activo FROM faq"
             . ($todas ? '' : ' WHERE activo = 1')
             . ' ORDER BY orden ASC, id ASC';

        echo json_encode(['success' => true, 'data' => $this->db->query($sql)->fetchAll()]);
    }

    /** PUT /faq — reemplaza la lista completa. */
    public function replace(): void {
        Auth::requireAdmin();

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $lista = $body['faq'] ?? $body['data'] ?? null;

        if (!is_array($lista)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Faltan las preguntas.']);
            return;
        }
        if (count($lista) > 60) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Son demasiadas preguntas (máximo 60).']);
            return;
        }

        $existentes = array_map('intval', $this->db->query("SELECT id FROM faq")->fetchAll(PDO::FETCH_COLUMN));

        $update = $this->db->prepare(
            "UPDATE faq SET pregunta = :pregunta, respuesta = :respuesta, orden = :orden, activo = :activo WHERE id = :id"
        );
        $insert = $this->db->prepare(
            "INSERT INTO faq (pregunta, respuesta, orden, activo) VALUES (:pregunta, :respuesta, :orden, :activo)"
        );

        $vistas = [];
        try {
            $this->db->beginTransaction();

            foreach (array_values($lista) as $i => $fila) {
                $pregunta  = trim((string)($fila['pregunta']  ?? ''));
                $respuesta = trim((string)($fila['respuesta'] ?? ''));
                if ($pregunta === '' || $respuesta === '') continue;   // fila vacia del formulario

                $campos = [
                    ':pregunta'  => mb_substr($pregunta, 0, 300),
                    ':respuesta' => mb_substr($respuesta, 0, 4000),
                    ':orden'     => $i + 1,
                    ':activo'    => isset($fila['activo']) ? (int)(bool)$fila['activo'] : 1,
                ];

                $id = (int)($fila['id'] ?? 0);
                if ($id > 0 && in_array($id, $existentes, true)) {
                    $update->execute($campos + [':id' => $id]);
                    $vistas[] = $id;
                } else {
                    $insert->execute($campos);
                    $vistas[] = (int)$this->db->lastInsertId();
                }
            }

            $sobran = array_diff($existentes, $vistas);
            if (!empty($sobran)) {
                $marcas = implode(',', array_fill(0, count($sobran), '?'));
                $this->db->prepare("DELETE FROM faq WHERE id IN ({$marcas})")->execute(array_values($sobran));
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('FaqController::replace: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No se pudieron guardar las preguntas.']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Preguntas guardadas.', 'total' => count($vistas)]);
    }
}
