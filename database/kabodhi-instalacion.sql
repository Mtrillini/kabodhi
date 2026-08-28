-- ============================================================
--  KABODHI — instalacion completa de la base de datos
-- ============================================================
--
--  COMO USARLO EN HOSTINGER
--
--  1. En hPanel: Bases de datos > MySQL. Crea una base y un usuario, y
--     anota nombre de base, usuario y contrasena: van al .env.
--  2. Entra a phpMyAdmin, selecciona ESA base en el panel izquierdo.
--  3. Pestana "Importar" > elegi este archivo > Continuar.
--
--  El script NO crea la base ni la selecciona: Hostinger le pone un nombre
--  propio (tipo u123456789_kabodhi) y lo elegis vos en el paso 2.
--
--  OJO: empieza con DROP TABLE. Sobre una base con datos reales, los borra.
--  Usalo para la instalacion inicial.
--
--  El usuario administrador NO viene incluido a proposito: la contrasena
--  quedaria publicada en el repositorio. Se crea despues, una sola vez,
--  con database/crear-admin.php (ver instrucciones al final).
--
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- ------------------------------------------------------------
--  Estructura
-- ------------------------------------------------------------

DROP TABLE IF EXISTS `pedido_items`;
DROP TABLE IF EXISTS `pedidos`;
DROP TABLE IF EXISTS `producto_imagenes`;
DROP TABLE IF EXISTS `productos`;
DROP TABLE IF EXISTS `categorias`;
DROP TABLE IF EXISTS `hongos_principales`;
DROP TABLE IF EXISTS `tarifas_envio`;
DROP TABLE IF EXISTS `configuracion`;
DROP TABLE IF EXISTS `mail_log`;
DROP TABLE IF EXISTS `login_intentos`;
DROP TABLE IF EXISTS `admin_users`;


