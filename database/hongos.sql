-- Hongos Adaptogenos — Ecommerce Database (esquema completo reconstruido desde el codigo)
-- Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS `hongos_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hongos_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `pedido_items`;
DROP TABLE IF EXISTS `producto_imagenes`;
DROP TABLE IF EXISTS `pedidos`;
DROP TABLE IF EXISTS `productos`;
DROP TABLE IF EXISTS `categorias`;
DROP TABLE IF EXISTS `tarifas_envio`;
DROP TABLE IF EXISTS `hongos_principales`;
DROP TABLE IF EXISTS `admin_users`;
SET FOREIGN_KEY_CHECKS = 1;

-- Categorias
CREATE TABLE `categorias` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Productos
CREATE TABLE `productos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `categoria_id` INT UNSIGNED NOT NULL,
    `marca` VARCHAR(150) DEFAULT NULL,
    `nombre` VARCHAR(200) NOT NULL,
    `descripcion` TEXT,
    `nota_olfativa` VARCHAR(500) DEFAULT NULL,
    `precio` DECIMAL(10,2) NOT NULL,
    `stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `stock_reservado` INT UNSIGNED NOT NULL DEFAULT 0,
    `imagen_url` VARCHAR(500),
    `tipo` VARCHAR(50) NOT NULL DEFAULT 'general',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `destacado` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_producto_categoria` (`categoria_id`),
    CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagenes de producto (galeria)
CREATE TABLE `producto_imagenes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` INT UNSIGNED NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `orden` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `fk_img_producto` (`producto_id`),
    CONSTRAINT `fk_img_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pedidos
CREATE TABLE `pedidos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cliente_nombre` VARCHAR(200) NOT NULL,
    `cliente_email` VARCHAR(200) NOT NULL,
    `cliente_telefono` VARCHAR(50),
    `cliente_direccion` TEXT,
    `envio_costo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `envio_descripcion` VARCHAR(300) DEFAULT NULL,
    `total` DECIMAL(10,2) NOT NULL,
    `estado` ENUM('pendiente','aprobado','rechazado','cancelado') NOT NULL DEFAULT 'pendiente',
    `mp_payment_id` VARCHAR(100),
    `mp_preference_id` VARCHAR(200),
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items de pedido
CREATE TABLE `pedido_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id` INT UNSIGNED NOT NULL,
    `producto_id` INT UNSIGNED NOT NULL,
    `cantidad` INT UNSIGNED NOT NULL,
    `precio_unitario` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_item_pedido` (`pedido_id`),
    KEY `fk_item_producto` (`producto_id`),
    CONSTRAINT `fk_item_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_item_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifas de envio (por rango de codigo postal)
CREATE TABLE `tarifas_envio` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `descripcion` VARCHAR(200) NOT NULL,
    `cp_desde` INT UNSIGNED NOT NULL,
    `cp_hasta` INT UNSIGNED NOT NULL,
    `precio` DECIMAL(10,2) NOT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hongos principales (seccion destacada del home, gestionada desde admin)
CREATE TABLE `hongos_principales` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(200) NOT NULL,
    `subtitulo` VARCHAR(200) DEFAULT NULL,
    `descripcion` TEXT,
    `imagen_url` VARCHAR(500),
    `orden` INT UNSIGNED NOT NULL DEFAULT 0,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios admin
