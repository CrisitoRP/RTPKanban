<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador'], 403);
}

$nombre = trim($_POST['nombre_completo'] ?? '');
$usuario = trim($_POST['nombre_usuario'] ?? '');
$correo = trim($_POST['correo'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';
$rol = $_POST['rol'] ?? 'responsable';

if (!$nombre || !$usuario || !$correo || !$contrasena) {
    json_response(['error' => 'Todos los campos son obligatorios'], 400);
}

// Verificar duplicados
$stmt = db()->prepare("SELECT 1 FROM gestion_usuarios WHERE correo = ? OR nombre_usuario = ?");
$stmt->execute([$correo, $usuario]);
if ($stmt->fetch()) json_response(['error' => 'Correo o usuario ya existe'], 400);

$hash = password_hash($contrasena, PASSWORD_BCRYPT);

db()->prepare("
    INSERT INTO gestion_usuarios (nombre_usuario, correo, hash_contrasena, nombre_completo, rol)
    VALUES (?, ?, ?, ?, ?)
")->execute([$usuario, $correo, $hash, $nombre, $rol]);

json_response(['ok' => true]);