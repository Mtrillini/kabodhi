<?php

/**
 * Limite de peticiones por IP para los endpoints publicos que crean pedidos
 * o mandan mails. Sin esto, un script podia crear pedidos sin parar (dejando
 * el catalogo sin stock) o usar el formulario de contacto para spamear.
 *
 * Cuenta en la tabla rate_limit. Si la tabla no existe o la base falla, deja
 * pasar y lo anota en el log: un problema nuestro nunca frena una compra.
 */
class RateLimiter {

    /**
     * true si la IP todavia puede hacer $accion; registra el intento.
     * @param int $maximo     intentos permitidos dentro de la ventana
     * @param int $ventanaMin minutos que se miran hacia atras
     */
    public static function permitir(string $accion, int $maximo, int $ventanaMin): bool {
        $clave = mb_substr($accion . ':' . self::ip(), 0, 160);
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM rate_limit
                 WHERE clave = :clave AND created_at > DATE_SUB(NOW(), INTERVAL :ventana MINUTE)"
            );
            $stmt->bindValue(':clave', $clave);
            $stmt->bindValue(':ventana', $ventanaMin, PDO::PARAM_INT);
            $stmt->execute();
            if ((int)$stmt->fetchColumn() >= $maximo) {
                return false;
            }

            $db->prepare("INSERT INTO rate_limit (clave) VALUES (:clave)")->execute([':clave' => $clave]);

            // Purga ocasional: la tabla no tiene otra limpieza.
            if (random_int(1, 20) === 1) {
                $db->exec("DELETE FROM rate_limit WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
            }
            return true;

        } catch (Throwable $e) {
            error_log('RateLimiter: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * IP del visitante. Detras del CDN de Hostinger REMOTE_ADDR es siempre la
     * del proxy, asi que se prefiere el primer valor de X-Forwarded-For.
     */
    public static function ip(): string {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $primera = trim(explode(',', $xff)[0]);
            if (filter_var($primera, FILTER_VALIDATE_IP)) {
                return substr($primera, 0, 45);
            }
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida'), 0, 45);
    }
}
