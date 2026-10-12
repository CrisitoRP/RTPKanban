<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador'], 403);
}

$d = json_input();
$id_usuario = $d['id_usuario'] ?? 0;
$accion = $d['accion'] ?? 'finalizar'; // 'finalizar' | 'reactivar'

if (!$id_usuario) json_response(['error' => 'ID requerido'], 400);

if ($accion === 'finalizar') {
    db()->prepare("
        UPDATE gestion_usuarios
        SET actividades_finalizadas = TRUE,
            actividades_finalizadas_en = NOW()
        WHERE id_usuario = ?
    ")->execute([$id_usuario]);
} else {
    db()->prepare("
        UPDATE gestion_usuarios
        SET actividades_finalizadas = FALSE,
            actividades_finalizadas_en = NULL
        WHERE id_usuario = ?
    ")->execute([$id_usuario]);
}

json_response(['ok' => true]);