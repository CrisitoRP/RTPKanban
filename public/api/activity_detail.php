<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

$id_actividad = $_GET['id'] ?? 0;
if (!$id_actividad) json_response(['error' => 'ID requerido'], 400);

$stmt = db()->prepare("
    SELECT a.*, p.nombre AS proyecto_nombre
    FROM gestion_actividades a
    JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
    WHERE a.id_actividad = ? AND a.eliminada = FALSE
");
$stmt->execute([$id_actividad]);
$actividad = $stmt->fetch();

if (!$actividad) json_response(['error' => 'Actividad no encontrada'], 404);

if (!can_access_project($user, $actividad['id_proyecto'])) {
    json_response(['error' => 'Sin permisos'], 403);
}

$stmtResp = db()->prepare("
    SELECT u.id_usuario, u.nombre_completo
    FROM gestion_actividades_responsables ar
    JOIN gestion_usuarios u ON u.id_usuario = ar.id_usuario
    WHERE ar.id_actividad = ? AND u.eliminado = FALSE AND u.activo = TRUE
    ORDER BY u.nombre_completo
");
$stmtResp->execute([$id_actividad]);
$actividad['responsables'] = $stmtResp->fetchAll();

$stmtAdj = db()->prepare("
    SELECT a.*, u.nombre_completo AS subido_por_nombre
    FROM gestion_adjuntos_actividad a
    LEFT JOIN gestion_usuarios u ON u.id_usuario = a.subido_por
    WHERE a.id_actividad = ?
    ORDER BY a.subido_en DESC
");
$stmtAdj->execute([$id_actividad]);
$adjuntos = $stmtAdj->fetchAll();

$stmtCom = db()->prepare("
    SELECT c.*, u.nombre_completo
    FROM gestion_comentarios_actividad c
    JOIN gestion_usuarios u ON u.id_usuario = c.id_usuario
    WHERE c.id_actividad = ?
    ORDER BY c.creado_en ASC
");
$stmtCom->execute([$id_actividad]);
$comentarios = $stmtCom->fetchAll();

json_response([
    'actividad'   => $actividad,
    'adjuntos'    => $adjuntos,
    'comentarios' => $comentarios,
    'user_rol'    => $user['rol'],
]);