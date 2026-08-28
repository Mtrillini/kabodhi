-- Contenido editable desde el panel.
--
-- Textos que estaban escritos a mano en el HTML y banners del home, para que
-- se cambien sin tocar codigo ni volver a publicar el sitio.

-- Textos: van en la tabla clave/valor que ya existe.
INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('nosotros_titulo', 'Nuestra esencia'),
    ('nosotros_texto',  'KABODHI nació de una convicción simple: la naturaleza tiene el poder de acompañar nuestra energía, nuestro enfoque y nuestro bienestar diario. No hace falta forzar al cuerpo; hace falta darle lo que necesita para volver a su equilibrio.\n\nInspirados en la sabiduría ancestral y en los rituales conscientes, elaboramos adaptógenos naturales y puros. Cada hongo funcional —Reishi, Melena de León, Cordyceps, Chaga, Shiitake— se trabaja con doble extracción para conservar todos sus principios activos y ofrecer la máxima biodisponibilidad.\n\nCreemos en el bienestar que nace de elecciones conscientes y sostenibles. En los pequeños rituales que transforman lo cotidiano en momentos de calma. En volver a lo esencial: ingredientes reales, sin agregados innecesarios.\n\nVolver a lo natural. Encontrar el equilibrio. Vivir en bienestar. Bienvenida, bienvenido, al universo KABODHI.'),
    ('instagram_usuario', 'kabodhi'),
    ('direccion',         'Buenos Aires, Argentina')
ON DUPLICATE KEY UPDATE `clave` = `clave`;


-- Banners del carrusel del home. Cada uno tiene su version de escritorio y la
-- de celular, porque el recorte que funciona en una no funciona en la otra.
CREATE TABLE IF NOT EXISTS `banners` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `titulo`          VARCHAR(200) NOT NULL,
    `imagen_desktop`  VARCHAR(500) NOT NULL,
    `imagen_mobile`   VARCHAR(500) NULL,
    `link`            VARCHAR(500) NULL,
    `orden`           INT UNSIGNED NOT NULL DEFAULT 0,
    `activo`          TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_orden` (`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los cuatro que ya estaban en index.html.
INSERT INTO `banners` (`titulo`, `imagen_desktop`, `imagen_mobile`, `orden`, `activo`) VALUES
    ('KABODHI — Reishi, equilibrio y calma',              'images/banner1.png', 'images/banner1-mobile.png', 1, 1),
    ('KABODHI — Cordyceps, energía y vitalidad',          'images/banner2.png', 'images/banner2-mobile.png', 2, 1),
    ('KABODHI — Melena de León, enfoque y claridad',      'images/banner3.png', 'images/banner3-mobile.png', 3, 1),
    ('KABODHI — Reishi doble extracto, calma y descanso', 'images/banner4.png', 'images/banner4-mobile.png', 4, 1);
