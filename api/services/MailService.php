<?php

class MailService {

    private static function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool {
        $from     = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@nuve.com';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'NÜVE Perfumería';
        $reply    = $replyTo ?: $from;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $headers .= "Reply-To: {$reply}\r\n";

        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";

        return @mail($to, $encodedSubject, $htmlBody, $headers);
    }

    public static function enviarContacto(string $nombre, string $email, string $asunto, string $mensaje): bool {
        $to = defined('CONTACT_EMAIL') ? CONTACT_EMAIL : 'diegobrunetti33@gmail.com';

        $nombreSafe  = htmlspecialchars($nombre,  ENT_QUOTES);
        $emailSafe   = htmlspecialchars($email,   ENT_QUOTES);
        $asuntoSafe  = htmlspecialchars($asunto,  ENT_QUOTES);
        $mensajeSafe = nl2br(htmlspecialchars($mensaje, ENT_QUOTES));

        $body = self::layout("Nuevo mensaje de contacto", "
            <p><strong>Nombre:</strong> {$nombreSafe}</p>
            <p><strong>Email:</strong> <a href=\"mailto:{$emailSafe}\">{$emailSafe}</a></p>
            <p><strong>Asunto:</strong> {$asuntoSafe}</p>
            <p style=\"margin-top:1rem;\"><strong>Mensaje:</strong></p>
            <p style=\"white-space:pre-line;\">{$mensajeSafe}</p>
        ");

        // Reply-To = email del visitante para poder responderle directamente
        return self::send($to, "Contacto web — {$asuntoSafe}", $body, $email);
    }

    public static function enviarPedidoCreado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = htmlspecialchars($pedido['cliente_nombre'] ?? '', ENT_QUOTES);
        $pedidoId = (int)($pedido['id'] ?? 0);

        $body = self::layout("Pedido #{$pedidoId} recibido", "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pago está siendo procesado. Te notificaremos por email cuando se confirme.</p>
            <p>Gracias por tu paciencia.</p>
            <p style=\"margin-top:1.5rem;\"><strong>Número de pedido: #{$pedidoId}</strong></p>
        ");

        self::send($email, "Tu pedido #{$pedidoId} fue recibido — NÜVE Perfumería", $body);
    }

    public static function enviarPedidoAprobado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = htmlspecialchars($pedido['cliente_nombre'] ?? '', ENT_QUOTES);
        $pedidoId = (int)($pedido['id'] ?? 0);

        $body = self::layout("Pedido #{$pedidoId} aprobado", "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>¡Buenas noticias! Tu pedido <strong>#{$pedidoId}</strong> fue <strong>aprobado</strong> y ya está siendo preparado.</p>
            <p>Nos contactaremos contigo para coordinar el envío por moto.</p>
            <p>¡Gracias por elegir NÜVE Perfumería!</p>
        ");

        self::send($email, "Tu pedido #{$pedidoId} fue aprobado — NÜVE Perfumería", $body);
    }

    public static function enviarPedidoRechazado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = htmlspecialchars($pedido['cliente_nombre'] ?? '', ENT_QUOTES);
        $pedidoId = (int)($pedido['id'] ?? 0);

        $body = self::layout("Pedido #{$pedidoId} — información de pago", "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Lamentablemente tu pedido <strong>#{$pedidoId}</strong> no pudo ser procesado.</p>
            <p>Si tenés alguna consulta, comunicate con nosotros por WhatsApp.</p>
        ");

        self::send($email, "Información sobre tu pedido #{$pedidoId} — NÜVE Perfumería", $body);
    }

    private static function layout(string $title, string $content): string {
        $fromName = defined('MAIL_FROM_NAME') ? htmlspecialchars(MAIL_FROM_NAME, ENT_QUOTES) : 'NÜVE Perfumería';
        $year     = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f5f0ea;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0ea;padding:40px 20px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:4px;overflow:hidden;max-width:600px;width:100%;">
          <tr>
            <td style="background:#1a1a1a;padding:24px 32px;text-align:center;">
              <span style="color:#e8d8c7;font-size:22px;letter-spacing:4px;font-weight:300;">{$fromName}</span>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;color:#333;font-size:14px;line-height:1.7;">
              {$content}
            </td>
          </tr>
          <tr>
            <td style="background:#f5f0ea;padding:20px 32px;text-align:center;font-size:11px;color:#888;">
              &copy; {$year} {$fromName} — Todos los derechos reservados.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
