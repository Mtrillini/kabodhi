-- Seguimiento de envio.
--
-- Suma el estado "enviado" (entre aprobado y entregado) y los datos de
-- tracking que se le mandan al cliente por mail.

ALTER TABLE `pedidos`
    MODIFY COLUMN `estado`
    ENUM('pendiente','aprobado','enviado','entregado','rechazado','cancelado')
    NOT NULL DEFAULT 'pendiente';

ALTER TABLE `pedidos`
    ADD COLUMN `transporte`      VARCHAR(100) NULL AFTER `envio_descripcion`,
    ADD COLUMN `tracking_codigo` VARCHAR(120) NULL AFTER `transporte`,
    ADD COLUMN `tracking_url`    VARCHAR(500) NULL AFTER `tracking_codigo`,
    ADD COLUMN `enviado_at`      TIMESTAMP NULL AFTER `tracking_url`;

-- Registro de mails enviados: sirve para saber que se le mando al cliente
-- y para reintentar/diagnosticar cuando el envio falla.
CREATE TABLE IF NOT EXISTS `mail_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pedido_id`  INT UNSIGNED NULL,
    `destino`    VARCHAR(200) NOT NULL,
    `asunto`     VARCHAR(300) NOT NULL,
    `tipo`       VARCHAR(50)  NOT NULL,
    `exito`      TINYINT(1)   NOT NULL DEFAULT 0,
    `error`      VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pedido` (`pedido_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
