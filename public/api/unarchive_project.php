<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$id_proyecto = $data['id_proyecto'] ?? null;

if (!$id_proyecto) {
    echo json_encode(['error' => 'ID de proyecto requerido']);
    exit;
}

$stmt = db()->prepare("SELECT nombre FROM gestion_proyectos WHERE id_proyecto = ? AND eliminado = FALSE");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) {
    echo json_encode(['error' => 'Proyecto no encontrado']);
    exit;
}

if (!can_access_project($user, $id_proyecto)) {
    echo json_encode(['error' => 'Sin acceso al proyecto']);
    exit;
}

if ($user['rol'] !== 'administrador' && $user['rol'] !== 'responsable') {
    echo json_encode(['error' => 'Sin permisos para desarchivar']);
    exit;
}

db()->prepare("
    UPDATE gestion_proyectos
    SET archivado = FALSE,
        archivado_en = NULL,
        archivado_por = NULL,
        actualizado_en = NOW()
    WHERE id_proyecto = ?
")->execute([$id_proyecto]);

$stmtH = db()->prepare("
    INSERT INTO gestion_historial_proyecto
        (id_proyecto, id_usuario, tipo, accion, descripcion, metadata, creado_en)
    VALUES (?, ?, 'edicion', 'Proyecto desarchivado', 'Se desarchivó el proyecto', ?::jsonb, NOW())
");
$stmtH->execute([
    $id_proyecto,
    $user['id_usuario'],
    json_encode(['campo' => 'archivado', 'valor_nuevo' => false], JSON_UNESCAPED_UNICODE)
]);

echo json_encode(['ok' => true, 'mensaje' => 'Proyecto desarchivado correctamente']);