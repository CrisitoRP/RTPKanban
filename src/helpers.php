<?php
require_once __DIR__ . '/db.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function can_access_project($user, $id_proyecto) {
    if ($user['rol'] === 'administrador') return true;
    $stmt = db()->prepare("
        SELECT 1 FROM gestion_miembros_proyecto
        WHERE id_proyecto = ? AND id_usuario = ?
    ");
    $stmt->execute([$id_proyecto, $user['id_usuario']]);
    return (bool) $stmt->fetch();
}

function recalcular_avance_proyecto($id_proyecto) {
    $stmt = db()->prepare("SELECT calcular_avance_proyecto(?) AS avance");
    $stmt->execute([$id_proyecto]);
    $avance = (float) $stmt->fetch()['avance'];

    db()->prepare("
        UPDATE gestion_proyectos
        SET porcentaje_avance = ?, actualizado_en = CURRENT_TIMESTAMP
        WHERE id_proyecto = ?
    ")->execute([$avance, $id_proyecto]);

    return $avance;
}

function fmt_date($fecha, $conHora = false) {
    if (!$fecha) return '—';
    $ts = strtotime($fecha);
    return $conHora ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}

function status_label($estado) {
    return [
        'por_hacer'    => 'Por hacer',
        'en_curso'     => 'En curso',
        'en_revision'  => 'En revisión',
        'completada'   => 'Completada',
        'bloqueado'    => 'Bloqueado',
    ][$estado] ?? $estado;
}

function project_status_label($estado) {
    return [
        'activo'     => 'Activo',
        'en_riesgo'  => 'En riesgo',
        'detenido'   => 'Detenido',
        'finalizado' => 'Finalizado',
        'cancelado'  => 'Cancelado',
    ][$estado] ?? $estado;
}

function priority_label($prioridad) {
    return [
        'baja'    => 'Baja',
        'media'   => 'Media',
        'alta'    => 'Alta',
        'critica' => 'Crítica',
    ][$prioridad] ?? $prioridad;
}

function generar_token($largo = 64) {
    return bin2hex(random_bytes($largo / 2));
}

/**
 * Verifica si un usuario puede eliminar/archivar un proyecto o actividad
 */
function puede_gestionar($user, $nivel = 'proyecto') {
    if ($user['rol'] === 'administrador') return true;
    if ($nivel === 'proyecto' && $user['rol'] === 'responsable') return true;
    return false;
}


/**
 * Envía un correo usando PHPMailer.
 * 🔍 Versión con DEBUG activado para diagnosticar lentitud.
 */
function enviar_correo($destinatario, $nombre_dest, $asunto, $cuerpo_html) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // ================================================================
        // 🔍 DEBUG — Mide tiempos y muestra mensajes SMTP en el log
        // ================================================================
        $mail->SMTPDebug = 3; // 0=silencioso, 2=mensajes SMTP, 3=verbose
        $mail->Debugoutput = function($str, $level) {
            error_log("[PHPMailer] " . trim($str));
        };

        $t0 = microtime(true);
        error_log("[PHPMailer] ===== INICIO envío a $destinatario =====");

        // ================================================================
        // CONFIGURACIÓN SMTP
        // ================================================================
        $puerto = (int) env('MAIL_PORT', 587);

        $mail->isSMTP();
        $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('MAIL_USER');
        $mail->Password   = env('MAIL_PASS');

        // Si el puerto es 465 → SMTPS (SSL). Si es 587 → STARTTLS.
        if ($puerto === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port    = $puerto;
        $mail->CharSet = 'UTF-8';

        // Timeout de conexión (evita que se cuelgue si Gmail no responde)
        $mail->Timeout = 15;
        $mail->SMTPKeepAlive = false;

        // ================================================================
        // REMITENTE Y DESTINATARIO
        // ================================================================
        $mail->setFrom(env('MAIL_USER'), env('MAIL_FROM_NAME', 'RTP Kanban'));
        $mail->addAddress($destinatario, $nombre_dest);

        // ================================================================
        // CONTENIDO
        // ================================================================
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo_html;
        $mail->AltBody = strip_tags($cuerpo_html);

        // ================================================================
        // ENVÍO
        // ================================================================
        $tAntesDeEnviar = microtime(true);
        error_log("[PHPMailer] Setup completado en " . round(($tAntesDeEnviar - $t0) * 1000) . " ms. Enviando...");

        $mail->send();

        $t1 = microtime(true);
        error_log("[PHPMailer] ✅ Envío OK — Total: " . round($t1 - $t0, 2) . " segundos");
        error_log("[PHPMailer] ===== FIN envío a $destinatario =====");

        return true;

    } catch (Exception $e) {
        $tError = microtime(true);
        error_log("[PHPMailer] ❌ Error tras " . round($tError - $t0, 2) . " segundos: " . $mail->ErrorInfo);
        error_log("[PHPMailer] ===== FIN con error =====");
        return false;
    }
}

/* ============================================================
   HISTORIAL DE PROYECTO
   ============================================================ */

/**
 * Registra un evento en el historial del proyecto.
 *
 * @param int    $id_proyecto  ID del proyecto
 * @param string $tipo         'creacion' | 'eliminacion' | 'usuario' | 'bloqueo' | 'edicion'
 * @param string $accion       Identificador técnico: 'proyecto_creado', 'actividad_creada', etc.
 * @param string $descripcion  Texto legible para el usuario
 * @param array  $metadata     Datos extra (opcional)
 */
function registrar_historial($id_proyecto, $tipo, $accion, $descripcion, $metadata = null) {
    $user = current_user();
    $id_usuario = $user['id_usuario'] ?? null;

    try {
        $stmt = db()->prepare("
            INSERT INTO gestion_historial_proyecto
                (id_proyecto, id_usuario, tipo, accion, descripcion, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id_proyecto,
            $id_usuario,
            $tipo,
            $accion,
            $descripcion,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (Exception $e) {
        error_log("Error registrando historial: " . $e->getMessage());
    }
}

/* ============================================================
   BLOQUEO DE PROYECTO
   ============================================================ */

/**
 * Devuelve true si el proyecto está bloqueado.
 */
function proyecto_bloqueado($id_proyecto) {
    try {
        $stmt = db()->prepare("SELECT bloqueado FROM gestion_proyectos WHERE id_proyecto = ?");
        $stmt->execute([$id_proyecto]);
        $row = $stmt->fetch();
        return $row && !empty($row['bloqueado']);
    } catch (Exception $e) {
        error_log("Error consultando bloqueo: " . $e->getMessage());
        return false;
    }
}

/**
 * Corta la ejecución con un JSON de error si el proyecto está bloqueado.
 * Úsala al inicio de cualquier endpoint que modifique datos del proyecto.
 */
function bloquear_si_proyecto_bloqueado($id_proyecto) {
    if (proyecto_bloqueado($id_proyecto)) {
        json_response([
            'error' => 'El proyecto está bloqueado. No se pueden realizar cambios hasta desbloquearlo.'
        ], 403);
    }
}