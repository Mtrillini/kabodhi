-- Integracion con Correo Argentino (API MiCorreo).
--
-- SOLO para bases que ya existian: una instalacion nueva con
-- kabodhi-instalacion.sql ya trae todo esto (el ALTER fallaria).
--
-- 1. Los productos pasan a tener peso y medidas: la API cotiza por bulto.
--    Si un producto no las tiene, se usan los valores por defecto de
--    Ajustes > Configuracion.
-- 2. Cada pedido tiene su registro en `envios`: que cotizo el correo, que se
--    le cobro al cliente, tipo de entrega, sucursal, medidas, tracking y el
--    estado del envio (independiente del estado del pedido/pago).
-- 3. `envio_historial` guarda cada cambio de estado, quien lo hizo y cuando.
--
-- Los campos transporte / tracking_* de `pedidos` se mantienen (los usan los
-- mails, el remito y el CSV) y se sincronizan desde `envios`.

ALTER TABLE `productos`
    ADD COLUMN `peso_gramos` INT UNSIGNED      NULL AFTER `stock_reservado`,
    ADD COLUMN `alto_cm`     SMALLINT UNSIGNED NULL AFTER `peso_gramos`,
    ADD COLUMN `ancho_cm`    SMALLINT UNSIGNED NULL AFTER `alto_cm`,
    ADD COLUMN `largo_cm`    SMALLINT UNSIGNED NULL AFTER `ancho_cm`;


CREATE TABLE IF NOT EXISTS `envios` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pedido_id`           INT UNSIGNED NOT NULL,

    -- De donde salio el costo: 'correo' (API MiCorreo), 'tabla' (tarifas por
    -- CP) o 'sin_costo' (tienda sin tarifas configuradas).
    `proveedor`           ENUM('correo','tabla','sin_costo') NOT NULL DEFAULT 'tabla',
    `tipo_entrega`        ENUM('domicilio','sucursal') NOT NULL DEFAULT 'domicilio',
    -- Producto de Correo: CP = Paq.ar Clasico, EP = Paq.ar Expreso.
    `producto`            VARCHAR(5)   NULL,
    `producto_nombre`     VARCHAR(150) NULL,

    `cp_origen`           VARCHAR(10)  NULL,
    `cp_destino`          VARCHAR(10)  NOT NULL,
    `provincia_codigo`    CHAR(1)      NULL,
    `sucursal_codigo`     VARCHAR(20)  NULL,
    `sucursal_nombre`     VARCHAR(200) NULL,

    -- Direccion estructurada (la API la pide separada; pedidos.cliente_direccion
    -- sigue teniendo el texto completo).
    `dest_calle`          VARCHAR(200) NULL,
    `dest_numero`         VARCHAR(20)  NULL,
    `dest_piso_depto`     VARCHAR(50)  NULL,
    `dest_ciudad`         VARCHAR(150) NULL,
    `dest_provincia`      VARCHAR(100) NULL,

    -- Bulto que se cotizo.
    `peso_gramos`         INT UNSIGNED      NOT NULL DEFAULT 0,
    `alto_cm`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `ancho_cm`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `largo_cm`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- costo_cotizado: lo que dijo el correo / la tabla.
    -- costo_cobrado: lo que efectivamente pago el cliente (0 si bonificado).
    `costo_cotizado`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `costo_cobrado`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `bonificado`          TINYINT(1)    NOT NULL DEFAULT 0,
    `plazo_min_dias`      TINYINT UNSIGNED NULL,
    `plazo_max_dias`      TINYINT UNSIGNED NULL,
    `cotizacion_valida_hasta` DATETIME NULL,

    -- Orden importada en MiCorreo (POST /shipping/import).
    `ext_order_id`        VARCHAR(60)  NULL,
    `importado_at`        DATETIME     NULL,
    `importado_respuesta` TEXT         NULL,

    `tracking_codigo`     VARCHAR(120) NULL,
    `tracking_url`        VARCHAR(500) NULL,

    `estado`              ENUM('pendiente','preparando','enviado','en_traslado','en_sucursal','entregado','rechazado','devuelto','cancelado')
                          NOT NULL DEFAULT 'pendiente',
    `estado_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_pedido` (`pedido_id`),
    UNIQUE KEY `uq_ext_order` (`ext_order_id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_proveedor` (`proveedor`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_envio_pedido` FOREIGN KEY (`pedido_id`)
        REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `envio_historial` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `envio_id`   INT UNSIGNED NOT NULL,
    `estado`     VARCHAR(20)  NOT NULL,
    `detalle`    VARCHAR(500) NULL,
    -- 'sistema' (al crear el pedido / sincronizacion), 'panel' (un admin) o
    -- 'correo' (respuesta de la API).
    `origen`     ENUM('sistema','panel','correo') NOT NULL DEFAULT 'sistema',
    `usuario_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_envio` (`envio_id`, `created_at`),
    CONSTRAINT `fk_historial_envio` FOREIGN KEY (`envio_id`)
        REFERENCES `envios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Registro de envios para los pedidos que ya existian: hereda lo que habia en
-- pedidos (costo, tracking) y deduce el estado del envio del estado del pedido.
INSERT INTO `envios` (`pedido_id`, `proveedor`, `tipo_entrega`, `producto_nombre`, `cp_destino`,
                      `costo_cotizado`, `costo_cobrado`, `bonificado`,
                      `tracking_codigo`, `tracking_url`, `estado`, `estado_at`, `created_at`)
SELECT p.id,
       IF(p.envio_descripcion IS NULL, 'sin_costo', 'tabla'),
       'domicilio',
       p.envio_descripcion,
       -- El CP es el ultimo numero de la direccion (el checkout lo arma asi).
       COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(p.cliente_direccion, ',', -1)), ''), '0'),
       p.envio_costo, p.envio_costo, 0,
       p.tracking_codigo, p.tracking_url,
       CASE p.estado
           WHEN 'enviado'   THEN 'enviado'
           WHEN 'entregado' THEN 'entregado'
           WHEN 'cancelado' THEN 'cancelado'
           WHEN 'rechazado' THEN 'cancelado'
           ELSE 'pendiente'
       END,
       COALESCE(p.enviado_at, p.created_at),
       p.created_at
FROM `pedidos` p
LEFT JOIN `envios` e ON e.pedido_id = p.id
WHERE e.id IS NULL;

INSERT INTO `envio_historial` (`envio_id`, `estado`, `detalle`, `origen`, `created_at`)
SELECT e.id, e.estado, 'Registro creado al migrar a la integración con Correo Argentino.', 'sistema', e.estado_at
FROM `envios` e
LEFT JOIN `envio_historial` h ON h.envio_id = e.id
WHERE h.id IS NULL;


-- Configuracion de la integracion (se edita desde Ajustes > Configuracion).
INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('envio_modo',               'tabla'),
    ('correo_ambiente',          'test'),
    ('correo_cp_origen',         ''),
    ('correo_permite_sucursal',  '1'),
    ('correo_permite_expreso',   '1'),
    ('correo_tracking_url',      'https://www.correoargentino.com.ar/formularios/e-commerce?id={codigo}'),
    ('envio_peso_default_gramos','300'),
    ('envio_alto_default_cm',    '10'),
    ('envio_ancho_default_cm',   '15'),
    ('envio_largo_default_cm',   '20')
ON DUPLICATE KEY UPDATE `valor` = `valor`;
