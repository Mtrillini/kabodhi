<?php

/**
 * Configuracion de la tienda (tabla clave/valor).
 *
 * Los valores que antes vivian hardcodeados en js/config.js ahora se editan
 * desde el panel: numero de WhatsApp, email de contacto y umbral de envio gratis.
 */
class ConfigService {
    private PDO $db;

    /** Claves editables, con su valor por defecto si la fila no existe. */
    public const DEFAULTS = [
        // Contacto y venta
        'whatsapp_numero'    => '',
        'contacto_email'     => '',
        'envio_gratis_desde' => '0',
        'direccion'          => '',
        'instagram_usuario'  => '',
        // Contenido editable de la pagina Nosotros
        'nosotros_titulo'    => '',
        'nosotros_texto'     => '',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        $stmt   = $this->db->query("SELECT clave, valor FROM configuracion");
        $guardado = [];
        foreach ($stmt->fetchAll() as $row) {
            $guardado[$row['clave']] = $row['valor'];
        }

        $config = [];
        foreach (self::DEFAULTS as $clave => $default) {
            $config[$clave] = $guardado[$clave] ?? $default;
        }
        return $config;
    }

    public function get(string $clave, ?string $default = null): ?string {
        $stmt = $this->db->prepare("SELECT valor FROM configuracion WHERE clave = :clave");
        $stmt->execute([':clave' => $clave]);
        $valor = $stmt->fetchColumn();
        if ($valor === false) {
            return $default ?? (self::DEFAULTS[$clave] ?? null);
        }
        return $valor;
    }

    /** Umbral de envio gratis en pesos. 0 = desactivado. */
    public function getEnvioGratisDesde(): float {
        return (float)$this->get('envio_gratis_desde', '0');
    }

    /**
     * Guarda solo las claves conocidas; ignora cualquier otra cosa que llegue
     * en el body para que el endpoint no se convierta en un almacen libre.
     */
    public function saveMany(array $data): array {
        $stmt = $this->db->prepare(
            "INSERT INTO configuracion (clave, valor) VALUES (:clave, :valor)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );

        foreach ($data as $clave => $valor) {
            if (!array_key_exists($clave, self::DEFAULTS)) continue;
            $stmt->execute([':clave' => $clave, ':valor' => (string)$valor]);
        }

        return $this->getAll();
    }
}
