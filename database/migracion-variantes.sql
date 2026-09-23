-- Variantes de producto (aromas, tamanos, colores).
--
-- Un producto que se vende en varias opciones deja de necesitar una fila por
-- opcion: "Vela" es UN producto con N variantes, cada una con su stock y su
-- foto. El precio vive en el producto; `precio` en la variante es opcional y
-- solo se usa si alguna opcion vale distinto.
--
-- Regla que aplica el codigo: si un producto tiene variantes, el stock vive
-- SOLO en las variantes (productos.stock queda sin uso y la API devuelve la
-- suma). Un producto sin variantes sigue funcionando igual que siempre.
--
-- Esta migracion no borra nada: solo crea la tabla y agrega dos columnas.

CREATE TABLE IF NOT EXISTS `producto_variantes` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `producto_id`     INT UNSIGNED NOT NULL,
    `nombre`          VARCHAR(120) NOT NULL,
    `sku`             VARCHAR(60)  NULL,

    -- NULL = usa el precio del producto. Solo se completa si esta opcion
    -- cuesta distinto que las demas.
    `precio`          DECIMAL(10,2) NULL,

    `stock`           INT UNSIGNED NOT NULL DEFAULT 0,
    `stock_reservado` INT UNSIGNED NOT NULL DEFAULT 0,

    -- Foto propia de la opcion; vacia = se usa la del producto.
    `imagen_url`      VARCHAR(500) NULL,

    -- Bulto para cotizar el envio; NULL = hereda las del producto.
    `peso_gramos`     INT UNSIGNED      NULL,
    `alto_cm`         SMALLINT UNSIGNED NULL,
    `ancho_cm`        SMALLINT UNSIGNED NULL,
    `largo_cm`        SMALLINT UNSIGNED NULL,

    `activo`          TINYINT(1)   NOT NULL DEFAULT 1,
    `orden`           INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_producto` (`producto_id`, `orden`),
    KEY `idx_activo` (`activo`),
    CONSTRAINT `fk_variante_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Que opcion se vendio en cada linea del pedido.
--
-- `variante_nombre` es una copia del nombre al momento de la venta: si despues
-- se renombra o se borra la variante, el pedido viejo se sigue leyendo igual.
-- Por eso la foreign key es ON DELETE SET NULL y no RESTRICT.
ALTER TABLE `pedido_items`
    ADD COLUMN `variante_id`     INT UNSIGNED NULL AFTER `producto_id`,
    ADD COLUMN `variante_nombre` VARCHAR(120) NULL AFTER `variante_id`,
    ADD KEY `idx_variante` (`variante_id`),
    ADD CONSTRAINT `fk_item_variante` FOREIGN KEY (`variante_id`)
        REFERENCES `producto_variantes` (`id`) ON DELETE SET NULL;
