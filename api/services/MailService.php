<?php

/**
 * Armado de los mails de la tienda. El envio en si lo hace Mailer.
 */
class MailService {

    // ---------------------------------------------------------------
    // Contacto
    // ---------------------------------------------------------------

    public static function enviarContacto(string $nombre, string $email, string $asunto, string $mensaje): bool {
        $to = self::emailContacto();

        $nombreSafe  = self::esc($nombre);
        $emailSafe   = self::esc($email);
        $asuntoSafe  = self::esc($asunto);
        $mensajeSafe = nl2br(self::esc($mensaje));

        $body = self::layout("Nuevo mensaje de contacto", "
            <p><strong>Nombre:</strong> {$nombreSafe}</p>
            <p><strong>Email:</strong> <a href=\"mailto:{$emailSafe}\" style=\"color:#1F3D2E;\">{$emailSafe}</a></p>
            <p><strong>Asunto:</strong> {$asuntoSafe}</p>
            <p style=\"margin-top:24px;\"><strong>Mensaje:</strong></p>
            <p style=\"white-space:pre-line;\">{$mensajeSafe}</p>
        ");

        // Reply-To = el visitante, para poder responderle directo desde el cliente de mail.
        return Mailer::enviar($to, "Contacto web — {$asuntoSafe}", $body, $email, null, 'contacto');
    }

    // ---------------------------------------------------------------
    // Ciclo del pedido
    // ---------------------------------------------------------------

    /** Al crear el pedido: comprobante con el detalle completo. */
    public static function enviarPedidoCreado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Recibimos tu pedido. Te escribimos de nuevo apenas se confirme el pago.</p>
            " . self::bloqueResumen($pedido) . "
            " . self::bloqueEntrega($pedido) . "
            <p style=\"margin-top:28px;color:#8B7966;font-size:13px;\">
              Guardá este mail: el número de pedido te sirve para cualquier consulta.
            </p>
        ";

        $body = self::layout("Pedido #{$pedidoId} recibido", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Recibimos tu pedido #{$pedidoId} — KABODHI", $body, null, $pedidoId, 'pedido_creado');

        // Aviso interno para no depender de mirar el panel.
        self::avisarAdmin($pedido);
    }

    public static function enviarPedidoAprobado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pago se confirmó y ya estamos preparando el pedido. Cuando salga te mandamos
               el código de seguimiento.</p>
            " . self::bloqueResumen($pedido) . "
            " . self::bloqueEntrega($pedido) . "
            <p style=\"margin-top:28px;\">Gracias por elegirnos.</p>
        ";

        $body = self::layout("Pago confirmado", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Confirmamos el pago de tu pedido #{$pedidoId} — KABODHI", $body, null, $pedidoId, 'pedido_aprobado');
    }

    /** Al marcar el pedido como enviado: datos de seguimiento. */
    public static function enviarPedidoEnviado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pedido <strong>#{$pedidoId}</strong> ya salió para tu domicilio.</p>
            " . self::bloqueSeguimiento($pedido) . "
            " . self::bloqueEntrega($pedido) . "
            " . self::bloqueResumen($pedido) . "
        ";

        $body = self::layout("Tu pedido está en camino", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Tu pedido #{$pedidoId} está en camino — KABODHI", $body, null, $pedidoId, 'pedido_enviado');
    }

    public static function enviarPedidoEntregado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);
        $whatsapp = self::linkWhatsapp();

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Registramos la entrega de tu pedido <strong>#{$pedidoId}</strong>. Esperamos que lo disfrutes.</p>
            <p>Si algo no llegó como esperabas, respondé este mail"
            . ($whatsapp ? " o escribinos por <a href=\"{$whatsapp}\" style=\"color:#1F3D2E;\">WhatsApp</a>" : '')
            . " y lo resolvemos.</p>
        ";

        $body = self::layout("Pedido entregado", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Tu pedido #{$pedidoId} fue entregado — KABODHI", $body, null, $pedidoId, 'pedido_entregado');
    }

