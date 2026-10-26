<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

// 🔍 DEBUG: registrar TODO lo que llega
error_log("[create_project] ===== INICIO =====");

$user = current_user();
if (!$user || $user['rol'] === 'visualizador') {
    json_response(['error' => 'Sin permisos'], 403);
}

$d = json_input();

// 🔍 DEBUG: ver el payload completo
error_log("[create_project] Payload: " . json_encode($d, JSON_UNESCAPED_UNICODE));

$nombre = trim($d['nombre'] ?? '');
$actividades = $d['actividades'] ?? [];
$invitaciones = $d['invitaciones'] ?? [];
$fecha_apertura = $d['fecha_apertura'] ?? null;

// 🔍 DEBUG: contadores
error_log("[create_project] Nombre: '$nombre'");
error_log("[create_project] Actividades: " . count($actividades));
error_log("[create_project] Invitaciones: " . count($invitaciones));
error_log("[create_project] Fecha apertura: " . ($fecha_apertura ?? 'NULL'));

if (!$nombre) json_response(['error' => 'Falta el nombre del proyecto'], 400);

// ============================================================
// Calcular si el proyecto está abierto ahora mismo o programado
// ============================================================
$abierto = true;
$fecha_apertura_valida = null;

if (!empty($fecha_apertura)) {
    $ts = strtotime($fecha_apertura);
    if ($ts === false) {
        json_response(['error' => 'Fecha de apertura inválida'], 400);
    }
    $fecha_apertura_valida = date('Y-m-d H:i:s', $ts);
    if ($ts > time()) {
        $abierto = false;
    }
}

// ============================================================
// 1. Crear el proyecto
// ============================================================
db()->beginTransaction();

