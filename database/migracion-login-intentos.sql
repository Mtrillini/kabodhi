-- Freno de fuerza bruta en el login del panel.
-- Sin esto se pueden probar contrasenas a razon de cientos por minuto.

CREATE TABLE IF NOT EXISTS `login_intentos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ip`         VARCHAR(45)  NOT NULL,
    `usuario`    VARCHAR(200) NULL,
    `exito`      TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_fecha` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
