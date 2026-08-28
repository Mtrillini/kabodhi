-- Roles del panel e invitaciones.
--
--   super : ve y toca todo, incluidos usuarios y configuracion de la tienda.
--   admin : operacion diaria (productos, categorias, hongos, pedidos, envios).
--
-- Para sumar gente no hay registro abierto: un super genera un link de
-- invitacion con vencimiento y quien lo recibe elige su propia contrasena.
-- Un formulario de alta publico en un panel de administracion seria una
-- puerta abierta.

ALTER TABLE `admin_users`
    ADD COLUMN `rol` ENUM('super','admin') NOT NULL DEFAULT 'admin' AFTER `email`;

-- Los usuarios que ya existian eran los unicos administradores: quedan super.
UPDATE `admin_users` SET `rol` = 'super';

CREATE TABLE IF NOT EXISTS `admin_invitaciones` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `token`       CHAR(64) NOT NULL,
    `email`       VARCHAR(200) NOT NULL,
    `rol`         ENUM('super','admin') NOT NULL DEFAULT 'admin',
    `creado_por`  INT UNSIGNED NULL,
    `expira_at`   TIMESTAMP NOT NULL,
    `usado_at`    TIMESTAMP NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_expira` (`expira_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
