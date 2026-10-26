<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador'], 403);
}

$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;
$cambios = $d['cambios'] ?? [];

if (!$id_proyecto) json_response(['error' => 'Falta id_proyecto'], 400);
if (empty($cambios)) json_response(['error' => 'No hay cambios'], 400);

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($id_proyecto);

// Validar total
$total = 0;
foreach ($cambios as $c) {
    $p = (float) $c['porcentaje_aporte'];
    if ($p < 0 || $p > 100) {
        json_response(['error' => 'Los porcentajes deben estar entre 0 y 100'], 400);
    }
    $total += $p;
}

if ($total > 100) {
    json_response(['error' => "La suma no puede exceder 100%. Suma actual: " . number_format($total, 2) . "%"], 400);
}

// Aplicar cambios
db()->beginTransaction();
try {
    foreach ($cambios as $c) {
        db()->prepare("
            UPDATE gestion_actividades
            SET porcentaje_aporte = ?, actualizado_en = NOW()
            WHERE id_actividad = ? AND id_proyecto = ?
        ")->execute([$c['porcentaje_aporte'], $c['id_actividad'], $id_proyecto]);
    }
    db()->commit();

    // 📝 Registrar en el historial (un solo evento resumen)
    $cantidad_cambios = count($cambios);
    registrar_historial(
        $id_proyecto,
        'edicion',
        'porcentajes_actualizados',
        'Se actualizaron los porcentajes de ' . $cantidad_cambios . ' actividad' . ($cantidad_cambios > 1 ? 'es' : '') . ' (total: ' . number_format($total, 2) . '%)',
        ['total' => $total, 'cantidad' => $cantidad_cambios]
    );

    json_response(['ok' => true, 'total' => $total]);
} catch (Exception $e) {
    db()->rollBack();
    json_response(['error' => 'Error: ' . $e->getMessage()], 500);
}