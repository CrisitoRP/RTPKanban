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
 * Envía un correo usando PHPMailer
 */
function enviar_correo($destinatario, $nombre_dest, $asunto, $cuerpo_html) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Configuración SMTP
        $mail->isSMTP();
        $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('MAIL_USER');
        $mail->Password   = env('MAIL_PASS');
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) env('MAIL_PORT', 587);
        $mail->CharSet    = 'UTF-8';

        // Remitente y destinatario
        $mail->setFrom(env('MAIL_USER'), env('MAIL_FROM_NAME', 'RTP Kanban'));
        $mail->addAddress($destinatario, $nombre_dest);

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo_html;
        $mail->AltBody = strip_tags($cuerpo_html);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}