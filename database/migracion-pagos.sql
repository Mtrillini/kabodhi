-- Pagos: Mercado Pago con historial y transferencia bancaria.
--
-- SOLO para bases que ya existian: una instalacion nueva con
-- kabodhi-instalacion.sql ya trae todo esto (el ALTER fallaria).
--
-- 1. El pedido sabe con que metodo se paga (Mercado Pago o transferencia) y
--    si tuvo descuento por transferencia.
-- 2. `pagos` guarda cada pago recibido: medio, cuotas, monto, comision de
--    Mercado Pago y neto acreditado. Un pedido puede tener mas de un intento
--    (rechazado y luego aprobado, o un reembolso).

ALTER TABLE `pedidos`
    ADD COLUMN `metodo_pago`    ENUM('mercadopago','transferencia') NOT NULL DEFAULT 'mercadopago' AFTER `total`,
    ADD COLUMN `descuento_pct`  DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER `metodo_pago`,
    ADD COLUMN `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `descuento_pct`,
    ADD COLUMN `pagado_at`      DATETIME NULL AFTER `descuento_monto`;


CREATE TABLE IF NOT EXISTS `pagos` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pedido_id`        INT UNSIGNED NOT NULL,
    `proveedor`        ENUM('mercadopago','transferencia') NOT NULL,
    -- Id del pago en Mercado Pago (unico) o la referencia que cargo el admin.
    `referencia`       VARCHAR(100) NULL,
    -- Estado tal como lo maneja Mercado Pago: approved, pending, in_process,
    -- rejected, cancelled, refunded, charged_back. Las transferencias usan
    -- pending / approved.
    `estado`           VARCHAR(30)  NOT NULL DEFAULT 'pending',
    `estado_detalle`   VARCHAR(100) NULL,
    -- Medio y tipo: visa / master / account_money...; credit_card / debit_card /
    -- account_money / ticket / bank_transfer.
    `medio`            VARCHAR(50)  NULL,
    `tipo`             VARCHAR(50)  NULL,
    `cuotas`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `monto_cuota`      DECIMAL(10,2) NULL,
    `monto`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `comision`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `neto`             DECIMAL(10,2) NULL,
    `reembolsado`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `moneda`           CHAR(3)      NOT NULL DEFAULT 'ARS',
    `payload`          MEDIUMTEXT   NULL,
    `aprobado_at`      DATETIME     NULL,
    `usuario_id`       INT UNSIGNED NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_mp` (`proveedor`, `referencia`),
    KEY `idx_pedido` (`pedido_id`),
    KEY `idx_estado` (`estado`),
    CONSTRAINT `fk_pago_pedido` FOREIGN KEY (`pedido_id`)
        REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Pedidos que ya tenian un pago de Mercado Pago: se registra con lo que se
-- sabe (id y estado del pedido). El detalle se completa con "Sincronizar con
-- Mercado Pago" desde el panel.
INSERT INTO `pagos` (`pedido_id`, `proveedor`, `referencia`, `estado`, `monto`, `aprobado_at`, `created_at`)
SELECT p.id, 'mercadopago', p.mp_payment_id,
       CASE p.estado
           WHEN 'pendiente' THEN 'pending'
           WHEN 'rechazado' THEN 'rejected'
           WHEN 'cancelado' THEN 'cancelled'
           ELSE 'approved'
       END,
       p.total,
       IF(p.estado IN ('aprobado','enviado','entregado'), p.updated_at, NULL),
       p.created_at
FROM `pedidos` p
LEFT JOIN `pagos` g ON g.proveedor = 'mercadopago' AND g.referencia = p.mp_payment_id
WHERE p.mp_payment_id IS NOT NULL AND p.mp_payment_id <> '' AND g.id IS NULL;

UPDATE `pedidos` SET `pagado_at` = `updated_at`
WHERE `pagado_at` IS NULL AND `estado` IN ('aprobado','enviado','entregado');


-- Configuracion de pagos (Ajustes > Configuracion > Pagos).
INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('mp_cuotas_max',            '12'),
    ('mp_excluir_efectivo',      '0'),
    ('mp_mostrar_cuotas',        '1'),
    ('mp_descriptor',            'KABODHI'),
    ('transferencia_activa',     '0'),
    ('transferencia_descuento',  '0'),
    ('transferencia_titular',    ''),
    ('transferencia_banco',      ''),
    ('transferencia_cbu',        ''),
    ('transferencia_alias',      ''),
    ('transferencia_cuit',       ''),
    ('transferencia_instrucciones', 'Envianos el comprobante por WhatsApp o respondiendo el mail del pedido y lo confirmamos a la brevedad.')
ON DUPLICATE KEY UPDATE `valor` = `valor`;
