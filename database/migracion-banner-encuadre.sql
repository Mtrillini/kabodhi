-- Encuadre de cada banner.
--
-- La franja del inicio es mas ancha que las imagenes, asi que el navegador
-- recorta: con estos tres valores se elige que parte queda a la vista, sin
-- tener que volver a exportar la imagen.
--
--   foco_x / foco_y : que punto de la imagen queda centrado (0 a 100).
--                     50 = como estaba hasta ahora.
--   zoom            : acercamiento en porcentaje (100 = sin acercar). Hace
--                     falta para poder correr la imagen a los costados en
--                     pantallas anchas, donde a lo ancho ya entra entera.
--
-- Esta migracion no borra nada: solo agrega columnas con el valor actual.

-- `boton_x` es aparte del encuadre: cada banner trae su texto a distinta
-- distancia del borde (uno al 8,5% del ancho y otro al 12,7%), asi que el
-- boton se alinea con el texto de SU banner y no con una medida unica.

ALTER TABLE `banners`
    ADD COLUMN `foco_x`  TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER `boton_texto`,
    ADD COLUMN `foco_y`  TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER `foco_x`,
    ADD COLUMN `zoom`    SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER `foco_y`,
    ADD COLUMN `boton_x` TINYINT UNSIGNED NOT NULL DEFAULT 12 AFTER `zoom`;
