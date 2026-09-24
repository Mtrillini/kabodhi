-- Combos: el admin arma una lista fija de productos y le pone un precio
-- unico, mas barato que comprarlos sueltos. El cliente no elige que
-- productos entran en el combo (eso ya lo decidio el admin) — solo elige la
-- fragancia/opcion de cada producto, si ese producto tiene variantes.
--
-- Al pagar, el combo se "abre" en N pedido_items normales (uno por
-- producto + opcion elegida), con el precio del combo repartido entre
-- ellos. Asi el stock, el peso para el envio y los mails funcionan
-- exactamente igual que con productos sueltos, sin que el resto del
-- sistema tenga que saber que existen los combos.
--
-- Esta migracion no borra nada: solo crea tablas y agrega una columna.

CREATE TABLE IF NOT EXISTS `promos` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `nombre`          VARCHAR(150) NOT NULL,
    `descripcion`     TEXT NULL,
    -- Cuantos productos tiene el combo (se calcula solo, es un espejo de
    -- promo_productos para no tener que hacer un COUNT en cada consulta).
    `cantidad_items`  TINYINT UNSIGNED NOT NULL,
    -- Precio fijo del combo completo (no por producto).
    `precio`          DECIMAL(10,2) NOT NULL,
    `imagen_url`      VARCHAR(500) NULL,
    `activo`          TINYINT(1)   NOT NULL DEFAULT 1,
    `orden`           INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_orden` (`orden`),
    KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que productos forman este combo (lista fija, la define el admin). Si un
-- producto se borra, sale solo de la lista (el combo sigue existiendo con
-- los que le queden).
CREATE TABLE IF NOT EXISTS `promo_productos` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `promo_id`     INT UNSIGNED NOT NULL,
    `producto_id`  INT UNSIGNED NOT NULL,
    UNIQUE KEY `uq_promo_producto` (`promo_id`, `producto_id`),
    CONSTRAINT `fk_promoprod_promo` FOREIGN KEY (`promo_id`)
        REFERENCES `promos` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_promoprod_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que combo genero esta linea del pedido, y una copia del nombre (si el
-- combo se borra o se renombra despues, el pedido viejo se sigue leyendo
-- igual). ON DELETE SET NULL: borrar una promo no rompe pedidos ya hechos.
ALTER TABLE `pedido_items`
    ADD COLUMN `promo_id`     INT UNSIGNED NULL AFTER `variante_nombre`,
    ADD COLUMN `promo_nombre` VARCHAR(150) NULL AFTER `promo_id`,
    ADD KEY `idx_pedido_promo` (`promo_id`),
    ADD CONSTRAINT `fk_item_promo` FOREIGN KEY (`promo_id`)
        REFERENCES `promos` (`id`) ON DELETE SET NULL;