CREATE TABLE `admin_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES (placeholder — se reemplazan al definir la marca)
-- ============================================================

INSERT INTO `categorias` (`nombre`, `slug`) VALUES
('Adaptogenos', 'adaptogenos'),
('Blends', 'blends');

-- Convenciones de campos (rubro adaptogenos):
--   nombre        = hongo / producto
--   marca         = beneficio principal (tag corto en la card)
--   nota_olfativa = formato / bajada corta
--   tipo          = objetivo para filtrar (enfoque/energia/equilibrio/defensas/bienestar)
INSERT INTO `productos` (`categoria_id`, `marca`, `nombre`, `descripcion`, `nota_olfativa`, `precio`, `stock`, `imagen_url`, `tipo`, `activo`, `destacado`) VALUES
(1, 'Enfoque', 'Melena de Leon', 'Hericium erinaceus de doble extraccion. Acompana la claridad mental, la memoria y el foco sostenido a lo largo del dia. Ideal para jornadas de estudio o trabajo profundo.', 'Enfoque & claridad · 60 capsulas', 28900.00, 40, 'images/prod-melena.svg', 'enfoque', 1, 1),
(1, 'Equilibrio', 'Reishi', 'Ganoderma lucidum de doble extraccion. El hongo de la calma: apoya el descanso reparador, la respuesta al estres y el equilibrio diario.', 'Equilibrio & calma · 60 capsulas', 28900.00, 45, 'images/prod-reishi.svg', 'equilibrio', 1, 1),
(1, 'Energia', 'Cordyceps', 'Cordyceps militaris de doble extraccion. Energia limpia, resistencia y rendimiento fisico. El aliado natural para entrenar y rendir.', 'Energia & vitalidad · 60 capsulas', 28900.00, 38, 'images/prod-cordyceps.svg', 'energia', 1, 1),
(1, 'Defensas', 'Chaga', 'Inonotus obliquus de doble extraccion. Rico en antioxidantes, acompana las defensas naturales y el bienestar general.', 'Defensas naturales · 50g polvo', 28900.00, 30, 'images/prod-chaga.svg', 'defensas', 1, 1),
(1, 'Bienestar', 'Shiitake', 'Lentinula edodes de doble extraccion. Nutritivo y funcional, apoya la inmunidad y el bienestar cotidiano.', 'Bienestar & inmunidad · 60 capsulas', 28900.00, 26, 'images/prod-shiitake.svg', 'bienestar', 1, 0),
(2, 'Enfoque', 'Blend Focus', 'Mezcla funcional de Melena de Leon y Cordyceps. Claridad mental y energia sostenida en una sola toma diaria.', 'Melena + Cordyceps · 50g polvo', 31900.00, 22, 'images/prod-blend-focus.svg', 'enfoque', 1, 1),
(2, 'Equilibrio', 'Blend Calm', 'Mezcla funcional de Reishi y Chaga. Calma, defensas y equilibrio para cerrar el dia.', 'Reishi + Chaga · 50g polvo', 31900.00, 20, 'images/prod-blend-calm.svg', 'equilibrio', 1, 0);

-- Melena de Leon se destaca arriba en el circulo de beneficios; aca van los otros 3.
INSERT INTO `hongos_principales` (`nombre`, `subtitulo`, `descripcion`, `imagen_url`, `orden`, `activo`) VALUES
('Reishi', 'Equilibrio & calma', 'Tradicionalmente utilizado dentro de rutinas de bienestar orientadas al descanso, la calma y el equilibrio cotidiano.', 'images/hongo-reishi.png', 1, 1),
('Cordyceps', 'Energia & vitalidad', 'Una opcion pensada para acompanar rutinas activas y momentos donde buscamos energia y rendimiento.', 'images/hongo-cordyceps.png', 2, 1),
('Shiitake', 'Bienestar diario', 'Un hongo valorado por su perfil nutricional y su integracion sencilla en habitos de bienestar cotidiano.', 'images/hongo-shiitake.png', 3, 1);

INSERT INTO `tarifas_envio` (`descripcion`, `cp_desde`, `cp_hasta`, `precio`, `activo`) VALUES
('Envio CABA', 1000, 1499, 3500.00, 1),
('Envio GBA', 1500, 1900, 4500.00, 1),
('Envio Interior', 1901, 9999, 6500.00, 1);

-- Admin user: password = "Nuve2024!" (bcrypt)
INSERT INTO `admin_users` (`username`, `email`, `password_hash`) VALUES
('admin', 'admin@hongos.local', '$2y$12$7y6WMHl0gRjOf8YvdOt8M.UGgpFZzOl/OQx25.V4F8WIsnpWxD9F6');