CREATE TABLE `categorias` (
    `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `slug`   VARCHAR(100) NOT NULL,
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `productos` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `categoria_id`    INT UNSIGNED NOT NULL,
    `marca`           VARCHAR(150) NULL,
    `nombre`          VARCHAR(200) NOT NULL,
    `descripcion`     TEXT NULL,
    `nota_olfativa`   VARCHAR(500) NULL,
    `precio`          DECIMAL(10,2) NOT NULL,
    `stock`           INT UNSIGNED NOT NULL DEFAULT 0,
    `stock_reservado` INT UNSIGNED NOT NULL DEFAULT 0,
    `imagen_url`      VARCHAR(500) NULL,
    `tipo`            VARCHAR(50) NOT NULL DEFAULT 'enfoque',
    `activo`          TINYINT(1) NOT NULL DEFAULT 1,
    `destacado`       TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_categoria` (`categoria_id`),
    KEY `idx_activo` (`activo`),
    CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`categoria_id`)
        REFERENCES `categorias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Galeria: la primera imagen se replica en productos.imagen_url.
CREATE TABLE `producto_imagenes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `producto_id` INT UNSIGNED NOT NULL,
    `url`         VARCHAR(500) NOT NULL,
    `orden`       INT UNSIGNED NOT NULL DEFAULT 0,
    KEY `idx_producto` (`producto_id`),
    CONSTRAINT `fk_imagen_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Seccion destacada del home.
CREATE TABLE `hongos_principales` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `nombre`      VARCHAR(150) NOT NULL,
    `subtitulo`   VARCHAR(200) NULL,
    `descripcion` TEXT NULL,
    `imagen_url`  VARCHAR(500) NULL,
    `orden`       INT UNSIGNED NOT NULL DEFAULT 0,
    `activo`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_orden` (`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `pedidos` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `cliente_nombre`    VARCHAR(200) NOT NULL,
    `cliente_email`     VARCHAR(200) NOT NULL,
    `cliente_telefono`  VARCHAR(50) NULL,
    `cliente_dni`       VARCHAR(20) NULL,
    `cliente_direccion` TEXT NULL,
    `envio_costo`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `envio_descripcion` VARCHAR(300) NULL,
    `transporte`        VARCHAR(100) NULL,
    `tracking_codigo`   VARCHAR(120) NULL,
    `tracking_url`      VARCHAR(500) NULL,
    `enviado_at`        TIMESTAMP NULL,
    `total`             DECIMAL(10,2) NOT NULL,
    `estado`            ENUM('pendiente','aprobado','enviado','entregado','rechazado','cancelado')
                        NOT NULL DEFAULT 'pendiente',
    `mp_payment_id`     VARCHAR(100) NULL,
    `mp_preference_id`  VARCHAR(200) NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_estado` (`estado`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `pedido_items` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pedido_id`       INT UNSIGNED NOT NULL,
    `producto_id`     INT UNSIGNED NOT NULL,
    `cantidad`        INT UNSIGNED NOT NULL,
    `precio_unitario` DECIMAL(10,2) NOT NULL,
    KEY `idx_pedido` (`pedido_id`),
    KEY `idx_producto` (`producto_id`),
    CONSTRAINT `fk_item_pedido` FOREIGN KEY (`pedido_id`)
        REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- El cliente ingresa su CP y se le cobra el rango que corresponda.
CREATE TABLE `tarifas_envio` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `descripcion` VARCHAR(300) NOT NULL,
    `cp_desde`    INT UNSIGNED NOT NULL,
    `cp_hasta`    INT UNSIGNED NOT NULL,
    `precio`      DECIMAL(10,2) NOT NULL,
    `activo`      TINYINT(1) NOT NULL DEFAULT 1,
    KEY `idx_rango` (`cp_desde`, `cp_hasta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Valores que se editan desde Ajustes > Configuracion.
CREATE TABLE `configuracion` (
    `clave`      VARCHAR(60) NOT NULL PRIMARY KEY,
    `valor`      TEXT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `admin_users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(100) NOT NULL,
    `email`         VARCHAR(200) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Que mail se le mando a cada cliente, y si salio.
CREATE TABLE `mail_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pedido_id`  INT UNSIGNED NULL,
    `destino`    VARCHAR(200) NOT NULL,
    `asunto`     VARCHAR(300) NOT NULL,
    `tipo`       VARCHAR(50) NOT NULL,
    `exito`      TINYINT(1) NOT NULL DEFAULT 0,
    `error`      VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pedido` (`pedido_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Freno de fuerza bruta del login.
CREATE TABLE `login_intentos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ip`         VARCHAR(45) NOT NULL,
    `usuario`    VARCHAR(200) NULL,
    `exito`      TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ip_fecha` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  Datos
-- ------------------------------------------------------------

-- Categorias
INSERT INTO `categorias` (`id`, `nombre`, `slug`) VALUES
    (1, 'Adaptogenos', 'adaptogenos'),
    (2, 'Blends', 'blends');

-- Catalogo. Los que tienen activo = 0 no se muestran en la tienda;
-- se activan desde el panel sin tocar la base.
INSERT INTO `productos`
    (`id`, `categoria_id`, `marca`, `nombre`, `descripcion`, `nota_olfativa`,
     `precio`, `stock`, `stock_reservado`, `imagen_url`, `tipo`, `activo`, `destacado`) VALUES
    (1, 1, 'Enfoque', 'Melena de Leon', 'Hericium erinaceus de doble extraccion. Acompana la claridad mental, la memoria y el foco sostenido a lo largo del dia. Ideal para jornadas de estudio o trabajo profundo.', 'Enfoque & claridad · 60 capsulas', '28900.00', 40, 0, 'images/hongo-melena-de-leon.webp', 'enfoque', 1, 1),
    (2, 1, 'Equilibrio', 'Reishi', 'Ganoderma lucidum de doble extraccion. El hongo de la calma: apoya el descanso reparador, la respuesta al estres y el equilibrio diario.', 'Equilibrio & calma · 60 capsulas', '28900.00', 45, 0, 'images/hongo-reishi.webp', 'equilibrio', 1, 1),
    (3, 1, 'Energia', 'Cordyceps', 'Cordyceps militaris de doble extraccion. Energia limpia, resistencia y rendimiento fisico. El aliado natural para entrenar y rendir.', 'Energia & vitalidad · 60 capsulas', '28900.00', 38, 0, 'images/hongo-cordyceps.webp', 'energia', 1, 1),
    (4, 1, 'Defensas', 'Chaga', 'Inonotus obliquus de doble extraccion. Rico en antioxidantes, acompana las defensas naturales y el bienestar general.', 'Defensas naturales · 50g polvo', '28900.00', 30, 0, 'images/prod-chaga.svg', 'defensas', 0, 1),
    (5, 1, 'Equilibrio', 'Ashwagandha', 'Withania somnifera de doble extraccion. Raiz adaptogena que acompana el equilibrio, la resistencia y el bienestar en rutinas exigentes.', 'Equilibrio & resistencia · 50 ml', '28900.00', 26, 0, 'images/hongo-ashwagandha.webp', 'equilibrio', 1, 0),
    (6, 2, 'Enfoque', 'Blend Focus', 'Mezcla funcional de Melena de Leon y Cordyceps. Claridad mental y energia sostenida en una sola toma diaria.', 'Melena + Cordyceps · 50g polvo', '31900.00', 22, 0, 'images/prod-blend-focus.svg', 'enfoque', 0, 1),
    (7, 2, 'Equilibrio', 'Blend Calm', 'Mezcla funcional de Reishi y Chaga. Calma, defensas y equilibrio para cerrar el dia.', 'Reishi + Chaga · 50g polvo', '31900.00', 20, 0, 'images/prod-blend-calm.svg', 'equilibrio', 0, 0);

-- Hongos destacados del home
INSERT INTO `hongos_principales`
    (`id`, `nombre`, `subtitulo`, `descripcion`, `imagen_url`, `orden`, `activo`) VALUES
    (2, 'Reishi', 'Equilibrio & calma', 'Tradicionalmente utilizado dentro de rutinas de bienestar orientadas al descanso, la calma y el equilibrio cotidiano.', 'images/hongo-reishi.webp', 1, 1),
    (3, 'Cordyceps', 'Energia & vitalidad', 'Una opcion pensada para acompanar rutinas activas y momentos donde buscamos energia y rendimiento.', 'images/hongo-cordyceps.webp', 2, 1),
    (4, 'Ashwagandha', 'Equilibrio & resistencia', 'Raiz adaptogena tradicionalmente utilizada para acompanar rutinas de bienestar orientadas al equilibrio y la resistencia cotidiana.', 'images/hongo-ashwagandha.webp', 3, 1),
    (5, 'Melena de Leon', 'Enfoque & claridad', 'Tradicionalmente asociado a rutinas de estudio y trabajo profundo, acompana momentos donde buscamos enfoque, memoria y claridad mental.', 'images/hongo-melena-de-leon.webp', 4, 1);

-- Tarifas de envio por rango de codigo postal. Ajustables desde el panel.
INSERT INTO `tarifas_envio` (`id`, `descripcion`, `cp_desde`, `cp_hasta`, `precio`, `activo`) VALUES
    (1, 'Envio CABA',     1000, 1499, '3500.00', 1),
    (2, 'Envio GBA',      1500, 1900, '4500.00', 1),
    (3, 'Envio Interior', 1901, 9999, '6500.00', 1);

-- Configuracion de la tienda (Ajustes > Configuracion)
-- envio_gratis_desde en 0 = desactivado.
INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('whatsapp_numero', '541171003392'),
    ('contacto_email', 'hola@kabodhi.com'),
    ('envio_gratis_desde', '0');


SET FOREIGN_KEY_CHECKS = 1;


-- ------------------------------------------------------------
--  Falta el administrador
-- ------------------------------------------------------------
--
--  Las tablas quedaron listas pero `admin_users` esta vacia, asi que
--  todavia no se puede entrar al panel.
--
--  Para crear el usuario:
--
--    1. Subi crear-admin.php a la raiz del sitio, junto con index.html.
--    2. Entra a https://TUDOMINIO/crear-admin.php
--    3. Elegi usuario, email y contrasena.
--    4. BORRA el archivo del servidor. El script se niega a correr si ya
--       existe un administrador, pero conviene no dejarlo dando vueltas.
--
--  No se incluye un usuario por defecto a proposito: su contrasena
--  quedaria publicada en el repositorio, que es publico.
--
-- ------------------------------------------------------------