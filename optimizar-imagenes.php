<?php
/**
 * Achica y convierte a WebP las imagenes que ya estan en uploads/.
 *
 * Desde ahora el panel guarda todo optimizado, pero lo subido antes sigue
 * pesando igual. Este script lo arregla de una vez: convierte cada imagen,
 * apunta la base a la version nueva y muestra cuanto se bajo.
 *
 * COMO USARLO
 *   1. Entra al panel (/admin) con tu usuario: el script solo corre con la
 *      sesion de un administrador abierta.
 *   2. Abri https://TUDOMINIO/optimizar-imagenes.php y mira el informe.
 *   3. Apreta "Optimizar" y espera.
 *   4. BORRA el archivo del servidor.
 *
 * Los archivos originales NO se borran: si algo saliera mal, siguen estando.
 * Una vez que verificaste que el sitio se ve bien, se pueden borrar a mano
 * desde el administrador de archivos del hosting.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/config/Config.php';
require_once __DIR__ . '/api/config/Database.php';
require_once __DIR__ . '/api/services/ImagenOptimizer.php';

ini_set('session.use_strict_mode', '1');
session_name('nuve_admin_session');
session_start();

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem;">Entrá primero al panel (/admin) y volvé a abrir esta página.</p>';
    exit;
}

$dir = __DIR__ . '/uploads/';

/** Columnas que guardan rutas de imagenes. */
const REFERENCIAS = [
    ['productos',          'imagen_url'],
    ['producto_imagenes',  'url'],
    ['producto_variantes', 'imagen_url'],
    ['hongos_principales', 'imagen_url'],
    ['banners',            'imagen_desktop'],
    ['banners',            'imagen_mobile'],
];

/** Imagenes convertibles de uploads/, con su peso. */
function candidatas(string $dir): array {
    $out = [];
    foreach (glob($dir . '*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF}', GLOB_BRACE) ?: [] as $ruta) {
        $out[] = ['ruta' => $ruta, 'nombre' => basename($ruta), 'peso' => (int)filesize($ruta)];
    }
    usort($out, fn($a, $b) => $b['peso'] <=> $a['peso']);
    return $out;
}

function kb(int $bytes): string {
    return number_format($bytes / 1024, 0, ',', '.') . ' KB';
}

$db        = Database::getInstance();
$imagenes  = candidatas($dir);
$pesoTotal = array_sum(array_column($imagenes, 'peso'));
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['optimizar'])) {
    @set_time_limit(300);
    $convertidas = 0; $antes = 0; $despues = 0; $errores = [];

    foreach ($imagenes as $img) {
        $nuevo = ImagenOptimizer::aWebp($img['ruta']);
        if ($nuevo === null) {
            $errores[] = $img['nombre'] . ' (no se pudo convertir o ya era más liviana)';
            continue;
        }

        $viejoUrl = 'uploads/' . $img['nombre'];
        $nuevoUrl = 'uploads/' . basename($nuevo);

        try {
            foreach (REFERENCIAS as [$tabla, $columna]) {
                $db->prepare("UPDATE `{$tabla}` SET `{$columna}` = :nuevo WHERE `{$columna}` = :viejo")
                   ->execute([':nuevo' => $nuevoUrl, ':viejo' => $viejoUrl]);
            }
        } catch (Throwable $e) {
            // Una tabla que todavia no existe (por ejemplo producto_variantes
            // sin migrar) no tiene que frenar el resto.
            error_log('optimizar-imagenes: ' . $e->getMessage());
        }

        $convertidas++;
        $antes   += $img['peso'];
        $despues += (int)filesize($nuevo);
    }

    $resultado = compact('convertidas', 'antes', 'despues', 'errores');
    $imagenes  = candidatas($dir);
    $pesoTotal = array_sum(array_column($imagenes, 'peso'));
}

$esc = fn(?string $t): string => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Optimizar imágenes — KABODHI</title>
<style>
  body { font-family: 'Lato', system-ui, sans-serif; background: #F5F1E8; color: #1C3A4F; margin: 0; padding: 2.5rem 1.2rem; }
  .caja { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 6px 24px rgba(28,58,79,.1); }
  h1 { font-family: Georgia, serif; font-weight: 400; font-size: 1.5rem; margin: 0 0 .4rem; }
  p.sub { color: #8B7966; font-size: .85rem; margin: 0 0 1.6rem; }
  table { width: 100%; border-collapse: collapse; font-size: .82rem; margin-bottom: 1.4rem; }
  th, td { text-align: left; padding: .5rem .4rem; border-bottom: 1px solid #E7DECD; }
  td.num, th.num { text-align: right; }
  .aviso { background: #F5F1E8; border-left: 3px solid #A66B3D; padding: .9rem 1rem; font-size: .82rem; margin-bottom: 1.4rem; }
  .ok { background: #EAF3EC; border-left-color: #4a7c59; }
  button { background: #1C3A4F; color: #fff; border: none; border-radius: 4px; padding: .8rem 1.8rem; font-size: .85rem; letter-spacing: .5px; cursor: pointer; }
  button:hover { background: #132836; }
  code { background: #F5F1E8; padding: .1rem .3rem; border-radius: 3px; }
</style>
</head>
<body>
<div class="caja">
  <h1>Optimizar imágenes</h1>
  <p class="sub">Convierte a WebP lo que ya estaba subido y apunta la tienda a la versión nueva.</p>

  <?php if (!ImagenOptimizer::disponible()): ?>
    <div class="aviso">
      Este hosting no tiene la extensión GD con soporte WebP, así que no se pueden convertir
      las imágenes desde acá. Se pueden optimizar a mano (por ejemplo en squoosh.app) y volver
      a subirlas desde el panel.
    </div>
  <?php endif; ?>

  <?php if ($resultado !== null): ?>
    <div class="aviso ok">
      <strong><?= (int)$resultado['convertidas'] ?> imágenes convertidas.</strong><br>
      Antes: <?= kb($resultado['antes']) ?> · Ahora: <?= kb($resultado['despues']) ?>
      <?php if ($resultado['antes'] > 0): ?>
        (<?= round(100 - $resultado['despues'] * 100 / $resultado['antes']) ?>% menos)
      <?php endif; ?>
      <?php if (!empty($resultado['errores'])): ?>
        <br><br>Sin cambios: <?= $esc(implode(', ', $resultado['errores'])) ?>
      <?php endif; ?>
      <br><br>Revisá que el sitio se vea bien y después borrá este archivo del servidor.
    </div>
  <?php endif; ?>

  <?php if (empty($imagenes)): ?>
    <p>No quedan imágenes para optimizar en <code>uploads/</code>.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Archivo</th><th class="num">Peso</th></tr></thead>
      <tbody>
        <?php foreach ($imagenes as $img): ?>
          <tr><td><?= $esc($img['nombre']) ?></td><td class="num"><?= kb($img['peso']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th class="num"><?= kb($pesoTotal) ?></th></tr></tfoot>
    </table>

    <div class="aviso">
      Los archivos originales no se borran: si algo se viera mal, siguen estando.
      Cuando verifiques que la tienda se ve bien, se pueden borrar desde el
      administrador de archivos del hosting.
    </div>

    <?php if (ImagenOptimizer::disponible()): ?>
      <form method="post"><button type="submit" name="optimizar" value="1">Optimizar</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
