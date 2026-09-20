<?php

/**
 * Pagos de un pedido (tabla `pagos`).
 *
 * Un pedido se paga por Mercado Pago (Checkout Pro) o por transferencia
 * bancaria confirmada a mano. Cada pago/intentos queda registrado con medio,
 * cuotas, monto, comision y neto, y es lo que decide el estado del pedido:
 *
 *   approved            -> pedido aprobado (descuenta stock, mail al cliente)
 *   rejected/cancelled  -> pedido rechazado si todavia estaba pendiente
 *   refunded/charged_back -> pedido cancelado (repone stock)
 *   pending/in_process  -> pedido sigue pendiente
 */
class PagoService {
    private PDO $db;

    /** Estados de MP que consideramos "cobrado". */
    public const ESTADOS_COBRADOS = ['approved'];

    public const ESTADOS_LABEL = [
        'approved'     => 'Aprobado',
        'pending'      => 'Pendiente',
        'in_process'   => 'En proceso',
        'in_mediation' => 'En mediación',
        'authorized'   => 'Autorizado',
        'rejected'     => 'Rechazado',
        'cancelled'    => 'Cancelado',
        'refunded'     => 'Reembolsado',
        'charged_back' => 'Contracargo',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // -----------------------------------------------------------------
    // Lectura
    // -----------------------------------------------------------------

    public function getByPedido(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT g.*, u.username AS usuario
             FROM pagos g
             LEFT JOIN admin_users u ON u.id = g.usuario_id
             WHERE g.pedido_id = :id
             ORDER BY g.id DESC"
        );
        $stmt->execute([':id' => $pedidoId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            unset($r['payload']);
            $r['estado_label'] = self::ESTADOS_LABEL[$r['estado']] ?? $r['estado'];
            $r['medio_label']  = self::medioLabel($r);
        }
        return $rows;
    }

    /** Resumen para el historial/panel: totales cobrados, comisiones, netos. */
    public function resumen(): array {
        $row = $this->db->query(
            "SELECT COUNT(*) AS pagos,
                    SUM(estado = 'approved') AS aprobados,
                    SUM(IF(estado = 'approved', monto, 0)) AS cobrado,
                    SUM(IF(estado = 'approved', comision, 0)) AS comisiones,
                    SUM(IF(estado = 'approved', COALESCE(neto, monto - comision), 0)) AS neto,
                    SUM(reembolsado) AS reembolsado,
                    SUM(estado = 'approved' AND proveedor = 'transferencia') AS transferencias,
                    SUM(estado = 'approved' AND cuotas > 1) AS en_cuotas
             FROM pagos"
        )->fetch();
        return array_map(fn($v) => $v === null ? 0 : $v, $row ?: []);
    }

    // -----------------------------------------------------------------
    // Mercado Pago
    // -----------------------------------------------------------------

    /**
     * Registra (o actualiza) un pago de MP y mueve el pedido segun su estado.
     * Idempotente: el mismo pago puede llegar varias veces (webhook duplicado,
     * sincronizacion manual).
     *
     * @return array {pago, pedido, cambio: string|null}
     */
    public function registrarPagoMP(array $payment): array {
        $resumen  = MercadoPagoService::resumirPago($payment);
        $pedidoId = $resumen['pedido_id'];
        if ($pedidoId <= 0) {
            throw new InvalidArgumentException('El pago no tiene referencia a un pedido.');
        }

        $pedidoService = new PedidoService();
        $pedido = $pedidoService->getById($pedidoId);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$pedidoId} no encontrado.");
        }

        $this->upsert(array_merge($resumen, ['proveedor' => 'mercadopago']));

        // mp_payment_id apunta al ultimo pago conocido del pedido.
        $this->db->prepare("UPDATE pedidos SET mp_payment_id = :mp, metodo_pago = 'mercadopago' WHERE id = :id")
                 ->execute([':mp' => $resumen['referencia'], ':id' => $pedidoId]);

        // Un pago aprobado solo aprueba el pedido si esta en pesos y cubre el
        // total. Con Checkout Pro el monto lo fija la preferencia, pero MP
        // recomienda verificarlo igual: si algun dia llega un "approved" por
        // menos plata, el pedido queda como esta y se avisa por log.
        if ($resumen['estado'] === 'approved' && !self::pagoCubrePedido($resumen, $pedido)) {
            error_log(sprintf(
                'PagoService: pago MP %s aprobado por %s %s no cubre el pedido #%d (total %s ARS); no se aprueba.',
                $resumen['referencia'], $resumen['monto'], $resumen['moneda'], $pedidoId, $pedido['total']
            ));
            return [
                'pago'   => $this->getUltimo($pedidoId),
                'pedido' => $pedido,
                'cambio' => null,
                'alerta' => 'El pago aprobado no coincide con el total del pedido; revisalo en Mercado Pago.',
            ];
        }

