<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$id_proyecto  = $d['id_proyecto']  ?? 0;
$correo       = trim($d['correo']  ?? '');
$rol          = $d['rol']          ?? 'responsable';

// ============================================================
// VALIDACIONES
// ============================================================
if (!$id_actividad || !$id_proyecto || !$correo || !$rol) {
    json_response(['error' => 'Datos incompletos'], 400);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Correo inválido'], 400);
}

if (!in_array($rol, ['administrador', 'responsable', 'visualizador'])) {
    json_response(['error' => 'Rol inválido'], 400);
}

// ============================================================
// PERMISOS
// ============================================================
if ($user['rol'] !== 'administrador') {
    $stmt = db()->prepare("
        SELECT 1 FROM gestion_miembros_proyecto
        WHERE id_proyecto = ? AND id_usuario = ? AND rol_miembro = 'administrador'
    ");
    $stmt->execute([$id_proyecto, $user['id_usuario']]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'No tienes permiso para asignar responsables en este proyecto'], 403);
    }
}

// ============================================================
// 🔒 VERIFICAR QUE EL PROYECTO NO ESTÉ BLOQUEADO
// ============================================================
bloquear_si_proyecto_bloqueado($id_proyecto);

// ============================================================
// VERIFICAR ACTIVIDAD
// ============================================================
$stmt = db()->prepare("
    SELECT a.id_actividad, a.titulo, a.eliminada, a.archivada,
           p.nombre AS proyecto_nombre
    FROM gestion_actividades a
    JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
    WHERE a.id_actividad = ? AND a.id_proyecto = ?
");
$stmt->execute([$id_actividad, $id_proyecto]);
$actividad = $stmt->fetch();

if (!$actividad) {
    json_response(['error' => 'Actividad no encontrada en este proyecto'], 404);
}

if ($actividad['eliminada'] || $actividad['archivada']) {
    json_response(['error' => 'No se puede asignar responsables a una actividad archivada o eliminada'], 400);
}

// ============================================================
// ¿USUARIO EXISTE?
// ============================================================
$stmt = db()->prepare("
    SELECT id_usuario, nombre_completo, activo, eliminado
    FROM gestion_usuarios
    WHERE correo = ?
");
$stmt->execute([$correo]);
$usuarioExistente = $stmt->fetch();

if ($usuarioExistente && ($usuarioExistente['eliminado'] || !$usuarioExistente['activo'])) {
    json_response(['error' => 'El usuario existe pero está inactivo o eliminado'], 400);
}

// ============================================================
// CASO A: USUARIO YA EXISTE → asignar directo
// ============================================================
if ($usuarioExistente) {
    $id_usuario = $usuarioExistente['id_usuario'];

    // ¿Ya es responsable de esta actividad?
    $stmt = db()->prepare("
        SELECT 1 FROM gestion_actividades_responsables
        WHERE id_actividad = ? AND id_usuario = ?
    ");
    $stmt->execute([$id_actividad, $id_usuario]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Este usuario ya es responsable de la actividad'], 400);
    }

    // Asegurar miembro del proyecto
    $stmt = db()->prepare("
        SELECT 1 FROM gestion_miembros_proyecto
        WHERE id_proyecto = ? AND id_usuario = ?
    ");
    $stmt->execute([$id_proyecto, $id_usuario]);
    if (!$stmt->fetch()) {
        db()->prepare("
            INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
            VALUES (?, ?, ?)
        ")->execute([$id_proyecto, $id_usuario, $rol]);
    }

    // Vincular responsable
    try {
        db()->prepare("
            INSERT INTO gestion_actividades_responsables (id_actividad, id_usuario)
            VALUES (?, ?)
        ")->execute([$id_actividad, $id_usuario]);
    } catch (Exception $e) {
        json_response(['error' => 'Error vinculando responsable: ' . $e->getMessage()], 500);
    }

    // Actualizar asignado_a principal si no tenía
    db()->prepare("
        UPDATE gestion_actividades
        SET asignado_a = COALESCE(asignado_a, ?)
        WHERE id_actividad = ?
    ")->execute([$id_usuario, $id_actividad]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'usuario',
        'responsable_asignado',
        'Se asignó a ' . $usuarioExistente['nombre_completo'] . ' como responsable de la actividad "' . $actividad['titulo'] . '"',
        [
            'id_actividad' => $id_actividad,
            'id_usuario'   => $id_usuario,
            'correo'       => $correo
        ]
    );

    // Correo de notificación
    $linkProyecto = env('APP_URL', 'http://localhost:8000') . '/project_detail.php?id=' . $id_proyecto;
    $cuerpo = "
        <div style='font-family: Roboto, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f7fa; padding: 20px;'>
            <div style='background: white; border-radius: 12px; padding: 30px; border-top: 5px solid #78BE20;'>
                <h1 style='color: #9F2241;'>Te asignaron una actividad</h1>
                <p style='color: #55585A; font-size: 14px; line-height: 1.6;'>
                    <b>" . h($user['nombre_completo']) . "</b> te ha asignado como responsable de la actividad
                    <b>" . h($actividad['titulo']) . "</b> en el proyecto
                    <b>" . h($actividad['proyecto_nombre']) . "</b>.
                </p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . $linkProyecto . "' style='background: #78BE20; color: white; padding: 14px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; display: inline-block;'>
                        Ver proyecto
                    </a>
                </div>
            </div>
        </div>
    ";
    enviar_correo($correo, $correo, 'Te asignaron una actividad en RTP Kanban', $cuerpo);

    json_response([
        'ok' => true,
        'modo' => 'directo',
        'mensaje' => 'Responsable asignado correctamente',
        'usuario' => [
            'id_usuario' => $id_usuario,
            'nombre_completo' => $usuarioExistente['nombre_completo']
        ]
    ]);
}

// ============================================================
// CASO B: USUARIO NO EXISTE → crear invitación vinculada
// ============================================================
$stmt = db()->prepare("
    SELECT 1 FROM gestion_invitaciones
    WHERE id_proyecto = ? AND correo = ? AND estado = 'pendiente'
");
$stmt->execute([$id_proyecto, $correo]);
if ($stmt->fetch()) {
    json_response(['error' => 'Ya hay una invitación pendiente para este correo'], 400);
}

$token  = generar_token(64);
$expira = date('Y-m-d H:i:s', strtotime('+7 days'));

try {
    db()->prepare("
        INSERT INTO gestion_invitaciones
            (id_proyecto, id_actividad, correo, rol_miembro, token, expira_en, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $id_proyecto,
        $id_actividad,
        $correo,
        $rol,
        $token,
        $expira,
        $user['id_usuario']
    ]);
} catch (Exception $e) {
    json_response(['error' => 'Error guardando invitación: ' . $e->getMessage()], 500);
}

// 📝 Registrar en el historial
registrar_historial(
    $id_proyecto,
    'usuario',
    'responsable_invitado',
    'Se invitó a ' . $correo . ' como responsable de la actividad "' . $actividad['titulo'] . '"',
    [
        'id_actividad' => $id_actividad,
        'correo'       => $correo,
        'rol'          => $rol
    ]
);

$link = env('APP_URL', 'http://localhost:8000') . '/invite.php?token=' . $token;
$rolLabel = ucfirst($rol);

$cuerpo = "
    <div style='font-family: Roboto, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f7fa; padding: 20px;'>
        <div style='background: white; border-radius: 12px; padding: 30px; border-top: 5px solid #78BE20;'>
            <h1 style='color: #9F2241; margin-bottom: 10px;'>Invitación a RTP Kanban</h1>
            <p style='color: #55585A; font-size: 14px; line-height: 1.6;'>
                <b>" . h($user['nombre_completo']) . "</b> te ha invitado a colaborar en el proyecto
                <b>" . h($actividad['proyecto_nombre']) . "</b> con el rol de <b>" . $rolLabel . "</b>
                y te ha asignado como responsable de la actividad
                <b>" . h($actividad['titulo']) . "</b>.
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

json_response([
    'ok' => true,
    'modo' => 'invitacion',
    'mensaje' => 'Invitación enviada. Al aceptarla, quedará como responsable de la actividad.'
]);