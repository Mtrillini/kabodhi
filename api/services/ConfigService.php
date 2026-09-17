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
        // Envios: 'tabla' (tarifas por CP) o 'correo' (API de Correo Argentino,
        // con la tabla como respaldo si la API falla).
        'envio_modo'                => 'tabla',
        'correo_ambiente'           => 'test',
        'correo_cp_origen'          => '',
        'correo_permite_sucursal'   => '1',
        'correo_permite_expreso'    => '1',
        'correo_tracking_url'       => 'https://www.correoargentino.com.ar/formularios/e-commerce?id={codigo}',
        // Bulto por defecto para productos sin peso/medidas cargadas.
        'envio_peso_default_gramos' => '300',
        'envio_alto_default_cm'     => '10',
        'envio_ancho_default_cm'    => '15',
        'envio_largo_default_cm'    => '20',
        // Pagos: Mercado Pago (Checkout Pro) y transferencia bancaria.
        'mp_cuotas_max'             => '12',
        'mp_excluir_efectivo'       => '0',
        'mp_mostrar_cuotas'         => '1',
        'mp_descriptor'             => 'KABODHI',
        'transferencia_activa'      => '0',
        'transferencia_descuento'   => '0',
        'transferencia_titular'     => '',
        'transferencia_banco'       => '',
        'transferencia_cbu'         => '',
        'transferencia_alias'       => '',
        'transferencia_cuit'        => '',
        'transferencia_instrucciones' => 'Envianos el comprobante por WhatsApp o respondiendo el mail del pedido y lo confirmamos a la brevedad.',
    ];

    public const ENVIO_MODOS      = ['tabla', 'correo'];
    public const CORREO_AMBIENTES = ['test', 'prod'];

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

    /** Configuracion de pagos, tipada. */
    public function getPagoConfig(): array {
        $cfg = $this->getAll();
        $descuento = (float)$cfg['transferencia_descuento'];
        return [
            'mp_cuotas_max'       => max(1, min(24, (int)$cfg['mp_cuotas_max'])),
            'mp_excluir_efectivo' => (string)$cfg['mp_excluir_efectivo'] === '1',
            'mp_mostrar_cuotas'   => (string)$cfg['mp_mostrar_cuotas'] === '1',
            'mp_descriptor'       => trim((string)$cfg['mp_descriptor']) ?: 'KABODHI',
            'transferencia'       => [
                'activa'        => (string)$cfg['transferencia_activa'] === '1'
                                   && (trim((string)$cfg['transferencia_cbu']) !== '' || trim((string)$cfg['transferencia_alias']) !== ''),
                'descuento'     => max(0.0, min(50.0, $descuento)),
                'titular'       => trim((string)$cfg['transferencia_titular']),
                'banco'         => trim((string)$cfg['transferencia_banco']),
                'cbu'           => trim((string)$cfg['transferencia_cbu']),
                'alias'         => trim((string)$cfg['transferencia_alias']),
                'cuit'          => trim((string)$cfg['transferencia_cuit']),
                'instrucciones' => trim((string)$cfg['transferencia_instrucciones']),
            ],
        ];
    }

    /** Toda la configuracion de envios de una vez, ya tipada. */
    public function getEnvioConfig(): array {
        $cfg = $this->getAll();
        return [
            'modo'             => in_array($cfg['envio_modo'], self::ENVIO_MODOS, true) ? $cfg['envio_modo'] : 'tabla',
            'ambiente'         => in_array($cfg['correo_ambiente'], self::CORREO_AMBIENTES, true) ? $cfg['correo_ambiente'] : 'test',
            'cp_origen'        => preg_replace('/\D+/', '', (string)$cfg['correo_cp_origen']),
            'permite_sucursal' => (string)$cfg['correo_permite_sucursal'] === '1',
            'permite_expreso'  => (string)$cfg['correo_permite_expreso'] === '1',
            'tracking_url'     => (string)$cfg['correo_tracking_url'],
            'gratis_desde'     => (float)$cfg['envio_gratis_desde'],
            'bulto_default'    => [
                'peso_gramos' => max(1, (int)$cfg['envio_peso_default_gramos']),
                'alto_cm'     => max(1, (int)$cfg['envio_alto_default_cm']),
                'ancho_cm'    => max(1, (int)$cfg['envio_ancho_default_cm']),
                'largo_cm'    => max(1, (int)$cfg['envio_largo_default_cm']),
            ],
        ];
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
