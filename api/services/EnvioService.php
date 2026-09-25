<?php

require_once __DIR__ . '/MiCorreoClient.php';

/**
 * Envios.
 *
 * Tres responsabilidades, en este orden dentro del archivo:
 *
 *   1. Tarifas por CP (tabla `tarifas_envio`): el ABM del panel y el calculo
 *      "viejo". Sigue siendo el respaldo cuando Correo Argentino no responde.
 *   2. Cotizacion unificada: segun `envio_modo`, pide precios a la API de
 *      Correo Argentino (domicilio clasico/expreso y sucursal) o a la tabla, y
 *      devuelve una lista de opciones con el mismo formato para el front.
 *   3. Registro del envio de cada pedido (tabla `envios`): cuanto se cotizo y
 *      cuanto se cobro, tipo de entrega, sucursal, bulto, tracking, estado con
 *      historial e importacion de la orden a MiCorreo.
 */
class EnvioService {
    private PDO $db;

    /** Estados del envio, en el orden "normal" del recorrido. */
    public const ESTADOS = [
        'pendiente'   => 'Pendiente de despacho',
        'preparando'  => 'En preparación',
        'enviado'     => 'Despachado',
        'en_traslado' => 'En traslado',
        'en_sucursal' => 'En sucursal, listo para retirar',
        'entregado'   => 'Entregado',
        'rechazado'   => 'Rechazado por el destinatario',
        'devuelto'    => 'Devuelto al remitente',
        'cancelado'   => 'Cancelado',
    ];

    /** Estados en los que el paquete ya no esta en manos de la tienda. */
    private const ESTADOS_DESPACHADOS = ['enviado', 'en_traslado', 'en_sucursal', 'entregado', 'rechazado', 'devuelto'];

    /** Estados finales: desde aca no se avanza mas. */
    private const ESTADOS_FINALES = ['entregado', 'devuelto', 'cancelado'];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =================================================================
    // 1. Tarifas por CP (tabla)
    // =================================================================

