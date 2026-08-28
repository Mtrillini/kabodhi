-- DNI del cliente en el pedido.
--
-- Hace falta para facturar y para despachar con Andreani o Correo Argentino,
-- que lo piden como identificacion del destinatario.
--
-- Queda NULL en los pedidos ya cargados: no hay forma de completarlo hacia
-- atras, y obligarlo romperia el historial.

ALTER TABLE `pedidos`
    ADD COLUMN `cliente_dni` VARCHAR(20) NULL AFTER `cliente_telefono`;
