<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';

$token = $_GET['token'] ?? '';
$error = '';
$invitacion = null;

if ($token) {
    $stmt = db()->prepare("
        SELECT i.*, p.nombre AS proyecto_nombre, u.nombre_completo AS invitado_por
        FROM gestion_invitaciones i
        JOIN gestion_proyectos p ON p.id_proyecto = i.id_proyecto
        LEFT JOIN gestion_usuarios u ON u.id_usuario = i.creado_por
        WHERE i.token = ? AND i.estado = 'pendiente' AND i.expira_en > NOW()
    ");
    $stmt->execute([$token]);
    $invitacion = $stmt->fetch();

    if (!$invitacion) $error = 'La invitación no es válida o ya expiró.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitacion) {
    $nombre = trim($_POST['nombre'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';

    if (!$nombre || !$usuario || !$contrasena) {
        $error = 'Todos los campos son obligatorios.';
    } else {
        $stmt = db()->prepare("SELECT * FROM gestion_usuarios WHERE correo = ?");
        $stmt->execute([$invitacion['correo']]);
        $existente = $stmt->fetch();

        if ($existente) {
            $_SESSION['id_usuario'] = $existente['id_usuario'];
            $id_usuario = $existente['id_usuario'];
        } else {
            $hash = password_hash($contrasena, PASSWORD_BCRYPT);
            $stmt = db()->prepare("
                INSERT INTO gestion_usuarios (nombre_usuario, correo, hash_contrasena, nombre_completo, rol)
                VALUES (?, ?, ?, ?, ?)
                RETURNING id_usuario
            ");
            $stmt->execute([$usuario, $invitacion['correo'], $hash, $nombre, $invitacion['rol_miembro']]);
            $id_usuario = $stmt->fetch()['id_usuario'];
            $_SESSION['id_usuario'] = $id_usuario;
        }

        db()->prepare("
            INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
            VALUES (?, ?, ?)
            ON CONFLICT (id_proyecto, id_usuario) DO UPDATE SET rol_miembro = EXCLUDED.rol_miembro
        ")->execute([$invitacion['id_proyecto'], $id_usuario, $invitacion['rol_miembro']]);

        // 🆕 Vincular como responsable de la actividad si la invitación lo trae
        if (!empty($invitacion['id_actividad'])) {
            // Verificar que no exista ya
            $stmt = db()->prepare("
                SELECT 1 FROM gestion_actividades_responsables
                WHERE id_actividad = ? AND id_usuario = ?
            ");
            $stmt->execute([$invitacion['id_actividad'], $id_usuario]);
            if (!$stmt->fetch()) {
                db()->prepare("
                    INSERT INTO gestion_actividades_responsables (id_actividad, id_usuario)
                    VALUES (?, ?)
                ")->execute([$invitacion['id_actividad'], $id_usuario]);

                // Si la actividad no tiene asignado_a principal, ponerlo
                db()->prepare("
                    UPDATE gestion_actividades
                    SET asignado_a = COALESCE(asignado_a, ?)
                    WHERE id_actividad = ?
                ")->execute([$id_usuario, $invitacion['id_actividad']]);
            }
        }

        db()->prepare("
            UPDATE gestion_invitaciones SET estado = 'aceptada', aceptado_en = NOW()
            WHERE id_invitacion = ?
        ")->execute([$invitacion['id_invitacion']]);

        header('Location: project_detail.php?id=' . $invitacion['id_proyecto']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Invitación · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
<div class="login-wrapper">
    <?php if (!$invitacion): ?>
        <div class="login-card">
            <div class="login-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
            <h1 class="login-institucion" style="font-size: 13pt;">Invitación no válida</h1>
            <p class="login-subtitle" style="color: #E5074C;"><?= h($error) ?></p>
            <a href="login.php" class="btn" style="width: 100%; text-align: center; padding: 14px;">Ir al login</a>
        </div>
    <?php else: ?>
        <div class="login-card">
            <div class="login-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>

            <h1 class="login-institucion">Te han invitado a<br>RTP Kanban</h1>

            <div style="background: #ecf9d9; padding: 16px; border-radius: 10px; margin-bottom: 20px; text-align: center;">
                <p style="color: #55585A; font-size: 11pt; margin-bottom: 6px;">
                    <b><?= h($invitacion['invitado_por'] ?? 'Un administrador') ?></b> te invitó al proyecto:
                </p>
                <p style="color: #9F2241; font-size: 13pt; font-weight: 700; margin-bottom: 6px;">
                    <?= h($invitacion['proyecto_nombre']) ?>
                </p>
                <p style="color: #55585A; font-size: 10.5pt;">
                    Rol: <b><?= h(ucfirst($invitacion['rol_miembro'])) ?></b>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <p style="font-size: 10.5pt; color: #55585A; margin-bottom: 8px; text-align: center;">
                    <b>Correo:</b> <?= h($invitacion['correo']) ?>
                </p>

                <input type="text" name="nombre" placeholder="Nombre completo" required>
                <input type="text" name="usuario" placeholder="Nombre de usuario" required>
                <input type="password" name="contrasena" placeholder="Contraseña" required>

                <button type="submit">Aceptar invitación</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>