try {
    $stmt = db()->prepare("
        INSERT INTO gestion_proyectos
            (nombre, id_responsable, creado_por, estado, fecha_apertura, abierto)
        VALUES (?, ?, ?, 'activo', ?, ?)
        RETURNING id_proyecto
    ");
    $stmt->execute([
        $nombre,
        $user['id_usuario'],
        $user['id_usuario'],
        $fecha_apertura_valida,
        $abierto ? 'true' : 'false'
    ]);
    $id_proyecto = $stmt->fetch()['id_proyecto'];

    error_log("[create_project] Proyecto creado con ID: $id_proyecto");

    db()->prepare("
        INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
        VALUES (?, ?, 'administrador')
        ON CONFLICT (id_proyecto, id_usuario) DO NOTHING
    ")->execute([$id_proyecto, $user['id_usuario']]);

    // 2. Crear actividades
    if (!empty($actividades)) {
        $stmtAct = db()->prepare("
            INSERT INTO gestion_actividades
                (id_proyecto, titulo, estado, prioridad, fecha_limite, creado_por)
            VALUES (?, ?, 'por_hacer', ?, ?, ?)
        ");
        foreach ($actividades as $a) {
            $stmtAct->execute([
                $id_proyecto,
                $a['titulo'],
                $a['prioridad'] ?? 'media',
                !empty($a['fecha']) ? $a['fecha'] : null,
                $user['id_usuario']
            ]);
        }
        error_log("[create_project] " . count($actividades) . " actividades creadas");
    }

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'creacion',
        'proyecto_creado',
        'Se creó el proyecto "' . $nombre . '"',
        ['fecha_apertura' => $fecha_apertura_valida, 'abierto' => $abierto]
    );

    if (!$abierto && $fecha_apertura_valida) {
        registrar_historial(
            $id_proyecto,
            'bloqueo',
            'proyecto_programado',
            'El proyecto se abrirá automáticamente el ' . date('d/m/Y \a \l\a\s H:i', strtotime($fecha_apertura_valida)),
            ['fecha_apertura' => $fecha_apertura_valida]
        );
    }

    db()->commit();
    error_log("[create_project] ✅ Commit OK");
} catch (Exception $e) {
    db()->rollBack();
    error_log("[create_project] ❌ Error creando proyecto: " . $e->getMessage());
    json_response(['error' => 'Error creando proyecto: ' . $e->getMessage()], 500);
}

// ============================================================
// 3. Enviar invitaciones
// ============================================================
$resultados = [];

error_log("[create_project] Iniciando envío de " . count($invitaciones) . " invitaciones...");

foreach ($invitaciones as $i => $inv) {
    $email = $inv['email'] ?? '';
    $rol = $inv['rol'] ?? '';

    error_log("[create_project] Invitación #$i → email='$email' rol='$rol'");

    if (!$email || !$rol) {
        error_log("[create_project] ⚠️ Invitación #$i inválida (falta email o rol)");
        $resultados[] = ['email' => $email, 'ok' => false, 'error' => 'Datos incompletos'];
        continue;
    }

    $token = generar_token(64);
    $expira = date('Y-m-d H:i:s', strtotime('+7 days'));

    try {
        db()->prepare("
            INSERT INTO gestion_invitaciones
                (id_proyecto, correo, rol_miembro, token, expira_en, creado_por)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$id_proyecto, $email, $rol, $token, $expira, $user['id_usuario']]);
        error_log("[create_project] Invitación #$i guardada en BD");
    } catch (Exception $e) {
        error_log("[create_project] ❌ Error insert invitación #$i: " . $e->getMessage());
        $resultados[] = ['email' => $email, 'ok' => false, 'error' => $e->getMessage()];
        continue;
    }

    $link = env('APP_URL', 'http://localhost:8000') . '/invite.php?token=' . $token;
    $rolLabel = ucfirst($rol);

    $avisoProgramado = '';
    if (!$abierto && $fecha_apertura_valida) {
        $fechaBonita = date('d/m/Y \a \l\a\s H:i', strtotime($fecha_apertura_valida));
        $avisoProgramado = "
            <div style='background: #fff7ed; border-left: 4px solid #F08217; padding: 12px 16px; border-radius: 8px; margin: 16px 0;'>
                <p style='color: #78350f; font-size: 13px; margin: 0;'>
                    ⏳ <b>Nota:</b> Este proyecto estará disponible a partir del <b>" . $fechaBonita . "</b>.
                </p>
            </div>
        ";
    }

    $cuerpo = "
        <div style='font-family: Roboto, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f7fa; padding: 20px;'>
            <div style='background: white; border-radius: 12px; padding: 30px; border-top: 5px solid #78BE20;'>
                <h1 style='color: #9F2241; margin-bottom: 10px;'>Invitación a RTP Kanban</h1>
                <p style='color: #55585A; font-size: 14px; line-height: 1.6;'>
                    <b>" . h($user['nombre_completo']) . "</b> te ha invitado a colaborar en el proyecto
                    <b>" . h($nombre) . "</b> con el rol de <b>" . $rolLabel . "</b>.
                </p>
                " . $avisoProgramado . "
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . $link . "' style='background: #78BE20; color: white; padding: 14px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; display: inline-block;'>
                        Aceptar invitación
                    </a>
                </div>
                <p style='color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                    O copia este enlace:<br>
                    <a href='" . $link . "' style='color: #78BE20; word-break: break-all;'>" . $link . "</a>
                </p>
                <p style='color: #94a3b8; font-size: 12px; margin-top: 20px;'>
                    Expira el " . date('d/m/Y', strtotime($expira)) . ".
                </p>
            </div>
        </div>
    ";

    error_log("[create_project] Llamando enviar_correo() a $email...");
    $t0 = microtime(true);
    $ok = enviar_correo($email, $email, 'Invitación a RTP Kanban', $cuerpo);
    $t1 = microtime(true);

    error_log("[create_project] enviar_correo() devolvió: " . ($ok ? 'TRUE' : 'FALSE') . " en " . round($t1 - $t0, 2) . "s");

    $resultados[] = ['email' => $email, 'ok' => $ok];
}

error_log("[create_project] ===== FIN — " . count($resultados) . " resultados =====");

json_response([
    'ok' => true,
    'id_proyecto' => $id_proyecto,
    'abierto' => $abierto,
    'fecha_apertura' => $fecha_apertura_valida,
    'invitaciones_resultados' => $resultados,
]);