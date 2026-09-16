<?php

/**
 * Error al hablar con la API de Correo Argentino. Lleva el HTTP code para que
 * quien llama distinga "credenciales mal" (401) de "el correo esta caido" (5xx).
 */
class MiCorreoException extends RuntimeException {
    public int $httpCode;

    public function __construct(string $message, int $httpCode = 0, ?Throwable $previous = null) {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }
}

/**
 * Cliente HTTP de la API MiCorreo (Correo Argentino), v1.
 *
 * Doc oficial: https://www.correoargentino.com.ar/MiCorreo/public/img/pag/apiMiCorreo.pdf
 *
 * Flujo:
 *   1. POST /token con Basic auth (MICORREO_USER:MICORREO_PASSWORD) -> JWT.
 *      Se cachea en storage/micorreo/ hasta que vence.
 *   2. Todo lo demas va con "Authorization: Bearer <jwt>".
 *   3. Las operaciones necesitan el customerId de la cuenta MiCorreo: viene del
 *      .env o se obtiene una vez con POST /users/validate (email + clave de la
 *      cuenta) y se cachea.
 *
 * Esta clase no sabe nada de pedidos ni de la base: recibe arrays y devuelve
 * arrays. La logica de negocio vive en EnvioService.
 */
class MiCorreoClient {
    public const AMBIENTE_TEST = 'test';
    public const AMBIENTE_PROD = 'prod';

    private const URLS = [
        self::AMBIENTE_TEST => 'https://apitest.correoargentino.com.ar/micorreo/v1',
        self::AMBIENTE_PROD => 'https://api.correoargentino.com.ar/micorreo/v1',
    ];

    /** Entrega a domicilio / en sucursal, tal como los nombra la API. */
    public const ENTREGA_DOMICILIO = 'D';
    public const ENTREGA_SUCURSAL  = 'S';

    /** Productos: Paq.ar Clasico / Paq.ar Expreso. */
    public const PRODUCTO_CLASICO = 'CP';
    public const PRODUCTO_EXPRESO = 'EP';

    /** Limites que exige la API para cotizar. */
    public const PESO_MAX_GRAMOS = 25000;
    public const MEDIDA_MAX_CM   = 150;

    /**
     * Codigo de provincia (una letra) que usa la API, por nombre normalizado.
     * @see provinciaCodigo()
     */
    public const PROVINCIAS = [
        'C' => 'Ciudad Autónoma de Buenos Aires',
        'B' => 'Buenos Aires',
        'K' => 'Catamarca',
        'H' => 'Chaco',
        'U' => 'Chubut',
        'X' => 'Córdoba',
        'W' => 'Corrientes',
        'E' => 'Entre Ríos',
        'P' => 'Formosa',
        'Y' => 'Jujuy',
        'L' => 'La Pampa',
        'F' => 'La Rioja',
        'M' => 'Mendoza',
        'N' => 'Misiones',
        'Q' => 'Neuquén',
        'R' => 'Río Negro',
        'A' => 'Salta',
        'J' => 'San Juan',
        'D' => 'San Luis',
        'Z' => 'Santa Cruz',
        'S' => 'Santa Fe',
        'G' => 'Santiago del Estero',
        'V' => 'Tierra del Fuego',
        'T' => 'Tucumán',
    ];

    private string $ambiente;
    private string $baseUrl;
    private string $user;
    private string $password;
    private string $customerId;
    private string $cacheDir;

    public function __construct(string $ambiente = self::AMBIENTE_TEST) {
        $this->ambiente   = isset(self::URLS[$ambiente]) ? $ambiente : self::AMBIENTE_TEST;
        $this->baseUrl    = self::URLS[$this->ambiente];
        // Override para pruebas (mock local). Nunca se usa en produccion real.
        if (defined('MICORREO_BASE_URL') && trim((string)MICORREO_BASE_URL) !== '') {
            $this->baseUrl = rtrim(trim((string)MICORREO_BASE_URL), '/');
        }
        $this->user       = trim((string)MICORREO_USER);
        $this->password   = trim((string)MICORREO_PASSWORD);
        $this->customerId = trim((string)MICORREO_CUSTOMER_ID);
        // storage/ ya esta en .gitignore y bloqueado por HTTP (.htaccess).
        $this->cacheDir   = dirname(__DIR__, 2) . '/storage/micorreo';
    }

    /** Hay credenciales de API cargadas en el .env. */
    public function configurado(): bool {
        return $this->user !== '' && $this->password !== '';
    }

    public function ambiente(): string {
        return $this->ambiente;
    }

    // -----------------------------------------------------------------
    // Operaciones
    // -----------------------------------------------------------------

