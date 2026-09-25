-- Retiro en punto de encuentro: agrega el valor 'retiro' a los ENUM de la
-- tabla `envios` que hasta ahora solo contemplaban envios reales
-- (correo/tabla/sin_costo, a domicilio o a sucursal).
--
-- Sin esta migracion, cualquier pedido con retiro en punto de encuentro
-- rompe al crearse: MySQL rechaza el INSERT porque 'retiro' no es un valor
-- valido para esas columnas ENUM.

ALTER TABLE `envios`
    MODIFY COLUMN `proveedor`    ENUM('correo','tabla','sin_costo','retiro') NOT NULL DEFAULT 'tabla',
    MODIFY COLUMN `tipo_entrega` ENUM('domicilio','sucursal','retiro')       NOT NULL DEFAULT 'domicilio';
