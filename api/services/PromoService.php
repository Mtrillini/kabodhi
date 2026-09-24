<?php

/**
 * Combos: el admin arma un combo con una lista fija de productos y le pone
 * un precio unico, mas barato que comprarlos sueltos. El cliente no elige
 * que productos entran en el combo (eso ya lo decidio el admin) — solo
 * elige la fragancia/opcion de cada producto, si ese producto tiene
 * variantes. Esta clase resuelve dos cosas separadas:
 *
 *   1. El ABM del panel (getAll/getById/create/update/delete).
 *   2. Convertir un combo agregado al carrito en items normales de pedido
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
             WHERE pp.promo_id IN ({$marcas})
             ORDER BY pp.id ASC"
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
            ':cantidad_items' => self::contarProductos($data['producto_ids'] ?? []),
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
        // Un PUT parcial (por ej. solo "activo", desde el toggle de la
        // tabla) no manda producto_ids ni pasa por la validacion completa.
        $completo = array_key_exists('nombre', $data) || array_key_exists('producto_ids', $data);
        if ($completo && ($error = $this->validar($data))) {
            throw new InvalidArgumentException($error);
        }

        $allowed = ['nombre', 'descripcion', 'precio', 'imagen_url', 'activo', 'orden'];
        $campos  = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;
            $campos[] = "`{$field}` = :{$field}";
            $params[":{$field}"] = match ($field) {
                'descripcion', 'imagen_url' => self::nullSiVacio($data[$field]),
                'activo', 'orden' => (int)$data[$field],
                'precio' => (float)$data[$field],
                default  => trim((string)$data[$field]),
            };
        }
        // cantidad_items es siempre la cuenta real de producto_ids: nunca se
        // confia en un numero mandado aparte, asi no se puede desincronizar.
        if (array_key_exists('producto_ids', $data)) {
            $campos[] = "`cantidad_items` = :cantidad_items";
            $params[':cantidad_items'] = self::contarProductos($data['producto_ids']);
        }
        if ($campos) {
            $this->db->prepare("UPDATE promos SET " . implode(', ', $campos) . " WHERE id = :id")
                     ->execute($params);
        }

        if (array_key_exists('producto_ids', $data)) {
            $this->syncProductos($id, (array)$data['producto_ids']);
        }

        return $this->getById($id);
    }

    private static function contarProductos($productoIds): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$productoIds), fn($v) => $v > 0)));
        return count($ids);
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
        if (!isset($data['precio']) || (float)$data['precio'] <= 0) {
            return 'Ingresá un precio válido para el combo.';
        }
        $productoIds = array_filter((array)($data['producto_ids'] ?? []));
        if (count($productoIds) < 2) {
            return 'Elegí al menos 2 productos para armar el combo.';
        }
        if (count($productoIds) > 20) {
            return 'Un combo no puede tener más de 20 productos.';
        }
        return null;
    }

    private static function nullSiVacio($valor): ?string {
        $texto = trim((string)($valor ?? ''));
        return $texto === '' ? null : $texto;
    }

    // =================================================================
    // Uso en el checkout: abrir un combo en items normales del pedido
    // =================================================================

    /**
     * Convierte un item de carrito de tipo "promo" (solo identifica el combo
     * + las opciones/fragancias elegidas para cada producto) en items
     * normales de pedido, listos para PedidoService::crear().
     *
     * El cliente NO eligio que productos entran en el combo (el admin ya lo
     * decidio). Solo eligio la fragancia/opcion de cada producto, si eso
     * corresponde. El precio del combo se reparte en partes iguales entre
     * todos los productos (el ultimo se lleva el redondeo si queda fraccion).
     *
     * @throws InvalidArgumentException datos invalidos (fragancia mal elegida,
     *         etc. — mensaje pensado para el cliente)
     * @throws RuntimeException combo o algo elegido ya no disponible
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
            throw new InvalidArgumentException("Hay un problema con este combo: esperábamos {$n} productos pero llegó diferente. Recargá la página.");
        }

        // Los productos del combo son fijos (los eligio el admin, no el
        // cliente): el conjunto de producto_id que llega tiene que ser
        // exactamente el mismo que el del combo, ni mas ni menos, para que
        // nadie pueda colar un producto mas caro al precio del combo.
        $picksPorProducto = [];
        foreach ($picks as $pick) {
            $pid = (int)($pick['producto_id'] ?? 0);
            if ($pid > 0) $picksPorProducto[$pid] = $pick;
        }
        $enviados = $picksPorProducto ? array_keys($picksPorProducto) : [];
        sort($enviados);
        $fijos = $promo['producto_ids'];
        sort($fijos);
        if ($enviados !== $fijos) {
            throw new InvalidArgumentException("Este combo cambió. Recargá la página y volvé a agregarlo al carrito.");
        }

        $productoService = new ProductoService();

        // Reparto del precio: cada producto paga precio_total / N,
        // el ultimo se lleva el redondeo si hay fraccion (para que cierre exacto).
        $precioPorProducto = floor(((float)$promo['precio'] / $n) * 100) / 100;
        $resto             = round((float)$promo['precio'] - $precioPorProducto * $n, 2);

        $expandidos = [];
        foreach (array_values($promo['producto_ids']) as $i => $productoId) {
            $pick = $picksPorProducto[$productoId];
            $producto = $productoService->getById($productoId, false);
            if (!$producto) {
                throw new RuntimeException("Uno de los productos del combo ya no está disponible.");
            }

            $variante = null;
            if (!empty($producto['variantes'])) {
                $varianteId = (int)($pick['variante_id'] ?? 0);
                if ($varianteId <= 0) {
                    throw new InvalidArgumentException("Elegí una fragancia/opción para \"{$producto['nombre']}\".");
                }
                foreach ($producto['variantes'] as $v) {
                    if ((int)$v['id'] === $varianteId) { $variante = $v; break; }
                }
                if ($variante === null) {
                    throw new RuntimeException("La fragancia/opción elegida para \"{$producto['nombre']}\" ya no existe.");
                }
            }

            $precioPick = $precioPorProducto + ($i === $n - 1 ? $resto : 0);

            $expandidos[] = [
                'id'          => $productoId,
                'variante_id' => $variante !== null ? (int)$variante['id'] : null,
                'precio_pick' => $precioPick,
            ];
        }

        // Cada producto del combo es unico (asi lo garantiza syncProductos),
        // asi que no hace falta agrupar duplicados: una linea por producto.
        $resultado = [];
        foreach ($expandidos as $e) {
            $resultado[] = [
                'id'              => $e['id'],
                'variante_id'     => $e['variante_id'],
                'cantidad'        => 1,
                'precio_unitario' => round($e['precio_pick'], 2),
                'promo_id'        => $promoId,
                'promo_nombre'    => $promo['nombre'],
            ];
        }
        return $resultado;
    }
}
