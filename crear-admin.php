<?php
/**
 * Alta del primer administrador del panel.
 *
 * Se usa UNA vez despues de importar kabodhi-instalacion.sql, y despues se
 * borra del servidor. Existe para que la contrasena la elijas vos y nunca
 * quede escrita en el repositorio.
 *
 * Se niega a correr si ya hay un administrador cargado.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/config/Config.php';
require_once __DIR__ . '/api/config/Database.php';

$error   = null;
$exito   = false;
$yaExiste = false;

try {
    $db = Database::getInstance();
    $yaExiste = (int)$db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() > 0;
} catch (Throwable $e) {
    $error = 'No se pudo conectar a la base. Revisá los datos del .env.';
}

if (!$error && !$yaExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']  ?? '';
    $repetir  = $_POST['password2'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Completá todos los campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido.';
    } elseif ($password !== $repetir) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 10) {
        $error = 'Usá al menos 10 caracteres.';
    } elseif (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Combiná letras y números.';
    } else {
        try {
            $stmt = $db->prepare(
                "INSERT INTO admin_users (username, email, password_hash) VALUES (:u, :e, :h)"
            );
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
            $exito = true;
        } catch (Throwable $e) {
            $error = 'No se pudo crear el usuario: ' . $e->getMessage();
        }
    }
}

$esc = fn(?string $t): string => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear administrador — KABODHI</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: #F5F1E8; font-family: 'Lato', Helvetica, sans-serif; color: #1F3D2E; padding: 2rem 1rem;
    }
    .caja { background: #fff; border-radius: 6px; box-shadow: 0 20px 60px rgba(0,0,0,0.12);
            padding: 2.5rem; width: 100%; max-width: 430px; }
    .logo { font-family: 'Playfair Display', Georgia, serif; font-size: 1.7rem; letter-spacing: 0.3em;
            text-align: center; margin-bottom: 0.3rem; }
    .sub { text-align: center; font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase;
           color: #8B7966; margin-bottom: 2rem; }
    label { display: block; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase;
            color: #8B7966; margin-bottom: 0.35rem; }
    input { width: 100%; padding: 0.7rem 0.85rem; border: 1px solid #D8D6CD; border-radius: 4px;
            font-family: inherit; font-size: 0.9rem; margin-bottom: 1.1rem; background: #fff; color: #1F3D2E; }
    input:focus { outline: 2px solid #1F3D2E; outline-offset: 1px; border-color: #1F3D2E; }
    button { width: 100%; padding: 0.85rem; background: #1F3D2E; color: #F5F1E8; border: none;
             border-radius: 4px; font-family: inherit; font-size: 0.8rem; letter-spacing: 0.12em;
             text-transform: uppercase; cursor: pointer; }
    button:hover { background: #16301F; }
    .aviso { padding: 0.9rem 1rem; border-radius: 4px; font-size: 0.82rem; line-height: 1.6; margin-bottom: 1.5rem; }
    .aviso--error { background: #F6E4E1; color: #A32E24; }
    .aviso--ok    { background: #E0EDE6; color: #2F6B4F; }
    .pista { font-size: 0.72rem; color: #8B7966; margin: -0.7rem 0 1.1rem; }
    a { color: #1F3D2E; }
  </style>
</head>
<body>
  <div class="caja">
    <div class="logo">KABODHI</div>
    <div class="sub">Crear administrador</div>

    <?php if ($error): ?>
      <div class="aviso aviso--error"><?= $esc($error) ?></div>
    <?php endif; ?>

    <?php if ($exito): ?>
      <div class="aviso aviso--ok">
        <strong>Usuario creado.</strong><br>
        Ya podés entrar en <a href="admin/login.html">/admin/login.html</a>.
        <br><br>
        <strong>Borrá este archivo del servidor</strong> (<code>crear-admin.php</code>).
      </div>

    <?php elseif ($yaExiste): ?>
      <div class="aviso aviso--error">
        Ya hay un administrador cargado, así que este script no hace nada.<br><br>
        Si perdiste la contraseña, cambiala desde el panel, o borrá la fila de
        <code>admin_users</code> en phpMyAdmin y recargá esta página.
        <br><br>
        <strong>Borrá este archivo del servidor.</strong>
      </div>

    <?php elseif (!$error || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <form method="post" autocomplete="off">
        <label for="username">Usuario</label>
        <input type="text" id="username" name="username" required
               value="<?= $esc($_POST['username'] ?? '') ?>" placeholder="martin">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required
               value="<?= $esc($_POST['email'] ?? '') ?>" placeholder="hola@kabodhi.com">

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <p class="pista">Mínimo 10 caracteres, con letras y números.</p>

        <label for="password2">Repetir contraseña</label>
        <input type="password" id="password2" name="password2" required autocomplete="new-password">

        <button type="submit">Crear administrador</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