    public function getAll(): array {
        $stmt = $this->db->query(
            "SELECT * FROM tarifas_envio ORDER BY cp_desde ASC"
        );
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM tarifas_envio WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Tarifa de la tabla que corresponde a un CP. Si se pasa el subtotal del
     * carrito y supera el umbral de envio gratis configurado, el precio queda en 0.
     */
    public function calcular(int $cp, ?float $subtotal = null, ?int $pesoGramos = null): ?array {
        $peso = $pesoGramos ?? (int)(new ConfigService())->getEnvioConfig()['bulto_default']['peso_gramos'];
        $stmt = $this->db->prepare(
            "SELECT * FROM tarifas_envio
             WHERE activo = 1 AND cp_desde <= :cp1 AND cp_hasta >= :cp2
               AND (peso_desde_gramos IS NULL OR peso_desde_gramos <= :peso1)
               AND (peso_hasta_gramos IS NULL OR peso_hasta_gramos >= :peso2)
             ORDER BY (peso_desde_gramos IS NULL AND peso_hasta_gramos IS NULL) ASC, cp_desde ASC
             LIMIT 1"
        );
        $stmt->execute([':cp1' => $cp, ':cp2' => $cp, ':peso1' => $peso, ':peso2' => $peso]);
        $row = $stmt->fetch();
        if ($row === false) return null;

        $row['precio_lista'] = (float)$row['precio'];
        $row['bonificado']   = false;

        $umbral = (new ConfigService())->getEnvioGratisDesde();
        if ($umbral > 0 && $subtotal !== null && $subtotal >= $umbral) {
            $row['precio']      = 0.0;
            $row['bonificado']  = true;
            $row['descripcion'] = $row['descripcion'] . ' (envío bonificado)';
        }

        return $row;
    }

    public function hayTarifasActivas(): bool {
        return (int)$this->db->query("SELECT COUNT(*) FROM tarifas_envio WHERE activo = 1")->fetchColumn() > 0;
    }

    public function create(array $data): array {
        $stmt = $this->db->prepare(
            "INSERT INTO tarifas_envio
                (descripcion, cp_desde, cp_hasta, peso_desde_gramos, peso_hasta_gramos, precio, activo)
             VALUES (:descripcion, :cp_desde, :cp_hasta, :peso_desde, :peso_hasta, :precio, :activo)"
        );
        $stmt->execute([
            ':descripcion' => $data['descripcion'],
            ':cp_desde'    => (int)$data['cp_desde'],
            ':cp_hasta'    => (int)$data['cp_hasta'],
            // Vacio = sin tope de ese lado (aplica a cualquier peso).
            ':peso_desde'  => self::pesoONull($data['peso_desde_gramos'] ?? null),
            ':peso_hasta'  => self::pesoONull($data['peso_hasta_gramos'] ?? null),
            ':precio'      => (float)$data['precio'],
            ':activo'      => isset($data['activo']) ? (int)$data['activo'] : 1,
        ]);
        return $this->getById((int)$this->db->lastInsertId());
    }

    /** Entero positivo o null (sin tope). */
    private static function pesoONull($valor): ?int {
        if ($valor === null || $valor === '') return null;
        $n = (int)$valor;
        return $n > 0 ? $n : null;
    }

    public function update(int $id, array $data): ?array {
        $allowed = ['descripcion', 'cp_desde', 'cp_hasta', 'peso_desde_gramos', 'peso_hasta_gramos', 'precio', 'activo'];
        $fields  = [];
        $params  = [':id' => $id];

        $medidas = ['peso_desde_gramos', 'peso_hasta_gramos'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[]         = "`{$field}` = :{$field}";
                $params[":{$field}"] = in_array($field, $medidas, true)
                    ? self::pesoONull($data[$field])
                    : $data[$field];
            }
        }

        if (empty($fields)) return $this->getById($id);

        $this->db->prepare("UPDATE tarifas_envio SET " . implode(', ', $fields) . " WHERE id = :id")
                 ->execute($params);

        return $this->getById($id);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM tarifas_envio WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // =================================================================
    // 2. Cotizacion unificada
    // =================================================================

    /**
     * Cotiza el envio de un carrito a un CP.
     *
     * @param string $cp     CP de destino tal como lo escribio el cliente.
     * @param array  $items  [['id'=>.., 'cantidad'=>..], ...]. Se validan contra
     *                       la base: el precio y las medidas salen de ahi.
     * @return array {
     *   proveedor: 'correo'|'tabla'|'sin_costo',
     *   cp, subtotal, bulto, valida_hasta, aviso,
     *   opciones: [ {id, proveedor, tipo_entrega, producto, nombre, precio,
     *                precio_lista, bonificado, plazo_min, plazo_max, plazo,
     *                requiere_sucursal}, ... ]
     * }
     * Sin opciones = no hay forma de enviar a ese CP.
     */
    public function cotizar(string $cp, array $items): array {
        $cpLimpio = MiCorreoClient::cp($cp);
        if (strlen($cpLimpio) !== 4 || (int)$cpLimpio < 1000) {
            throw new InvalidArgumentException('Código postal inválido.');
        }

        $itemsValidados = $this->validarItems($items);
        $subtotal       = 0.0;
        foreach ($itemsValidados as $it) {
            $subtotal += $it['precio'] * $it['cantidad'];
        }

        $config = (new ConfigService())->getEnvioConfig();
        $bulto  = $this->armarBulto($itemsValidados, $config['bulto_default']);

        $resultado = [
            'proveedor'    => 'sin_costo',
            'cp'           => $cpLimpio,
            'subtotal'     => round($subtotal, 2),
            'bulto'        => $bulto,
            'valida_hasta' => null,
            'aviso'        => null,
            'opciones'     => [],
        ];

        if ($config['modo'] === 'correo') {
            try {
                $correo = $this->cotizarCorreo($cpLimpio, $bulto, $subtotal, $config);
                if (!empty($correo['opciones'])) {
                    return array_merge($resultado, $correo, ['proveedor' => 'correo']);
                }
                $resultado['aviso'] = 'Correo Argentino no tiene servicio para ese código postal.';
            } catch (Throwable $e) {
                // La API fallo: se cae a la tabla y se deja registro. El cliente
                // no tiene por que enterarse del detalle.
                error_log('EnvioService::cotizar correo -> ' . $e->getMessage());
                $resultado['aviso'] = 'No pudimos cotizar con Correo Argentino; se aplica la tarifa de la tienda.';
            }
        }

        if ($this->hayTarifasActivas()) {
            $tabla = $this->cotizarTabla((int)$cpLimpio, $bulto['peso_gramos'], $subtotal, $config);
            $resultado['proveedor'] = 'tabla';
            $resultado['opciones']  = $tabla;
            return $resultado;
        }

        // Modo Correo sin tabla de respaldo: si la API no dio precio, no se
        // inventa un envio gratis; el cliente reintenta.
        if ($config['modo'] === 'correo') {
            $resultado['aviso'] = $resultado['aviso'] ?: 'No pudimos calcular el envío. Probá de nuevo en unos minutos.';
            return $resultado;
        }

        // Tienda sin tarifas y sin Correo: no se cobra envio.
        $resultado['opciones'] = [[
            'id'                => 'sin_costo',
            'proveedor'         => 'sin_costo',
            'tipo_entrega'      => 'domicilio',
            'producto'          => null,
            'nombre'            => 'Envío a coordinar',
            'precio'            => 0.0,
            'precio_lista'      => 0.0,
            'bonificado'        => false,
            'plazo_min'         => null,
            'plazo_max'         => null,
            'plazo'             => null,
            'requiere_sucursal' => false,
        ]];

        $this->agregarRetiroPuntoEncuentro($resultado);
        return $resultado;
    }

    private function agregarRetiroPuntoEncuentro(array &$resultado): void {
        $cfg = (new ConfigService())->getAll();
        if (($cfg['retiro_punto_encuentro_activo'] ?? '0') !== '1') return;
        $info = trim($cfg['retiro_punto_encuentro_info'] ?? '');
        if ($info === '') return;

        $resultado['opciones'][] = [
            'id'                => 'retiro_punto',
            'proveedor'         => 'retiro',
            'tipo_entrega'      => 'retiro',
            'producto'          => null,
            'nombre'            => 'Retiro en punto de encuentro',
            'precio'            => 0.0,
            'precio_lista'      => 0.0,
            'bonificado'        => true,
            'plazo_min'         => null,
            'plazo_max'         => null,
            'plazo'             => 'Coordinamos la fecha',
            'requiere_sucursal' => false,
            'info_especial'     => $info,
        ];
    }

    private function cotizarCorreo(string $cpDestino, array $bulto, float $subtotal, array $config): array {
        if ($config['cp_origen'] === '') {
            throw new RuntimeException('Falta el CP de origen de Correo Argentino en la configuración.');
        }
        if ($bulto['peso_gramos'] > MiCorreoClient::PESO_MAX_GRAMOS) {
            throw new RuntimeException("El bulto supera los 25 kg que acepta Correo Argentino ({$bulto['peso_gramos']} g).");
        }

        $cliente = new MiCorreoClient($config['ambiente']);
        if (!$cliente->configurado()) {
            throw new RuntimeException('Correo Argentino sin credenciales en el .env.');
        }

        $tipo = $config['permite_sucursal'] ? null : MiCorreoClient::ENTREGA_DOMICILIO;

        // Cache corto por bulto+destino: el cliente cambia cantidades y vuelve a
        // pedir la misma cotizacion varias veces en pocos minutos.
        $cacheKey = sprintf('rates-%s-%s-%s-%s-%d-%d-%d-%d.json', $config['ambiente'], $config['cp_origen'], $cpDestino,
                            $tipo ?? 'DS', $bulto['peso_gramos'], $bulto['alto_cm'], $bulto['ancho_cm'], $bulto['largo_cm']);
        $cache = dirname(__DIR__, 2) . '/storage/micorreo/' . $cacheKey;
        $res   = null;
        if (is_file($cache) && filemtime($cache) > time() - 15 * 60) {
            $res = json_decode((string)file_get_contents($cache), true);
            if (!is_array($res) || !isset($res['rates'])) $res = null;
        }
        if ($res === null) {
            $res = $cliente->cotizar($config['cp_origen'], $cpDestino, $bulto, $tipo);
            $dir = dirname($cache);
            if (is_dir($dir) || @mkdir($dir, 0750, true)) {
                @file_put_contents($cache, json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }

        $opciones = [];
        foreach ($res['rates'] as $rate) {
            $entrega  = strtoupper((string)($rate['deliveredType'] ?? 'D'));
            $producto = strtoupper((string)($rate['productType']   ?? ''));
            $precio   = round((float)($rate['price'] ?? 0), 2);

            if ($precio <= 0) continue;
            if ($entrega === MiCorreoClient::ENTREGA_SUCURSAL && !$config['permite_sucursal']) continue;
            if ($producto === MiCorreoClient::PRODUCTO_EXPRESO && !$config['permite_expreso']) continue;

            $plazoMin = self::dias($rate['deliveryTimeMin'] ?? null);
            $plazoMax = self::dias($rate['deliveryTimeMax'] ?? null);

            $opciones[] = self::aplicarBonificacion([
                'id'                => 'correo:' . $entrega . ':' . $producto,
                'proveedor'         => 'correo',
                'tipo_entrega'      => $entrega === MiCorreoClient::ENTREGA_SUCURSAL ? 'sucursal' : 'domicilio',
                'producto'          => $producto,
                'nombre'            => self::nombreOpcionCorreo($entrega, $producto, (string)($rate['productName'] ?? '')),
                'precio'            => $precio,
                'precio_lista'      => $precio,
                'bonificado'        => false,
                'plazo_min'         => $plazoMin,
                'plazo_max'         => $plazoMax,
                'plazo'             => self::textoPlazo($plazoMin, $plazoMax),
                'requiere_sucursal' => $entrega === MiCorreoClient::ENTREGA_SUCURSAL,
            ], $subtotal, $config['gratis_desde']);
        }

        // Domicilio antes que sucursal; dentro de cada uno, el mas barato primero.
        usort($opciones, function ($a, $b) {
            if ($a['tipo_entrega'] !== $b['tipo_entrega']) {
                return $a['tipo_entrega'] === 'domicilio' ? -1 : 1;
            }
            return $a['precio_lista'] <=> $b['precio_lista'];
        });

        return [
            'opciones'     => $opciones,
            'valida_hasta' => $res['validTo'] ?? null,
        ];
    }

    /**
     * Tarifa por CP y por peso del bulto completo (los pesos de cada
     * producto/variante ya vienen sumados en $pesoGramos, ver armarBulto()).
     * Una tarifa con peso_desde/hasta en NULL de ese lado no tiene tope por
     * ahi, asi que las tarifas cargadas antes de esto (sin peso) siguen
     * aplicando a cualquier peso sin tocarlas.
     *
     * Entre varias que matchean se prefiere la mas especifica: la que tiene
     * rango de peso cargado gana sobre la que no, asi un admin puede sumar
     * un escalon por peso sin que la tarifa vieja (sin peso) se lo pise.
     */
    private function cotizarTabla(int $cp, int $pesoGramos, float $subtotal, array $config): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM tarifas_envio
             WHERE activo = 1 AND cp_desde <= :cp1 AND cp_hasta >= :cp2
               AND (peso_desde_gramos IS NULL OR peso_desde_gramos <= :peso1)
               AND (peso_hasta_gramos IS NULL OR peso_hasta_gramos >= :peso2)
             ORDER BY (peso_desde_gramos IS NULL AND peso_hasta_gramos IS NULL) ASC, cp_desde ASC
             LIMIT 1"
        );
        $stmt->execute([':cp1' => $cp, ':cp2' => $cp, ':peso1' => $pesoGramos, ':peso2' => $pesoGramos]);
        $row = $stmt->fetch();
        if ($row === false) return [];

        $precio = round((float)$row['precio'], 2);
        return [self::aplicarBonificacion([
            'id'                => 'tabla:' . (int)$row['id'],
            'proveedor'         => 'tabla',
            'tipo_entrega'      => 'domicilio',
            'producto'          => null,
            'nombre'            => (string)$row['descripcion'],
            'precio'            => $precio,
            'precio_lista'      => $precio,
            'bonificado'        => false,
            'plazo_min'         => null,
            'plazo_max'         => null,
            'plazo'             => null,
            'requiere_sucursal' => false,
        ], $subtotal, $config['gratis_desde'])];
    }

    private static function aplicarBonificacion(array $opcion, float $subtotal, float $umbral): array {
        if ($umbral > 0 && $subtotal >= $umbral) {
            $opcion['precio']     = 0.0;
            $opcion['bonificado'] = true;
        }
        return $opcion;
    }

    /**
     * Bulto unico con todos los items: se apilan (suma de altos y pesos) y las
     * otras dos medidas son las del item mas grande. Aproximado, pero es lo que
     * se puede hacer sin saber como se embala cada pedido.
     */
    public function armarBulto(array $itemsValidados, array $default): array {
        $peso = 0; $alto = 0; $ancho = 0; $largo = 0;

        foreach ($itemsValidados as $it) {
            $q      = (int)$it['cantidad'];
            $peso  += ((int)($it['peso_gramos'] ?: $default['peso_gramos'])) * $q;
            $alto  += ((int)($it['alto_cm']     ?: $default['alto_cm']))     * $q;
            $ancho  = max($ancho, (int)($it['ancho_cm'] ?: $default['ancho_cm']));
            $largo  = max($largo, (int)($it['largo_cm'] ?: $default['largo_cm']));
        }

        $max = MiCorreoClient::MEDIDA_MAX_CM;
        return [
            'peso_gramos' => max(1, $peso),
            'alto_cm'     => max(1, min($alto,  $max)),
            'ancho_cm'    => max(1, min($ancho, $max)),
            'largo_cm'    => max(1, min($largo, $max)),
        ];
    }

    /**
     * Trae los productos del carrito desde la base. Devuelve solo los activos
     * con cantidad valida; el precio y las medidas son los reales, no los que
     * mando el cliente.
     */
    public function validarItems(array $items): array {
        // Una linea por producto+variante: dos aromas del mismo producto pesan
        // distinto y no se pueden sumar como si fueran lo mismo.
        $lineas = [];
        foreach ($items as $it) {
            $id  = (int)($it['id'] ?? $it['producto_id'] ?? 0);
            $vid = (int)($it['variante_id'] ?? 0);
            $q   = (int)($it['cantidad'] ?? 1);
            if ($id <= 0 || $q <= 0) continue;

            $clave = $id . ':' . $vid;
            if (!isset($lineas[$clave])) {
                $lineas[$clave] = ['producto_id' => $id, 'variante_id' => $vid, 'cantidad' => 0];
            }
            $lineas[$clave]['cantidad'] += $q;
        }
        if (empty($lineas)) {
            throw new InvalidArgumentException('El carrito está vacío.');
        }

        $productoIds = array_values(array_unique(array_column($lineas, 'producto_id')));
        $marcas = implode(',', array_fill(0, count($productoIds), '?'));
        $stmt   = $this->db->prepare(
            "SELECT id, nombre, precio, peso_gramos, alto_cm, ancho_cm, largo_cm
             FROM productos WHERE activo = 1 AND id IN ({$marcas})"
        );
        $stmt->execute($productoIds);
        $productos = [];
        foreach ($stmt->fetchAll() as $p) {
            $productos[(int)$p['id']] = $p;
        }

        $varianteIds = array_values(array_filter(array_unique(array_column($lineas, 'variante_id'))));
        $variantes   = [];
        if (!empty($varianteIds)) {
            $marcas = implode(',', array_fill(0, count($varianteIds), '?'));
            $stmt   = $this->db->prepare(
                "SELECT id, producto_id, nombre, precio, peso_gramos, alto_cm, ancho_cm, largo_cm
                 FROM producto_variantes WHERE activo = 1 AND id IN ({$marcas})"
            );
            $stmt->execute($varianteIds);
            foreach ($stmt->fetchAll() as $v) {
                $variantes[(int)$v['id']] = $v;
            }
        }

        $out = [];
        foreach ($lineas as $linea) {
            $p = $productos[$linea['producto_id']] ?? null;
            if ($p === null) continue;

            $v = null;
            if ($linea['variante_id'] > 0) {
                $v = $variantes[$linea['variante_id']] ?? null;
                // Variante inexistente, de baja o de otro producto: se ignora
                // la linea en vez de cotizar un bulto que no se va a vender.
                if ($v === null || (int)$v['producto_id'] !== (int)$p['id']) continue;
            }

            $out[] = [
                'id'          => (int)$p['id'],
                'variante_id' => $v !== null ? (int)$v['id'] : null,
                'nombre'      => $v !== null ? $p['nombre'] . ' — ' . $v['nombre'] : $p['nombre'],
                'cantidad'    => $linea['cantidad'],
                'precio'      => $v !== null && $v['precio'] !== null ? (float)$v['precio'] : (float)$p['precio'],
                // Medidas propias de la variante; si no tiene, las del producto.
                'peso_gramos' => self::medidaHeredada($v, $p, 'peso_gramos'),
                'alto_cm'     => self::medidaHeredada($v, $p, 'alto_cm'),
                'ancho_cm'    => self::medidaHeredada($v, $p, 'ancho_cm'),
                'largo_cm'    => self::medidaHeredada($v, $p, 'largo_cm'),
            ];
        }
        if (empty($out)) {
            throw new InvalidArgumentException('Ninguno de los productos del carrito está disponible.');
        }
        return $out;
    }

    /** Medida de la variante; si no la tiene cargada, la del producto. */
    private static function medidaHeredada(?array $variante, array $producto, string $campo): ?int {
        $valor = $variante[$campo] ?? null;
        if ($valor === null) $valor = $producto[$campo] ?? null;
        return $valor !== null ? (int)$valor : null;
    }

    /**
     * Sucursales de Correo de una provincia, con cache de 12 horas en disco:
     * la lista cambia poco y la API es lenta para lo que es (un combo).
     */
    public function sucursales(string $provincia): array {
        $codigo = MiCorreoClient::provinciaCodigo($provincia);
        if ($codigo === null) {
            throw new InvalidArgumentException('Provincia no reconocida.');
        }

        $config = (new ConfigService())->getEnvioConfig();
        $cache  = dirname(__DIR__, 2) . "/storage/micorreo/sucursales-{$config['ambiente']}-{$codigo}.json";
        if (is_file($cache) && filemtime($cache) > time() - 12 * 3600) {
            $data = json_decode((string)file_get_contents($cache), true);
            if (is_array($data)) return $data;
        }

        $lista = (new MiCorreoClient($config['ambiente']))->sucursales($codigo);

        $dir = dirname($cache);
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($cache, json_encode($lista, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $lista;
    }

    /** Prueba de credenciales para el panel. */
    public function probarConexion(): array {
        $config  = (new ConfigService())->getEnvioConfig();
        $cliente = new MiCorreoClient($config['ambiente']);
        if (!$cliente->configurado()) {
            throw new RuntimeException('Faltan MICORREO_USER / MICORREO_PASSWORD en el .env.');
        }
        return $cliente->probar();
    }

    // =================================================================
    // 3. Registro del envio de un pedido
    // =================================================================

    /**
     * Resuelve el envio de un pedido a partir de lo que eligio el cliente.
     * Vuelve a cotizar en el servidor: del cliente solo se toma el CP, la
     * opcion elegida y (si corresponde) la sucursal y la direccion.
     *
     * @param array $envioData  {cp, opcion_id?, sucursal_codigo?, sucursal_nombre?,
     *                           provincia?, calle?, numero?, piso_depto?, ciudad?}
     * @param array $items      Items del carrito [['id','cantidad'],...]
     * @return array Fila lista para insertar en `envios` (sin pedido_id) mas
     *               'descripcion' para pedidos.envio_descripcion.
     */
    public function resolverParaPedido(array $envioData, array $items): array {
        $config = (new ConfigService())->getEnvioConfig();

        // Tienda sin tarifas ni Correo: no se cobra ni se pide CP (comportamiento
        // historico).
        if ($config['modo'] !== 'correo' && !$this->hayTarifasActivas()) {
            $cp = MiCorreoClient::cp((string)($envioData['cp'] ?? ''));
            return $this->filaSinCosto($cp !== '' ? $cp : '0', $envioData);
        }

        $cp = (string)($envioData['cp'] ?? '');
        if (MiCorreoClient::cp($cp) === '') {
            throw new InvalidArgumentException('Falta el código postal para calcular el envío.');
        }

        $cotizacion = $this->cotizar($cp, $items);
        if (empty($cotizacion['opciones'])) {
            throw new RuntimeException("No hay envíos disponibles para el código postal {$cotizacion['cp']}.");
        }

        $opcionId = trim((string)($envioData['opcion_id'] ?? ''));
        $opcion   = null;
        foreach ($cotizacion['opciones'] as $o) {
            if ($o['id'] === $opcionId) { $opcion = $o; break; }
        }
        if ($opcion === null) {
            if ($opcionId !== '') {
                // Eligio algo que ya no se ofrece (p. ej. Correo cayo y quedo la
                // tabla): no se le cobra otra cosa sin que la vea.
                throw new RuntimeException('La opción de envío elegida ya no está disponible. Volvé a calcular el envío.');
            }
            if (count($cotizacion['opciones']) !== 1) {
                throw new InvalidArgumentException('Elegí una forma de envío.');
            }
            // Una sola forma de envio (tabla / sin costo): no hace falta elegir.
            $opcion = $cotizacion['opciones'][0];
        }

        $sucursalCodigo = null;
        $sucursalNombre = null;
        if ($opcion['requiere_sucursal']) {
            $sucursalCodigo = trim((string)($envioData['sucursal_codigo'] ?? ''));
            $sucursalNombre = trim((string)($envioData['sucursal_nombre'] ?? ''));
            if ($sucursalCodigo === '') {
                throw new InvalidArgumentException('Elegí la sucursal de Correo Argentino donde retirar.');
            }
            // Si se puede, se toma el nombre de la lista oficial (no del cliente).
            $provincia = (string)($envioData['provincia'] ?? '');
            if ($provincia !== '') {
                try {
                    foreach ($this->sucursales($provincia) as $s) {
                        if ($s['codigo'] === $sucursalCodigo) {
                            $sucursalNombre = trim($s['nombre'] . ' — ' . $s['direccion'] . ', ' . $s['localidad'], ' —,');
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('EnvioService::resolverParaPedido sucursales -> ' . $e->getMessage());
                }
            }
        }

        $descripcion = $opcion['nombre'];
        if ($opcion['plazo']) $descripcion .= " ({$opcion['plazo']})";
        if ($opcion['bonificado']) $descripcion .= ' (envío bonificado)';

        return [
            'proveedor'               => $opcion['proveedor'],
            'tipo_entrega'            => $opcion['tipo_entrega'],
            'producto'                => $opcion['producto'],
            'producto_nombre'         => $opcion['nombre'],
            'cp_origen'               => $opcion['proveedor'] === 'correo' ? $config['cp_origen'] : null,
            'cp_destino'              => $cotizacion['cp'],
            'provincia_codigo'        => MiCorreoClient::provinciaCodigo((string)($envioData['provincia'] ?? '')),
            'sucursal_codigo'         => $sucursalCodigo,
            'sucursal_nombre'         => $sucursalNombre !== '' ? $sucursalNombre : null,
            'dest_calle'              => self::nullSiVacio($envioData['calle']      ?? ''),
            'dest_numero'             => self::nullSiVacio($envioData['numero']     ?? ''),
            'dest_piso_depto'         => self::nullSiVacio($envioData['piso_depto'] ?? ''),
            'dest_ciudad'             => self::nullSiVacio($envioData['ciudad']     ?? ''),
            'dest_provincia'          => self::nullSiVacio($envioData['provincia']  ?? ''),
            'peso_gramos'             => $cotizacion['bulto']['peso_gramos'],
            'alto_cm'                 => $cotizacion['bulto']['alto_cm'],
            'ancho_cm'                => $cotizacion['bulto']['ancho_cm'],
            'largo_cm'                => $cotizacion['bulto']['largo_cm'],
            'costo_cotizado'          => $opcion['precio_lista'],
            'costo_cobrado'           => $opcion['precio'],
            'bonificado'              => $opcion['bonificado'] ? 1 : 0,
            'plazo_min_dias'          => $opcion['plazo_min'],
            'plazo_max_dias'          => $opcion['plazo_max'],
            'cotizacion_valida_hasta' => self::fechaSql($cotizacion['valida_hasta']),
            'descripcion'             => $descripcion,
        ];
    }

    private function filaSinCosto(string $cp, array $envioData): array {
        return [
            'proveedor'               => 'sin_costo',
            'tipo_entrega'            => 'domicilio',
            'producto'                => null,
            'producto_nombre'         => null,
            'cp_origen'               => null,
            'cp_destino'              => $cp,
            'provincia_codigo'        => MiCorreoClient::provinciaCodigo((string)($envioData['provincia'] ?? '')),
            'sucursal_codigo'         => null,
            'sucursal_nombre'         => null,
            'dest_calle'              => self::nullSiVacio($envioData['calle']      ?? ''),
            'dest_numero'             => self::nullSiVacio($envioData['numero']     ?? ''),
            'dest_piso_depto'         => self::nullSiVacio($envioData['piso_depto'] ?? ''),
            'dest_ciudad'             => self::nullSiVacio($envioData['ciudad']     ?? ''),
            'dest_provincia'          => self::nullSiVacio($envioData['provincia']  ?? ''),
            'peso_gramos'             => 0,
            'alto_cm'                 => 0,
            'ancho_cm'                => 0,
            'largo_cm'                => 0,
            'costo_cotizado'          => 0.0,
            'costo_cobrado'           => 0.0,
            'bonificado'              => 0,
            'plazo_min_dias'          => null,
            'plazo_max_dias'          => null,
            'cotizacion_valida_hasta' => null,
            'descripcion'             => null,
        ];
    }

    /**
     * Inserta la fila de `envios` de un pedido recien creado. Se llama dentro
     * de la transaccion de PedidoService::crear().
     */
    public function crearRegistro(int $pedidoId, array $fila): int {
        unset($fila['descripcion']);
        $fila['pedido_id'] = $pedidoId;

        $cols = array_keys($fila);
        $sql  = "INSERT INTO envios (" . implode(', ', $cols) . ")
                 VALUES (:" . implode(', :', $cols) . ")";
        $params = [];
        foreach ($fila as $k => $v) $params[":{$k}"] = $v;
        $this->db->prepare($sql)->execute($params);

        $envioId = (int)$this->db->lastInsertId();
        $this->registrarHistorial($envioId, 'pendiente', 'Pedido creado.', 'sistema', null);
        return $envioId;
    }

    public function getByPedido(int $pedidoId, bool $conHistorial = true): ?array {
        $stmt = $this->db->prepare("SELECT * FROM envios WHERE pedido_id = :id");
        $stmt->execute([':id' => $pedidoId]);
        $envio = $stmt->fetch();
        if ($envio === false) return null;

        $envio['estado_label'] = self::ESTADOS[$envio['estado']] ?? $envio['estado'];
        $envio['tracking_url_publica'] = $this->urlSeguimiento($envio);
        if ($conHistorial) {
            $envio['historial'] = $this->historial((int)$envio['id']);
        }
        return $envio;
    }

    public function historial(int $envioId): array {
        $stmt = $this->db->prepare(
            "SELECT h.id, h.estado, h.detalle, h.origen, h.created_at, u.username AS usuario
             FROM envio_historial h
             LEFT JOIN admin_users u ON u.id = h.usuario_id
             WHERE h.envio_id = :id
             ORDER BY h.id ASC"
        );
        $stmt->execute([':id' => $envioId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['estado_label'] = self::ESTADOS[$r['estado']] ?? $r['estado'];
        }
        return $rows;
    }

    /**
     * Historial de envios para el panel: un renglon por pedido con lo que se
     * cotizo, lo que se cobro y el estado.
     *
     * @param array $filtros {estado?, proveedor?, desde?, hasta?, q?}
     */
    public function listar(array $filtros = []): array {
        $sql = "SELECT e.*, p.cliente_nombre, p.cliente_email, p.cliente_direccion,
                       p.estado AS pedido_estado, p.total AS pedido_total, p.created_at AS pedido_created_at
                FROM envios e
                INNER JOIN pedidos p ON p.id = e.pedido_id
                WHERE 1 = 1";
        $params = [];

        if (!empty($filtros['estado']) && isset(self::ESTADOS[$filtros['estado']])) {
            $sql .= " AND e.estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['proveedor']) && in_array($filtros['proveedor'], ['correo', 'tabla', 'sin_costo'], true)) {
            $sql .= " AND e.proveedor = :proveedor";
            $params[':proveedor'] = $filtros['proveedor'];
        }
        if (!empty($filtros['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['desde'])) {
            $sql .= " AND p.created_at >= :desde";
            $params[':desde'] = $filtros['desde'] . ' 00:00:00';
        }
        if (!empty($filtros['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['hasta'])) {
            $sql .= " AND p.created_at <= :hasta";
            $params[':hasta'] = $filtros['hasta'] . ' 23:59:59';
        }
        if (!empty($filtros['q'])) {
            $sql .= " AND (p.cliente_nombre LIKE :q1 OR p.cliente_email LIKE :q2
                           OR e.tracking_codigo LIKE :q3 OR CAST(p.id AS CHAR) = :q4)";
            $like = '%' . $filtros['q'] . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
            $params[':q4'] = $filtros['q'];
        }

        $sql .= " ORDER BY p.created_at DESC LIMIT 500";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['estado_label']         = self::ESTADOS[$r['estado']] ?? $r['estado'];
            $r['tracking_url_publica'] = $this->urlSeguimiento($r);
        }
        return $rows;
    }

    /** Totales para la cabecera del historial. */
    public function resumen(): array {
        $row = $this->db->query(
            "SELECT COUNT(*) AS envios,
                    SUM(costo_cotizado) AS cotizado,
                    SUM(costo_cobrado)  AS cobrado,
                    SUM(bonificado)     AS bonificados,
                    SUM(estado IN ('enviado','en_traslado','en_sucursal')) AS en_curso,
                    SUM(estado = 'entregado') AS entregados,
                    SUM(estado = 'pendiente' OR estado = 'preparando') AS por_despachar
             FROM envios"
        )->fetch();
        return array_map(fn($v) => $v === null ? 0 : $v, $row ?: []);
    }

    /**
     * Cambio de estado hecho desde el panel (o por sincronizacion).
     *
     * Efectos sobre el pedido: 'enviado' y 'entregado' lo pasan al mismo
     * estado (y disparan sus mails); 'en_sucursal' avisa al cliente que puede
     * retirar. Los demas estados son internos del envio.
     *
     * @param bool $sincronizarPedido false cuando el que llama es PedidoService
     *                                (evita el ida y vuelta).
     */
    public function cambiarEstado(int $pedidoId, string $estado, ?string $detalle, string $origen, ?int $usuarioId, bool $sincronizarPedido = true): array {
        if (!isset(self::ESTADOS[$estado])) {
            throw new InvalidArgumentException("Estado de envío inválido: {$estado}");
        }
        $envio = $this->getByPedido($pedidoId, false);
        if (!$envio) {
            throw new RuntimeException("El pedido #{$pedidoId} no tiene envío registrado.");
        }

        if ($envio['estado'] !== $estado) {
            $this->db->prepare(
                "UPDATE envios SET estado = :estado, estado_at = NOW() WHERE id = :id"
            )->execute([':estado' => $estado, ':id' => $envio['id']]);
            $this->registrarHistorial((int)$envio['id'], $estado, $detalle, $origen, $usuarioId);
        } elseif ($detalle !== null && $detalle !== '') {
            // Mismo estado pero con una nota: se anota igual.
            $this->registrarHistorial((int)$envio['id'], $estado, $detalle, $origen, $usuarioId);
        }

        if ($sincronizarPedido && $envio['estado'] !== $estado) {
            $pedidoService = new PedidoService();
            if ($estado === 'enviado' || $estado === 'entregado') {
                $pedidoService->actualizarEstado($pedidoId, $estado, false);
            } elseif ($estado === 'en_sucursal') {
                $pedido = $pedidoService->getById($pedidoId);
                if ($pedido) MailService::enviarPedidoEnSucursal($pedido);
            }
        }

        return $this->getByPedido($pedidoId);
    }

    /**
     * El pedido cambio de estado desde el panel / MercadoPago: si el envio
     * quedo "atras", lo alcanza. Nunca retrocede ni pisa un estado final.
     */
    public function sincronizarDesdePedido(int $pedidoId, string $estadoPedido): void {
        $envio = $this->getByPedido($pedidoId, false);
        if (!$envio) return;

        $actual = $envio['estado'];
        $nuevo  = null;

        if ($estadoPedido === 'aprobado' && $actual === 'cancelado') {
            // Pago rechazado y luego reintentado con exito: el envio vuelve a la cola.
            $nuevo = 'pendiente';
        } elseif ($estadoPedido === 'enviado' && !in_array($actual, self::ESTADOS_DESPACHADOS, true) && !in_array($actual, self::ESTADOS_FINALES, true)) {
            $nuevo = 'enviado';
        } elseif ($estadoPedido === 'entregado' && !in_array($actual, self::ESTADOS_FINALES, true)) {
            $nuevo = 'entregado';
        } elseif (($estadoPedido === 'cancelado' || $estadoPedido === 'rechazado')
                  && !in_array($actual, self::ESTADOS_DESPACHADOS, true) && !in_array($actual, self::ESTADOS_FINALES, true)) {
            $nuevo = 'cancelado';
        }

        if ($nuevo !== null) {
            $this->cambiarEstado($pedidoId, $nuevo, "Pedido pasado a \"{$estadoPedido}\".", 'sistema', null, false);
        }
    }

    /** Tracking cargado a mano (o devuelto por MiCorreo). */
    public function actualizarTracking(int $pedidoId, ?string $codigo, ?string $url): void {
        $codigo = self::nullSiVacio($codigo ?? '');
        $url    = self::nullSiVacio($url ?? '');
        $this->db->prepare(
            "UPDATE envios SET tracking_codigo = :codigo, tracking_url = :url WHERE pedido_id = :id"
        )->execute([':codigo' => $codigo, ':url' => $url, ':id' => $pedidoId]);
    }

    /** Datos de destino editables desde el panel (para poder importar pedidos viejos). */
    public function actualizarDestino(int $pedidoId, array $data): array {
        $envio = $this->getByPedido($pedidoId, false);
        if (!$envio) {
            throw new RuntimeException("El pedido #{$pedidoId} no tiene envío registrado.");
        }

        $campos = ['dest_calle', 'dest_numero', 'dest_piso_depto', 'dest_ciudad', 'dest_provincia',
                   'cp_destino', 'sucursal_codigo', 'sucursal_nombre',
                   'peso_gramos', 'alto_cm', 'ancho_cm', 'largo_cm'];
        $set = []; $params = [':id' => $envio['id']];
        foreach ($campos as $c) {
            if (!array_key_exists($c, $data)) continue;
            $valor = $data[$c];
            if (in_array($c, ['peso_gramos', 'alto_cm', 'ancho_cm', 'largo_cm'], true)) {
                $valor = max(0, (int)$valor);
            } elseif ($c === 'cp_destino') {
                $valor = MiCorreoClient::cp((string)$valor);
                if ($valor === '') throw new InvalidArgumentException('Código postal de destino inválido.');
            } else {
                $valor = self::nullSiVacio((string)$valor);
            }
            $set[] = "`{$c}` = :{$c}";
            $params[":{$c}"] = $valor;
        }
        if (isset($data['dest_provincia'])) {
            $set[] = "provincia_codigo = :prov";
            $params[':prov'] = MiCorreoClient::provinciaCodigo((string)$data['dest_provincia']);
        }
        if (!empty($set)) {
            $this->db->prepare("UPDATE envios SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
        }
        return $this->getByPedido($pedidoId);
    }

    /**
     * Crea la orden en MiCorreo (POST /shipping/import) con los datos del
     * pedido. Es idempotente: extOrderId es fijo por pedido, asi que un
     * reintento no duplica la orden (la API responde 409 y se toma como ya
     * importada).
     */
    public function importarACorreo(int $pedidoId, ?int $usuarioId): array {
        $envio = $this->getByPedido($pedidoId, false);
        if (!$envio) {
            throw new RuntimeException("El pedido #{$pedidoId} no tiene envío registrado.");
        }
        if ($envio['importado_at'] !== null) {
            throw new RuntimeException('Este envío ya fue importado en MiCorreo (' . $envio['ext_order_id'] . ').');
        }
        if (in_array($envio['estado'], self::ESTADOS_FINALES, true)) {
            throw new RuntimeException('El envío está ' . self::ESTADOS[$envio['estado']] . ': no se puede importar.');
        }

        $pedido = (new PedidoService())->getById($pedidoId);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$pedidoId} no encontrado.");
        }
        if (!in_array($pedido['estado'], ['aprobado', 'enviado'], true)) {
            throw new RuntimeException('El pedido tiene que estar aprobado (pagado) antes de generar el envío.');
        }

        $config  = (new ConfigService())->getEnvioConfig();
        $cliente = new MiCorreoClient($config['ambiente']);
        if (!$cliente->configurado()) {
            throw new RuntimeException('Correo Argentino sin credenciales en el .env.');
        }

        $orden      = $this->armarOrdenMiCorreo($pedido, $envio, $config);
        $extOrderId = $orden['extOrderId'];

        try {
            $respuesta = $cliente->importarEnvio($orden);
        } catch (MiCorreoException $e) {
            // Ya estaba importada (un intento anterior que no llego a guardarse).
            // Segun la doc oficial viene como 402 "La orden ya fue importada con
            // anterioridad"; 409 se contempla por si cambia.
            $yaImportada = $e->httpCode === 409
                || ($e->httpCode === 402 && stripos($e->getMessage(), 'ya fue importada') !== false);
            if (!$yaImportada) throw $e;
            $respuesta = ['message' => 'Orden ya importada previamente.', 'conflict' => true];
        }

        // La doc oficial solo devuelve createdAt; si algun dia viene el tracking, se guarda.
        $tracking = self::nullSiVacio((string)($respuesta['trackingNumber'] ?? $respuesta['tracking'] ?? ''));

        $this->db->prepare(
            "UPDATE envios
             SET ext_order_id = :ext, importado_at = NOW(), importado_respuesta = :resp,
                 tracking_codigo = COALESCE(:tracking, tracking_codigo),
                 -- MySQL evalua el SET en orden y con los valores ya asignados:
                 -- estado_at tiene que ir antes de cambiar estado.
                 estado_at = IF(estado = 'pendiente', NOW(), estado_at),
                 estado = IF(estado = 'pendiente', 'preparando', estado)
             WHERE id = :id"
        )->execute([
            ':ext'      => $extOrderId,
            ':resp'     => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
            ':tracking' => $tracking,
            ':id'       => $envio['id'],
        ]);

        $detalle = "Orden importada en MiCorreo ({$config['ambiente']}) como {$extOrderId}."
                 . ($tracking ? " Tracking {$tracking}." : ' La etiqueta y el tracking se generan desde el panel de MiCorreo.');
        $this->registrarHistorial((int)$envio['id'], $envio['estado'] === 'pendiente' ? 'preparando' : $envio['estado'], $detalle, 'correo', $usuarioId);

        if ($tracking) {
            $this->propagarTrackingAlPedido($pedidoId, $tracking, $envio);
        }

        return $this->getByPedido($pedidoId);
    }

    private function armarOrdenMiCorreo(array $pedido, array $envio, array $config): array {
        [$calle, $numero, $piso] = $this->direccionEstructurada($envio, $pedido);
        $provincia = $envio['provincia_codigo'] ?: MiCorreoClient::provinciaCodigo((string)$envio['dest_provincia']);

        $tipo = $envio['tipo_entrega'] === 'sucursal' ? MiCorreoClient::ENTREGA_SUCURSAL : MiCorreoClient::ENTREGA_DOMICILIO;

        $shipping = [
            'deliveryType'  => $tipo,
            'weight'        => max(1, (int)$envio['peso_gramos'] ?: $config['bulto_default']['peso_gramos']),
            'declaredValue' => round((float)$pedido['total'] - (float)$pedido['envio_costo'], 2),
            'height'        => max(1, (int)$envio['alto_cm']  ?: $config['bulto_default']['alto_cm']),
            'length'        => max(1, (int)$envio['largo_cm'] ?: $config['bulto_default']['largo_cm']),
            'width'         => max(1, (int)$envio['ancho_cm'] ?: $config['bulto_default']['ancho_cm']),
        ];

        if ($tipo === MiCorreoClient::ENTREGA_SUCURSAL) {
            if (empty($envio['sucursal_codigo'])) {
                throw new RuntimeException('El envío es a sucursal pero no tiene sucursal elegida.');
            }
            $shipping['agency'] = (string)$envio['sucursal_codigo'];
        } else {
            if ($calle === '' || $numero === '') {
                throw new RuntimeException('No se pudo separar calle y número de la dirección. Completalos en "Datos de destino" y volvé a intentar.');
            }
            if ($provincia === null) {
                throw new RuntimeException('No se reconoce la provincia de destino. Corregila en "Datos de destino".');
            }
            $ciudad = (string)($envio['dest_ciudad'] ?: $this->ciudadDesdeDireccion((string)$pedido['cliente_direccion']));
            if ($ciudad === '') {
                throw new RuntimeException('Falta la ciudad de destino. Completala en "Datos de destino".');
            }
            // Piso y depto: la API los corta a 3 caracteres.
            $shipping['address'] = [
                'streetName'   => $calle,
                'streetNumber' => $numero,
                'floor'        => mb_substr($piso['piso'],  0, 3),
                'apartment'    => mb_substr($piso['depto'], 0, 3),
                'city'         => $ciudad,
                'provinceCode' => $provincia,
                'postalCode'   => MiCorreoClient::cp((string)$envio['cp_destino']),
            ];
        }

        $telefono = preg_replace('/[^\d+]/', '', (string)($pedido['cliente_telefono'] ?? ''));

        return [
            'extOrderId'  => 'KABODHI-' . (int)$pedido['id'],
            'orderNumber' => (string)(int)$pedido['id'],
            'recipient'   => [
                'name'      => (string)$pedido['cliente_nombre'],
                'email'     => (string)$pedido['cliente_email'],
                'phone'     => $telefono,
                'cellPhone' => $telefono,
            ],
            'shipping'    => $shipping,
        ];
    }

    /**
     * Calle, numero y piso/depto. Usa los campos estructurados si estan; si
     * no (pedidos anteriores a la integracion), intenta separarlos del texto
     * "Av. Santa Fe 1234, Piso 3, Depto A, Ciudad, Provincia, CP".
     */
    private function direccionEstructurada(array $envio, array $pedido): array {
        $calle  = trim((string)($envio['dest_calle']  ?? ''));
        $numero = trim((string)($envio['dest_numero'] ?? ''));
        $extra  = trim((string)($envio['dest_piso_depto'] ?? ''));

        if ($calle === '' || $numero === '') {
            $partes = array_map('trim', explode(',', (string)($pedido['cliente_direccion'] ?? '')));
            $primera = $partes[0] ?? '';
            if (preg_match('/^(.*?)\s+(\d+[a-zA-Z]?)\s*(.*)$/u', $primera, $m)) {
                $calle  = $calle  !== '' ? $calle  : trim($m[1]);
                $numero = $numero !== '' ? $numero : $m[2];
                if ($extra === '') $extra = trim($m[3]);
            }
            // Las partes siguientes que hablen de piso/depto tambien suman.
            if ($extra === '') {
                foreach (array_slice($partes, 1) as $p) {
                    if (preg_match('/piso|dpto|depto|dto|of\b|local/iu', $p)) $extra .= ($extra ? ' ' : '') . $p;
                }
            }
        }

        return [$calle, $numero, self::pisoYDepto($extra)];
    }

    /**
     * "Piso 3, Depto A" / "3° B" / "3 B" / "PB" / "Dto 5" -> ['piso'=>..,'depto'=>..].
     * Lo que no se entiende va entero como depto (Correo lo muestra en la etiqueta).
     */
    public static function pisoYDepto(string $extra): array {
        $piso  = ['piso' => '', 'depto' => ''];
        $extra = trim($extra);
        if ($extra === '') return $piso;

        if (preg_match('/piso\s*(pb|[0-9]{1,2}|[a-z])\b/iu', $extra, $m)) $piso['piso'] = strtoupper($m[1]);
        if (preg_match('/(?:dpto|depto|dto|departamento|depart)\.?\s*([0-9a-z]{1,4})\b/iu', $extra, $m)) $piso['depto'] = strtoupper($m[1]);

        // "3° B", "3 B", "3ºB", "PB A", "12-C"
        if ($piso['piso'] === '' && $piso['depto'] === ''
            && preg_match('/^(pb|[0-9]{1,2})\s*[°ºo]?\s*[,\-\/]?\s*([a-z0-9]{1,4})?\s*$/iu', $extra, $m)) {
            $piso['piso']  = strtoupper($m[1]);
            $piso['depto'] = isset($m[2]) ? strtoupper($m[2]) : '';
        }

        if ($piso['piso'] === '' && $piso['depto'] === '') $piso['depto'] = mb_substr($extra, 0, 20);
        return $piso;
    }

    private function ciudadDesdeDireccion(string $direccion): string {
        // "..., Ciudad, Provincia, CP": la ciudad es la antepenultima parte.
        $partes = array_map('trim', explode(',', $direccion));
        $n = count($partes);
        return $n >= 3 ? $partes[$n - 3] : '';
    }

    /** Copia el tracking a pedidos.* (mails, remito y CSV leen de ahi). */
    private function propagarTrackingAlPedido(int $pedidoId, string $tracking, array $envio): void {
        $url = $this->urlSeguimiento(array_merge($envio, ['tracking_codigo' => $tracking, 'tracking_url' => null]));
        $this->db->prepare(
            "UPDATE pedidos
             SET transporte = COALESCE(transporte, 'Correo Argentino'),
                 tracking_codigo = :codigo,
                 tracking_url = COALESCE(tracking_url, :url)
             WHERE id = :id"
        )->execute([':codigo' => $tracking, ':url' => $url, ':id' => $pedidoId]);
        $this->actualizarTracking($pedidoId, $tracking, $url);
    }

    /** URL publica de seguimiento: la cargada a mano o la de Correo con el codigo. */
    public function urlSeguimiento(array $envio): ?string {
        if (!empty($envio['tracking_url'])) return $envio['tracking_url'];
        $codigo = trim((string)($envio['tracking_codigo'] ?? ''));
        if ($codigo === '' || ($envio['proveedor'] ?? '') !== 'correo') return null;

        // Se lee una vez por request: el historial la usa fila por fila.
        static $plantilla = null;
        if ($plantilla === null) {
            $plantilla = (string)(new ConfigService())->get('correo_tracking_url', '');
        }
        if ($plantilla === '') return null;
        return str_replace('{codigo}', rawurlencode($codigo), $plantilla);
    }

    private function registrarHistorial(int $envioId, string $estado, ?string $detalle, string $origen, ?int $usuarioId): void {
        if (!in_array($origen, ['sistema', 'panel', 'correo'], true)) $origen = 'sistema';
        $this->db->prepare(
            "INSERT INTO envio_historial (envio_id, estado, detalle, origen, usuario_id)
             VALUES (:envio_id, :estado, :detalle, :origen, :usuario_id)"
        )->execute([
            ':envio_id'   => $envioId,
            ':estado'     => $estado,
            ':detalle'    => $detalle !== null ? mb_substr($detalle, 0, 500) : null,
            ':origen'     => $origen,
            ':usuario_id' => $usuarioId,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function nombreOpcionCorreo(string $entrega, string $producto, string $nombreApi): string {
        $servicio = $producto === MiCorreoClient::PRODUCTO_EXPRESO ? 'Expreso'
                  : ($producto === MiCorreoClient::PRODUCTO_CLASICO ? 'Clásico' : ($nombreApi ?: 'Paq.ar'));
        $donde = $entrega === MiCorreoClient::ENTREGA_SUCURSAL ? 'retiro en sucursal' : 'a domicilio';
        return "Correo Argentino {$servicio} — {$donde}";
    }

    private static function dias($valor): ?int {
        if ($valor === null || $valor === '') return null;
        $n = (int)preg_replace('/\D+/', '', (string)$valor);
        return $n > 0 ? $n : null;
    }

    private static function textoPlazo(?int $min, ?int $max): ?string {
        if ($min === null && $max === null) return null;
        if ($min !== null && $max !== null && $min !== $max) return "{$min} a {$max} días hábiles";
        $n = $max ?? $min;
        return $n === 1 ? '1 día hábil' : "{$n} días hábiles";
    }

    private static function fechaSql($iso): ?string {
        if (empty($iso)) return null;
        $ts = strtotime((string)$iso);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private static function nullSiVacio($valor): ?string {
        $texto = trim((string)$valor);
        return $texto === '' ? null : $texto;
    }
}