    /**
     * Cotiza un bulto. Devuelve la lista de tarifas tal como la da la API:
     *   [ ['deliveredType'=>'D','productType'=>'CP','productName'=>'Paq.ar Clásico',
     *      'price'=>1234.5,'deliveryTimeMin'=>'3','deliveryTimeMax'=>'6'], ... ]
     *
     * @param string|null $tipoEntrega 'D' | 'S' | null (null = ambas)
     */
    public function cotizar(string $cpOrigen, string $cpDestino, array $bulto, ?string $tipoEntrega = null): array {
        $payload = [
            'customerId'            => $this->customerId(),
            'postalCodeOrigin'      => self::cp($cpOrigen),
            'postalCodeDestination' => self::cp($cpDestino),
            'dimensions'            => [
                'weight' => (int)$bulto['peso_gramos'],
                'height' => (int)$bulto['alto_cm'],
                'width'  => (int)$bulto['ancho_cm'],
                'length' => (int)$bulto['largo_cm'],
            ],
        ];
        if ($tipoEntrega !== null) {
            $payload['deliveredType'] = $tipoEntrega;
        }

        $res = $this->request('POST', '/rates', $payload);

        $rates = $res['rates'] ?? [];
        if (!is_array($rates)) $rates = [];

        return [
            'validTo' => $res['validTo'] ?? null,
            'rates'   => array_values($rates),
        ];
    }

