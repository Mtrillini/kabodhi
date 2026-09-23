-- Boton propio en cada banner del home.
--
-- Hasta ahora un banner con `link` hacia clickeable toda la imagen, sin que se
-- viera a donde llevaba. Con `boton_texto` cargado se dibuja un boton encima
-- del banner (el destino sigue siendo `link`), asi cada banner puede mandar a
-- un lado distinto: "Descubrir colección" a /productos, "Ver velas" a
-- /productos?categoria=velas.
--
-- Sin `boton_texto` todo sigue como antes: la imagen entera es el link.
--
-- Esta migracion no borra nada: solo agrega una columna.

ALTER TABLE `banners`
    ADD COLUMN `boton_texto` VARCHAR(60) NULL AFTER `link`;
