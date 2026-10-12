<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

// Solo admin del proyecto o administrador global puede invitar
$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;
$correo = trim($d['correo'] ?? '');
$rol = $d['rol'] ?? '';

if (!$id_proyecto || !$correo || !$rol) {
    json_response(['error' => 'Datos incompletos'], 400);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Correo inválido'], 400);
}

if (!in_array($rol, ['administrador', 'responsable', 'visualizador'])) {
    json_response(['error' => 'Rol inválido'], 400);
}

// Verificar que el usuario tenga permiso sobre el proyecto
if ($user['rol'] !== 'administrador') {
    $stmt = db()->prepare("
        SELECT 1 FROM gestion_miembros_proyecto
        WHERE id_proyecto = ? AND id_usuario = ? AND rol_miembro = 'administrador'
    ");
    $stmt->execute([$id_proyecto, $user['id_usuario']]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'No tienes permiso para invitar en este proyecto'], 403);
    }
}

// Obtener proyecto
$stmt = db()->prepare("SELECT nombre FROM gestion_proyectos WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) json_response(['error' => 'Proyecto no encontrado'], 404);

// Verificar si ya existe una invitación pendiente para ese correo
$stmt = db()->prepare("
    SELECT 1 FROM gestion_invitaciones
    WHERE id_proyecto = ? AND correo = ? AND estado = 'pendiente'
");
$stmt->execute([$id_proyecto, $correo]);
if ($stmt->fetch()) {
    json_response(['error' => 'Ya hay una invitación pendiente para este correo'], 400);
}

// Crear token
$token = generar_token(64);
$expira = date('Y-m-d H:i:s', strtotime('+7 days'));

try {
    db()->prepare("
        INSERT INTO gestion_invitaciones
            (id_proyecto, correo, rol_miembro, token, expira_en, creado_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$id_proyecto, $correo, $rol, $token, $expira, $user['id_usuario']]);
} catch (Exception $e) {
    json_response(['error' => 'Error guardando invitación: ' . $e->getMessage()], 500);
}

// Armar link
$link = env('APP_URL', 'http://localhost:8000') . '/invite.php?token=' . $token;
$rolLabel = ucfirst($rol);

// HTML del correo
$cuerpo = "
    <div style='font-family: Roboto, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f7fa; padding: 20px;'>
        <div style='background: white; border-radius: 12px; padding: 30px; border-top: 5px solid #78BE20;'>
            <h1 style='color: #9F2241; margin-bottom: 10px;'>Invitación a RTP Kanban</h1>
            <p style='color: #55585A; font-size: 14px; line-height: 1.6;'>
                <b>" . h($user['nombre_completo']) . "</b> te ha invitado a colaborar en el proyecto
                <b>" . h($proyecto['nombre']) . "</b> con el rol de <b>" . $rolLabel . "</b>.
            </p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . $link . "' style='background: #78BE20; color: white; padding: 14px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; display: inline-block;'>
                    Aceptar invitación
                </a>
            </div>
            <p style='color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                O copia este enlace:<br>
                <a href='" . $link . "' style='color: #78BE20; word-break: break-all;'>" . $link . "</a>
            </p>
            <p style='color: #94a3b8; font-size: 12px; margin-top: 20px;'>
                Expira el " . date('d/m/Y', strtotime($expira)) . ".
            </p>
        </div>
    </div>
";

$ok = enviar_correo($correo, $correo, 'Invitación a RTP Kanban', $cuerpo);

if (!$ok) {
    json_response(['error' => 'No se pudo enviar el correo. Revisa la configuración.'], 500);
}

json_response(['ok' => true]);