    /**
     * Sucursales de una provincia (codigo de una letra). Devuelve solo las
     * activas que reciben paquetes, ya achatadas para el front.
     */
    public function sucursales(string $provinciaCodigo): array {
        $provinciaCodigo = strtoupper(substr(trim($provinciaCodigo), 0, 1));
        if (!isset(self::PROVINCIAS[$provinciaCodigo])) {
            throw new InvalidArgumentException("Código de provincia inválido: {$provinciaCodigo}");
        }

        $res = $this->request('GET', '/agencies?customerId=' . rawurlencode($this->customerId())
                                     . '&provinceCode=' . $provinciaCodigo);

        $lista = [];
        foreach ((array)$res as $ag) {
            if (!is_array($ag)) continue;
            if (($ag['status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
            $servicios = $ag['services'] ?? [];
            if (isset($servicios['packageReception']) && !$servicios['packageReception']) continue;

            $dir = $ag['location']['address'] ?? [];
            $lista[] = [
                'codigo'    => (string)($ag['code'] ?? ''),
                'nombre'    => (string)($ag['name'] ?? ''),
                'direccion' => trim(implode(' ', array_filter([
                    $dir['streetName']   ?? '',
                    $dir['streetNumber'] ?? '',
                ]))),
                'localidad' => (string)($dir['locality'] ?? $dir['city'] ?? ''),
                'cp'        => (string)($dir['postalCode'] ?? ''),
                'telefono'  => (string)($ag['phone'] ?? ''),
                'lat'       => $ag['location']['latitude']  ?? null,
                'lng'       => $ag['location']['longitude'] ?? null,
            ];
        }

        usort($lista, fn($a, $b) => strcmp($a['localidad'] . $a['nombre'], $b['localidad'] . $b['nombre']));
        return $lista;
    }

    /**
     * Importa una orden de envio en MiCorreo (POST /shipping/import).
     * $orden ya viene armada por EnvioService con la forma que pide la API.
     * Devuelve la respuesta cruda (createdAt y, si la API lo manda, trackingNumber).
     */
    public function importarEnvio(array $orden): array {
        $orden['customerId'] = $this->customerId();
        return $this->request('POST', '/shipping/import', $orden);
    }

    /**
     * Prueba de conexion para el panel: pide token y resuelve el customerId.
     */
    public function probar(): array {
        $this->token(true);
        return [
            'ambiente'    => $this->ambiente,
            'customer_id' => $this->customerId(),
        ];
    }

    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------

    private function customerId(): string {
        if ($this->customerId !== '') return $this->customerId;

        $cache = $this->leerCache('customer-' . $this->ambiente . '.json');
        if (!empty($cache['customerId'])) {
            return $this->customerId = (string)$cache['customerId'];
        }

        $email = trim((string)MICORREO_EMAIL);
        $clave = (string)MICORREO_CLAVE;
        if ($email === '' || $clave === '') {
            throw new MiCorreoException(
                'Falta MICORREO_CUSTOMER_ID (o MICORREO_EMAIL + MICORREO_CLAVE para obtenerlo) en el .env.'
            );
        }

        $res = $this->request('POST', '/users/validate', ['email' => $email, 'password' => $clave]);
        $id  = (string)($res['customerId'] ?? '');
        if ($id === '') {
            throw new MiCorreoException('MiCorreo no devolvió el customerId al validar el usuario.');
        }

        $this->escribirCache('customer-' . $this->ambiente . '.json', ['customerId' => $id, 'email' => $email]);
        return $this->customerId = $id;
    }

    private function token(bool $forzar = false): string {
        if (!$this->configurado()) {
            throw new MiCorreoException('Faltan MICORREO_USER / MICORREO_PASSWORD en el .env.');
        }

        $archivo = 'token-' . $this->ambiente . '.json';
        if (!$forzar) {
            $cache = $this->leerCache($archivo);
            // Un minuto de margen: no queremos usar un token que vence en el viaje.
            if (!empty($cache['token']) && !empty($cache['expira']) && $cache['expira'] - 60 > time()) {
                return $cache['token'];
            }
        }

        $res = $this->request('POST', '/token', null, [
            'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password),
        ]);

        $token = (string)($res['token'] ?? '');
        if ($token === '') {
            throw new MiCorreoException('MiCorreo no devolvió un token.');
        }

        // "expires" viene en ISO 8601. Si no se puede parsear, se asume 1 hora.
        $expira = !empty($res['expires']) ? strtotime((string)$res['expires']) : false;
        if ($expira === false || $expira <= time()) {
            $expira = time() + 3600;
        }

        $this->escribirCache($archivo, ['token' => $token, 'expira' => $expira]);
        return $token;
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /**
     * @param array|null $body      Se manda como JSON.
     * @param array|null $authHeader Reemplaza el Bearer (solo para /token).
     */
    private function request(string $method, string $endpoint, ?array $body = null, ?array $authHeader = null): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($authHeader !== null) {
            $headers = array_merge($headers, $authHeader);
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token();
        }

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'POST') {
            // /token es POST sin cuerpo.
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $raw       = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new MiCorreoException("No se pudo conectar con Correo Argentino: {$curlError}", 0);
        }

        $decoded = json_decode((string)$raw, true);

        if ($httpCode >= 400) {
            // Un 401 con token cacheado: puede haberse revocado. Se limpia para
            // que el proximo intento pida uno nuevo.
            if ($httpCode === 401 && $authHeader === null) {
                @unlink($this->cacheDir . '/token-' . $this->ambiente . '.json');
            }
            $msg = is_array($decoded)
                ? ($decoded['message'] ?? $decoded['error'] ?? $decoded['description'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE))
                : trim((string)$raw);
            if ($msg === '' || $msg === null) $msg = 'sin detalle';
            error_log("MiCorreo {$method} {$endpoint} -> {$httpCode}: {$msg}");
            throw new MiCorreoException("Correo Argentino respondió {$httpCode}: {$msg}", $httpCode);
        }

        if (!is_array($decoded)) {
            throw new MiCorreoException("Respuesta inválida de Correo Argentino ({$httpCode}).", $httpCode);
        }

        return $decoded;
    }

    // -----------------------------------------------------------------
    // Cache en disco (token y customerId)
    // -----------------------------------------------------------------

    private function leerCache(string $archivo): array {
        $ruta = $this->cacheDir . '/' . $archivo;
        if (!is_file($ruta)) return [];
        $data = json_decode((string)@file_get_contents($ruta), true);
        return is_array($data) ? $data : [];
    }

    private function escribirCache(string $archivo, array $data): void {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0750, true)) {
            // Sin cache se sigue funcionando: solo se pide el token cada vez.
            return;
        }
        @file_put_contents($this->cacheDir . '/' . $archivo, json_encode($data), LOCK_EX);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** CP argentino: solo los 4 digitos (el CPA "B1636ABC" se reduce a "1636"). */
    public static function cp(string $cp): string {
        $digitos = preg_replace('/\D+/', '', $cp);
        return substr($digitos, 0, 4);
    }

    /**
     * Codigo de provincia a partir de lo que escribio el cliente ("Buenos Aires",
     * "CABA", "Capital Federal", "cordoba"...). Null si no se reconoce.
     */
    public static function provinciaCodigo(string $texto): ?string {
        $t = trim($texto);
        if ($t === '') return null;
        if (strlen($t) === 1 && isset(self::PROVINCIAS[strtoupper($t)])) return strtoupper($t);

        $norm = self::normalizar($t);

        $alias = [
            'CABA' => 'C', 'CAPITAL FEDERAL' => 'C', 'CAPITAL' => 'C',
            'CIUDAD DE BUENOS AIRES' => 'C', 'CIUDAD AUTONOMA DE BUENOS AIRES' => 'C',
            'BS AS' => 'B', 'BSAS' => 'B', 'PROVINCIA DE BUENOS AIRES' => 'B', 'GBA' => 'B',
            'TIERRA DEL FUEGO ANTARTIDA E ISLAS DEL ATLANTICO SUR' => 'V',
        ];
        if (isset($alias[$norm])) return $alias[$norm];

        foreach (self::PROVINCIAS as $codigo => $nombre) {
            if (self::normalizar($nombre) === $norm) return $codigo;
        }
        return null;
    }

    private static function normalizar(string $s): string {
        // mb_: strtoupper() deja las vocales acentuadas en minuscula.
        $s = mb_strtoupper($s, 'UTF-8');
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
