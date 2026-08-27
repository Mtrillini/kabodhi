<?php

/**
 * Transporte de mail.
 *
 * Elige el primer canal disponible:
 *   1. SMTP, si hay MAIL_SMTP_HOST configurado (lo normal en produccion).
 *   2. mail() de PHP, como respaldo en hostings que lo tengan andando.
 *   3. Spool a disco (storage/mails/*.html), para desarrollo: no hay servidor
 *      de mail en local, asi que el mail se guarda para poder revisarlo.
 *
 * Cada intento queda registrado en la tabla mail_log.
 */
class Mailer {

    /** Segundos de espera para conectar y para cada respuesta del servidor. */
    private const TIMEOUT = 15;

    /**
     * @param string|null $replyTo  Reply-To distinto del remitente.
     * @param int|null    $pedidoId Para asociar el registro en mail_log.
     * @param string      $tipo     Etiqueta del tipo de mail (para mail_log).
     */
    public static function enviar(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $replyTo = null,
        ?int $pedidoId = null,
        string $tipo = 'generico'
    ): bool {
        $error = null;
        $exito = false;

        try {
            if (self::smtpConfigurado()) {
                $exito = self::enviarPorSmtp($to, $subject, $htmlBody, $replyTo);
            } else {
                $exito = self::enviarPorMail($to, $subject, $htmlBody, $replyTo);
                if (!$exito) {
                    // Sin SMTP y sin mail(): lo dejamos en disco para no perderlo.
                    // exito queda en false a proposito: el mail NO llego al cliente,
                    // y en produccion eso tiene que verse en el log.
                    $spooled = self::spool($to, $subject, $htmlBody);
                    $error = $spooled
                        ? 'Sin SMTP y mail() no disponible; el mail quedó en storage/mails/'
                        : 'Sin SMTP, mail() no disponible y no se pudo escribir storage/mails/';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            error_log('Mailer: ' . $error);
            // Aunque falle el envio, guardamos el contenido para poder reenviarlo.
            self::spool($to, $subject, $htmlBody);
        }

        self::registrar($to, $subject, $tipo, $exito, $error, $pedidoId);
        return $exito;
    }

    private static function smtpConfigurado(): bool {
        return defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '';
    }

    // ---------------------------------------------------------------
    // Canal 1: SMTP
    // ---------------------------------------------------------------

    private static function enviarPorSmtp(string $to, string $subject, string $htmlBody, ?string $replyTo): bool {
        $host   = MAIL_SMTP_HOST;
        $port   = (int)(defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 587);
        $user   = defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '';
        $pass   = defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : '';
        $secure = strtolower(defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : 'tls');

        // El puerto 465 habla TLS desde el saludo; el 587 arranca en claro y sube con STARTTLS.
        $remoto = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";

        $contexto = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $socket = @stream_socket_client($remoto, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $contexto);
        if (!$socket) {
            throw new RuntimeException("No se pudo conectar a {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($socket, self::TIMEOUT);

        try {
            self::esperar($socket, 220);

            $hostLocal = self::hostLocal();
            self::comando($socket, "EHLO {$hostLocal}", 250);

            if ($secure === 'tls') {
                self::comando($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Fallo el handshake TLS.');
                }
                // Tras STARTTLS hay que volver a saludar.
                self::comando($socket, "EHLO {$hostLocal}", 250);
            }

            if ($user !== '') {
                self::comando($socket, 'AUTH LOGIN', 334);
                self::comando($socket, base64_encode($user), 334);
                self::comando($socket, base64_encode($pass), 235);
            }

            $from = self::remitente();
            self::comando($socket, "MAIL FROM:<{$from}>", 250);
            self::comando($socket, "RCPT TO:<{$to}>", [250, 251]);
            self::comando($socket, 'DATA', 354);

            $mensaje = self::armarMensaje($to, $subject, $htmlBody, $replyTo);
            fwrite($socket, $mensaje . "\r\n.\r\n");
            self::esperar($socket, 250);

            self::comando($socket, 'QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }

        return true;
    }

    /** Envia un comando y valida el codigo de respuesta. */
    private static function comando($socket, string $comando, $esperado): string {
        fwrite($socket, $comando . "\r\n");
        return self::esperar($socket, $esperado);
    }

    /** Lee la respuesta del servidor (soporta multilinea) y valida el codigo. */
    private static function esperar($socket, $esperado): string {
        $esperados = is_array($esperado) ? $esperado : [$esperado];
        $respuesta = '';

        while (($linea = fgets($socket, 515)) !== false) {
            $respuesta .= $linea;
            // En una respuesta multilinea, el 4to caracter es "-" salvo en la ultima.
            if (strlen($linea) < 4 || $linea[3] !== '-') break;
        }

        if ($respuesta === '') {
            throw new RuntimeException('El servidor SMTP no respondió (timeout).');
        }

        $codigo = (int)substr($respuesta, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException('SMTP respondió: ' . trim($respuesta));
        }

        return $respuesta;
    }

    // ---------------------------------------------------------------
    // Canal 2: mail() de PHP
    // ---------------------------------------------------------------

    private static function enviarPorMail(string $to, string $subject, string $htmlBody, ?string $replyTo): bool {
        $from     = self::remitente();
        $fromName = self::nombreRemitente();
        $reply    = $replyTo ?: $from;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . self::encabezadoUtf8($fromName) . " <{$from}>\r\n";
        $headers .= "Reply-To: {$reply}\r\n";

        return @mail($to, self::encabezadoUtf8($subject), $htmlBody, $headers);
    }

    // ---------------------------------------------------------------
    // Canal 3: spool a disco (desarrollo)
    // ---------------------------------------------------------------

    private static function spool(string $to, string $subject, string $htmlBody): bool {
        $dir = dirname(__DIR__, 2) . '/storage/mails';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }

        $nombre = date('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 6) . '.html';
        $cabecera = "<!-- Para: {$to} | Asunto: {$subject} | " . date('c') . " -->\n";

        return @file_put_contents($dir . '/' . $nombre, $cabecera . $htmlBody) !== false;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function armarMensaje(string $to, string $subject, string $htmlBody, ?string $replyTo): string {
        $from  = self::remitente();
        $reply = $replyTo ?: $from;

        $cabeceras = [
            'Date: ' . date('r'),
            'From: ' . self::encabezadoUtf8(self::nombreRemitente()) . " <{$from}>",
            "To: <{$to}>",
            'Subject: ' . self::encabezadoUtf8($subject),
            "Reply-To: {$reply}",
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::hostLocal() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // Una linea que empieza con "." se escapa duplicandola (RFC 5321).
        $cuerpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\r\n", $htmlBody));

        return implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo;
    }

    private static function encabezadoUtf8(string $texto): string {
        // Los encabezados solo aceptan ASCII: los acentos van en base64.
        return preg_match('/[\x80-\xFF]/', $texto)
            ? '=?UTF-8?B?' . base64_encode($texto) . '?='
            : $texto;
    }

    private static function remitente(): string {
        return defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'noreply@kabodhi.com';
    }

    private static function nombreRemitente(): string {
        return defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'KABODHI';
    }

    private static function hostLocal(): string {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'localhost';
    }

    private static function registrar(string $to, string $subject, string $tipo, bool $exito, ?string $error, ?int $pedidoId): void {
        try {
            $stmt = Database::getInstance()->prepare(
                "INSERT INTO mail_log (pedido_id, destino, asunto, tipo, exito, error)
                 VALUES (:pedido_id, :destino, :asunto, :tipo, :exito, :error)"
            );
            $stmt->execute([
                ':pedido_id' => $pedidoId,
                ':destino'   => $to,
                ':asunto'    => mb_substr($subject, 0, 300),
                ':tipo'      => $tipo,
                ':exito'     => $exito ? 1 : 0,
                ':error'     => $error !== null ? mb_substr($error, 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            // El log no debe romper el flujo del pedido.
            error_log('Mailer::registrar: ' . $e->getMessage());
        }
    }
}
