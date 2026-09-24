-- Tarifas de envio por peso, ademas de por codigo postal.
--
-- Hoy `tarifas_envio` solo mira el CP: un pedido de 1 frasco paga lo mismo
-- que uno de 10. Con estas dos columnas (opcionales) se puede armar, por
-- ejemplo, "CABA hasta 1kg = $2500" y "CABA de 1 a 3kg = $3800": la tarifa
-- que se usa es la que matchea el CP Y el peso del carrito completo (los
-- pesos de cada producto ya se suman solos, ver EnvioService::armarBulto).
--
-- NULL = sin limite de ese lado, asi las tarifas ya cargadas (sin peso)
-- siguen aplicando a cualquier peso, sin que haya que retocarlas.
--
-- Esta migracion no borra nada: solo agrega columnas.

ALTER TABLE `tarifas_envio`
    ADD COLUMN `peso_desde_gramos` INT UNSIGNED NULL AFTER `cp_hasta`,
    ADD COLUMN `peso_hasta_gramos` INT UNSIGNED NULL AFTER `peso_desde_gramos`;
