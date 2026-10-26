<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';

    if (login($correo, $contrasena)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Correo o contraseña incorrectos';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>RTP · Iniciar sesión</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
<div class="login-wrapper">
    <form method="POST" class="login-card">
        <div class="login-logo">
            <img src="assets/img/logo-rtp.png" alt="RTP">
        </div>

        <h1 class="login-institucion">
            Red de Transporte de<br>
            Pasajeros de la Ciudad de<br>
            México
        </h1>

        <h2 class="login-bienvenida">Bienvenido</h2>

        <p class="login-subtitle">Sistema de Gestión de Proyectos Institucionales</p>

        <div class="login-form">
            <input type="email" name="correo" placeholder="Correo" required autofocus>
            <input type="password" name="contrasena" placeholder="Contraseña" required>

            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <button type="submit">Iniciar sesión</button>
        </div>
    </form>
</div>
</body>
</html>