        $cambio = $this->aplicarEstado($pedido, $resumen['estado'], $resumen['aprobado_at']);

        return [
            'pago'   => $this->getUltimo($pedidoId),
            'pedido' => $pedidoService->getById($pedidoId),
            'cambio' => $cambio,
        ];
    }

    /**
     * Trae de MP todos los pagos del pedido y los registra. Sirve cuando el
     * webhook no llego o para completar el detalle de pagos viejos.
     */
    public function sincronizarConMP(int $pedidoId): array {
        $mp = new MercadoPagoService();
        if (!$mp->configurado()) {
            throw new RuntimeException('Mercado Pago no está configurado (MP_ACCESS_TOKEN).');
        }

        $pagos = $mp->buscarPagosPorPedido($pedidoId);

        // Un pedido viejo puede tener el id guardado sin external_reference.
        if (empty($pagos)) {
            $stmt = $this->db->prepare("SELECT mp_payment_id FROM pedidos WHERE id = :id");
            $stmt->execute([':id' => $pedidoId]);
            $mpId = (string)$stmt->fetchColumn();
            if ($mpId !== '' && ctype_digit($mpId)) {
                $p = $mp->getPayment($mpId);
                if ((int)($p['external_reference'] ?? 0) === $pedidoId || empty($p['external_reference'])) {
                    $p['external_reference'] = (string)$pedidoId;
                    $pagos = [$p];
                }
            }
        }

        // Del mas viejo al mas nuevo, asi el ultimo estado es el que queda.
        $resultado = null;
        foreach (array_reverse($pagos) as $p) {
            $resultado = $this->registrarPagoMP($p);
        }

        return [
            'encontrados' => count($pagos),
            'pagos'       => $this->getByPedido($pedidoId),
            'pedido'      => $resultado['pedido'] ?? (new PedidoService())->getById($pedidoId),
        ];
    }

    /**
     * Reembolso (total o parcial) del ultimo pago aprobado de MP. Un reembolso
     * total cancela el pedido (repone stock).
     */
    public function reembolsar(int $pedidoId, ?float $monto, ?int $usuarioId): array {
        $pago = $this->getUltimo($pedidoId, 'mercadopago');
        if (!$pago || $pago['estado'] !== 'approved') {
            throw new RuntimeException('El pedido no tiene un pago de Mercado Pago aprobado para reembolsar.');
        }
        $disponible = (float)$pago['monto'] - (float)$pago['reembolsado'];
        if ($monto !== null && ($monto <= 0 || $monto > $disponible + 0.01)) {
            throw new InvalidArgumentException('El monto a reembolsar tiene que ser mayor a 0 y hasta ' . number_format($disponible, 2, ',', '.') . '.');
        }

        $mp  = new MercadoPagoService();
        $mp->reembolsar((string)$pago['referencia'], $monto);

        // MP ya tiene el nuevo estado: se vuelve a leer y registrar.
        $payment = $mp->getPayment((string)$pago['referencia']);
        $payment['external_reference'] = (string)$pedidoId;
        $r = $this->registrarPagoMP($payment);

        $this->db->prepare("UPDATE pagos SET usuario_id = :u WHERE id = :id")
                 ->execute([':u' => $usuarioId, ':id' => $r['pago']['id']]);

        return $r;
    }

    // -----------------------------------------------------------------
    // Transferencia bancaria
    // -----------------------------------------------------------------

    /** Al crear un pedido por transferencia: queda un pago pendiente a confirmar. */
    public function registrarTransferenciaPendiente(int $pedidoId, float $monto): void {
        $this->db->prepare(
            "INSERT INTO pagos (pedido_id, proveedor, referencia, estado, medio, tipo, cuotas, monto, comision, neto, moneda)
             VALUES (:pedido, 'transferencia', NULL, 'pending', 'transferencia', 'bank_transfer', 1, :monto, 0, :monto2, 'ARS')"
        )->execute([':pedido' => $pedidoId, ':monto' => $monto, ':monto2' => $monto]);
    }

    /**
     * El admin vio la transferencia en el banco: se confirma el pago y el
     * pedido pasa a aprobado.
     */
    public function confirmarTransferencia(int $pedidoId, ?float $monto, string $referencia, ?int $usuarioId): array {
        $pedidoService = new PedidoService();
        $pedido = $pedidoService->getById($pedidoId);
        if (!$pedido) {
            throw new RuntimeException("Pedido #{$pedidoId} no encontrado.");
        }
        if ($pedido['metodo_pago'] !== 'transferencia') {
            throw new RuntimeException('Este pedido no se paga por transferencia.');
        }
        if (!in_array($pedido['estado'], ['pendiente', 'rechazado', 'cancelado'], true)) {
            throw new RuntimeException('El pedido ya está ' . $pedido['estado'] . '.');
        }

        $monto = $monto !== null ? round($monto, 2) : (float)$pedido['total'];
        if ($monto <= 0) {
            throw new InvalidArgumentException('El monto recibido tiene que ser mayor a 0.');
        }
        $referencia = trim($referencia);

        $pendiente = $this->getUltimo($pedidoId, 'transferencia');
        if ($pendiente && $pendiente['estado'] === 'pending') {
            $this->db->prepare(
                "UPDATE pagos SET estado = 'approved', referencia = :ref, monto = :monto, neto = :monto2,
                        aprobado_at = NOW(), usuario_id = :u
                 WHERE id = :id"
            )->execute([':ref' => $referencia !== '' ? $referencia : null, ':monto' => $monto, ':monto2' => $monto,
                        ':u' => $usuarioId, ':id' => $pendiente['id']]);
        } else {
            $this->db->prepare(
                "INSERT INTO pagos (pedido_id, proveedor, referencia, estado, medio, tipo, cuotas, monto, comision, neto, moneda, aprobado_at, usuario_id)
                 VALUES (:pedido, 'transferencia', :ref, 'approved', 'transferencia', 'bank_transfer', 1, :monto, 0, :monto2, 'ARS', NOW(), :u)"
            )->execute([':pedido' => $pedidoId, ':ref' => $referencia !== '' ? $referencia : null,
                        ':monto' => $monto, ':monto2' => $monto, ':u' => $usuarioId]);
        }

        // null: pagado_at lo sella la base con NOW(), igual que created_at.
        $cambio = $this->aplicarEstado($pedido, 'approved', null);

        return [
            'pago'   => $this->getUltimo($pedidoId),
            'pedido' => $pedidoService->getById($pedidoId),
            'cambio' => $cambio,
        ];
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    /** El pago esta en ARS y su monto alcanza el total del pedido (tolerancia de 1 centavo). */
    private static function pagoCubrePedido(array $resumen, array $pedido): bool {
        return strtoupper((string)$resumen['moneda']) === 'ARS'
            && (float)$resumen['monto'] + 0.01 >= (float)$pedido['total'];
    }

    /**
     * Mueve el pedido segun el estado del pago. Devuelve el nuevo estado del
     * pedido o null si no cambio.
     */
    private function aplicarEstado(array $pedido, string $estadoPago, ?string $aprobadoAt): ?string {
        $pedidoId = (int)$pedido['id'];
        $actual   = $pedido['estado'];
        $pedidoService = new PedidoService();

        switch ($estadoPago) {
            case 'approved':
                // Un "approved" repetido sobre un pedido ya despachado no lo
                // hace retroceder ni reenvia el mail.
                if (in_array($actual, ['aprobado', 'enviado', 'entregado'], true)) {
                    $this->sellarPagadoAt($pedidoId, $aprobadoAt);
                    return null;
                }
                $pedidoService->actualizarEstado($pedidoId, 'aprobado');
                $this->sellarPagadoAt($pedidoId, $aprobadoAt);
                return 'aprobado';

            case 'rejected':
            case 'cancelled':
                if ($actual === 'pendiente') {
                    // Libera la reserva de stock y manda el mail de pago rechazado.
                    $pedidoService->actualizarEstado($pedidoId, 'rechazado');
                    return 'rechazado';
                }
                return null;

            case 'refunded':
            case 'charged_back':
                if (!in_array($actual, ['cancelado', 'rechazado'], true)) {
                    $pedidoService->cancelar($pedidoId);
                    return 'cancelado';
                }
                return null;

            default:
                return null;
        }
    }

    private function sellarPagadoAt(int $pedidoId, ?string $aprobadoAt): void {
        $this->db->prepare("UPDATE pedidos SET pagado_at = COALESCE(pagado_at, :at, NOW()) WHERE id = :id")
                 ->execute([':at' => $aprobadoAt, ':id' => $pedidoId]);
    }

    private function upsert(array $r): void {
        $this->db->prepare(
            "INSERT INTO pagos (pedido_id, proveedor, referencia, estado, estado_detalle, medio, tipo, cuotas,
                                monto_cuota, monto, comision, neto, reembolsado, moneda, payload, aprobado_at)
             VALUES (:pedido_id, :proveedor, :referencia, :estado, :estado_detalle, :medio, :tipo, :cuotas,
                     :monto_cuota, :monto, :comision, :neto, :reembolsado, :moneda, :payload, :aprobado_at)
             ON DUPLICATE KEY UPDATE
                estado = VALUES(estado), estado_detalle = VALUES(estado_detalle),
                medio = VALUES(medio), tipo = VALUES(tipo), cuotas = VALUES(cuotas),
                monto_cuota = VALUES(monto_cuota), monto = VALUES(monto), comision = VALUES(comision),
                neto = VALUES(neto), reembolsado = VALUES(reembolsado), moneda = VALUES(moneda),
                payload = VALUES(payload), aprobado_at = COALESCE(aprobado_at, VALUES(aprobado_at))"
        )->execute([
            ':pedido_id'      => $r['pedido_id'],
            ':proveedor'      => $r['proveedor'],
            ':referencia'     => $r['referencia'],
            ':estado'         => $r['estado'],
            ':estado_detalle' => $r['estado_detalle'],
            ':medio'          => $r['medio'],
            ':tipo'           => $r['tipo'],
            ':cuotas'         => $r['cuotas'],
            ':monto_cuota'    => $r['monto_cuota'],
            ':monto'          => $r['monto'],
            ':comision'       => $r['comision'],
            ':neto'           => $r['neto'],
            ':reembolsado'    => $r['reembolsado'],
            ':moneda'         => $r['moneda'],
            ':payload'        => $r['payload'],
            ':aprobado_at'    => $r['aprobado_at'],
        ]);
    }

    private function getUltimo(int $pedidoId, ?string $proveedor = null): ?array {
        $sql = "SELECT * FROM pagos WHERE pedido_id = :id" . ($proveedor ? " AND proveedor = :prov" : '') . " ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $params = [':id' => $pedidoId];
        if ($proveedor) $params[':prov'] = $proveedor;
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) return null;
        unset($row['payload']);
        $row['estado_label'] = self::ESTADOS_LABEL[$row['estado']] ?? $row['estado'];
        $row['medio_label']  = self::medioLabel($row);
        return $row;
    }

    /** "Visa crédito en 6 cuotas", "Dinero en cuenta", "Transferencia bancaria"... */
    public static function medioLabel(array $pago): string {
        if (($pago['proveedor'] ?? '') === 'transferencia') return 'Transferencia bancaria';

        $medios = ['visa' => 'Visa', 'master' => 'Mastercard', 'amex' => 'American Express', 'naranja' => 'Naranja',
                   'cabal' => 'Cabal', 'maestro' => 'Maestro', 'debvisa' => 'Visa Débito', 'debmaster' => 'Mastercard Débito',
                   'account_money' => 'Dinero en cuenta MP', 'rapipago' => 'Rapipago', 'pagofacil' => 'Pago Fácil',
                   'consumer_credits' => 'Cuotas sin tarjeta MP', 'debcabal' => 'Cabal Débito', 'tarshop' => 'Tarjeta Shopping',
                   'cencosud' => 'Cencosud', 'argencard' => 'Argencard', 'cmr' => 'CMR', 'diners' => 'Diners'];
        $tipos  = ['credit_card' => 'crédito', 'debit_card' => 'débito', 'prepaid_card' => 'prepaga',
                   'ticket' => 'efectivo', 'account_money' => '', 'digital_currency' => '', 'bank_transfer' => 'transferencia'];

        $medio = (string)($pago['medio'] ?? '');
        $tipo  = (string)($pago['tipo'] ?? '');
        $texto = $medios[$medio] ?? ($medio !== '' ? ucfirst($medio) : 'Mercado Pago');
        if (!empty($tipos[$tipo]) && !in_array($medio, ['account_money', 'rapipago', 'pagofacil', 'consumer_credits'], true)) {
            $texto .= ' ' . $tipos[$tipo];
        }
        $cuotas = (int)($pago['cuotas'] ?? 1);
        if ($cuotas > 1) $texto .= " en {$cuotas} cuotas";
        return $texto;
    }
}
