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
            <p><strong>Email:</strong> <a href=\"mailto:{$emailSafe}\" style=\"color:#1C3A4F;\">{$emailSafe}</a></p>
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

    /**
     * Al crear el pedido. Por transferencia el cliente todavia tiene que
     * hacer algo (mandar el comprobante), asi que el mail sale enseguida
     * con los datos bancarios. Por Mercado Pago no: si todavia no pago,
     * mandarle un mail de "pedido recibido" es confuso (y si abandona el
     * pago, le queda un mail de un pedido que nunca se concreto). Ahi el
     * primer mail al cliente es enviarPedidoAprobado, cuando el webhook
     * confirma el pago. El aviso interno al equipo sale siempre.
     */
    public static function enviarPedidoCreado(array $pedido): void {
        $pedidoId = (int)($pedido['id'] ?? 0);
        $porTransferencia = ($pedido['metodo_pago'] ?? '') === 'transferencia';

        if ($porTransferencia) {
            $email = $pedido['cliente_email'] ?? '';
            if ($email) {
                $nombre = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));

                $contenido = "
                    <p>Hola <strong>{$nombre}</strong>,</p>
                    <p>Recibimos tu pedido. Para confirmarlo, transferí el total a la cuenta de abajo y mandanos el comprobante.</p>
                    " . self::bloqueTransferencia($pedido) . "
                    " . self::bloqueResumen($pedido) . "
                    " . self::bloqueEntrega($pedido) . "
                    <p style=\"margin-top:28px;color:#8B7966;font-size:13px;\">
                      Guardá este mail: el número de pedido te sirve para cualquier consulta.
                    </p>
                ";

                $body = self::layout("Pedido #{$pedidoId} recibido", $contenido, "Pedido #{$pedidoId}");
                Mailer::enviar($email, "Recibimos tu pedido #{$pedidoId} — KABODHI", $body, null, $pedidoId, 'pedido_creado');
            }
        }

        // Aviso interno para no depender de mirar el panel: sale siempre,
        // este pago o no, para que el equipo tenga visibilidad del pedido.
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

        $destino = self::esRetiroEnSucursal($pedido)
            ? 'ya fue despachado a la sucursal de Correo Argentino que elegiste. Te avisamos cuando esté listo para retirar.'
            : 'ya salió para tu domicilio.';

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pedido <strong>#{$pedidoId}</strong> {$destino}</p>
            " . self::bloqueSeguimiento($pedido) . "
            " . self::bloqueEntrega($pedido) . "
            " . self::bloqueResumen($pedido) . "
        ";

        $body = self::layout("Tu pedido está en camino", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Tu pedido #{$pedidoId} está en camino — KABODHI", $body, null, $pedidoId, 'pedido_enviado');
    }

    /** El paquete llego a la sucursal de Correo y el cliente ya puede retirarlo. */
    public static function enviarPedidoEnSucursal(array $pedido): void {
        $email = $pedido['cliente_email'] ?? '';
        if (!$email) return;

        $nombre   = self::esc(self::primerNombre($pedido['cliente_nombre'] ?? ''));
        $pedidoId = (int)($pedido['id'] ?? 0);

        $contenido = "
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Tu pedido <strong>#{$pedidoId}</strong> ya está en la sucursal de Correo Argentino, listo para retirar.</p>
            " . self::bloqueEntrega($pedido) . "
            " . self::bloqueSeguimiento($pedido) . "
            <p style=\"color:#8B7966;font-size:13px;\">
              El correo lo guarda unos días; si no lo retirás en ese plazo vuelve a nuestro depósito.
            </p>
        ";

        $body = self::layout("Tu pedido te espera en la sucursal", $contenido, "Pedido #{$pedidoId}");
        Mailer::enviar($email, "Tu pedido #{$pedidoId} está listo para retirar — KABODHI", $body, null, $pedidoId, 'pedido_en_sucursal');
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
            . ($whatsapp ? " o escribinos por <a href=\"{$whatsapp}\" style=\"color:#1C3A4F;\">WhatsApp</a>" : '')
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
            . ($whatsapp ? " o escribinos por <a href=\"{$whatsapp}\" style=\"color:#1C3A4F;\">WhatsApp</a>" : '')
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

    /**
     * Invitacion al panel. El link va en el cuerpo y tambien se le muestra a
     * quien la genera, asi que si el mail no sale (SMTP sin configurar) igual
     * se puede pasar por otro medio.
     */
    public static function enviarInvitacion(string $email, string $link, string $rol, int $dias): bool {
        $rolTexto = $rol === 'super' ? 'administrador principal' : 'operador';
        $linkSafe = self::esc($link);

        $contenido = "
            <p>Te invitaron a administrar la tienda de KABODHI como <strong>{$rolTexto}</strong>.</p>
            <p>Entrá al link y elegí tu usuario y tu contraseña. Nadie más las va a ver.</p>
            <p style=\"margin:28px 0;\">
              <a href=\"{$linkSafe}\"
                 style=\"display:inline-block;padding:12px 24px;background:#1C3A4F;color:#F5F1E8;
                        text-decoration:none;border-radius:4px;font-size:14px;letter-spacing:1px;\">
                Crear mi acceso
              </a>
            </p>
            <p style=\"color:#8B7966;font-size:13px;\">
              El link vence en {$dias} días y sirve una sola vez.
              Si no te esperabas esta invitación, ignorá el mensaje.
            </p>
            <p style=\"color:#8B7966;font-size:12px;word-break:break-all;\">
              Si el botón no funciona, copiá esta dirección:<br>{$linkSafe}
            </p>
        ";

        $body = self::layout('Tu acceso al panel', $contenido, 'Invitación');
        return Mailer::enviar($email, 'Te invitaron al panel de KABODHI', $body, null, null, 'invitacion');
    }

    /** Copia interna cuando entra un pedido nuevo. */
    private static function avisarAdmin(array $pedido): void {
        $to = self::emailContacto();
        if (!$to) return;

        $pedidoId = (int)($pedido['id'] ?? 0);
        $cliente  = self::esc($pedido['cliente_nombre']   ?? '');
        $email    = self::esc($pedido['cliente_email']    ?? '');
        $telefono = self::esc($pedido['cliente_telefono'] ?? '');
        $dni      = self::esc($pedido['cliente_dni']      ?? '');

        $contenido = "
            <p>Entró el pedido <strong>#{$pedidoId}</strong>.</p>
            <p>
              <strong>Cliente:</strong> {$cliente}<br>
              <strong>Email:</strong> {$email}<br>
              " . ($telefono ? "<strong>Teléfono:</strong> {$telefono}<br>" : '') . "
              " . ($dni ? "<strong>DNI:</strong> {$dni}" : '') . "
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

            $nombre = $item['producto_nombre'] ?? ('Producto #' . ($item['producto_id'] ?? ''));
            // La opcion elegida (aroma, tamano) va pegada al nombre; los
            // pedidos anteriores a las variantes no la tienen.
            if (!empty($item['variante_nombre'])) {
                $nombre .= ' — ' . $item['variante_nombre'];
            }
            if (!empty($item['promo_nombre'])) {
                $nombre .= ' (combo: ' . $item['promo_nombre'] . ')';
            }
            $nombre = self::esc($nombre);
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

        $descuento     = (float)($pedido['descuento_monto'] ?? 0);
        $filaDescuento = '';
        if ($descuento > 0) {
            $pct = (float)($pedido['descuento_pct'] ?? 0);
            $filaDescuento = "
          <tr>
            <td style=\"padding:4px 0;font-size:14px;color:#3a7a3a;\">Descuento por transferencia" . ($pct > 0 ? ' (' . rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',') . '%)' : '') . "</td>
            <td style=\"padding:4px 0;text-align:right;font-size:14px;color:#3a7a3a;\">&minus; " . self::money($descuento) . "</td>
          </tr>";
        }

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
          </tr>{$filaDescuento}
          <tr>
            <td style=\"padding:4px 0;font-size:14px;color:#8B7966;\">{$envioLabel}</td>
            <td style=\"padding:4px 0;text-align:right;font-size:14px;color:#8B7966;\">{$envioValor}</td>
          </tr>
          <tr>
            <td style=\"padding:12px 0 0;border-top:2px solid #1C3A4F;font-size:16px;font-weight:bold;\">Total</td>
            <td style=\"padding:12px 0 0;border-top:2px solid #1C3A4F;text-align:right;font-size:16px;font-weight:bold;\">
              " . self::money($total) . "
            </td>
          </tr>
        </table>";
    }

    /** Datos bancarios para pagar por transferencia (van en el mail de pedido recibido). */
    private static function bloqueTransferencia(array $pedido): string {
        $t = (new ConfigService())->getPagoConfig()['transferencia'];
        $total = self::money((float)($pedido['total'] ?? 0));

        $fila = function (string $label, string $valor, bool $mono = false): string {
            if ($valor === '') return '';
            $estilo = $mono ? 'font-family:monospace;letter-spacing:1px;' : '';
            return "<div style=\"font-size:14px;margin-bottom:6px;\"><strong>{$label}:</strong> <span style=\"{$estilo}\">" . self::esc($valor) . "</span></div>";
        };

        $lineas = $fila('Titular', $t['titular'])
                . $fila('Banco', $t['banco'])
                . $fila('CBU/CVU', $t['cbu'], true)
                . $fila('Alias', $t['alias'], true)
                . $fila('CUIT/CUIL', $t['cuit']);

        $instrucciones = $t['instrucciones'] !== ''
            ? "<div style=\"font-size:13px;color:#8B7966;margin-top:10px;\">" . self::esc($t['instrucciones']) . "</div>"
            : '';

        return "
        <div style=\"margin:24px 0;padding:18px;background:#F5F1E8;border-radius:4px;\">
          <div style=\"font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8B7966;margin-bottom:10px;\">
            Datos para la transferencia
          </div>
          <div style=\"font-size:18px;font-weight:bold;margin-bottom:12px;\">Total a transferir: {$total}</div>
          {$lineas}{$instrucciones}
          <div style=\"font-size:12px;color:#8B7966;margin-top:10px;\">Referencia: pedido #" . (int)($pedido['id'] ?? 0) . "</div>
        </div>";
    }

    /** El pedido es para retirar en una sucursal de Correo Argentino. */
    private static function esRetiroEnSucursal(array $pedido): bool {
        return ($pedido['envio']['tipo_entrega'] ?? '') === 'sucursal';
    }

    private static function bloqueEntrega(array $pedido): string {
        if (self::esRetiroEnSucursal($pedido)) {
            $sucursal = trim((string)($pedido['envio']['sucursal_nombre'] ?? ''));
            if ($sucursal === '') $sucursal = 'Sucursal de Correo Argentino ' . self::esc((string)($pedido['envio']['sucursal_codigo'] ?? ''));
            return "
            <div style=\"margin:24px 0;padding:16px;background:#F5F1E8;border-radius:4px;\">
              <div style=\"font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8B7966;margin-bottom:6px;\">
                Retiro en sucursal
              </div>
              <div style=\"font-size:14px;\">" . self::esc($sucursal) . "</div>
              <div style=\"font-size:12px;color:#8B7966;margin-top:6px;\">Llevá tu DNI para retirarlo.</div>
            </div>";
        }

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

        // Sin link cargado a mano, el de Correo Argentino se arma con el codigo.
        if ($url === '' && !empty($pedido['envio']['tracking_url_publica'])) {
            $url = (string)$pedido['envio']['tracking_url_publica'];
        }

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
                 style=\"display:inline-block;margin-top:12px;padding:11px 22px;background:#1C3A4F;color:#F5F1E8;
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
<body style="margin:0;padding:0;background:#F5F1E8;font-family:'Lato',Helvetica,Arial,sans-serif;color:#1C3A4F;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F1E8;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;max-width:600px;width:100%;">
          <tr>
            <td style="background:#1C3A4F;padding:28px 32px;text-align:center;">
              <div style="font-family:'Playfair Display',Georgia,serif;color:#F5F1E8;font-size:24px;letter-spacing:6px;font-weight:400;">KABODHI</div>
              <div style="color:#A66B3D;font-size:10px;letter-spacing:3px;text-transform:uppercase;margin-top:6px;">
                Adaptógenos naturales
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;font-size:15px;line-height:1.7;">
              {$etiquetaHtml}
              <h1 style="font-family:'Playfair Display',Georgia,serif;font-size:22px;font-weight:400;margin:0 0 20px;color:#1C3A4F;">{$titleSafe}</h1>
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
