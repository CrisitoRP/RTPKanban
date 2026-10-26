<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
if ($user['rol'] !== 'administrador') {
    http_response_code(403);
    echo json_encode(['error' => 'Solo el administrador puede reordenar proyectos.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Función auxiliar para guardar en el historial (con protección de errores)
function registrar_movimiento_manual($id_proyecto, $id_usuario, $nueva_columna) {
    try {
        $etapas = [
            'sin_iniciar' => 'Sin iniciar',
            'en_curso'    => 'En curso',
            'en_revision' => 'En revisión',
            'programado'  => 'Programados',
            'finalizado'  => 'Finalizado',
            'bloqueado'   => 'Bloqueado'
        ];
        $nombre_etapa = $etapas[$nueva_columna] ?? $nueva_columna;
        $descripcion = "El proyecto fue movido MANUALMENTE a la etapa: " . $nombre_etapa;

        $stmt = db()->prepare("
            INSERT INTO gestion_historial_proyecto (id_proyecto, id_usuario, tipo, accion, descripcion)
            VALUES (?, ?, 'edicion', 'mover_columna', ?)
        ");
        $stmt->execute([$id_proyecto, $id_usuario, $descripcion]);
    } catch (Exception $e) {
        // Si falla el historial, lo ignoramos para no bloquear el movimiento
        error_log("Error al guardar historial: " . $e->getMessage());
    }
}

/* ============================================================
   1) RESET GLOBAL
   ============================================================ */
if (!empty($input['reset_all'])) {
    db()->exec("UPDATE gestion_proyectos SET columna_manual = NULL, orden_manual = 0");
    
    // Registrar reset global en el historial de cada proyecto activo
    try {
        $stmtProyectos = db()->query("SELECT id_proyecto FROM gestion_proyectos WHERE archivado = FALSE AND eliminado = FALSE");
        foreach ($stmtProyectos->fetchAll() as $p) {
            registrar_movimiento_manual($p['id_proyecto'], $user['id_usuario'], 'sin_iniciar');
        }
    } catch (Exception $e) {
        error_log("Error en reset global historial: " . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   2) RESET DE UNA SOLA TARJETA
   ============================================================ */
if (isset($input['id_proyecto']) && array_key_exists('columna_manual', $input) && $input['columna_manual'] === null) {
    $id = (int)$input['id_proyecto'];
    $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = NULL, orden_manual = 0 WHERE id_proyecto = ?");
    $stmt->execute([$id]);
    
    registrar_movimiento_manual($id, $user['id_usuario'], 'sin_iniciar'); // Vuelve a automático

    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   3) MOVER UNA SOLA TARJETA (Drag & Drop o menú)
   ============================================================ */
if (isset($input['id_proyecto'], $input['columna_manual'])) {
    $id = (int)$input['id_proyecto'];
    $col = $input['columna_manual'];
    $orden = isset($input['orden']) ? (int)$input['orden'] : 0;

    $columnas_validas = ['sin_iniciar', 'en_curso', 'en_revision', 'programado', 'finalizado', 'bloqueado'];
    if (!in_array($col, $columnas_validas, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Columna inválida.']);
        exit;
    }

    // Obtener columna anterior para ver si cambió
    $stmtCheck = db()->prepare("SELECT columna_manual FROM gestion_proyectos WHERE id_proyecto = ?");
    $stmtCheck->execute([$id]);
    $col_anterior = $stmtCheck->fetchColumn();

    $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, orden_manual = ? WHERE id_proyecto = ?");
    $stmt->execute([$col, $orden, $id]);

    // Solo registrar si realmente cambió de etapa
    if ($col_anterior !== $col) {
        registrar_movimiento_manual($id, $user['id_usuario'], $col);
    }

    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   4) GUARDAR ORDEN COMPLETO DE UNA COLUMNA
   ============================================================ */
if (isset($input['columna_manual'], $input['orden_lista']) && is_array($input['orden_lista'])) {
    $col = $input['columna_manual'];
    $columnas_validas = ['sin_iniciar', 'en_curso', 'en_revision', 'programado', 'finalizado', 'bloqueado'];
    if (!in_array($col, $columnas_validas, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Columna inválida.']);
        exit;
    }

    $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, orden_manual = ? WHERE id_proyecto = ?");
    foreach ($input['orden_lista'] as $item) {
        if (!isset($item['id_proyecto'], $item['orden'])) continue;
        $stmt->execute([$col, (int)$item['orden'], (int)$item['id_proyecto']]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Solicitud inválida.']);