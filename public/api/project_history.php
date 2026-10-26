<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

$id_proyecto = (int) ($_GET['id_proyecto'] ?? 0);
$filtro      = $_GET['filtro'] ?? 'todos';

if (!$id_proyecto) {
    json_response(['error' => 'ID de proyecto inválido'], 400);
}

if (!can_access_project($user, $id_proyecto)) {
    json_response(['error' => 'Sin acceso a este proyecto'], 403);
}

// ============================================================
// FILTRO
// ============================================================
$where  = "h.id_proyecto = ?";
$params = [$id_proyecto];

switch ($filtro) {
    case 'creaciones':
        $where .= " AND h.tipo = 'creacion'";
        break;
    case 'eliminaciones':
        $where .= " AND h.tipo = 'eliminacion'";
        break;
    case 'usuarios':
        $where .= " AND h.tipo = 'usuario'";
        break;
    case 'bloqueos':
        $where .= " AND h.tipo = 'bloqueo'";
        break;
    case 'ediciones':
        $where .= " AND h.tipo = 'edicion'";
        break;
    case 'todos':
    default:
        // Sin filtro adicional
        break;
}

// ============================================================
// CONSULTA PRINCIPAL
// ============================================================
$stmt = db()->prepare("
    SELECT h.id_historial,
           h.id_proyecto,
           h.id_usuario,
           h.tipo,
           h.accion,
           h.descripcion,
           h.metadata,
           h.creado_en,
           u.nombre_completo AS usuario_nombre,
           u.correo AS usuario_correo
    FROM gestion_historial_proyecto h
    LEFT JOIN gestion_usuarios u ON u.id_usuario = h.id_usuario
    WHERE $where
    ORDER BY h.creado_en DESC
    LIMIT 500
");
$stmt->execute($params);
$eventos = $stmt->fetchAll();

// ============================================================
// CONTEO POR TIPO (para los badges)
// ============================================================
$stmt = db()->prepare("
    SELECT tipo, COUNT(*) AS total
    FROM gestion_historial_proyecto
    WHERE id_proyecto = ?
    GROUP BY tipo
");
$stmt->execute([$id_proyecto]);

$conteo = [
    'creacion'    => 0,
    'eliminacion' => 0,
    'usuario'     => 0,
    'bloqueo'     => 0,
    'edicion'     => 0
];

foreach ($stmt->fetchAll() as $row) {
    $conteo[$row['tipo']] = (int) $row['total'];
}

// ============================================================
// RESPUESTA
// ============================================================
json_response([
    'eventos' => $eventos,
    'conteo'  => $conteo
]);