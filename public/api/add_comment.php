<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

$id_actividad = $_POST['id_actividad'] ?? 0;
$contenido = trim($_POST['contenido'] ?? '');

if (!$id_actividad || !$contenido) json_response(['error' => 'Datos incompletos'], 400);

$stmt = db()->prepare("SELECT id_proyecto, estado FROM gestion_actividades WHERE id_actividad = ?");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if (!can_access_project($user, $act['id_proyecto'])) json_response(['error' => 'Sin permisos'], 403);

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($act['id_proyecto']);

$es_admin = ($user['rol'] === 'administrador' && $act['estado'] === 'bloqueado');

db()->prepare("
    INSERT INTO gestion_comentarios_actividad (id_actividad, id_usuario, contenido, es_nota_admin)
    VALUES (?, ?, ?, ?)
")->execute([$id_actividad, $user['id_usuario'], $contenido, $es_admin]);

json_response(['ok' => true]);