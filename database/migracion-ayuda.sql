-- La pagina de Ayuda pasa a editarse desde el panel.
--
-- Estaba escrita a mano en ayuda.html: cambiar un plazo de envio o sumar una
-- pregunta frecuente pedia tocar el archivo y volver a subirlo. Ahora los dos
-- bloques de texto viven en `configuracion` (igual que los de Nosotros) y las
-- preguntas en su propia tabla, con orden y activo.
--
-- Esta migracion no borra nada: crea la tabla y agrega filas de configuracion
-- con el texto que hoy tiene la pagina.

CREATE TABLE IF NOT EXISTS `faq` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pregunta`  VARCHAR(300) NOT NULL,
    `respuesta` TEXT NOT NULL,
    `orden`     INT UNSIGNED NOT NULL DEFAULT 0,
    `activo`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_orden` (`orden`),
    KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Los dos bloques de texto, con lo que hoy dice la pagina.
-- Un renglon en blanco separa parrafos; una linea que empieza con "- " es un item.
INSERT INTO `configuracion` (`clave`, `valor`) VALUES
    ('ayuda_envios_titulo',  'Envíos y entregas'),
    ('ayuda_envios_texto',
'Realizamos envíos a todo el país. Una vez acreditado el pago, despachamos tu pedido dentro de las 24 a 48 horas hábiles.

- CABA y GBA: entrega estimada de 2 a 4 días hábiles.
- Interior del país: entrega estimada de 3 a 7 días hábiles, según destino.

Todos los envíos incluyen código de seguimiento, que te enviamos por WhatsApp o email apenas despachamos tu pedido. El costo de envío se calcula automáticamente en el checkout según tu código postal.

Cada producto viaja protegido con embalaje especial para garantizar que llegue en perfectas condiciones.'),
    ('ayuda_cambios_titulo', 'Cambios y devoluciones'),
    ('ayuda_cambios_texto',
'Queremos que tu compra sea perfecta. Por tratarse de productos de consumo, aceptamos cambios y devoluciones únicamente cuando el producto se encuentra cerrado, sin uso y con su precinto o sellado original intacto.

- Tenés 10 días corridos desde que recibís tu pedido para solicitar un cambio o devolución, conforme a la Ley de Defensa del Consumidor (Ley 24.240).
- Si el producto llegó dañado o no corresponde a lo que compraste, escribinos dentro de las 48 horas de recibido, con fotos del producto y del embalaje, y lo reponemos sin costo.
- Para iniciar un cambio o devolución, contactanos por WhatsApp indicando tu número de pedido.

Una vez recibido y verificado el producto, gestionamos el cambio o el reintegro por el mismo medio de pago utilizado en la compra.'),
    ('ayuda_faq_titulo',     'Preguntas frecuentes')
ON DUPLICATE KEY UPDATE `valor` = `valor`;


-- Las preguntas que hoy estan en la pagina.
INSERT INTO `faq` (`pregunta`, `respuesta`, `orden`) VALUES
    ('¿Qué son los adaptógenos?',
     'Los adaptógenos son sustancias naturales —en nuestro caso, hongos funcionales— que ayudan al cuerpo a adaptarse al estrés y a recuperar su equilibrio. Acompañan la energía, el enfoque, el descanso y las defensas de forma natural.', 1),
    ('¿Qué significa "doble extracción"?',
     'Es un proceso que combina extracción en agua y en alcohol para conservar todos los principios activos del hongo (beta-glucanos y triterpenos). Así logramos la máxima biodisponibilidad y potencia en cada toma.', 2),
    ('¿Cómo tomo los productos?',
     'Las cápsulas se toman con agua según la dosis indicada en el envase. Los polvos se disuelven en agua, café, té o licuados. Podés incorporarlos a tu ritual diario, en el momento del día que más te acomode.', 3),
    ('¿Qué medios de pago aceptan?',
     'Aceptamos tarjetas de crédito y débito a través de Mercado Pago. El pago se procesa en un entorno 100% seguro.', 4),
    ('¿Me pueden ayudar a elegir mi producto?',
     'Por supuesto. Escribinos por WhatsApp o Instagram y contanos qué buscás y te recomendamos el producto ideal para vos.', 5);
