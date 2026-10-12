<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

// Proyectos del usuario
if ($user['rol'] === 'administrador') {
    $proyectos = db()->query("SELECT * FROM gestion_proyectos")->fetchAll();
} else {
    $stmt = db()->prepare("
        SELECT p.* FROM gestion_proyectos p
        JOIN gestion_miembros_proyecto m ON m.id_proyecto = p.id_proyecto
        WHERE m.id_usuario = ?
    ");
    $stmt->execute([$user['id_usuario']]);
    $proyectos = $stmt->fetchAll();
}

$ids = array_column($proyectos, 'id_proyecto');
$idsPlaceholder = $ids ? implode(',', array_fill(0, count($ids), '?')) : '0';

// 1. SEMÁFORO POR PROYECTO
$semaforo = [];
if ($ids) {
    $stmt = db()->prepare("
        SELECT
            p.id_proyecto,
            p.nombre AS proyecto,
            COUNT(a.id_actividad) FILTER (WHERE a.estado = 'completada') AS completadas,
            COUNT(a.id_actividad) FILTER (WHERE a.estado = 'en_curso') AS en_curso,
            COUNT(a.id_actividad) FILTER (WHERE a.estado = 'en_revision') AS en_revision,
            COUNT(a.id_actividad) FILTER (WHERE a.estado = 'por_hacer') AS por_hacer,
            COUNT(a.id_actividad) FILTER (WHERE a.estado = 'bloqueado') AS bloqueadas,
            COUNT(a.id_actividad) AS total
        FROM gestion_proyectos p
        LEFT JOIN gestion_actividades a ON a.id_proyecto = p.id_proyecto
        WHERE p.id_proyecto IN ($idsPlaceholder)
        GROUP BY p.id_proyecto, p.nombre
        ORDER BY p.nombre
    ");
    $stmt->execute($ids);
    $semaforo = $stmt->fetchAll();
}

// 2. CARGA POR PROYECTO (con colores según fecha límite)
$carga = [];
if ($ids) {
    $stmt = db()->prepare("
        SELECT
            u.id_usuario,
            u.nombre_completo,
            COUNT(a.id_actividad) FILTER (WHERE a.fecha_limite > CURRENT_DATE + INTERVAL '7 days') AS verdes,
            COUNT(a.id_actividad) FILTER (WHERE a.fecha_limite BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days') AS naranjas,
            COUNT(a.id_actividad) FILTER (WHERE a.fecha_limite < CURRENT_DATE) AS rojos,
            COUNT(a.id_actividad) AS total
        FROM gestion_usuarios u
        LEFT JOIN gestion_actividades a ON a.asignado_a = u.id_usuario
            AND a.id_proyecto IN ($idsPlaceholder)
            AND a.estado NOT IN ('completada')
        WHERE u.activo = TRUE
          AND u.rol IN ('responsable','administrador')
        GROUP BY u.id_usuario, u.nombre_completo
        HAVING COUNT(a.id_actividad) > 0
        ORDER BY total DESC
    ");
    $stmt->execute($ids);
    $carga = $stmt->fetchAll();
}

// 3. AVANCE CONTRA TIEMPO
$avance_tiempo = [];
foreach ($proyectos as $p) {
    $hoy = new DateTime();
    $inicio = $p['fecha_inicio'] ? new DateTime($p['fecha_inicio']) : null;
    $fin = $p['fecha_fin'] ? new DateTime($p['fecha_fin']) : null;

    $tiempo_pct = 0;
    if ($inicio && $fin && $fin > $inicio) {
        $total = $inicio->diff($fin)->days;
        $transcurrido = $inicio->diff($hoy)->days;
        $tiempo_pct = $total > 0 ? min(100, round(($transcurrido / $total) * 100, 1)) : 0;
    }

    $avance_tiempo[] = [
        'proyecto'      => $p['nombre'],
        'id_proyecto'   => $p['id_proyecto'],
        'avance_real'   => (float) $p['porcentaje_avance'],
        'avance_tiempo' => $tiempo_pct,
    ];
}

// 4. ÚLTIMO ACCESO DE RESPONSABLES
$stmt = db()->query("
    SELECT
        id_usuario,
        nombre_completo,
        ultimo_acceso,
        umbral_alerta_dias,
        EXTRACT(DAY FROM (CURRENT_TIMESTAMP - ultimo_acceso))::INTEGER AS dias_sin_acceso
    FROM gestion_usuarios
    WHERE activo = TRUE
      AND rol IN ('responsable','administrador')
    ORDER BY ultimo_acceso ASC NULLS LAST
");
$ultimo_acceso = $stmt->fetchAll();

// 5. TOP 5 PROYECTOS DETENIDOS
$detenidos = [];
if ($ids) {
    $stmt = db()->prepare("
        SELECT
            p.id_proyecto,
            p.nombre AS proyecto,
            p.id_responsable,
            u.nombre_completo AS responsable,
            p.ultima_actividad_en,
            EXTRACT(DAY FROM (CURRENT_TIMESTAMP - p.ultima_actividad_en))::INTEGER AS dias_sin_cambios
        FROM gestion_proyectos p
        LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
        WHERE p.id_proyecto IN ($idsPlaceholder)
          AND p.estado NOT IN ('finalizado','cancelado')
          AND p.ultima_actividad_en < CURRENT_TIMESTAMP - INTERVAL '7 days'
        ORDER BY dias_sin_cambios DESC
        LIMIT 5
    ");
    $stmt->execute($ids);
    $detenidos = $stmt->fetchAll();
}

json_response([
    'semaforo'       => $semaforo,
    'carga'          => $carga,
    'avance_tiempo'  => $avance_tiempo,
    'ultimo_acceso'  => $ultimo_acceso,
    'detenidos'      => $detenidos,
]);