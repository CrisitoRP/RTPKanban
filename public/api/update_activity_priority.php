<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

// Solo admin y responsable pueden cambiar prioridad
if ($user['rol'] === 'visualizador') {
    json_response(['error' => 'Sin permisos para cambiar la prioridad'], 403);
}

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$nueva_prioridad = $d['prioridad'] ?? '';

if (!$id_actividad || !$nueva_prioridad) {
    json_response(['error' => 'Datos incompletos'], 400);
}

if (!in_array($nueva_prioridad, ['baja', 'media', 'alta', 'critica'])) {
    json_response(['error' => 'Prioridad no válida'], 400);
}

$stmt = db()->prepare("SELECT id_proyecto, titulo, prioridad, estado FROM gestion_actividades WHERE id_actividad = ?");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if (!can_access_project($user, $act['id_proyecto'])) {
    json_response(['error' => 'Sin permisos'], 403);
}
if ($act['estado'] === 'bloqueado') {
    json_response(['error' => 'La actividad está bloqueada'], 403);
}

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($act['id_proyecto']);

$prioridad_anterior = $act['prioridad'] ?? 'media';

db()->prepare("
    UPDATE gestion_actividades
    SET prioridad = ?, actualizado_en = NOW()
    WHERE id_actividad = ?
")->execute([$nueva_prioridad, $id_actividad]);

// 📝 Registrar en el historial
registrar_historial(
    $act['id_proyecto'],
    'edicion',
    'actividad_prioridad_cambiada',
    'Se cambió la prioridad de la actividad "' . $act['titulo'] . '" de ' . $prioridad_anterior . ' a ' . $nueva_prioridad,
    [
        'id_actividad' => $id_actividad,
        'anterior'     => $prioridad_anterior,
        'nueva'        => $nueva_prioridad
    ]
);

json_response(['ok' => true, 'prioridad' => $nueva_prioridad]);