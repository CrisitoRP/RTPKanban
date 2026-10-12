<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede notificar'], 403);
}

$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;

if (!$id_proyecto) json_response(['error' => 'ID requerido'], 400);

$stmt = db()->prepare("
    SELECT p.nombre, p.id_responsable, u.nombre_completo AS responsable,
           EXTRACT(DAY FROM (CURRENT_TIMESTAMP - p.ultima_actividad_en))::INTEGER AS dias
    FROM gestion_proyectos p
    LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
    WHERE p.id_proyecto = ?
");
$stmt->execute([$id_proyecto]);
$p = $stmt->fetch();

if (!$p || !$p['id_responsable']) {
    json_response(['error' => 'Proyecto o responsable no encontrado'], 404);
}

db()->prepare("
    INSERT INTO gestion_notificaciones
        (id_usuario, id_proyecto, tipo, titulo, mensaje)
    VALUES (?, ?, 'proyecto_detenido', ?, ?)
")->execute([
    $p['id_responsable'],
    $id_proyecto,
    'Proyecto detenido',
    'El administrador ' . $user['nombre_completo'] . ' te notifica que el proyecto "' .
    $p['nombre'] . '" lleva ' . $p['dias'] . ' días sin cambios.'
]);

json_response(['ok' => true, 'dias' => $p['dias']]);