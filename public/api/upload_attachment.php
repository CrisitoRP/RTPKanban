<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);
if ($user['rol'] === 'visualizador') json_response(['error' => 'Sin permisos'], 403);

$id_actividad = $_POST['id_actividad'] ?? 0;
$tipo = $_POST['tipo'] ?? '';

if (!$id_actividad || !$tipo) json_response(['error' => 'Datos incompletos'], 400);

$stmt = db()->prepare("SELECT id_proyecto, estado FROM gestion_actividades WHERE id_actividad = ?");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if (!can_access_project($user, $act['id_proyecto'])) json_response(['error' => 'Sin permisos'], 403);
if ($act['estado'] === 'bloqueado') json_response(['error' => 'Actividad bloqueada'], 403);

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($act['id_proyecto']);

if ($tipo === 'enlace') {
    $url = trim($_POST['url'] ?? '');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_response(['error' => 'URL no válida'], 400);
    }

    db()->prepare("
        INSERT INTO gestion_adjuntos_actividad (id_actividad, tipo, url_externa, subido_por)
        VALUES (?, 'enlace', ?, ?)
    ")->execute([$id_actividad, $url, $user['id_usuario']]);

    json_response(['ok' => true]);
}

if (!isset($_FILES['archivo'])) json_response(['error' => 'Falta el archivo'], 400);

$archivo = $_FILES['archivo'];
if ($archivo['error'] !== UPLOAD_ERR_OK) json_response(['error' => 'Error al subir'], 500);
if ($archivo['size'] > 10 * 1024 * 1024) json_response(['error' => 'Excede los 10 MB'], 400);

$uploadDir = __DIR__ . '/../assets/uploads/actividades/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
$nuevoNombre = 'act_' . $id_actividad . '_' . uniqid() . '.' . $ext;
$destino = $uploadDir . $nuevoNombre;

if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
    json_response(['error' => 'Error al mover archivo'], 500);
}

db()->prepare("
    INSERT INTO gestion_adjuntos_actividad
        (id_actividad, tipo, nombre_archivo, ruta_archivo, tipo_mime, tamano_archivo, subido_por)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([
    $id_actividad, $tipo, $archivo['name'],
    'assets/uploads/actividades/' . $nuevoNombre,
    $archivo['type'], $archivo['size'], $user['id_usuario']
]);

json_response(['ok' => true]);