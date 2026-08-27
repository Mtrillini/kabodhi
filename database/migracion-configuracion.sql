-- Configuracion de la tienda editable desde el panel admin.
-- Reemplaza los valores que estaban hardcodeados en js/config.js.

CREATE TABLE IF NOT EXISTS `configuracion` (
    `clave`      VARCHAR(60) NOT NULL PRIMARY KEY,
    `valor`      TEXT,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('whatsapp_numero',    '541171003392'),
    ('contacto_email',     'hola@kabodhi.com'),
    ('envio_gratis_desde', '0')
ON DUPLICATE KEY UPDATE `clave` = `clave`;
