-- NÜVE Ecommerce Database
-- Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS `nuve_ecommerce` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `nuve_ecommerce`;

-- Categorías
CREATE TABLE IF NOT EXISTS `categorias` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Productos
CREATE TABLE IF NOT EXISTS `productos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `categoria_id` INT UNSIGNED NOT NULL,
    `nombre` VARCHAR(200) NOT NULL,
    `descripcion` TEXT,
    `nota_olfativa` VARCHAR(300),
    `precio` DECIMAL(10,2) NOT NULL,
    `stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `imagen_url` VARCHAR(500),
    `tipo` ENUM('original','tester') NOT NULL DEFAULT 'original',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_producto_categoria` (`categoria_id`),
    CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pedidos
CREATE TABLE IF NOT EXISTS `pedidos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cliente_nombre` VARCHAR(200) NOT NULL,
    `cliente_email` VARCHAR(200) NOT NULL,
    `cliente_telefono` VARCHAR(50),
    `cliente_direccion` TEXT,
    `total` DECIMAL(10,2) NOT NULL,
    `estado` ENUM('pendiente','aprobado','rechazado','cancelado') NOT NULL DEFAULT 'pendiente',
    `mp_payment_id` VARCHAR(100),
    `mp_preference_id` VARCHAR(200),
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items de pedido
CREATE TABLE IF NOT EXISTS `pedido_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id` INT UNSIGNED NOT NULL,
    `producto_id` INT UNSIGNED NOT NULL,
    `cantidad` INT UNSIGNED NOT NULL,
    `precio_unitario` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_item_pedido` (`pedido_id`),
    KEY `fk_item_producto` (`producto_id`),
    CONSTRAINT `fk_item_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_item_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios admin
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES
-- ============================================================

INSERT INTO `categorias` (`nombre`, `slug`) VALUES
('Originales', 'originales'),
('Testers', 'testers');

-- Productos originales
INSERT INTO `productos` (`categoria_id`, `nombre`, `descripcion`, `nota_olfativa`, `precio`, `stock`, `imagen_url`, `tipo`, `activo`) VALUES
(1, 'Noir Absolu', 'Una oda a la oscuridad elegante. Noir Absolu envuelve la piel con una intensidad que perdura desde el amanecer hasta la medianoche, dejando una estela magnética e inconfundible.', 'Notas de salida: Bergamota, Pimienta Negra. Corazón: Oud, Rosa Damascena. Fondo: Ámbar, Sándalo, Almizcle', 75000.00, 12, 'https://via.placeholder.com/600x600/0D0D0D/F2ECE6?text=NUVE', 'original', 1),
(1, 'Velours Doré', 'La sofisticación en estado puro. Una fragancia floral-amaderada que evoca los grandes salones del París de entreguerras, con una feminidad intemporal.', 'Notas de salida: Neroli, Ylang-Ylang. Corazón: Jazmín Absoluto, Iris. Fondo: Cedro, Vainilla, Pachulí', 85000.00, 8, 'https://via.placeholder.com/600x600/D6C6AD/0D0D0D?text=NUVE', 'original', 1),
(1, 'Brume Sacrée', 'Inspirada en los rituales de pureza, esta fragancia acuosa y especiada transporta a templos orientales donde el incienso se mezcla con el frescor del mar.', 'Notas de salida: Limón, Sal Marina. Corazón: Incienso, Jazmín Marino. Fondo: Vetiver, Musgo Blanco', 68000.00, 15, 'https://via.placeholder.com/600x600/A8A29A/F2ECE6?text=NUVE', 'original', 1),
(1, 'Ambre Impérial', 'Una fragancia oriental de carácter imperial. Ambre Impérial combina la calidez irresistible del ámbar con especias exóticas para crear una presencia imponente.', 'Notas de salida: Azafrán, Cardamomo. Corazón: Rosa Turca, Oud Blanco. Fondo: Ámbar Gris, Benjuí, Vainilla', 82000.00, 6, 'https://via.placeholder.com/600x600/F2ECE6/0D0D0D?text=NUVE', 'original', 1),
(1, 'Lumière Froide', 'Una fragancia fresca de madera blanca, pensada para quienes buscan distinción sin estridencias. Luminosa, limpia, inolvidable.', 'Notas de salida: Pomelo, Jengibre. Corazón: Lirio, Madera de Cachemira. Fondo: Almizcle Blanco, Cedro Atlas', 59000.00, 20, 'https://via.placeholder.com/600x600/B7B7B7/0D0D0D?text=NUVE', 'original', 1);

-- Productos testers
INSERT INTO `productos` (`categoria_id`, `nombre`, `descripcion`, `nota_olfativa`, `precio`, `stock`, `imagen_url`, `tipo`, `activo`) VALUES
(2, 'Noir Absolu — Tester', 'Tester oficial de Noir Absolu. Frasco sin caja, contenido completo 100ml. Misma calidad, precio accesible para descubrir esta fragancia icónica.', 'Notas de salida: Bergamota, Pimienta Negra. Corazón: Oud, Rosa Damascena. Fondo: Ámbar, Sándalo, Almizcle', 42000.00, 5, 'https://via.placeholder.com/600x600/0D0D0D/F2ECE6?text=TESTER', 'tester', 1),
(2, 'Velours Doré — Tester', 'Tester oficial de Velours Doré. Frasco sin caja, contenido completo 100ml. La esencia del lujo accesible para explorar antes de comprometerte.', 'Notas de salida: Neroli, Ylang-Ylang. Corazón: Jazmín Absoluto, Iris. Fondo: Cedro, Vainilla, Pachulí', 48000.00, 3, 'https://via.placeholder.com/600x600/D6C6AD/0D0D0D?text=TESTER', 'tester', 1),
(2, 'Brume Sacrée — Tester', 'Tester oficial de Brume Sacrée. Frasco sin caja, contenido completo 100ml. Descubrí la experiencia sagrada de esta fragancia acuática a precio especial.', 'Notas de salida: Limón, Sal Marina. Corazón: Incienso, Jazmín Marino. Fondo: Vetiver, Musgo Blanco', 35000.00, 7, 'https://via.placeholder.com/600x600/A8A29A/F2ECE6?text=TESTER', 'tester', 1);

-- Admin user: password = "Nuve2024!" (bcrypt)
INSERT INTO `admin_users` (`username`, `email`, `password_hash`) VALUES
('admin', 'admin@nuve.com', '$2y$12$7y6WMHl0gRjOf8YvdOt8M.UGgpFZzOl/OQx25.V4F8WIsnpWxD9F6');

-- Note: The password hash above is the bcrypt hash of "Nuve2024!"
-- Generated with: password_hash('Nuve2024!', PASSWORD_BCRYPT, ['cost' => 12])
