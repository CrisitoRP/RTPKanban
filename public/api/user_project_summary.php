<?php
// Forzar que cualquier salida sea JSON, incluso errores
header('Content-Type: application/json; charset=utf-8');

// Capturar errores fatales y warnings como JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Excepción: ' . $e->getMessage()]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['error' => 'Error fatal: ' . $err['message'] . ' en ' . $err['file'] . ':' . $err['line']]);
    }
});

// ✅ Rutas corregidas (src/ está dos niveles arriba, en la raíz del proyecto)
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/project_state.php';

// Verificar sesión SIN redirigir: si no hay sesión, devolver JSON 401
if (!function_exists('current_user')) {
    http_response_code(500);
    echo json_encode(['error' => 'auth.php no define current_user()']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'No hay sesión activa. Inicia sesión de nuevo.']);
    exit;
}

$id_proyecto = (int)($_GET['id_proyecto'] ?? 0);
$id_usuario  = (int)($_GET['id_usuario'] ?? 0);

if (!$id_proyecto || !$id_usuario) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

if (!function_exists('can_access_project')) {
    http_response_code(500);
    echo json_encode(['error' => 'project_state.php no define can_access_project()']);
    exit;
}

if (!can_access_project($user, $id_proyecto)) {
    http_response_code(403);
    echo json_encode(['error' => 'No tienes acceso a este proyecto']);
    exit;
}

$stmt = db()->prepare("
    SELECT id_usuario, nombre_completo, correo, rol
    FROM gestion_usuarios
    WHERE id_usuario = ? AND eliminado = FALSE
");
$stmt->execute([$id_usuario]);
$usuario = $stmt->fetch();
if (!$usuario) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuario no encontrado']);
    exit;
}

$stmt = db()->prepare("
    SELECT rol_miembro
    FROM gestion_miembros_proyecto
    WHERE id_proyecto = ? AND id_usuario = ?
    LIMIT 1
");
$stmt->execute([$id_proyecto, $id_usuario]);
$miembro = $stmt->fetch();

// ✅ SQL compatible con PostgreSQL (sin DISTINCT conflictivo y con paréntesis en el ORDER BY)
$stmt = db()->prepare("
    SELECT a.id_actividad, a.titulo, a.estado, a.prioridad,
           a.porcentaje_aporte, a.fecha_inicio, a.fecha_limite
    FROM gestion_actividades a
    WHERE a.id_proyecto = ?
      AND a.archivada = FALSE
      AND a.eliminada = FALSE
      AND (
        a.asignado_a = ?
        OR EXISTS (
            SELECT 1 FROM gestion_actividades_responsables ar
            WHERE ar.id_actividad = a.id_actividad AND ar.id_usuario = ?
        )
      )
    ORDER BY (a.fecha_limite IS NULL) ASC, a.fecha_limite ASC, a.prioridad DESC
");
$stmt->execute([$id_proyecto, $id_usuario, $id_usuario]);
$actividades = $stmt->fetchAll();

$total_actividades  = count($actividades);
$completadas        = 0;
$porcentaje_total   = 0.0;
$porcentaje_logrado = 0.0;
$en_curso           = 0;
$por_hacer          = 0;
$bloqueadas         = 0;
$en_revision        = 0;
$programadas        = 0;

foreach ($actividades as &$a) {
    $pct = (float)$a['porcentaje_aporte'];
    $porcentaje_total += $pct;

    if ($a['estado'] === 'completada') {
        $completadas++;
        $porcentaje_logrado += $pct;
    } elseif ($a['estado'] === 'en_curso')    { $en_curso++; }
    elseif ($a['estado'] === 'por_hacer')    { $por_hacer++; }
    elseif ($a['estado'] === 'bloqueado')    { $bloqueadas++; }
    elseif ($a['estado'] === 'en_revision')  { $en_revision++; }
    elseif ($a['estado'] === 'programado')   { $programadas++; }

    $a['porcentaje_aporte'] = $pct;
}
unset($a);

$avance_usuario = $porcentaje_total > 0
    ? round(($porcentaje_logrado / $porcentaje_total) * 100, 2)
    : 0;

echo json_encode([
    'usuario'              => $usuario,
    'rol_miembro'          => $miembro['rol_miembro'] ?? null,
    'actividades'          => $actividades,
    'resumen' => [
        'total_actividades'      => $total_actividades,
        'completadas'            => $completadas,
        'en_curso'               => $en_curso,
        'en_revision'            => $en_revision,
        'por_hacer'              => $por_hacer,
        'bloqueadas'             => $bloqueadas,
        'programadas'            => $programadas,
        'porcentaje_total'       => round($porcentaje_total, 2),
        'porcentaje_logrado'     => round($porcentaje_logrado, 2),
        'avance_usuario_pct'     => $avance_usuario,
    ],
]);