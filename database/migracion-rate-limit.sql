-- Contador de peticiones por IP para limitar pedidos y mensajes de contacto.
-- Correr una vez en phpMyAdmin (pestana SQL).
CREATE TABLE IF NOT EXISTS `rate_limit` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `clave`      VARCHAR(160) NOT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_clave_fecha` (`clave`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
