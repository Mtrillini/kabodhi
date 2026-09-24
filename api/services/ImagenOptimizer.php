<?php

/**
 * Achica y convierte a WebP las imagenes que se suben desde el panel.
 *
 * Un banner exportado de Canva puede pesar 2 MB en PNG; el mismo banner en
 * WebP a 1920 px pesa alrededor de 150 KB y se ve igual en pantalla. Como el
 * home baja cuatro banners, la diferencia es la mayor parte de lo que espera
 * el visitante.
 *
 * Si el hosting no tiene GD con soporte WebP, todo sigue funcionando: se deja
 * el archivo original tal como se subio.
 */
class ImagenOptimizer {

    /**
     * Lado mas largo, en pixeles. 1600 alcanza para cualquier pantalla de
     * notebook y para un banner a todo el ancho: a 1920 los banners pesaban
     * unos 180 KB cada uno y son lo primero que baja el visitante.
     */
    public const ANCHO_MAX = 1600;

    /** 82 es el punto donde WebP deja de verse distinto del original. */
    public const CALIDAD = 82;

    public static function disponible(): bool {
        return function_exists('imagewebp')
            && function_exists('imagecreatefromjpeg')
            && function_exists('imagecreatefrompng');
    }

    /**
     * Convierte $origen a WebP al lado de si mismo.
     *
     * @return string|null La ruta del .webp, o null si no se pudo (y entonces
     *                     hay que seguir usando el original).
     */
    public static function aWebp(string $origen, ?string $mime = null): ?string {
        if (!self::disponible() || !is_file($origen)) {
            return null;
        }

        $mime = $mime ?: (string)mime_content_type($origen);
        $img  = self::abrir($origen, $mime);
        if ($img === null) {
            return null;
        }

        try {
            $img = self::redimensionar($img);

            $destino = preg_replace('/\.[^.]+$/', '', $origen) . '.webp';
            // Un nombre repetido (mismo base .jpg y .png) no debe pisar nada.
            if (is_file($destino) && $destino !== $origen) {
                $destino = preg_replace('/\.[^.]+$/', '', $origen) . '-' . substr(md5($origen), 0, 6) . '.webp';
            }

            if (!imagewebp($img, $destino, self::CALIDAD) || !is_file($destino)) {
                return null;
            }

            // Si el original ya era mas liviano (pasa con fotos chicas), no se
            // gana nada: se descarta el webp.
            if (filesize($destino) >= filesize($origen)) {
                @unlink($destino);
                return null;
            }

            return $destino;
        } finally {
            if ($img instanceof GdImage || is_resource($img)) {
                imagedestroy($img);
            }
        }
    }

    /** @return GdImage|resource|null */
    private static function abrir(string $ruta, string $mime) {
        $img = false;
        switch ($mime) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($ruta); break;
            case 'image/png':  $img = @imagecreatefrompng($ruta);  break;
            case 'image/webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($ruta) : false; break;
            case 'image/gif':  $img = function_exists('imagecreatefromgif')  ? @imagecreatefromgif($ruta)  : false; break;
        }
        return $img === false ? null : $img;
    }

    /**
     * Achica manteniendo la proporcion. Las transparencias del PNG se
     * conservan; sin esto un logo con fondo transparente sale con fondo negro.
     *
     * @param  GdImage|resource $img
     * @return GdImage|resource
     */
    private static function redimensionar($img) {
        $ancho = imagesx($img);
        $alto  = imagesy($img);
        $lado  = max($ancho, $alto);
        if ($lado <= self::ANCHO_MAX) {
            return $img;
        }

        $escala      = self::ANCHO_MAX / $lado;
        $nuevoAncho  = max(1, (int)round($ancho * $escala));
        $nuevoAlto   = max(1, (int)round($alto  * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagecopyresampled($destino, $img, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($img);

        return $destino;
    }
}
