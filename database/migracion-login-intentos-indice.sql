-- El conteo de intentos fallidos filtra por usuario, exito y fecha, pero el
-- unico indice era (ip, created_at): cada login recorria la tabla entera.
-- Tambien se indexa created_at solo, para la purga de registros viejos.
-- Correr una vez en phpMyAdmin (pestana SQL). Si el indice ya existe, MySQL
-- avisa "Duplicate key name" y no pasa nada.
ALTER TABLE `login_intentos`
    ADD INDEX `idx_usuario_fecha` (`usuario`, `exito`, `created_at`),
    ADD INDEX `idx_fecha` (`created_at`);
