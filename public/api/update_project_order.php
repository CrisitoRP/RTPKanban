<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/project_state.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
if ($user['rol'] !== 'administrador') {
    http_response_code(403);
    echo json_encode(['error' => 'Solo el administrador puede reordenar proyectos.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

/* ========== FUNCIÓN AUXILIAR PARA EL HISTORIAL ========== */
function registrar_historial_movimiento($id_proyecto, $id_usuario, $columna_destino, $fecha_apertura = null, $motivo = null) {
    $etapas = [
        'sin_iniciar' => 'Sin iniciar',
        'en_curso'    => 'En curso',
        'en_revision' => 'En revisión',
        'programado'  => 'Programado',
        'finalizado'  => 'Finalizado',
        'bloqueado'   => 'Bloqueado'
    ];
    $nombre_etapa = $etapas[$columna_destino] ?? $columna_destino;
    
    // Texto limpio sin etiquetas HTML
    $descripcion = "Movió el proyecto a la columna " . $nombre_etapa . ".";
    
    if ($columna_destino === 'programado' && $fecha_apertura) {
        $fecha_formateada = date('d/m/Y H:i', strtotime($fecha_apertura));
        $descripcion .= " Programado para abrirse el " . $fecha_formateada . ".";
    }
    if ($columna_destino === 'bloqueado' && $motivo) {
        $descripcion .= " Motivo: " . $motivo . ".";
    }

    $stmt = db()->prepare("
        INSERT INTO gestion_historial_proyecto 
        (id_proyecto, id_usuario, tipo, accion, descripcion, metadata, creado_en) 
        VALUES (?, ?, 'edicion', 'movimiento_columna', ?, '{}', CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$id_proyecto, $id_usuario, $descripcion]);
}

/* ========== 1) RESET GLOBAL ========== */
if (!empty($input['reset_all'])) {
    db()->exec("UPDATE gestion_proyectos SET columna_manual = NULL, orden_manual = 0, estado = 'activo', bloqueado = FALSE, fecha_apertura = NULL");
    echo json_encode(['ok' => true]);
    exit;
}

/* ========== 2) RESET DE UNA SOLA TARJETA ========== */
if (isset($input['id_proyecto']) && array_key_exists('columna_manual', $input) && $input['columna_manual'] === null) {
    $id = (int)$input['id_proyecto'];
    db()->prepare("UPDATE gestion_proyectos SET columna_manual = NULL, orden_manual = 0, estado = 'activo', bloqueado = FALSE, fecha_apertura = NULL WHERE id_proyecto = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

/* ========== 3) MOVER UNA SOLA TARJETA ========== */
if (isset($input['id_proyecto'], $input['columna_manual'])) {
    $id    = (int)$input['id_proyecto'];
    $col   = $input['columna_manual'];
    $orden = isset($input['orden']) ? (int)$input['orden'] : 0;
    $fecha_apertura = $input['fecha_apertura'] ?? null;
    $motivo = $input['motivo'] ?? null;

    $columnas_validas = ['sin_iniciar', 'en_curso', 'en_revision', 'programado', 'finalizado', 'bloqueado'];
    if (!in_array($col, $columnas_validas, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Columna inválida.']);
        exit;
    }

    // Obtener el estado anterior del proyecto para el historial
    $stmtOld = db()->prepare("SELECT columna_manual FROM gestion_proyectos WHERE id_proyecto = ?");
    $stmtOld->execute([$id]);
    $oldData = $stmtOld->fetch();
    $col_anterior = $oldData ? $oldData['columna_manual'] : null;

    // Manejo específico para PROGRAMADO
    if ($col === 'programado') {
        if (empty($fecha_apertura)) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere fecha de apertura para programar.']);
            exit;
        }
        
        $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, orden_manual = ?, fecha_apertura = ?, bloqueado = FALSE WHERE id_proyecto = ?");
        $stmt->execute([$col, $orden, $fecha_apertura, $id]);
        
        if ($col_anterior !== 'programado') {
            registrar_historial_movimiento($id, $user['id_usuario'], $col, $fecha_apertura, null);
        }
        
        echo json_encode(['ok' => true]);
        exit;
    }

    // Manejo específico para BLOQUEADO
    if ($col === 'bloqueado') {
        $motivo = $motivo ?? 'Bloqueado manualmente';
        
        $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, orden_manual = ?, bloqueado = TRUE, motivo_bloqueo = ?, bloqueado_en = CURRENT_TIMESTAMP, bloqueado_por = ? WHERE id_proyecto = ?");
        $stmt->execute([$col, $orden, $motivo, $user['id_usuario'], $id]);
        
        if ($col_anterior !== 'bloqueado') {
            registrar_historial_movimiento($id, $user['id_usuario'], $col, null, $motivo);
        }
        
        echo json_encode(['ok' => true]);
        exit;
    }

    // Para las demás columnas, desbloqueamos y limpiamos la fecha de apertura
    $stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, orden_manual = ?, bloqueado = FALSE, motivo_bloqueo = NULL, fecha_apertura = NULL WHERE id_proyecto = ?");
    $stmt->execute([$col, $orden, $id]);
    
    if ($col_anterior !== $col) {
        registrar_historial_movimiento($id, $user['id_usuario'], $col, null, null);
    }
    
    echo json_encode(['ok' => true]);
    exit;
}

/* ========== 4) GUARDAR ORDEN COMPLETO DE UNA COLUMNA ========== */
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
echo json_encode(['error' => 'Solicitud inválida']);