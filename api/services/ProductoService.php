<?php

class ProductoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // $incluirInactivos: solo lo usa el panel admin, para poder ver y reactivar
    // productos dados de baja. La tienda publica siempre recibe activo = 1.
    public function getAll(?string $categoria = null, ?string $tipo = null, ?string $search = null, ?bool $destacado = null, bool $incluirInactivos = false): array {
        $sql = "SELECT p.*, c.nombre AS categoria_nombre, c.slug AS categoria_slug,
                       GREATEST(0, p.stock - p.stock_reservado) AS stock_disponible
                FROM productos p
                INNER JOIN categorias c ON p.categoria_id = c.id
                WHERE 1 = 1";
        $params = [];

        if (!$incluirInactivos) {
            $sql .= " AND p.activo = 1";
        }

        if ($categoria !== null && $categoria !== '') {
            $sql .= " AND c.slug = :categoria";
            $params[':categoria'] = $categoria;
        }
        if ($tipo !== null && $tipo !== '') {
            $sql .= " AND p.tipo = :tipo";
            $params[':tipo'] = $tipo;
        }
        if ($search !== null && $search !== '') {
            // Un placeholder por columna: con prepares nativos (EMULATE_PREPARES
            // = false) no se puede reusar el mismo nombre dos veces.
            $sql .= " AND (p.nombre LIKE :search_nombre
                        OR p.descripcion LIKE :search_desc
                        OR p.nota_olfativa LIKE :search_nota)";
            $termino = '%' . $search . '%';
            $params[':search_nombre'] = $termino;
            $params[':search_desc']   = $termino;
            $params[':search_nota']   = $termino;
        }
        if ($destacado !== null) {
            $sql .= " AND p.destacado = :destacado";
            $params[':destacado'] = $destacado ? 1 : 0;
        }

        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll();

        // Attach imagenes to each product
        if (!empty($productos)) {
            $ids = implode(',', array_column($productos, 'id'));
            $imgStmt = $this->db->query(
                "SELECT producto_id, id, url, orden FROM producto_imagenes
                 WHERE producto_id IN ($ids) ORDER BY orden ASC, id ASC"
            );
            $allImgs = $imgStmt->fetchAll();

            $imgMap = [];
            foreach ($allImgs as $img) {
                $imgMap[$img['producto_id']][] = ['id' => $img['id'], 'url' => $img['url']];
            }

            // La tienda solo ve las opciones publicadas; el panel las ve todas.
            $varMap = $this->variantesPorProducto(array_column($productos, 'id'), !$incluirInactivos);

            foreach ($productos as &$p) {
                $p['imagenes']  = $imgMap[$p['id']] ?? [];
                $p['variantes'] = $varMap[$p['id']] ?? [];
                $p = self::conStockDeVariantes($p);
            }
            unset($p);
        }

        return $productos;
    }

    /**
     * @param bool $incluirInactivos El panel necesita ver los dados de baja;
     *                               la compra NO, o se venderia un producto
     *                               discontinuado que ya no figura en la tienda.
     */
    public function getById(int $id, bool $incluirInactivos = true): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria_nombre, c.slug AS categoria_slug,
                    GREATEST(0, p.stock - p.stock_reservado) AS stock_disponible
             FROM productos p
             INNER JOIN categorias c ON p.categoria_id = c.id
             WHERE p.id = :id" . ($incluirInactivos ? "" : " AND p.activo = 1")
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        if ($result === false) return null;

        $imgStmt = $this->db->prepare(
            "SELECT id, url, orden FROM producto_imagenes WHERE producto_id = :id ORDER BY orden ASC, id ASC"
        );
        $imgStmt->execute([':id' => $id]);
        $result['imagenes'] = $imgStmt->fetchAll();

        $variantes = $this->variantesPorProducto([$id], !$incluirInactivos);
        $result['variantes'] = $variantes[$id] ?? [];

        return self::conStockDeVariantes($result);
    }

    /**
     * Variantes de varios productos, indexadas por producto_id.
     *
     * @param bool $soloActivas la tienda publica no debe ofrecer una opcion
     *                          dada de baja; el panel si tiene que verla.
     */
    private function variantesPorProducto(array $productoIds, bool $soloActivas): array {
        $productoIds = array_values(array_unique(array_map('intval', $productoIds)));
        if (empty($productoIds)) return [];

        $marcas = implode(',', array_fill(0, count($productoIds), '?'));
        $sql = "SELECT id, producto_id, nombre, sku, precio, stock, stock_reservado, imagen_url,
                       peso_gramos, alto_cm, ancho_cm, largo_cm, activo, orden,
                       GREATEST(0, stock - stock_reservado) AS stock_disponible
                FROM producto_variantes
                WHERE producto_id IN ({$marcas})"
             . ($soloActivas ? " AND activo = 1" : "")
             . " ORDER BY orden ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($productoIds);

        $out = [];
        foreach ($stmt->fetchAll() as $v) {
            $out[(int)$v['producto_id']][] = $v;
        }
        return $out;
    }

    /**
     * Con variantes, el stock del producto es la suma de las opciones: es lo
     * unico que la tienda y el panel tienen que mirar, y evita que alguien
     * edite `productos.stock` creyendo que se vende de ahi.
     */
    private static function conStockDeVariantes(array $producto): array {
        if (empty($producto['variantes'])) {
            return $producto;
        }
        $stock = 0; $reservado = 0; $disponible = 0;
        foreach ($producto['variantes'] as $v) {
            $stock      += (int)$v['stock'];
            $reservado  += (int)$v['stock_reservado'];
            $disponible += (int)$v['stock_disponible'];
        }
        $producto['stock']            = $stock;
        $producto['stock_reservado']  = $reservado;
        $producto['stock_disponible'] = $disponible;
        return $producto;
    }

    public function create(array $data): array {
        $imagenes  = $data['imagenes'] ?? [];
        $imagenUrl = !empty($imagenes) ? $imagenes[0] : ($data['imagen_url'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO productos (categoria_id, marca, nombre, descripcion, nota_olfativa, precio, stock, imagen_url, tipo, activo, destacado,
                                    peso_gramos, alto_cm, ancho_cm, largo_cm)
             VALUES (:categoria_id, :marca, :nombre, :descripcion, :nota_olfativa, :precio, :stock, :imagen_url, :tipo, :activo, :destacado,
                     :peso_gramos, :alto_cm, :ancho_cm, :largo_cm)"
        );
        $stmt->execute([
            ':categoria_id'  => $data['categoria_id'],
            ':marca'         => $data['marca']         ?? null,
            ':nombre'        => $data['nombre'],
            ':descripcion'   => $data['descripcion']   ?? null,
            ':nota_olfativa' => $data['nota_olfativa'] ?? null,
            ':precio'        => $data['precio'],
            ':stock'         => $data['stock']         ?? 0,
            ':imagen_url'    => $imagenUrl,
            ':tipo'          => $data['tipo']          ?? 'enfoque',
            ':activo'        => isset($data['activo'])    ? (int)$data['activo']    : 1,
            ':destacado'     => isset($data['destacado']) ? (int)$data['destacado'] : 0,
            // Bulto para cotizar el envio; vacio = usa el default de configuracion.
            ':peso_gramos'   => self::medida($data['peso_gramos'] ?? null),
            ':alto_cm'       => self::medida($data['alto_cm']     ?? null),
            ':ancho_cm'      => self::medida($data['ancho_cm']    ?? null),
            ':largo_cm'      => self::medida($data['largo_cm']    ?? null),
        ]);
        $id = (int)$this->db->lastInsertId();

        if (!empty($imagenes)) {
            $this->syncImagenes($id, $imagenes);
        }
        if (array_key_exists('variantes', $data)) {
            $this->syncVariantes($id, (array)$data['variantes']);
        }

        return $this->getById($id);
    }

    public function update(int $id, array $data): ?array {
        $fields = [];
        $params = [':id' => $id];

        $allowed = ['categoria_id','marca','nombre','descripcion','nota_olfativa','precio','stock','imagen_url','tipo','activo','destacado',
                    'peso_gramos','alto_cm','ancho_cm','largo_cm'];
        $medidas = ['peso_gramos','alto_cm','ancho_cm','largo_cm'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = in_array($field, $medidas, true) ? self::medida($data[$field]) : $data[$field];
            }
        }

        if (array_key_exists('imagenes', $data) && !empty($data['imagenes'])) {
            $fields[] = '`imagen_url` = :imagen_url_primary';
            $params[':imagen_url_primary'] = $data['imagenes'][0];
        }

        if (!empty($fields)) {
            $sql = "UPDATE productos SET " . implode(', ', $fields) . " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }

        if (array_key_exists('imagenes', $data)) {
            $this->syncImagenes($id, $data['imagenes']);
        }
        // Solo si el formulario las mando: un update parcial no debe borrarlas.
        if (array_key_exists('variantes', $data)) {
            $this->syncVariantes($id, (array)$data['variantes']);
        }

        return $this->getById($id);
    }

    /**
     * Guarda la lista de variantes tal como vino del panel: las que traen id
     * se actualizan, las nuevas se insertan y las que ya no estan se quitan.
     *
     * `stock_reservado` no se toca nunca desde aca: lo maneja StockService con
     * los pedidos pendientes.
     */
    private function syncVariantes(int $productoId, array $variantes): void {
        $stmt = $this->db->prepare("SELECT id FROM producto_variantes WHERE producto_id = :id");
        $stmt->execute([':id' => $productoId]);
        $existentes = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $columnas = "nombre = :nombre, sku = :sku, precio = :precio, stock = :stock,
                     imagen_url = :imagen_url, peso_gramos = :peso_gramos, alto_cm = :alto_cm,
                     ancho_cm = :ancho_cm, largo_cm = :largo_cm, activo = :activo, orden = :orden";

        $update = $this->db->prepare(
            "UPDATE producto_variantes SET {$columnas} WHERE id = :id AND producto_id = :producto_id"
        );
        $insert = $this->db->prepare(
            "INSERT INTO producto_variantes
                (producto_id, nombre, sku, precio, stock, imagen_url, peso_gramos, alto_cm, ancho_cm, largo_cm, activo, orden)
             VALUES
                (:producto_id, :nombre, :sku, :precio, :stock, :imagen_url, :peso_gramos, :alto_cm, :ancho_cm, :largo_cm, :activo, :orden)"
        );

        $vistas = [];
        foreach (array_values($variantes) as $i => $v) {
            $nombre = trim((string)($v['nombre'] ?? ''));
            if ($nombre === '') continue;   // fila vacia del formulario

            $campos = [
                ':nombre'      => mb_substr($nombre, 0, 120),
                ':sku'         => self::textoONull($v['sku'] ?? null, 60),
                ':precio'      => self::precioONull($v['precio'] ?? null),
                ':stock'       => max(0, (int)($v['stock'] ?? 0)),
                ':imagen_url'  => self::textoONull($v['imagen_url'] ?? null, 500),
                ':peso_gramos' => self::medida($v['peso_gramos'] ?? null),
                ':alto_cm'     => self::medida($v['alto_cm']     ?? null),
                ':ancho_cm'    => self::medida($v['ancho_cm']    ?? null),
                ':largo_cm'    => self::medida($v['largo_cm']    ?? null),
                ':activo'      => isset($v['activo']) ? (int)(bool)$v['activo'] : 1,
                ':orden'       => $i,
            ];

            $varianteId = (int)($v['id'] ?? 0);
            if ($varianteId > 0 && in_array($varianteId, $existentes, true)) {
                $update->execute($campos + [':id' => $varianteId, ':producto_id' => $productoId]);
                $vistas[] = $varianteId;
            } else {
                $insert->execute($campos + [':producto_id' => $productoId]);
                $vistas[] = (int)$this->db->lastInsertId();
            }
        }

        foreach (array_diff($existentes, $vistas) as $varianteId) {
            $this->quitarVariante((int)$varianteId);
        }
    }

    /**
     * Una variante que ya se vendio (o que tiene unidades reservadas por un
     * pedido pendiente) no se borra: se da de baja. Borrarla dejaria el
     * pedido sin referencia y liberaria una reserva que sigue viva.
     */
    private function quitarVariante(int $varianteId): void {
        $stmt = $this->db->prepare(
            "SELECT (SELECT COUNT(*) FROM pedido_items WHERE variante_id = v.id) AS vendida,
                    v.stock_reservado
             FROM producto_variantes v WHERE v.id = :id"
        );
        $stmt->execute([':id' => $varianteId]);
        $row = $stmt->fetch();
        if ($row === false) return;

        if ((int)$row['vendida'] > 0 || (int)$row['stock_reservado'] > 0) {
            $this->db->prepare("UPDATE producto_variantes SET activo = 0 WHERE id = :id")
                     ->execute([':id' => $varianteId]);
            return;
        }
        $this->db->prepare("DELETE FROM producto_variantes WHERE id = :id")
                 ->execute([':id' => $varianteId]);
    }

    /** Texto recortado, o null si vino vacio. */
    private static function textoONull($valor, int $max): ?string {
        $texto = trim((string)($valor ?? ''));
        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    /** Precio propio de la variante; vacio = hereda el del producto. */
    private static function precioONull($valor): ?float {
        if ($valor === null || $valor === '' || !is_numeric($valor)) return null;
        $n = (float)$valor;
        return $n > 0 ? $n : null;
    }

    /** Peso/medida: entero positivo o null (sin dato). */
    private static function medida($valor): ?int {
        if ($valor === null || $valor === '') return null;
        $n = (int)$valor;
        return $n > 0 ? $n : null;
    }

    private function syncImagenes(int $productoId, array $urls): void {
        $this->db->prepare("DELETE FROM producto_imagenes WHERE producto_id = :id")
                 ->execute([':id' => $productoId]);

        if (empty($urls)) return;

        $stmt = $this->db->prepare(
            "INSERT INTO producto_imagenes (producto_id, url, orden) VALUES (:pid, :url, :orden)"
        );
        foreach ($urls as $i => $url) {
            if ($url) $stmt->execute([':pid' => $productoId, ':url' => $url, ':orden' => $i]);
        }

        // Keep imagen_url in sync with first image
        $this->db->prepare("UPDATE productos SET imagen_url = :url WHERE id = :id")
                 ->execute([':url' => $urls[0], ':id' => $productoId]);
    }

    /**
     * Borra el producto de verdad si nunca se vendio.
     *
     * Si aparece en algun pedido no se puede: pedido_items lo referencia con
     * ON DELETE RESTRICT, y borrarlo destruiria el historial de esa venta. En
     * ese caso queda inactivo, que lo saca de la tienda sin romper los pedidos.
     *
     * @return string 'eliminado' | 'desactivado'
     */
    public function delete(int $id): string {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pedido_items WHERE producto_id = :id");
        $stmt->execute([':id' => $id]);
        $enPedidos = (int)$stmt->fetchColumn();

        if ($enPedidos > 0) {
            $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id")->execute([':id' => $id]);
            return 'desactivado';
        }

        // producto_imagenes cae solo por la foreign key en cascada.
        $this->db->prepare("DELETE FROM productos WHERE id = :id")->execute([':id' => $id]);
        return 'eliminado';
    }

    /** Cuantos pedidos incluyen este producto. */
    public function vecesVendido(int $id): int {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT pedido_id) FROM pedido_items WHERE producto_id = :id");
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    public function checkStock(int $id, int $cantidad): bool {
        $stmt = $this->db->prepare("SELECT stock FROM productos WHERE id = :id AND activo = 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return false;
        return (int)$row['stock'] >= $cantidad;
    }
}
