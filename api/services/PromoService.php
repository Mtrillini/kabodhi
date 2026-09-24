<?php

/**
 * "Arma tu combo": el cliente elige N productos (de una lista que arma el
 * admin) y paga un precio fijo. Esta clase resuelve dos cosas separadas:
 *
 *   1. El ABM del panel (getAll/getById/create/update/delete).
 *   2. Convertir un combo elegido en el carrito en items normales de pedido
 *      (validarYExpandir), que es lo que usa PedidoService al crear el
 *      pedido. De ahi en mas el combo no existe: son productos sueltos con
 *      su producto_id y variante_id de siempre.
 */
class PromoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** Publico: la tienda solo ve las activas. El panel pide todas. */
    public function getAll(bool $incluirInactivas = false): array {
        $sql = "SELECT * FROM promos" . ($incluirInactivas ? '' : ' WHERE activo = 1')
             . ' ORDER BY orden ASC, id ASC';
        $promos = $this->db->query($sql)->fetchAll();
        if (empty($promos)) return [];

        $ids = array_column($promos, 'id');
        $productosPorPromo = $this->productosPorPromo($ids);
        foreach ($promos as &$p) {
            $p['producto_ids'] = $productosPorPromo[(int)$p['id']] ?? [];
        }
        unset($p);
        return $promos;
    }

    public function getById(int $id, bool $incluirInactivas = true): ?array {
        $sql = "SELECT * FROM promos WHERE id = :id" . ($incluirInactivas ? '' : ' AND activo = 1');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $promo = $stmt->fetch();
        if ($promo === false) return null;

        $productos = $this->productosPorPromo([$id]);
        $promo['producto_ids'] = $productos[$id] ?? [];
        return $promo;
    }

    /** producto_id activos por promo_id, para no ofrecer un producto dado de baja. */
    private function productosPorPromo(array $promoIds): array {
        $promoIds = array_values(array_unique(array_map('intval', $promoIds)));
        if (empty($promoIds)) return [];

        $marcas = implode(',', array_fill(0, count($promoIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT pp.promo_id, pp.producto_id
             FROM promo_productos pp
             INNER JOIN productos pr ON pr.id = pp.producto_id AND pr.activo = 1
             WHERE pp.promo_id IN ({$marcas})"
        );
        $stmt->execute($promoIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['promo_id']][] = (int)$row['producto_id'];
        }
        return $out;
    }

    public function create(array $data): array {
        if ($error = $this->validar($data)) {
            throw new InvalidArgumentException($error);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO promos (nombre, descripcion, cantidad_items, precio, imagen_url, activo, orden)
             VALUES (:nombre, :descripcion, :cantidad_items, :precio, :imagen_url, :activo, :orden)"
        );
        $orden = isset($data['orden'])
            ? (int)$data['orden']
            : (int)$this->db->query("SELECT COALESCE(MAX(orden), 0) + 1 FROM promos")->fetchColumn();

        $stmt->execute([
            ':nombre'         => trim($data['nombre']),
            ':descripcion'    => self::nullSiVacio($data['descripcion'] ?? ''),
            ':cantidad_items' => (int)$data['cantidad_items'],
            ':precio'         => (float)$data['precio'],
            ':imagen_url'     => self::nullSiVacio($data['imagen_url'] ?? ''),
            ':activo'         => isset($data['activo']) ? (int)$data['activo'] : 1,
            ':orden'          => $orden,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->syncProductos($id, (array)($data['producto_ids'] ?? []));

        return $this->getById($id);
    }

    public function update(int $id, array $data): ?array {
        $completo = array_key_exists('nombre', $data) || array_key_exists('cantidad_items', $data);
        if ($completo && ($error = $this->validar($data))) {
            throw new InvalidArgumentException($error);
        }

        $allowed = ['nombre', 'descripcion', 'cantidad_items', 'precio', 'imagen_url', 'activo', 'orden'];
        $campos  = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;
            $campos[] = "`{$field}` = :{$field}";
            $params[":{$field}"] = match ($field) {
                'descripcion', 'imagen_url' => self::nullSiVacio($data[$field]),
                'cantidad_items', 'activo', 'orden' => (int)$data[$field],
                'precio' => (float)$data[$field],
                default  => trim((string)$data[$field]),
            };
        }
        if ($campos) {
            $this->db->prepare("UPDATE promos SET " . implode(', ', $campos) . " WHERE id = :id")
                     ->execute($params);
        }

        // Solo si el panel mando la lista: un PUT parcial (por ej. solo
        // "activo") no tiene que vaciar los productos elegibles.
        if (array_key_exists('producto_ids', $data)) {
            $this->syncProductos($id, (array)$data['producto_ids']);
        }

        return $this->getById($id);
    }

    public function delete(int $id): bool {
        // pedido_items.promo_id es ON DELETE SET NULL: un combo vendido se
        // puede borrar igual, el pedido queda con la copia del nombre.
        $stmt = $this->db->prepare("DELETE FROM promos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function syncProductos(int $promoId, array $productoIds): void {
        $this->db->prepare("DELETE FROM promo_productos WHERE promo_id = :id")->execute([':id' => $promoId]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $productoIds), fn($v) => $v > 0)));
        if (empty($ids)) return;

        $stmt = $this->db->prepare("INSERT INTO promo_productos (promo_id, producto_id) VALUES (:promo_id, :producto_id)");
        foreach ($ids as $productoId) {
            $stmt->execute([':promo_id' => $promoId, ':producto_id' => $productoId]);
        }
    }

    private function validar(array $data): ?string {
        if (trim($data['nombre'] ?? '') === '') {
            return 'Poné un nombre para el combo.';
        }
        $cantidad = (int)($data['cantidad_items'] ?? 0);
        if ($cantidad < 2 || $cantidad > 20) {
            return 'La cantidad de productos a elegir tiene que ser entre 2 y 20.';
        }
        if (!isset($data['precio']) || (float)$data['precio'] <= 0) {
            return 'Ingresá un precio válido para el combo.';
        }
        $productoIds = array_filter((array)($data['producto_ids'] ?? []));
        if (count($productoIds) < $cantidad) {
            return "Elegí al menos {$cantidad} productos para que el combo se pueda completar.";
        }
        return null;
    }

    private static function nullSiVacio($valor): ?string {
        $texto = trim((string)($valor ?? ''));
        return $texto === '' ? null : $texto;
    }

    // =================================================================
    // Uso en el checkout: abrir un combo elegido en items normales
    // =================================================================

    /**
     * Convierte un item de carrito de tipo "promo" (el combo + que producto
     * y opcion eligio el cliente en cada uno de los N lugares) en items
     * normales de pedido, listos para el resto de PedidoService::crear().
     *
     * El precio del combo se reparte entre los N productos elegidos (el
     * ultimo se lleva el redondeo, para que la suma de cierre exacto con el
     * precio del combo). Si el cliente eligio el mismo producto+opcion mas
     * de una vez, se junta en una sola linea con cantidad>1: asi el stock se
     * reserva una sola vez por esa linea y no se puede pisar a si mismo.
     *
     * @throws InvalidArgumentException datos del cliente invalidos (elegir
     *         mal, sin opcion, etc. — mensaje pensado para mostrarselo)
     * @throws RuntimeException el combo o algo elegido ya no esta disponible
     */
    public function validarYExpandir(array $item): array {
        $promoId = (int)($item['promo_id'] ?? 0);
        $picks   = $item['picks'] ?? [];

        if ($promoId <= 0) {
            throw new InvalidArgumentException('Combo inválido.');
        }
        $promo = $this->getById($promoId, false);
        if (!$promo) {
            throw new RuntimeException('Este combo ya no está disponible.');
        }

        $n = (int)$promo['cantidad_items'];
        if (!is_array($picks) || count($picks) !== $n) {
            throw new InvalidArgumentException("El combo \"{$promo['nombre']}\" necesita que elijas {$n} productos.");
        }

        $elegibles = array_fill_keys($promo['producto_ids'], true);
        $productoService = new ProductoService();

        // Reparto del precio: floor a centavos, el ultimo pick se lleva lo
        // que falta para llegar exacto al precio del combo.
        $precioPorPick = floor(((float)$promo['precio'] / $n) * 100) / 100;
        $resto         = round((float)$promo['precio'] - $precioPorPick * $n, 2);

        $expandidos = [];
        foreach (array_values($picks) as $i => $pick) {
            $productoId = (int)($pick['producto_id'] ?? 0);
            if ($productoId <= 0 || !isset($elegibles[$productoId])) {
                throw new InvalidArgumentException("Ese producto no forma parte del combo \"{$promo['nombre']}\".");
            }
            $producto = $productoService->getById($productoId, false);
            if (!$producto) {
                throw new RuntimeException('Uno de los productos del combo ya no está disponible.');
            }

            $variante = null;
            if (!empty($producto['variantes'])) {
                $varianteId = (int)($pick['variante_id'] ?? 0);
                if ($varianteId <= 0) {
                    throw new InvalidArgumentException("Elegí una opción para \"{$producto['nombre']}\" dentro del combo \"{$promo['nombre']}\".");
                }
                foreach ($producto['variantes'] as $v) {
                    if ((int)$v['id'] === $varianteId) { $variante = $v; break; }
                }
                if ($variante === null) {
                    throw new RuntimeException("La opción elegida para \"{$producto['nombre']}\" dentro del combo ya no está disponible.");
                }
            }

            $precioPick = $precioPorPick + ($i === $n - 1 ? $resto : 0);

            $expandidos[] = [
                'id'          => $productoId,
                'variante_id' => $variante !== null ? (int)$variante['id'] : null,
                'precio_pick' => $precioPick,
            ];
        }

        // Agrupa picks repetidos (mismo producto + misma opcion): sin esto,
        // dos lineas de cantidad 1 sobre el mismo producto verificarian
        // stock por separado contra el mismo numero disponible, y la
        // segunda reserva fallaria recien al confirmar el pedido en vez de
        // avisar antes.
        $agrupados = [];
        foreach ($expandidos as $e) {
            $clave = $e['id'] . ':' . ($e['variante_id'] ?? 0);
            if (!isset($agrupados[$clave])) {
                $agrupados[$clave] = [
                    'id' => $e['id'], 'variante_id' => $e['variante_id'],
                    'cantidad' => 0, 'suma_precio' => 0.0,
                ];
            }
            $agrupados[$clave]['cantidad']++;
            $agrupados[$clave]['suma_precio'] += $e['precio_pick'];
        }

        $resultado = [];
        foreach (array_values($agrupados) as $a) {
            $resultado[] = [
                'id'              => $a['id'],
                'variante_id'     => $a['variante_id'],
                'cantidad'        => $a['cantidad'],
                'precio_unitario' => round($a['suma_precio'] / $a['cantidad'], 2),
                'promo_id'        => $promoId,
                'promo_nombre'    => $promo['nombre'],
            ];
        }
        return $resultado;
    }
}
