-- Saca los banners duplicados.
--
-- migracion-contenido.sql insertaba los cuatro banners sin proteccion contra
-- repetidos, asi que correrla dos veces los duplico. Se queda el de menor id
-- de cada titulo, que es el original.

DELETE b FROM `banners` b
INNER JOIN (
    SELECT `titulo`, MIN(`id`) AS conservar
    FROM `banners`
    GROUP BY `titulo`
    HAVING COUNT(*) > 1
) d ON b.`titulo` = d.`titulo` AND b.`id` > d.conservar;

-- Y renumera el orden por las dudas.
SET @i := 0;
UPDATE `banners` SET `orden` = (@i := @i + 1) ORDER BY `id`;