    public static function enviarPedidoRechazado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);
        $whatsapp = self::linkWhatsapp();

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>No pudimos procesar el pago de tu pedido <strong>#{$pedidoId}</strong>, así que quedó sin efecto.
               No se te cobró nada.</p>
            <p>Si querés volver a intentarlo o preferís coordinar de otra forma, respondé este mail"
            . ($whatsapp ? " o escribinos por <a href=\"{$whatsapp}\" style=\"color:#1F3D2E;\">WhatsApp</a>" : '')
            . ".</p>
        ";

        $body = self::layout("No pudimos procesar el pago", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Sobre tu pedido #{$pedidoId} — KABODHI", $body, null, $pedidoId, 'pedido_rechazado');
    }

    public static function enviarPedidoCancelado(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pedido <strong>#{$pedidoId}</strong> fue cancelado. Si no lo pediste vos, respondé este mail
               y lo revisamos.</p>
        ";

        $body = self::layout("Pedido cancelado", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Tu pedido #{$pedidoId} fue cancelado — KABODHI", $body, null, $pedidoId, 'pedido_cancelado');
    }

    /** Copia interna cuando entra un pedido nuevo. */
    private static function avisarAdmin(array $pedido): void {
        $to = self::emailContacto();
        if (!$to) return;

        $pedidoId = (int)($pedido['id'] ?? 0);
        $cliente  = self::esc($pedido['cliente_nombre']   ?? '');
        $email    = self::esc($pedido['cliente_email']    ?? '');
        $telefono = self::esc($pedido['cliente_telefono'] ?? '');

        $contenido = "
            <p>Entró el pedido <strong>#{$pedidoId}</strong>.</p>
            <p>
              <strong>Cliente:</strong> {$cliente}<br>
              <strong>Email:</strong> {$email}<br>
              " . ($telefono ? "<strong>Teléfono:</strong> {$telefono}" : '') . "
            </p>
            " . self::bloqueResumen($pedido, 'Detalle') . "
            " . self::bloqueEntrega($pedido) . "
        ";

        $body = self::layout("Pedido nuevo #{$pedidoId}", $contenido, "Aviso interno", true);
        Mailer::enviar($to, "[KABODHI] Pedido nuevo #{$pedidoId}", $body, null, $pedidoId, 'aviso_admin');
    }

    // ---------------------------------------------------------------
    // Bloques reutilizables
    // ---------------------------------------------------------------

    /** Tabla de items con subtotal, envio y total. */
    private static function bloqueResumen(array $pedido, string $titulo = 'Tu pedido'): string {
        $items = $pedido['items'] ?? [];
        if (!$items) return '';

        $subtotal = 0.0;
        $filas    = '';

        foreach ($items as $item) {
            $cantidad = (int)($item['cantidad'] ?? 0);
            $precio   = (float)($item['precio_unitario'] ?? 0);
            $linea    = $precio * $cantidad;
            $subtotal += $linea;

            $nombre = self::esc($item['producto_nombre'] ?? ('Producto #' . ($item['producto_id'] ?? '')));
            $filas .= "
              <tr>
                <td style=\"padding:10px 0;border-bottom:1px solid #eee;font-size:14px;\">
                  {$nombre}<span style=\"color:#8B7966;\"> &times; {$cantidad}</span>
                </td>
                <td style=\"padding:10px 0;border-bottom:1px solid #eee;text-align:right;font-size:14px;white-space:nowrap;\">
                  " . self::money($linea) . "
                </td>
              </tr>";
        }

        $envio      = (float)($pedido['envio_costo'] ?? 0);
        $envioDesc  = $pedido['envio_descripcion'] ?? '';
        $envioLabel = 'Envío' . ($envioDesc ? ' — ' . self::esc($envioDesc) : '');
        $envioValor = $envio > 0 ? self::money($envio) : 'Sin cargo';
        $total      = (float)($pedido['total'] ?? 0);

        return "
        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:24px 0;border-collapse:collapse;\">
          <tr>
            <td colspan=\"2\" style=\"padding-bottom:8px;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8B7966;\">
              " . self::esc($titulo) . "
            </td>
          </tr>
          {$filas}
          <tr>
            <td style=\"padding:10px 0 4px;font-size:14px;color:#8B7966;\">Subtotal</td>
            <td style=\"padding:10px 0 4px;text-align:right;font-size:14px;color:#8B7966;\">" . self::money($subtotal) . "</td>
          </tr>
          <tr>
            <td style=\"padding:4px 0;font-size:14px;color:#8B7966;\">{$envioLabel}</td>
            <td style=\"padding:4px 0;text-align:right;font-size:14px;color:#8B7966;\">{$envioValor}</td>
          </tr>
          <tr>
            <td style=\"padding:12px 0 0;border-top:2px solid #1F3D2E;font-size:16px;font-weight:bold;\">Total</td>
            <td style=\"padding:12px 0 0;border-top:2px solid #1F3D2E;text-align:right;font-size:16px;font-weight:bold;\">
              " . self::money($total) . "
            </td>
          </tr>
        </table>";
    }

    private static function bloqueEntrega(array $pedido): string {
        $direccion = trim((string)($pedido['cliente_direccion'] ?? ''));
        if ($direccion === '') return '';

        return "
        <div style=\"margin:24px 0;padding:16px;background:#F5F1E8;border-radius:4px;\">
          <div style=\"font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8B7966;margin-bottom:6px;\">
            Dirección de entrega
          </div>
          <div style=\"font-size:14px;\">" . self::esc($direccion) . "</div>
        </div>";
    }

    private static function bloqueSeguimiento(array $pedido): string {
        $transporte = trim((string)($pedido['transporte']      ?? ''));
        $codigo     = trim((string)($pedido['tracking_codigo'] ?? ''));
        $url        = trim((string)($pedido['tracking_url']    ?? ''));

        if ($transporte === '' && $codigo === '' && $url === '') {
            return "
            <p style=\"color:#8B7966;font-size:13px;\">
              Te avisamos por este medio si hay novedades con la entrega.
            </p>";
        }

        $lineas = '';
        if ($transporte !== '') {
            $lineas .= "<div style=\"font-size:14px;margin-bottom:4px;\"><strong>Transporte:</strong> " . self::esc($transporte) . "</div>";
        }
        if ($codigo !== '') {
            $lineas .= "<div style=\"font-size:14px;margin-bottom:4px;\"><strong>Seguimiento:</strong> "
                     . "<span style=\"font-family:monospace;letter-spacing:1px;\">" . self::esc($codigo) . "</span></div>";
        }

        $boton = '';
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $urlSafe = self::esc($url);
            $boton = "
              <a href=\"{$urlSafe}\"
                 style=\"display:inline-block;margin-top:12px;padding:11px 22px;background:#1F3D2E;color:#F5F1E8;
                        text-decoration:none;border-radius:4px;font-size:13px;letter-spacing:1px;\">
                Seguir mi envío
              </a>";
        }

        return "
        <div style=\"margin:24px 0;padding:18px;background:#F5F1E8;border-radius:4px;\">
          <div style=\"font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8B7966;margin-bottom:10px;\">
            Seguimiento
          </div>
          {$lineas}{$boton}
        </div>";
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function esc(?string $texto): string {
        return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
    }

    private static function money(float $monto): string {
        return '$ ' . number_format($monto, 0, ',', '.');
    }

    /** "Ana Maria Gomez" -> "Ana": el saludo suena mejor con el primer nombre. */
    private static function primerNombre(string $nombreCompleto): string {
        $partes = preg_split('/\s+/', trim($nombreCompleto));
        return $partes[0] ?? $nombreCompleto;
    }

    private static function emailContacto(): string {
        try {
            $config = (new ConfigService())->get('contacto_email', '');
            if ($config) return $config;
        } catch (Throwable $e) {
            /* si la config no esta disponible, caemos a la constante */
        }
        return defined('CONTACT_EMAIL') ? CONTACT_EMAIL : '';
    }

    private static function linkWhatsapp(): string {
        try {
            $numero = (new ConfigService())->get('whatsapp_numero', '');
            return $numero ? 'https://wa.me/' . preg_replace('/\D+/', '', $numero) : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function layout(string $title, string $content, string $etiqueta = '', bool $esInterno = false): string {
        $year = date('Y');
        $etiquetaHtml = $etiqueta !== ''
            ? "<div style=\"font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#8B7966;margin-bottom:6px;\">"
              . self::esc($etiqueta) . "</div>"
            : '';

        $titleSafe = self::esc($title);
        $pie = $esInterno
            ? 'Aviso automático del panel de KABODHI.'
            : 'Este mail se envió automáticamente por tu compra. Podés responderlo si necesitás ayuda.';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{$titleSafe}</title>
</head>
<body style="margin:0;padding:0;background:#F5F1E8;font-family:Helvetica,Arial,sans-serif;color:#1F3D2E;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F1E8;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;max-width:600px;width:100%;">
          <tr>
            <td style="background:#1F3D2E;padding:28px 32px;text-align:center;">
              <div style="color:#F5F1E8;font-size:24px;letter-spacing:6px;font-weight:300;">KABODHI</div>
              <div style="color:#A66B3D;font-size:10px;letter-spacing:3px;text-transform:uppercase;margin-top:6px;">
                Adaptógenos naturales
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;font-size:15px;line-height:1.7;">
              {$etiquetaHtml}
              <h1 style="font-size:21px;font-weight:normal;margin:0 0 20px;color:#1F3D2E;">{$titleSafe}</h1>
              {$content}
            </td>
          </tr>
          <tr>
            <td style="background:#F5F1E8;padding:20px 32px;text-align:center;font-size:11px;color:#8B7966;line-height:1.6;">
              &copy; {$year} KABODHI<br>
              {$pie}
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
