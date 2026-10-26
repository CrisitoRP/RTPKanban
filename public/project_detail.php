<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_login();

$user = current_user();
$id_proyecto = $_GET['id'] ?? 0;

if (!$id_proyecto) {
    header('Location: projects.php');
    exit;
}

$stmt = db()->prepare("
    SELECT p.*, u.nombre_completo AS responsable_nombre
    FROM gestion_proyectos p
    LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
    WHERE p.id_proyecto = ?
");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) {
    header('Location: projects.php');
    exit;
}

if (!can_access_project($user, $id_proyecto)) {
    http_response_code(403);
    exit('No tienes acceso a este proyecto');
}

$stmt = db()->prepare("
    SELECT a.*, u.nombre_completo AS asignado_nombre
    FROM gestion_actividades a
    LEFT JOIN gestion_usuarios u ON u.id_usuario = a.asignado_a
    WHERE a.id_proyecto = ? AND a.archivada = FALSE AND a.eliminada = FALSE
    ORDER BY a.posicion, a.creado_en
");
$stmt->execute([$id_proyecto]);
$actividades = $stmt->fetchAll();

foreach ($actividades as &$act) {
    $stmtResp = db()->prepare("
        SELECT u.id_usuario, u.nombre_completo
        FROM gestion_actividades_responsables ar
        JOIN gestion_usuarios u ON u.id_usuario = ar.id_usuario
        WHERE ar.id_actividad = ? AND u.eliminado = FALSE AND u.activo = TRUE
        ORDER BY u.nombre_completo
    ");
    $stmtResp->execute([$act['id_actividad']]);
    $act['responsables'] = $stmtResp->fetchAll();

    if (empty($act['responsables']) && $act['asignado_a']) {
        $stmtU = db()->prepare("SELECT id_usuario, nombre_completo FROM gestion_usuarios WHERE id_usuario = ?");
        $stmtU->execute([$act['asignado_a']]);
        $u = $stmtU->fetch();
        if ($u) $act['responsables'] = [$u];
    }
}
unset($act);

$columnas = [
    'por_hacer'   => [],
    'en_curso'    => [],
    'en_revision' => [],
    'completada'  => [],
    'bloqueado'   => []
];
foreach ($actividades as $a) {
    $columnas[$a['estado']][] = $a;
}

$total_porcentaje = 0;
foreach ($actividades as $a) {
    $total_porcentaje += (float)($a['porcentaje_aporte'] ?? 0);
}

$stmt = db()->prepare("
    SELECT m.*, u.nombre_completo, u.correo, u.rol
    FROM gestion_miembros_proyecto m
    JOIN gestion_usuarios u ON u.id_usuario = m.id_usuario
    WHERE m.id_proyecto = ?
      AND u.eliminado = FALSE
      AND u.activo = TRUE
    ORDER BY m.rol_miembro, u.nombre_completo
");
$stmt->execute([$id_proyecto]);
$miembros = $stmt->fetchAll();

$proyecto_bloqueado  = !empty($proyecto['bloqueado']);
$proyecto_archivado  = !empty($proyecto['archivado']);
$fecha_apertura_ts   = !empty($proyecto['fecha_apertura']) ? strtotime($proyecto['fecha_apertura']) : null;
$proyecto_programado = ($fecha_apertura_ts && $fecha_apertura_ts > time());

if ($fecha_apertura_ts && $fecha_apertura_ts <= time() && empty($proyecto['abierto'])) {
    db()->prepare("UPDATE gestion_proyectos SET abierto = TRUE WHERE id_proyecto = ?")->execute([$id_proyecto]);
    $proyecto['abierto'] = true;
}

// Archivado = solo lectura, aunque no esté bloqueado ni programado
$proyecto_operativo = !$proyecto_bloqueado && !$proyecto_programado && !$proyecto_archivado;

$col_manual = !empty($proyecto['columna_manual']) ? $proyecto['columna_manual'] : null;

if ($proyecto_archivado) {
    $etapa_codigo = 'archivado';
    $etapa_label  = 'Archivado';
    $etapa_color  = '#FDC60A';
} elseif ($col_manual) {
    switch ($col_manual) {
        case 'bloqueado': $etapa_codigo='bloqueado'; $etapa_label='Bloqueado'; $etapa_color='#E5074C'; break;
        case 'programado': $etapa_codigo='programado'; $etapa_label='Programado'; $etapa_color='#F08217'; break;
        case 'finalizado': $etapa_codigo='finalizado'; $etapa_label='Finalizado'; $etapa_color='#027a35'; break;
        case 'en_revision': $etapa_codigo='en_revision'; $etapa_label='En revisión'; $etapa_color='#FDC60A'; break;
        case 'en_curso': $etapa_codigo='en_curso'; $etapa_label='En curso'; $etapa_color='#266cb4'; break;
        default: $etapa_codigo='sin_iniciar'; $etapa_label='Sin iniciar'; $etapa_color='#94a3b8'; break;
    }
} elseif ($proyecto_bloqueado) {
    $etapa_codigo='bloqueado'; $etapa_label='Bloqueado'; $etapa_color='#E5074C';
} elseif ($proyecto_programado) {
    $etapa_codigo='programado'; $etapa_label='Programado'; $etapa_color='#F08217';
} elseif ($proyecto['estado'] === 'finalizado' || (float)$proyecto['porcentaje_avance'] >= 100) {
    $etapa_codigo='finalizado'; $etapa_label='Finalizado'; $etapa_color='#027a35';
} elseif ($proyecto['estado'] === 'en_riesgo' || $proyecto['estado'] === 'detenido') {
    $etapa_codigo='en_revision'; $etapa_label='En revisión'; $etapa_color='#FDC60A';
} elseif ((float)$proyecto['porcentaje_avance'] == 0) {
    $etapa_codigo='sin_iniciar'; $etapa_label='Sin iniciar'; $etapa_color='#94a3b8';
} else {
    $etapa_codigo='en_curso'; $etapa_label='En curso'; $etapa_color='#266cb4';
}

// Solo se puede cambiar etapa si NO está archivado / bloqueado / programado
$puede_cambiar_etapa = ($user['rol'] === 'administrador' || $user['rol'] === 'responsable') && $proyecto_operativo;

function iniciales($nombre) {
    if (!$nombre) return '?';
    $partes = preg_split('/\s+/', trim($nombre));
    if (count($partes) === 1) return strtoupper(substr($partes[0], 0, 2));
    return strtoupper(substr($partes[0], 0, 1) . substr($partes[count($partes) - 1], 0, 1));
}

function renderTaskCard($a, $proyecto_bloqueado = false) {
    $estaBloqueada = ($a['estado'] === 'bloqueado');
    $clase = $estaBloqueada ? 'blocked' : '';
    $pct = (float)($a['porcentaje_aporte'] ?? 0);
    $pctVisible = min(100, max(0, $pct));
    $resto = 100 - $pctVisible;

    $prioridad = $a['prioridad'] ?? 'media';
    $prioColors = ['baja'=>'#027a35','media'=>'#FDC60A','alta'=>'#F08217','critica'=>'#E5074C'];
    $prioLabels = ['baja'=>'Baja','media'=>'Media','alta'=>'Alta','critica'=>'Crítica'];
    $prioColor = $prioColors[$prioridad] ?? '#FDC60A';
    $prioLabel = $prioLabels[$prioridad] ?? 'Media';

    $responsables = $a['responsables'] ?? [];
    ?>
    <div class="task-card <?= $clase ?>" 
         id="actividad-<?= (int)$a['id_actividad'] ?>"
         data-name="<?= h(strtolower($a['titulo'] . ' ' . ($a['descripcion'] ?? ''))) ?>"
         onclick="abrirDetalleActividad(<?= $a['id_actividad'] ?>)">

        <?php if ($estaBloqueada): ?>
            <div class="task-card-blocked-banner">
                <span class="blocked-banner-icon">🔒</span>
                <div class="blocked-banner-text">
                    <b>Actividad bloqueada</b>
                    <small>
                        Motivo: <strong><?= h($a['motivo_bloqueo'] ?? 'Sin especificar') ?></strong>
                        <?php if (!empty($a['bloqueado_en'])): ?>
                            · Bloqueada el <?= h(fmt_date($a['bloqueado_en'])) ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        <?php endif; ?>

        <div class="task-card-body">
            <div class="task-card-title">
                <?php if ($estaBloqueada): ?>
                    <span class="bloqueo-badge" title="Actividad bloqueada">🔒</span>
                <?php endif; ?>
                <?= h($a['titulo']) ?>
            </div>
            <div class="task-card-date <?= $estaBloqueada ? 'blocked-date' : '' ?>">
                <?php if ($estaBloqueada): ?>
                    📅 <?= h(fmt_date($a['fecha_limite'])) ?>
                    <?php if (!empty($a['bloqueado_en'])): ?>
                        <span class="blocked-date-extra">· Bloqueada <?= h(fmt_date($a['bloqueado_en'])) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    📅 <?= h(fmt_date($a['fecha_limite'])) ?>
                <?php endif; ?>
            </div>

            <div class="task-card-pct-ring" title="Aporta <?= $pct ?>% al proyecto">
                <svg viewBox="0 0 36 36" width="34" height="34">
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#78BE20" stroke-width="3"
                            stroke-dasharray="<?= $pctVisible ?> <?= $resto ?>"
                            stroke-dashoffset="0"
                            stroke-linecap="round"/>
                </svg>
                <span class="task-card-pct-text"><?= $pct ?>%</span>
            </div>
        </div>

        <div class="task-card-footer">
            <div class="task-card-priority">
                <span class="priority-dot" style="background: <?= $prioColor ?>;"></span>
                <span style="color: <?= $prioColor ?>; font-weight: 600;"><?= $prioLabel ?></span>
            </div>

            <div class="task-card-responsables">
                <?php if (empty($responsables)): ?>
                    <div class="task-card-avatar" style="background:#94a3b8;" title="Sin asignar">?</div>
                <?php else: ?>
                    <?php foreach ($responsables as $r): ?>
                        <div class="task-card-avatar" title="<?= h($r['nombre_completo']) ?>">
                            <?= h(iniciales($r['nombre_completo'])) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= h($proyecto['nombre']) ?> · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.project-header { background:#fff; border-radius:12px; padding:24px 28px; box-shadow:0 2px 8px rgba(0,0,0,.05); border-left:5px solid var(--rtp-verde); margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; gap:24px; flex-wrap:wrap; }
.project-progress-mini { display:flex; align-items:center; gap:12px; flex-shrink:0; }
.progress-circle { width:70px; height:70px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13pt; color:var(--rtp-guinda); position:relative; flex-shrink:0; }
.progress-circle::before { content:''; position:absolute; inset:0; border-radius:50%; padding:5px; background:conic-gradient(var(--rtp-verde) var(--pct), #e2e8f0 0); -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite:xor; mask-composite:exclude; }
.project-header-info { flex:1; min-width:240px; }
.project-header-info h1 { color:var(--rtp-guinda); font-size:18pt; margin-bottom:8px; }
.project-header-meta { display:flex; gap:20px; flex-wrap:wrap; font-size:10.5pt; color:var(--rtp-gris); margin-top:12px; }
.project-header-meta span b { color:var(--rtp-negro); }

.fecha-editable { position:relative; display:inline-block; padding:2px 6px; border-radius:6px; cursor:pointer; transition:all .2s ease; border:1px dashed transparent; }
.fecha-editable:hover { background:#ecf9d9; border-color:var(--rtp-verde); }
.fecha-default { display:inline-block; }
.fecha-hover { display:none; color:var(--rtp-verde-dark); font-weight:600; font-size:9.5pt; }
.fecha-editable:hover .fecha-default { display:none; }
.fecha-editable:hover .fecha-hover { display:inline-block; }

.fecha-editable-actividad { position:relative; display:inline-block; padding:2px 8px; border-radius:6px; cursor:pointer; transition:all .2s ease; border:1px dashed transparent; }
.fecha-editable-actividad:hover { background:#ecf9d9; border-color:var(--rtp-verde); transform:translateY(-1px); box-shadow:0 4px 10px rgba(120,190,32,.2); }
.fecha-editable-actividad .fecha-default { display:inline-block; }
.fecha-editable-actividad .fecha-hover { display:none; color:var(--rtp-verde-dark); font-weight:600; font-size:9.5pt; }
.fecha-editable-actividad:hover .fecha-default { display:none; }
.fecha-editable-actividad:hover .fecha-hover { display:inline-flex; align-items:center; gap:4px; }

.estado-editable-actividad { position:relative; display:inline-block; padding:2px 8px; border-radius:6px; cursor:pointer; transition:all .2s ease; border:1px dashed transparent; user-select:none; }
.estado-editable-actividad:hover { background:#ecf9d9; border-color:var(--rtp-verde); transform:translateY(-1px); box-shadow:0 4px 10px rgba(120,190,32,.2); }
.estado-editable-actividad .estado-default { display:inline-flex; align-items:center; gap:4px; }
.estado-editable-actividad .estado-hover { display:none; color:var(--rtp-verde-dark); font-weight:600; font-size:9.5pt; }
.estado-editable-actividad:hover .estado-default { display:none; }
.estado-editable-actividad:hover .estado-hover { display:inline-flex; align-items:center; gap:4px; }

.estado-menu { position:absolute; top:100%; left:0; margin-top:6px; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15); padding:6px; min-width:180px; z-index:600; display:none; border-top:3px solid var(--rtp-verde); }
.estado-menu.open { display:block; animation:slideDownPrio .15s ease; }
.estado-opcion { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:6px; cursor:pointer; font-size:10.5pt; font-weight:600; color:var(--rtp-gris); transition:all .15s; }
.estado-opcion .opcion-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.estado-opcion .check { margin-left:auto; opacity:0; font-weight:700; }
.estado-opcion.activa .check { opacity:1; }
.estado-opcion:hover { background:#ecf9d9; color:var(--rtp-verde-dark); }
.estado-opcion[data-estado="por_hacer"]:hover { background:#f1f5f9; color:#475569; }
.estado-opcion[data-estado="en_curso"]:hover { background:#eef6fd; color:#266cb4; }
.estado-opcion[data-estado="en_revision"]:hover { background:#fef9e7; color:#8a6d00; }
.estado-opcion[data-estado="completada"]:hover { background:#e6f4ea; color:#027a35; }

/* ============ SELECTOR DE ETAPA DEL PROYECTO ============ */
.etapa-selector-wrap { position:relative; display:inline-block; }
.etapa-selector {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 20px; border-radius:999px;
    font-weight:700; font-size:13pt; color:#fff;
    box-shadow:0 4px 12px rgba(0,0,0,.18);
    text-transform:uppercase; letter-spacing:.5px;
    white-space:nowrap; cursor:pointer;
    transition:all .2s ease; border:none; font-family:inherit;
}
.etapa-selector .dot-badge { width:11px; height:11px; border-radius:50%; background:#fff; opacity:.85; flex-shrink:0; }
.etapa-selector .etapa-hover-hint { display:none; font-size:9pt; opacity:.9; margin-left:4px; }
.etapa-selector.editable:hover {
    background:#78BE20 !important;
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(120,190,32,.45);
}
.etapa-selector.editable:hover .etapa-hover-hint { display:inline; }

.etapa-menu {
    position:absolute; top:calc(100% + 8px); right:0;
    background:#fff; border-radius:12px;
    box-shadow:0 12px 34px rgba(0,0,0,.18);
    padding:6px; min-width:200px; z-index:650;
    display:none; border-top:3px solid var(--rtp-verde);
    animation:slideDownPrio .15s ease;
}
.etapa-menu.open { display:block; }
.etapa-opcion {
    display:flex; align-items:center; gap:10px;
    padding:11px 14px; border-radius:8px; cursor:pointer;
    font-size:10.5pt; font-weight:600; color:var(--rtp-gris);
    transition:all .15s ease; user-select:none;
}
.etapa-opcion .opcion-dot { width:11px; height:11px; border-radius:50%; flex-shrink:0; }
.etapa-opcion .check { margin-left:auto; opacity:0; font-weight:700; }
.etapa-opcion.activa .check { opacity:1; }
.etapa-opcion[data-etapa="sin_iniciar"]:hover { background:#f1f5f9; color:#475569; }
.etapa-opcion[data-etapa="en_curso"]:hover    { background:#eef6fd; color:#266cb4; }
.etapa-opcion[data-etapa="en_revision"]:hover { background:#fef9e7; color:#8a6d00; }
.etapa-opcion[data-etapa="finalizado"]:hover  { background:#e6f4ea; color:#027a35; }

/* ============ TOAST GLOBAL ============ */
.toast-overlay { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; z-index:9999; pointer-events:none; }
.toast-box { background:#fff; border-radius:12px; padding:18px 26px; box-shadow:0 15px 40px rgba(0,0,0,0.2); display:flex; align-items:center; gap:14px; font-size:11pt; color:var(--rtp-gris); border-left:6px solid var(--rtp-verde); min-width:300px; max-width:520px; opacity:0; transform:scale(0.8) translateY(20px); transition:all 0.3s cubic-bezier(0.68,-0.55,0.265,1.55); pointer-events:auto; }
.toast-box.show { opacity:1; transform:scale(1) translateY(0); }
.toast-icon { font-size:22pt; flex-shrink:0; }
.toast-text b { color:var(--rtp-guinda); font-weight:700; }

.project-header-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; flex-shrink:0; }
.project-header-actions .btn-outline { background:var(--rtp-guinda) !important; color:#fff !important; border:2px solid #7a1a33 !important; transition:all .2s ease; }
.project-header-actions .btn-outline:hover { background:var(--rtp-verde) !important; border-color:var(--rtp-verde) !important; color:#fff !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }
.project-header-actions .btn-historial { background:var(--rtp-guinda) !important; color:#fff !important; border:none !important; }
.project-header-actions .btn-historial:hover { background:#7a1a33 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }
.project-header-actions .btn-invitar { background:var(--rtp-guinda) !important; color:#fff !important; border:none !important; }
.project-header-actions .btn-invitar:hover { background:#7a1a33 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }

.badge-etapa { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:999px; font-weight:700; font-size:13pt; color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.18); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
.badge-etapa .dot-badge { width:11px; height:11px; border-radius:50%; background:#fff; opacity:.85; flex-shrink:0; }
.project-header-status { display:flex; flex-direction:column; align-items:flex-end; gap:16px; flex-shrink:0; }

/* ===== Banner bloqueado (rojo) con botón integrado ===== */
.banner-bloqueado {
    background:linear-gradient(135deg,#fde8ec 0%,#fbd5de 100%);
    border-radius:12px;
    padding:16px 22px;
    margin-bottom:20px;
    border-left:5px solid #E5074C;
    display:flex;
    align-items:center;
    gap:16px;
    box-shadow:0 2px 8px rgba(229,7,76,.15);
}
.banner-bloqueado .icono { font-size:28pt; flex-shrink:0; }
.banner-bloqueado .texto { flex:1; color:#991b1b; }
.banner-bloqueado .texto b { display:block; color:#E5074C; font-size:13pt; margin-bottom:4px; }
.banner-bloqueado .texto small { font-size:10pt; color:#991b1b; opacity:.85; }
.banner-bloqueado .banner-action {
    flex-shrink:0;
    background:#FF0033;
    color:#fff;
    border:none;
    padding:12px 20px;
    border-radius:8px;
    font-family:inherit;
    font-weight:700;
    font-size:10.5pt;
    cursor:pointer;
    transition:all .2s ease;
    box-shadow:0 4px 12px rgba(255,0,51,.35);
    white-space:nowrap;
}
.banner-bloqueado .banner-action:hover {
    background:#e0002d;
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(255,0,51,.5), 0 2px 6px rgba(255,0,51,.3);
    filter:brightness(1.05);
}

/* ===== Banner programado (naranja) ===== */
.banner-programado { background:linear-gradient(135deg,#fff7ed 0%,#ffedd5 100%); border-radius:12px; padding:18px 24px; margin-bottom:20px; border-left:5px solid #F08217; display:flex; align-items:center; gap:18px; box-shadow:0 2px 8px rgba(240,130,23,.15); }
.banner-programado .icono { font-size:32pt; flex-shrink:0; }
.banner-programado .texto { flex:1; color:#78350f; }
.banner-programado .texto b { display:block; color:#F08217; font-size:14pt; margin-bottom:6px; }
.banner-programado .texto small { font-size:11pt; color:#78350f; opacity:.9; line-height:1.6; display:block; }
.banner-programado .texto small strong { color:#9F2241; font-weight:700; }

/* ===== Banner archivado (AMARILLO) con botón integrado ===== */
.banner-archivado {
    background: linear-gradient(135deg, #fffbe6 0%, #fff3bf 100%);
    border-radius: 12px;
    padding: 16px 22px;
    margin-bottom: 20px;
    border-left: 5px solid #FDC60A;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 8px rgba(253, 198, 10, .25);
}
.banner-archivado .icono { font-size: 28pt; flex-shrink: 0; }
.banner-archivado .texto { flex: 1; color: #5c4500; }
.banner-archivado .texto b { display: block; color: #b58900; font-size: 13pt; margin-bottom: 4px; }
.banner-archivado .texto small { font-size: 10pt; color: #5c4500; opacity: .85; }
.banner-archivado .banner-action {
    flex-shrink: 0;
    background: #F08217;
    color: #fff;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-family: inherit;
    font-weight: 700;
    font-size: 10.5pt;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 4px 12px rgba(240, 130, 23, .4);
    white-space: nowrap;
}
.banner-archivado .banner-action:hover {
    background: #d97412;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(240, 130, 23, .55), 0 2px 6px rgba(240, 130, 23, .3);
    filter: brightness(1.05);
}

@media (max-width: 700px) {
    .banner-bloqueado,
    .banner-archivado,
    .banner-programado { flex-wrap: wrap; }
    .banner-bloqueado .banner-action,
    .banner-archivado .banner-action { width: 100%; text-align: center; }
}

.total-bar { background:#fff; border-radius:12px; padding:16px 22px; box-shadow:0 2px 8px rgba(0,0,0,.05); border-left:5px solid <?= $total_porcentaje >= 100 ? '#027a35' : ($total_porcentaje >= 50 ? '#F08217' : '#94a3b8') ?>; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
.total-bar-info { display:flex; flex-direction:column; gap:4px; }
.total-bar-title { font-size:11pt; font-weight:700; color:var(--rtp-guinda); }
.total-bar-sub { font-size:10pt; color:var(--rtp-gris); }
.total-bar-btn { background:var(--rtp-guinda); color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; transition:all .2s ease; font-family:inherit; }
.total-bar-btn:hover { background:#7a1a33; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }

.info-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
.info-bar .spacer { flex:1; }
.info-bar .btn { transition:all .2s ease; }

.btn-nueva-actividad { background:#78BE20 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-nueva-actividad:hover { background:#5fa015 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.4),0 2px 6px rgba(120,190,32,.25); filter:brightness(1.05); }
.btn-archivar { background:#FDC60A !important; color:#3a2f00 !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-archivar:hover { background:#e5b100 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(253,198,10,.45),0 2px 6px rgba(253,198,10,.3); filter:brightness(1.05); }
.btn-eliminar { background:#F08217 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-eliminar:hover { background:#d97412 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(240,130,23,.45),0 2px 6px rgba(240,130,23,.3); filter:brightness(1.05); }
.btn-bloquear { background:#FF0033 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-bloquear:hover { background:#e0002d !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(255,0,51,.5),0 2px 6px rgba(255,0,51,.3); filter:brightness(1.05); }

.projects-search { margin-bottom:20px; }
.projects-search input { width:100%; max-width:400px; padding:12px 16px; border:1px solid var(--border); border-radius:10px; font-size:12pt; font-family:inherit; color:var(--rtp-gris); background:#fff; }
.projects-search input:focus { outline:none; border-color:var(--rtp-verde); box-shadow:0 0 0 3px rgba(120,190,32,.15); }

@keyframes destelloBusqueda { 0%{box-shadow:0 0 0 0 rgba(120,190,32,.8);transform:scale(1);} 50%{box-shadow:0 0 0 20px rgba(120,190,32,0),0 0 20px 5px rgba(120,190,32,.5);transform:scale(1.03);} 100%{box-shadow:0 0 0 0 rgba(120,190,32,0),0 0 0 0 rgba(120,190,32,0);transform:scale(1);} }
.card-destello { animation:destelloBusqueda 1s cubic-bezier(.22,1,.36,1) 3; border-color:var(--rtp-verde) !important; z-index:10; }

.kanban-board { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:24px; }
.kanban-col { background:#f8fafc; border-radius:12px; padding:14px; min-height:300px; }
.kanban-col-header { display:flex; align-items:center; gap:8px; padding-bottom:12px; border-bottom:2px solid #e2e8f0; margin-bottom:12px; }
.kanban-col-header .dot { width:10px; height:10px; border-radius:50%; }
.kanban-col-header .title { font-weight:700; font-size:11pt; color:var(--rtp-guinda); flex:1; }
.kanban-col-header .count { background:#e2e8f0; color:#475569; padding:2px 10px; border-radius:999px; font-size:9.5pt; font-weight:700; }

.task-card { background:#fff; border-radius:10px; border:1px solid #e2e8f0; border-left:4px solid var(--rtp-verde); margin-bottom:10px; transition:all .15s; cursor:pointer; position:relative; overflow:hidden; display:flex; flex-direction:column; }
.task-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.2); border-color:var(--rtp-verde); }
.task-card.blocked { border-left-color:#E5074C; background:#fde8ec; border-color:#fbd5de; }
.task-card.blocked:hover { box-shadow:0 6px 16px rgba(229,7,76,.25); border-color:#E5074C; }

/* ===== Banner dentro de la tarjeta de actividad bloqueada ===== */
.task-card-blocked-banner {
    background: linear-gradient(135deg, #fde8ec 0%, #fbd5de 100%);
    border-bottom: 3px solid #E5074C;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.task-card-blocked-banner .blocked-banner-icon { font-size: 18pt; flex-shrink: 0; }
.task-card-blocked-banner .blocked-banner-text { flex: 1; color: #991b1b; }
.task-card-blocked-banner .blocked-banner-text b { display: block; color: #E5074C; font-size: 10pt; margin-bottom: 2px; font-weight: 700; }
.task-card-blocked-banner .blocked-banner-text small { font-size: 9pt; color: #991b1b; opacity: .9; line-height: 1.4; display: block; }
.task-card-blocked-banner .blocked-banner-text small strong { color: #E5074C; font-weight: 700; }

.task-card-body { padding:12px 14px 10px 14px; position:relative; }
.task-card-title { font-weight:600; color:var(--rtp-negro); font-size:10.5pt; line-height:1.3; padding-right:45px; margin-bottom:4px; }
.blocked .task-card-title { color:#991b1b; }
.bloqueo-badge { display:inline-block; margin-right:4px; font-size:11pt; }
.task-card-date { font-size:9pt; color:var(--rtp-gris); }
.blocked-date { color:#991b1b; font-weight: 600; }
.blocked-date-extra { display:block; font-size:8.5pt; color:#b91c1c; opacity: .85; margin-top: 2px; }
.task-card-pct-ring { position:absolute; top:8px; right:8px; width:38px; height:38px; display:flex; align-items:center; justify-content:center; cursor:help; }
.task-card-pct-ring svg { position:absolute; top:2px; left:2px; transform:rotate(-90deg); }
.task-card-pct-text { position:relative; font-size:7.5pt; font-weight:700; color:var(--rtp-verde-dark); z-index:1; }
.task-card-footer { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 14px 10px 14px; border-top:1px dashed #e2e8f0; background:#fafbfc; }
.blocked .task-card-footer { border-top-color: #fbd5de; background: #fff0f4; }
.task-card-priority { display:flex; align-items:center; gap:6px; font-size:9pt; white-space:nowrap; flex-shrink:0; }
.priority-dot { width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0; }
.task-card-responsables { display:flex; align-items:center; overflow-x:auto; overflow-y:hidden; padding:2px 2px; max-width:55%; scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent; }
.task-card-responsables::-webkit-scrollbar { height:4px; }
.task-card-responsables::-webkit-scrollbar-track { background:transparent; }
.task-card-responsables::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
.task-card-avatar { width:28px; height:28px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:8pt; border:2px solid #fff; box-shadow:0 2px 4px rgba(0,0,0,.12); flex-shrink:0; margin-left:-8px; transition:transform .15s,z-index .15s; }
.task-card-avatar:first-child { margin-left:0; }
.task-card-avatar:hover { transform:scale(1.15); z-index:10; position:relative; }
.task-card.hidden { display:none; }

.members-card { background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-top:20px; }
.members-list { display:flex; flex-direction:column; gap:10px; margin-top:12px; }
.member-item { display:flex; align-items:center; gap:12px; padding:10px 14px; background:#f8fafc; border-radius:8px; border-left:4px solid var(--rtp-verde); }
.member-avatar { width:36px; height:36px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10pt; border:2px solid #fff; }
.member-info { flex:1; }
.member-name { font-weight:700; color:var(--rtp-negro); font-size:11pt; }
.member-email { font-size:9.5pt; color:var(--rtp-gris); }
.member-rol { background:var(--rtp-guinda); color:#fff; padding:4px 12px; border-radius:999px; font-size:9pt; font-weight:700; text-transform:capitalize; }
.member-rol.administrador { background:var(--rtp-guinda); }
.member-rol.responsable { background:var(--rtp-verde); }
.member-rol.visualizador { background:var(--estado-azul); }

.empty-msg { text-align:center; color:var(--rtp-gris-light); padding:20px; font-style:italic; font-size:10pt; }
.info-roles { background:#f8fafc; border-radius:10px; padding:14px 18px; margin-bottom:16px; font-size:10pt; color:var(--rtp-gris); line-height:1.6; border-left:4px solid var(--rtp-dorado); }
.info-roles b { color:var(--rtp-guinda); }
.link-generado { background:#f8fafc; border:1px dashed var(--rtp-verde); border-radius:10px; padding:14px 16px; margin-top:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.link-generado input { flex:1; min-width:200px; padding:8px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:9.5pt; font-family:monospace; background:#fff; color:var(--rtp-gris); }
.btn-copiar { padding:8px 14px; background:var(--rtp-verde); color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:10pt; transition:all .15s; }
.btn-copiar:hover { background:var(--rtp-verde-dark); box-shadow:0 4px 12px rgba(120,190,32,.3); }
.invite-list { margin-top:16px; display:flex; flex-direction:column; gap:8px; }
.invite-item { display:flex; align-items:center; gap:12px; padding:10px 14px; background:#ecf9d9; border-radius:8px; border-left:4px solid var(--rtp-verde); font-size:10.5pt; }
.invite-item-rol { background:var(--rtp-verde); color:#fff; padding:2px 10px; border-radius:999px; font-size:9pt; font-weight:700; text-transform:capitalize; }
.send-status { padding:12px 16px; border-radius:8px; font-size:10.5pt; margin-top:12px; display:none; }
.send-status.ok { display:block; background:#ecf9d9; color:var(--estado-verde); border-left:4px solid var(--estado-verde); }
.send-status.error { display:block; background:#fde8ec; color:#991b1b; border-left:4px solid var(--estado-rojo); }

.pct-row { display:grid; grid-template-columns:1fr 100px; gap:12px; align-items:center; padding:10px 14px; background:#f8fafc; border-radius:8px; margin-bottom:8px; border-left:4px solid var(--rtp-verde); }
.pct-row-title { font-weight:600; color:var(--rtp-negro); font-size:10.5pt; line-height:1.3; }
.pct-row input { padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:11pt; text-align:center; font-family:inherit; width:100%; font-weight:700; color:var(--rtp-guinda); }
.pct-row input:focus { outline:none; border-color:var(--rtp-verde); box-shadow:0 0 0 3px rgba(120,190,32,.15); }
.pct-summary { background:#f8fafc; border-radius:8px; padding:14px 18px; margin:16px 0; display:flex; justify-content:space-between; align-items:center; font-size:11pt; font-weight:600; border-left:4px solid var(--rtp-verde); }
.pct-summary.ok { border-left-color:#027a35; color:#027a35; }
.pct-summary.warn { border-left-color:#F08217; color:#F08217; }
.pct-summary.error { border-left-color:#E5074C; color:#E5074C; }

.porcentaje-box { background:#f8fafc; border-radius:10px; padding:14px; border-left:4px solid var(--rtp-verde); margin-bottom:8px; }
.porcentaje-input-row { display:flex; align-items:center; gap:10px; }
.porcentaje-input-row input { width:100px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:14pt; font-weight:700; text-align:center; color:var(--rtp-guinda); font-family:inherit; margin:0; }
.porcentaje-input-row input:focus { outline:none; border-color:var(--rtp-verde); box-shadow:0 0 0 3px rgba(120,190,32,.15); }
.porcentaje-input-row .pct-symbol { font-size:14pt; font-weight:700; color:var(--rtp-guinda); }
.porcentaje-info { flex:1; font-size:9.5pt; color:var(--rtp-gris); line-height:1.4; }
.porcentaje-info b { color:var(--rtp-verde-dark); }
.porcentaje-bar { margin-top:10px; height:6px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
.porcentaje-bar-fill { height:100%; background:var(--rtp-verde); width:0%; transition:width .3s; }

.modal .btn-outline { background:#fff; color:var(--rtp-gris); border:2px solid #e2e8f0; }
.modal .btn-outline:hover { border-color:var(--rtp-verde); color:var(--rtp-verde-dark); background:#f8fef0; }

.modal-actividad { position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; z-index:2000; padding:20px; }
.modal-actividad.hidden { display:none; }
.actividad-content { background:#fff; border-radius:14px; width:100%; max-width:850px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden; border-left:6px solid var(--rtp-verde); box-shadow:0 20px 60px rgba(0,0,0,.3); }
.actividad-bloqueo-aviso { background:#fde8ec; border-bottom:3px solid #E5074C; padding:16px 26px; display:flex; align-items:center; gap:16px; }
.bloqueo-icon { font-size:28pt; flex-shrink:0; }
.bloqueo-text { color:#991b1b; font-size:10.5pt; line-height:1.5; }
.bloqueo-text b { color:#E5074C; font-size:13pt; display:block; margin-bottom:4px; }

.actividad-header { display:flex; align-items:flex-start; gap:20px; padding:22px 26px; background:#fafbfc; border-bottom:1px solid #e2e8f0; position:relative; }
.actividad-pct-circle { width:80px; height:80px; position:relative; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.actividad-pct-circle svg { position:absolute; top:0; left:0; width:80px; height:80px; transform:rotate(-90deg); }
.actividad-pct-circle .pct-value { position:relative; font-size:15pt; font-weight:700; color:var(--rtp-verde-dark); z-index:1; }
.actividad-pct-circle .pct-label { position:absolute; bottom:-2px; left:50%; transform:translateX(-50%); font-size:8pt; color:var(--rtp-gris); background:#fff; padding:1px 6px; border-radius:999px; white-space:nowrap; }
.actividad-header-info { flex:1; min-width:0; }
.actividad-header-title { font-size:15pt; font-weight:700; color:var(--rtp-guinda); margin-bottom:8px; line-height:1.3; }
.actividad-header-meta { display:flex; flex-wrap:wrap; gap:12px; font-size:10pt; color:var(--rtp-gris); margin-bottom:8px; align-items:center; }
.actividad-header-meta > span { display:inline-flex; align-items:center; gap:4px; }
.actividad-header-responsables { display:flex; flex-wrap:wrap; gap:8px; font-size:9.5pt; color:var(--rtp-gris); align-items:center; }
.resp-avatar-mini { width:24px; height:24px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:8pt; border:2px solid #fff; margin-right:-6px; }
.resp-name { margin-right:12px; }
.sin-asignar-wrapper { display:inline-flex; align-items:center; }
.sin-asignar-text { color:#94a3b8; font-style:italic; padding:4px 10px; border-radius:8px; border:2px dashed transparent; cursor:default; transition:all .2s ease; display:inline-flex; align-items:center; gap:6px; }
.sin-asignar-wrapper:hover .sin-asignar-text { color:var(--rtp-verde-dark); background:#ecf9d9; border-color:var(--rtp-verde); cursor:pointer; transform:translateY(-1px); box-shadow:0 4px 10px rgba(120,190,32,.2); }
.sin-asignar-btn-label { display:none; }
.sin-asignar-wrapper:hover .sin-asignar-default { display:none; }
.sin-asignar-wrapper:hover .sin-asignar-btn-label { display:inline-flex; align-items:center; gap:4px; font-weight:700; font-style:normal; }
.actividad-close { position:absolute; top:12px; right:16px; font-size:24pt; line-height:1; cursor:pointer; color:var(--rtp-gris); background:none; border:none; font-weight:bold; padding:0; width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:50%; transition:all .15s; }
.actividad-close:hover { background:#fee2e2; color:var(--estado-rojo); }

.actividad-body { flex:1; overflow-y:auto; padding:22px 26px; }
.actividad-section { margin-bottom:24px; }
.actividad-section-title { font-size:10pt; font-weight:700; color:var(--rtp-guinda); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.actividad-descripcion { color:var(--rtp-gris); font-size:10.5pt; line-height:1.6; background:#f8fafc; padding:14px 16px; border-radius:10px; border-left:4px solid var(--rtp-verde); }

.adjuntos-list { display:flex; flex-wrap:wrap; gap:10px; }
.adjunto-item { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:#e0f2fe; color:#075985; border-radius:8px; text-decoration:none; font-size:10pt; transition:all .15s; border-left:3px solid #0ea5e9; }
.adjunto-item:hover { background:#bae6fd; transform:translateY(-1px); box-shadow:0 4px 10px rgba(14,165,233,.2); }
.adjunto-item.imagen { background:#ecf9d9; color:#3f6212; border-left-color:#78BE20; }
.adjunto-item.imagen:hover { background:#d9f99d; }
.adjunto-item.enlace { background:#fef3c7; color:#78350f; border-left-color:#F08217; }
.adjunto-item.enlace:hover { background:#fde68a; }
.adjunto-item .adjunto-icon { font-size:14pt; }
.adjunto-item .adjunto-name { max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.comentarios-list { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.comentario-item { background:#f8fafc; border-radius:10px; padding:12px 16px; border-left:4px solid var(--rtp-verde); }
.comentario-meta { font-size:9pt; color:var(--rtp-gris); margin-bottom:6px; display:flex; align-items:center; gap:8px; }
.comentario-meta b { color:var(--rtp-guinda); font-size:10pt; }
.comentario-texto { font-size:10.5pt; color:var(--rtp-negro); line-height:1.5; }

.comentario-box { display:flex; align-items:flex-end; gap:10px; padding-top:14px; border-top:1px solid #e2e8f0; position:relative; }
.comentario-box textarea { flex:1; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; font-size:10.5pt; font-family:inherit; resize:vertical; min-height:60px; max-height:150px; color:var(--rtp-gris); }
.comentario-box textarea:focus { outline:none; border-color:var(--rtp-verde); box-shadow:0 0 0 3px rgba(120,190,32,.12); }
.comentario-actions { display:flex; gap:8px; align-items:center; flex-shrink:0; }

.btn-cargar-wrapper { position:relative; }
.btn-cargar { background:#fff; color:var(--rtp-gris); border:2px solid #e2e8f0; padding:10px 16px; border-radius:10px; cursor:pointer; font-size:10.5pt; font-weight:600; font-family:inherit; display:flex; align-items:center; gap:6px; transition:all .15s; height:44px; }
.btn-cargar:hover { border-color:var(--rtp-verde); color:var(--rtp-verde-dark); background:#f8fef0; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.2); }
.btn-cargar.open { background:var(--rtp-verde); color:#fff; border-color:var(--rtp-verde); }
.cargar-menu { position:absolute; bottom:100%; right:0; margin-bottom:8px; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15); padding:6px; display:none; min-width:200px; z-index:100; border-top:3px solid var(--rtp-verde); }
.cargar-menu.open { display:block; animation:slideUp .15s ease; }
@keyframes slideUp { from{opacity:0;transform:translateY(8px);} to{opacity:1;transform:translateY(0);} }
.cargar-menu button { display:flex; align-items:center; gap:10px; width:100%; padding:10px 14px; background:none; border:none; cursor:pointer; font-size:10.5pt; font-family:inherit; color:var(--rtp-gris); text-align:left; border-radius:6px; transition:all .15s; }
.cargar-menu button:hover { background:#ecf9d9; color:var(--rtp-verde-dark); }
.cargar-menu button .menu-icon { font-size:14pt; }
.btn-cargar-disabled { background:#f1f5f9; color:#94a3b8; border:2px solid #e2e8f0; padding:10px 16px; border-radius:10px; font-size:10.5pt; font-weight:600; height:44px; display:flex; align-items:center; gap:6px; cursor:not-allowed; }
.btn-enviar { background:var(--rtp-verde); color:#fff; border:none; padding:10px 20px; border-radius:10px; cursor:pointer; font-size:10.5pt; font-weight:700; font-family:inherit; height:44px; transition:all .15s; }
.btn-enviar:hover { background:var(--rtp-verde-dark); transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35); }
.btn-enviar:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.comentario-aviso-bloqueo { background:#fef3c7; color:#78350f; padding:10px 14px; border-radius:8px; font-size:9.5pt; margin-top:10px; border-left:4px solid #F08217; line-height:1.5; }

.actividad-footer { padding:16px 26px; border-top:1px solid #e2e8f0; background:#fafbfc; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.actividad-footer-left { display:flex; gap:8px; flex-wrap:wrap; }
.actividad-footer-right { display:flex; gap:8px; flex-wrap:wrap; }
.btn-modal { padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; border:none; transition:all .15s; }
.btn-modal.primary { background:var(--rtp-verde); color:#fff; }
.btn-modal.primary:hover { background:var(--rtp-verde-dark); }
.btn-modal.warning { background:#F08217; color:#fff; }
.btn-modal.warning:hover { background:#d97412; }
.btn-modal.naranja { background:#F08217; color:#fff; }
.btn-modal.naranja:hover { background:#d97412; transform:translateY(-1px); box-shadow:0 4px 10px rgba(240,130,23,.3); }
.btn-modal.danger { background:var(--estado-rojo); color:#fff; }
.btn-modal.danger:hover { background:#b8063c; }
.btn-modal.secondary { background:#fff; color:var(--rtp-gris); border:2px solid #e2e8f0; }
.btn-modal.secondary:hover { border-color:var(--rtp-guinda); color:var(--rtp-guinda); }
.permiso-aviso { background:#fef3c7; color:#78350f; padding:12px 16px; border-radius:8px; font-size:10pt; border-left:4px solid #F08217; margin-top:12px; }

.prioridad-selector { position:relative; display:inline-block; padding:2px 8px; border-radius:6px; cursor:pointer; transition:all .2s ease; border:1px dashed transparent; user-select:none; }
.prioridad-selector:hover { background:#ecf9d9; border-color:var(--rtp-verde); transform:translateY(-1px); box-shadow:0 4px 10px rgba(120,190,32,.2); }
.prioridad-selector .prioridad-default { display:inline-flex; align-items:center; gap:4px; }
.prioridad-selector .prioridad-hover { display:none; color:var(--rtp-verde-dark); font-weight:600; font-size:9.5pt; }
.prioridad-selector:hover .prioridad-default { display:none; }
.prioridad-selector:hover .prioridad-hover { display:inline-flex; align-items:center; gap:4px; }
.prioridad-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:2px; }
.prioridad-label { font-weight:600; display:inline-flex; align-items:center; }

.prioridad-menu { position:absolute; top:100%; left:0; margin-top:6px; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15); padding:6px; min-width:180px; z-index:600; display:none; border-top:3px solid var(--rtp-verde); }
.prioridad-menu.open { display:block; animation:slideDownPrio .15s ease; }
@keyframes slideDownPrio { from{opacity:0;transform:translateY(-6px);} to{opacity:1;transform:translateY(0);} }

.prioridad-opcion { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:6px; cursor:pointer; font-size:10.5pt; font-weight:600; color:var(--rtp-gris); transition:all .15s; }
.prioridad-opcion .opcion-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.prioridad-opcion .check { margin-left:auto; opacity:0; font-weight:700; }
.prioridad-opcion.activa .check { opacity:1; }
.prioridad-opcion:hover { background:#ecf9d9; color:var(--rtp-verde-dark); }
.prioridad-opcion[data-prio="baja"]:hover { background:#e6f4ea; color:#027a35; }
.prioridad-opcion[data-prio="media"]:hover { background:#fef9e7; color:#8a6d00; }
.prioridad-opcion[data-prio="alta"]:hover { background:#fff3e0; color:#9a3412; }
.prioridad-opcion[data-prio="critica"]:hover { background:#fde8ec; color:#991b1b; }

#modalBloquear .modal-content { border-top:5px solid #E5074C; }
#modalDesbloquear .modal-content { border-top:5px solid #F08217; }
#modalBloquearProyecto .modal-content { border-top:5px solid #E5074C; }
#modalDesbloquearProyecto .modal-content { border-top:5px solid #F08217; }
#modalHistorial .modal-content { border-top:5px solid #266cb4; }
#modalMensaje .modal-content { border-top:5px solid var(--rtp-verde); }
#modalMensaje #mensajeIcon { animation:pop .3s ease; }
@keyframes pop { 0%{transform:scale(.5);opacity:0;} 100%{transform:scale(1);opacity:1;} }

.historial-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; border-bottom:2px solid #e2e8f0; padding-bottom:12px; }
.historial-tab { padding:8px 14px; background:#f1f5f9; color:var(--rtp-gris); border:none; border-radius:8px; font-size:10pt; font-weight:600; cursor:pointer; transition:all .15s; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
.historial-tab:hover { background:#e2e8f0; color:var(--rtp-negro); }
.historial-tab.activo { background:var(--rtp-guinda); color:#fff; }
.historial-tab .badge { background:rgba(255,255,255,.3); padding:1px 7px; border-radius:999px; font-size:8.5pt; font-weight:700; }
.historial-tab.activo .badge { background:rgba(255,255,255,.25); }
.historial-list { display:flex; flex-direction:column; gap:10px; max-height:55vh; overflow-y:auto; padding-right:4px; }
.historial-item { display:flex; gap:14px; padding:12px 16px; background:#f8fafc; border-radius:10px; border-left:4px solid #94a3b8; align-items:flex-start; }
.historial-item.creacion { border-left-color:#027a35; background:#f0faf1; }
.historial-item.eliminacion { border-left-color:#E5074C; background:#fde8ec; }
.historial-item.usuario { border-left-color:#266cb4; background:#eef6fd; }
.historial-item.bloqueo { border-left-color:#E5074C; background:#fff0f4; }
.historial-item.edicion { border-left-color:#F08217; background:#fff7ed; }
.historial-item.movimiento { border-left-color:#78BE20; background:#f4ffdf; }
.historial-icon { font-size:18pt; flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center; background:#fff; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.historial-content { flex:1; min-width:0; }
.historial-desc { font-size:10.5pt; color:var(--rtp-negro); font-weight:600; line-height:1.4; margin-bottom:4px; }
.historial-meta { font-size:9pt; color:var(--rtp-gris); display:flex; gap:12px; flex-wrap:wrap; }
.historial-meta b { color:var(--rtp-guinda); }
.historial-empty { text-align:center; color:#94a3b8; padding:40px 20px; font-style:italic; font-size:10.5pt; }
.historial-loading { text-align:center; color:#94a3b8; padding:40px 20px; font-size:10.5pt; }
</style>
</head>
<body>
<div class="app">
    <div class="toast-overlay" id="toastOverlay"></div>

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <?php if ($proyecto_archivado): ?>
            <div class="banner-archivado">
                <div class="icono">📦</div>
                <div class="texto">
                    <b>Proyecto archivado</b>
                    <small>
                        Este proyecto está archivado desde el
                        <strong><?= h(fmt_date($proyecto['archivado_en'] ?? '')) ?></strong>.
                        Está en modo solo lectura: no se pueden crear actividades, comentar ni subir archivos.
                        Puedes desarchivarlo para volver a trabajar en él.
                    </small>
                </div>
                <?php if ($user['rol'] === 'administrador' || $user['rol'] === 'responsable'): ?>
                    <button class="banner-action" onclick="desarchivarProyecto()">📂 Desarchivar proyecto</button>
                <?php endif; ?>
            </div>
        <?php elseif ($proyecto_bloqueado): ?>
            <div class="banner-bloqueado">
                <div class="icono">🔒</div>
                <div class="texto">
                    <b>Proyecto bloqueado</b>
                    <small>
                        Motivo: <strong><?= h($proyecto['motivo_bloqueo'] ?? 'Sin especificar') ?></strong>
                        · Bloqueado el <?= h(fmt_date($proyecto['bloqueado_en'])) ?>
                    </small>
                </div>
                <?php if ($user['rol'] === 'administrador'): ?>
                    <button class="banner-action" onclick="abrirModalDesbloquearProyecto()">🔓 Desbloquear proyecto</button>
                <?php endif; ?>
            </div>
        <?php elseif ($proyecto_programado): ?>
            <div class="banner-programado">
                <div class="icono">⏳</div>
                <div class="texto">
                    <b>Proyecto programado</b>
                    <small>
                        Este proyecto se abrirá automáticamente el
                        <strong><?= h(date('d/m/Y', $fecha_apertura_ts)) ?></strong>
                        a las <strong><?= h(date('H:i', $fecha_apertura_ts)) ?></strong>.
                        Hasta entonces no se pueden crear actividades, comentar ni subir archivos.
                    </small>
                </div>
            </div>
        <?php endif; ?>

        <div class="project-header">
            <div class="project-progress-mini">
                <div class="progress-circle" style="--pct: <?= (float)$proyecto['porcentaje_avance'] ?>%;">
                    <?= (float)$proyecto['porcentaje_avance'] ?>%
                </div>
            </div>

            <div class="project-header-info" style="flex:1;">
                <h1><?= h($proyecto['nombre']) ?></h1>
                <?php if ($proyecto['descripcion']): ?>
                    <p style="color:var(--rtp-gris); font-size:10.5pt;"><?= h($proyecto['descripcion']) ?></p>
                <?php endif; ?>

                <div class="project-header-meta">
                    <span>📅 Inicio:
                        <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                            <span class="fecha-editable" onclick="abrirModalFecha('fecha_inicio', '<?= h($proyecto['fecha_inicio'] ?? '') ?>')">
                                <span class="fecha-default"><b><?= h(fmt_date($proyecto['fecha_inicio'])) ?></b></span>
                                <span class="fecha-hover">✏️ Modificar fecha</span>
                            </span>
                        <?php else: ?>
                            <b><?= h(fmt_date($proyecto['fecha_inicio'])) ?></b>
                        <?php endif; ?>
                    </span>
                    <span>🏁 Fin:
                        <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                            <span class="fecha-editable" onclick="abrirModalFecha('fecha_fin', '<?= h($proyecto['fecha_fin'] ?? '') ?>')">
                                <span class="fecha-default"><b><?= h(fmt_date($proyecto['fecha_fin'])) ?></b></span>
                                <span class="fecha-hover">✏️ Modificar fecha</span>
                            </span>
                        <?php else: ?>
                            <b><?= h(fmt_date($proyecto['fecha_fin'])) ?></b>
                        <?php endif; ?>
                    </span>
                    <span>👤 Responsable: <b><?= h($proyecto['responsable_nombre'] ?? 'Sin asignar') ?></b></span>
                </div>
            </div>

            <div class="project-header-status">
                <div class="etapa-selector-wrap">
                    <?php if ($puede_cambiar_etapa): ?>
                        <button class="etapa-selector editable"
                                style="background: <?= $etapa_color ?>;"
                                onclick="toggleEtapaMenu(event)"
                                title="Cambiar estado del proyecto">
                            <span class="dot-badge"></span>
                            <span id="etapaLabelActual"><?= $etapa_label ?></span>
                            <span class="etapa-hover-hint">✏️ Cambiar</span>
                        </button>
                        <div class="etapa-menu" id="etapaMenu">
                            <?php
                            $etapas_menu = [
                                ['codigo' => 'sin_iniciar', 'label' => 'Sin iniciar', 'color' => '#94a3b8', 'icon' => '⏸️'],
                                ['codigo' => 'en_curso',    'label' => 'En curso',    'color' => '#266cb4', 'icon' => '🔄'],
                                ['codigo' => 'en_revision', 'label' => 'En revisión', 'color' => '#FDC60A', 'icon' => '⏳'],
                                ['codigo' => 'finalizado',  'label' => 'Finalizado',  'color' => '#027a35', 'icon' => '✅'],
                            ];
                            foreach ($etapas_menu as $e):
                                $activa = ($etapa_codigo === $e['codigo']);
                            ?>
                                <div class="etapa-opcion <?= $activa ? 'activa' : '' ?>"
                                     data-etapa="<?= $e['codigo'] ?>"
                                     onclick="cambiarEtapaProyecto('<?= $e['codigo'] ?>', '<?= $e['label'] ?>', event)">
                                    <span class="opcion-dot" style="background:<?= $e['color'] ?>;"></span>
                                    <span><?= $e['icon'] ?> <?= $e['label'] ?></span>
                                    <span class="check">✓</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="badge-etapa" style="background: <?= $etapa_color ?>;">
                            <span class="dot-badge"></span>
                            <?= $etapa_label ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="project-header-actions">
                    <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                        <button class="btn btn-invitar" onclick="abrirModalInvitar()">✉️ Invitar usuarios</button>
                    <?php endif; ?>
                    <button class="btn btn-historial" onclick="abrirModalHistorial()">📜 Historial</button>
                    <a href="projects.php<?= $proyecto_archivado ? '?archivados=1' : '' ?>" class="btn btn-outline">← Volver</a>
                </div>
            </div>
        </div>

        <div class="total-bar">
            <div class="total-bar-info">
                <div class="total-bar-title">📊 Porcentaje asignado entre actividades: <?= number_format($total_porcentaje, 2) ?>%</div>
                <div class="total-bar-sub">
                    <?php if ($total_porcentaje < 100): ?>
                        Faltan <b><?= number_format(100 - $total_porcentaje, 2) ?>%</b> por asignar entre las actividades.
                    <?php elseif ($total_porcentaje == 100): ?>
                        ✅ El 100% del proyecto está distribuido entre las actividades.
                    <?php else: ?>
                        ⚠️ El porcentaje excede el 100%. Debes ajustar los valores.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                <button class="total-bar-btn" onclick="abrirModalPorcentajes()">⚙️ Ajustar porcentajes</button>
            <?php endif; ?>
        </div>

        <div class="info-bar">
            <?php if ($user['rol'] !== 'visualizador' && $proyecto_operativo): ?>
                <button class="btn btn-nueva-actividad" onclick="abrirModalNuevaActividad()">+ Nueva actividad</button>
            <?php endif; ?>
            <?php if (($user['rol'] === 'administrador' || $user['rol'] === 'responsable') && $proyecto_operativo): ?>
                <button class="btn btn-archivar" onclick="archivarProyecto()">📦 Archivar proyecto</button>
            <?php endif; ?>
            <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                <button class="btn btn-eliminar" onclick="eliminarProyecto()">🗑️ Eliminar proyecto</button>
            <?php endif; ?>
            <?php if ($user['rol'] === 'administrador' && $proyecto_operativo): ?>
                <button class="btn btn-bloquear" onclick="abrirModalBloquearProyecto()">🔒 Bloquear proyecto</button>
            <?php endif; ?>
            <div class="spacer"></div>
        </div>

        <h2 style="color:var(--rtp-guinda); margin-bottom:16px;">Tablero Kanban</h2>

        <div class="projects-search">
            <input type="text" id="buscadorActividades" placeholder="🔍 Buscar actividad..."
                   oninput="filtrarActividades()"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();buscarActividad();}">
        </div>

        <div class="kanban-board">
            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#94a3b8;"></span>
                    <span class="title">Por hacer</span>
                    <span class="count"><?= count($columnas['por_hacer']) ?></span>
                </div>
                <?php foreach ($columnas['por_hacer'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado); ?><?php endforeach; ?>
            </div>
            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#266cb4;"></span>
                    <span class="title">En curso</span>
                    <span class="count"><?= count($columnas['en_curso']) ?></span>
                </div>
                <?php foreach ($columnas['en_curso'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado); ?><?php endforeach; ?>
            </div>
            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#FDC60A;"></span>
                    <span class="title">En revisión</span>
                    <span class="count"><?= count($columnas['en_revision']) ?></span>
                </div>
                <?php foreach ($columnas['en_revision'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado); ?><?php endforeach; ?>
            </div>
            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#027a35;"></span>
                    <span class="title">Completada</span>
                    <span class="count"><?= count($columnas['completada']) ?></span>
                </div>
                <?php foreach ($columnas['completada'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado); ?><?php endforeach; ?>
            </div>
            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#E5074C;"></span>
                    <span class="title">Bloqueado</span>
                    <span class="count"><?= count($columnas['bloqueado']) ?></span>
                </div>
                <?php foreach ($columnas['bloqueado'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado); ?><?php endforeach; ?>
            </div>
        </div>

        <div class="members-card">
            <h2 style="color:var(--rtp-guinda); font-size:13pt; margin-bottom:4px;">👥 Miembros del proyecto</h2>
            <p style="color:var(--rtp-gris); font-size:10pt;"><?= count($miembros) ?> personas tienen acceso a este proyecto.</p>
            <div class="members-list">
                <?php if (empty($miembros)): ?>
                    <p class="empty-msg">No hay miembros asignados.</p>
                <?php else: ?>
                    <?php foreach ($miembros as $m): ?>
                        <div class="member-item">
                            <div class="member-avatar"><?= h(iniciales($m['nombre_completo'])) ?></div>
                            <div class="member-info">
                                <div class="member-name"><?= h($m['nombre_completo']) ?></div>
                                <div class="member-email"><?= h($m['correo']) ?></div>
                            </div>
                            <span class="member-rol <?= h($m['rol_miembro']) ?>"><?= h($m['rol_miembro']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<!-- ============ MODAL: NUEVA ACTIVIDAD ============ -->
<div id="modalActividad" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalActividad()">×</span>
        <h2>Nueva actividad</h2>

        <input type="text" class="input" id="actTitle" placeholder="Nombre de la actividad" maxlength="200">

        <label style="display:block; margin:10px 0 6px; font-size:10.5pt; color:var(--rtp-gris);">Descripción (opcional):</label>
        <textarea class="input" id="actDesc" placeholder="Describe la actividad..." style="min-height:70px;"></textarea>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px;">
            <div>
                <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris);">Fecha inicio:</label>
                <input type="date" class="input" id="actFechaInicio">
            </div>
            <div>
                <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris);">Fecha límite:</label>
                <input type="date" class="input" id="actFecha">
            </div>
        </div>

        <label style="display:block; margin:8px 0 6px; font-size:10.5pt; color:var(--rtp-gris);">Prioridad:</label>
        <select class="input" id="actPrioridad">
            <option value="baja"    data-color="#027a35">🟢 Baja</option>
            <option value="media"   data-color="#FDC60A" selected>🟡 Media</option>
            <option value="alta"    data-color="#F08217">🟠 Alta</option>
            <option value="critica" data-color="#E5074C">🔴 Crítica</option>
        </select>

        <label style="display:block; margin:8px 0 6px; font-size:10.5pt; color:var(--rtp-gris);">Asignar a (opcional):</label>
        <select class="input" id="actAsignado">
            <option value="">— Sin asignar —</option>
            <?php foreach ($miembros as $m): ?>
                <option value="<?= $m['id_usuario'] ?>"><?= h($m['nombre_completo']) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="display:block; margin:12px 0 6px; font-size:10.5pt; color:var(--rtp-gris);">📊 Porcentaje de aporte al proyecto:</label>
        <div class="porcentaje-box">
            <div class="porcentaje-input-row">
                <input type="number" id="actPorcentaje" min="0" max="100" step="0.5" value="0">
                <span class="pct-symbol">%</span>
                <div class="porcentaje-info">
                    ¿Qué porcentaje del proyecto representa esta actividad?<br>
                    <b>Disponible: <span id="porcentajeDisponible"><?= number_format(100 - $total_porcentaje, 2) ?>%</span></b>
                </div>
            </div>
            <div class="porcentaje-bar"><div class="porcentaje-bar-fill" id="porcentajeBar"></div></div>
        </div>

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalActividad()" style="flex:1;">← Regresar</button>
            <button class="btn" onclick="crearActividad()" style="flex:1;">✓ Crear actividad</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: AJUSTAR PORCENTAJES ============ -->
<div id="modalPorcentajes" class="modal hidden">
    <div class="modal-content large">
        <span class="close" onclick="cerrarModalPorcentajes()">×</span>
        <h2>⚙️ Ajustar porcentajes de actividades</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Modifica el porcentaje que aporta cada actividad al proyecto. La suma total <b>no debe exceder 100%</b>.</p>

        <div id="pctRows">
            <?php foreach ($actividades as $a): ?>
                <div class="pct-row">
                    <div class="pct-row-title">
                        <?= h($a['titulo']) ?>
                        <span style="color:#94a3b8; font-size:9pt; display:block;">
                            <?= h(status_label($a['estado'])) ?> · 📅 <?= h(fmt_date($a['fecha_limite'])) ?>
                        </span>
                    </div>
                    <input type="number" class="pct-input" data-id="<?= $a['id_actividad'] ?>" min="0" max="100" step="0.5" value="<?= (float)($a['porcentaje_aporte'] ?? 0) ?>">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pct-summary" id="pctSummary">
            <span>Total asignado:</span>
            <span><b id="pctTotal">0.00</b>%</span>
        </div>

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalPorcentajes()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnGuardarPorcentajes" onclick="guardarPorcentajes()" style="flex:1;">✓ Guardar cambios</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: INVITAR ============ -->
<div id="modalInvitar" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalInvitar()">×</span>
        <h2>Invitar usuarios al proyecto</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:20px;">Envía invitaciones por correo o comparte el link generado.</p>

        <input type="email" class="input" id="inviteEmail" placeholder="Correo electrónico" oninput="validarFormInvite()">
        <select class="input" id="inviteRol" onchange="validarFormInvite()">
            <option value="">— Selecciona un rol —</option>
            <option value="administrador">Administrador</option>
            <option value="responsable">Responsable de proyecto</option>
            <option value="visualizador">Visualizador</option>
        </select>

        <div class="info-roles">
            <b>Permisos por rol:</b><br>
            • <b>Administrador:</b> crear actividades, fechas, eliminar, invitar, todo.<br>
            • <b>Responsable:</b> subir archivos y comentar en actividades.<br>
            • <b>Visualizador:</b> solo puede comentar. No modifica nada.
        </div>

        <button class="btn" id="btnEnviarInvitacion" disabled onclick="enviarInvitacion()" style="width:100%;">✉️ Enviar invitación</button>
        <div class="send-status" id="sendStatus"></div>

        <div class="link-generado">
            <div style="flex:1; min-width:200px;">
                <div style="font-size:9.5pt; color:var(--rtp-gris); margin-bottom:6px;"><b>Link de invitación del proyecto:</b></div>
                <input type="text" id="inviteLink" readonly value="<?= h(env('APP_URL', 'http://localhost:8000')) ?>/invite.php?proyecto=<?= h($id_proyecto) ?>">
            </div>
            <button class="btn-copiar" onclick="copiarLink()">📋 Copiar</button>
        </div>

        <div class="invite-list" id="inviteList"></div>

        <div style="display:flex; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <button class="btn btn-outline" onclick="cerrarModalInvitar()" style="flex:1;">← Cerrar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DETALLE DE ACTIVIDAD ============ -->
<div id="modalDetalleActividad" class="modal-actividad hidden">
    <div class="actividad-content" id="actividadContent"></div>
</div>

<!-- ============ MODAL: BLOQUEAR ACTIVIDAD ============ -->
<div id="modalBloquear" class="modal hidden" style="z-index:3000;">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="cerrarModalBloquear()">×</span>
        <h2 style="color:#E5074C;">🔒 Bloquear actividad</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Esta acción moverá la actividad a la columna <b>Bloqueado</b> y notificará a los responsables.</p>

        <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">Motivo del bloqueo:</label>
        <textarea class="input" id="motivoBloqueo" placeholder="Explica por qué se bloquea esta actividad..." style="min-height:90px;"></textarea>

        <div id="errorMotivo" style="display:none; background:#fde8ec; color:#991b1b; padding:10px; border-radius:8px; font-size:10pt; margin-bottom:10px; border-left:4px solid #E5074C;">⚠️ El motivo es obligatorio</div>

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalBloquear()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnConfirmarBloqueo" onclick="confirmarBloqueo()" style="flex:1; background:#E5074C;">🔒 Bloquear</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DESBLOQUEAR ACTIVIDAD ============ -->
<div id="modalDesbloquear" class="modal hidden" style="z-index:3000;">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="cerrarModalDesbloquear()">×</span>
        <h2 style="color:#F08217;">🔓 Desbloquear actividad</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">La actividad volverá a su estado anterior. Opcionalmente puedes asignar una nueva fecha límite.</p>

        <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">Nueva fecha límite (opcional):</label>
        <input type="date" class="input" id="nuevaFechaDesbloqueo">
        <p style="font-size:9.5pt; color:#94a3b8; margin-top:6px;">Si dejas este campo vacío, se mantendrá la fecha actual.</p>

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalDesbloquear()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnConfirmarDesbloqueo" onclick="confirmarDesbloqueo()" style="flex:1; background:#F08217;">🔓 Desbloquear</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: BLOQUEAR PROYECTO ============ -->
<div id="modalBloquearProyecto" class="modal hidden" style="z-index:3000;">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="cerrarModalBloquearProyecto()">×</span>
        <h2 style="color:#E5074C;">🔒 Bloquear proyecto</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Esta acción bloqueará <b>todo el proyecto</b>.</p>

        <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">Motivo del bloqueo:</label>
        <textarea class="input" id="motivoBloqueoProyecto" placeholder="Explica por qué se bloquea este proyecto..." style="min-height:90px;"></textarea>

        <div id="errorMotivoProyecto" style="display:none; background:#fde8ec; color:#991b1b; padding:10px; border-radius:8px; font-size:10pt; margin-bottom:10px; border-left:4px solid #E5074C;">⚠️ El motivo es obligatorio</div>

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalBloquearProyecto()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnConfirmarBloqueoProyecto" onclick="confirmarBloqueoProyecto()" style="flex:1; background:#E5074C;">🔒 Bloquear proyecto</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DESBLOQUEAR PROYECTO ============ -->
<div id="modalDesbloquearProyecto" class="modal hidden" style="z-index:3000;">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="cerrarModalDesbloquearProyecto()">×</span>
        <h2 style="color:#F08217;">🔓 Desbloquear proyecto</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">El proyecto volverá a estar activo.</p>

        <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">Nueva fecha de finalización (opcional):</label>
        <input type="date" class="input" id="nuevaFechaDesbloqueoProyecto" value="<?= h($proyecto['fecha_fin'] ?? '') ?>">

        <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalDesbloquearProyecto()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnConfirmarDesbloqueoProyecto" onclick="confirmarDesbloqueoProyecto()" style="flex:1; background:#F08217;">🔓 Desbloquear proyecto</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: AGREGAR RESPONSABLE ============ -->
<div id="modalAgregarResponsable" class="modal hidden" style="z-index:3200;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalAgregarResponsable()">×</span>
        <h2>Agregar responsable a la actividad</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:20px;">El usuario quedará vinculado como responsable de esta actividad.</p>

        <input type="email" class="input" id="respEmail" placeholder="Correo electrónico" oninput="validarFormResponsable()">
        <select class="input" id="respRol" onchange="validarFormResponsable()">
            <option value="">— Selecciona un rol —</option>
            <option value="administrador">Administrador</option>
            <option value="responsable">Responsable de proyecto</option>
            <option value="visualizador">Visualizador</option>
        </select>

        <button class="btn" id="btnAgregarResponsable" disabled onclick="agregarResponsable()" style="width:100%;">✉️ Agregar responsable</button>
        <div class="send-status" id="respStatus"></div>

        <div class="invite-list" id="respList"></div>

        <div style="display:flex; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <button class="btn btn-outline" onclick="cerrarModalAgregarResponsable()" style="flex:1;">← Cerrar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: EDITAR FECHA (PROYECTO) ============ -->
<div id="modalFecha" class="modal hidden" style="z-index:3200;">
    <div class="modal-content" style="max-width:420px;">
        <span class="close" onclick="cerrarModalFecha()">×</span>
        <h2 id="tituloFecha">Modificar fecha</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Selecciona la nueva fecha. Puedes dejarla vacía para borrarla.</p>
        <input type="date" class="input" id="inputFecha" style="font-size:13pt; padding:12px;">
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button class="btn btn-outline" onclick="cerrarModalFecha()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnGuardarFecha" onclick="guardarFecha()" style="flex:1;">✓ Guardar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: EDITAR FECHA (ACTIVIDAD) ============ -->
<div id="modalFechaActividad" class="modal hidden" style="z-index:3500;">
    <div class="modal-content" style="max-width:420px;">
        <span class="close" onclick="cerrarModalFechaActividad()">×</span>
        <h2 id="tituloFechaActividad">Modificar fecha de la actividad</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Selecciona la nueva fecha. Puedes dejarla vacía para borrarla.</p>
        <input type="date" class="input" id="inputFechaActividad" style="font-size:13pt; padding:12px;">
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button class="btn btn-outline" onclick="cerrarModalFechaActividad()" style="flex:1;">← Cancelar</button>
            <button class="btn" id="btnGuardarFechaActividad" onclick="guardarFechaActividad()" style="flex:1;">✓ Guardar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: HISTORIAL ============ -->
<div id="modalHistorial" class="modal hidden" style="z-index:3200;">
    <div class="modal-content large" style="max-width:780px;">
        <span class="close" onclick="cerrarModalHistorial()">×</span>
        <h2>📜 Historial de "<?= h($proyecto['nombre']) ?>"</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:16px;">Registro de todos los cambios del proyecto.</p>

        <div class="historial-tabs">
            <button class="historial-tab activo" data-filtro="todos" onclick="cargarHistorial('todos', this)">📋 Todos <span class="badge" id="badge-todos">0</span></button>
            <button class="historial-tab" data-filtro="creaciones" onclick="cargarHistorial('creaciones', this)">✨ Creaciones <span class="badge" id="badge-creaciones">0</span></button>
            <button class="historial-tab" data-filtro="eliminaciones" onclick="cargarHistorial('eliminaciones', this)">🗑️ Eliminaciones <span class="badge" id="badge-eliminaciones">0</span></button>
            <button class="historial-tab" data-filtro="usuarios" onclick="cargarHistorial('usuarios', this)">👥 Usuarios <span class="badge" id="badge-usuarios">0</span></button>
            <button class="historial-tab" data-filtro="bloqueos" onclick="cargarHistorial('bloqueos', this)">🔒 Bloqueos <span class="badge" id="badge-bloqueos">0</span></button>
            <button class="historial-tab" data-filtro="ediciones" onclick="cargarHistorial('ediciones', this)">✏️ Ediciones <span class="badge" id="badge-ediciones">0</span></button>
        </div>

        <div class="historial-list" id="historialList">
            <div class="historial-loading">Cargando historial...</div>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <button class="btn btn-outline" onclick="cerrarModalHistorial()" style="flex:1;">← Cerrar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: MENSAJE GENÉRICO ============ -->
<div id="modalMensaje" class="modal hidden" style="z-index:4000;">
    <div class="modal-content" style="max-width:420px; text-align:center;">
        <div id="mensajeIcon" style="font-size:40pt; margin-bottom:10px;">✅</div>
        <h2 id="mensajeTitulo" style="color:var(--rtp-guinda); margin-bottom:10px;">Título</h2>
        <p id="mensajeTexto" style="color:var(--rtp-gris); font-size:11pt; margin-bottom:20px;"></p>
        <button class="btn" onclick="cerrarModalMensaje()" style="width:100%;">Aceptar</button>
    </div>
</div>

<script>
const PROYECTO_ID = <?= (int)$id_proyecto ?>;
const USER_ROL = '<?= h($user['rol']) ?>';
const PROYECTO_BLOQUEADO = <?= $proyecto_bloqueado ? 'true' : 'false' ?>;
const PROYECTO_ARCHIVADO = <?= $proyecto_archivado ? 'true' : 'false' ?>;
const PROYECTO_PROGRAMADO = <?= $proyecto_programado ? 'true' : 'false' ?>;
const PROYECTO_OPERATIVO = <?= $proyecto_operativo ? 'true' : 'false' ?>;
const invitaciones = [];

let ACTIVIDAD_ID_ACTUAL = null;
let ID_BLOQUEAR = null;
let ID_DESBLOQUEAR = null;
let CALLBACK_MENSAJE = null;
let CAMPO_FECHA_ACTUAL = null;
let CAMPO_FECHA_ACTIVIDAD = null;
let ID_ACTIVIDAD_FECHA = null;

const PRIO_COLORS = { baja:'#027a35', media:'#FDC60A', alta:'#F08217', critica:'#E5074C' };
const PRIO_LABELS = { baja:'Baja', media:'Media', alta:'Alta', critica:'Crítica' };

const ESTADO_COLORS = { por_hacer:'#94a3b8', en_curso:'#266cb4', en_revision:'#FDC60A', completada:'#027a35' };
const ESTADO_LABELS = { por_hacer:'Por hacer', en_curso:'En curso', en_revision:'En revisión', completada:'Completada', bloqueado:'Bloqueado' };
const ESTADO_ICONS  = { por_hacer:'📝', en_curso:'🔄', en_revision:'⏳', completada:'✅', bloqueado:'🔒' };

const ETAPA_LABELS = { sin_iniciar:'Sin iniciar', en_curso:'En curso', en_revision:'En revisión', finalizado:'Finalizado', archivado:'Archivado' };

function mostrarToast(mensaje, icono = '✅') {
    const overlay = document.getElementById('toastOverlay');
    if (!overlay) return;

    overlay.innerHTML = '';

    const toast = document.createElement('div');
    toast.className = 'toast-box';
    toast.innerHTML = `
        <div class="toast-icon">${icono}</div>
        <div class="toast-text">${escapeHtml(mensaje)}</div>
    `;

    overlay.appendChild(toast);
    void toast.offsetWidth;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) toast.remove();
        }, 400);
    }, 3000);
}

window.toggleEtapaMenu = function(event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('etapaMenu');
    if (!menu) return;
    menu.classList.toggle('open');
};

window.cambiarEtapaProyecto = async function(codigo, label, event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('etapaMenu');
    if (menu) menu.classList.remove('open');

    try {
        const res = await fetch('api/update_project_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_proyecto: PROYECTO_ID,
                columna_manual: codigo
            })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error', '❌ Error', json.error);
            return;
        }

        const nombreProyecto = <?= json_encode($proyecto['nombre']) ?>;
        const msj = 'El proyecto "' + nombreProyecto + '" cambió su estado a: ' + label;
        try {
            sessionStorage.setItem('rtp_toast_pending', JSON.stringify({ mensaje: msj, icono: '✅' }));
        } catch (e) { /* ignorar */ }

        window.location.href = 'projects.php';
    } catch (err) {
        mostrarMensaje('error', '❌ Error', err.message);
    }
};

document.addEventListener('click', () => {
    const menu = document.getElementById('etapaMenu');
    if (menu) menu.classList.remove('open');
});

window.filtrarActividades = function() {
    const query = document.getElementById('buscadorActividades').value.toLowerCase().trim();
    document.querySelectorAll('.task-card').forEach(card => {
        const name = card.getAttribute('data-name') || '';
        card.classList.toggle('hidden', !name.includes(query));
    });
};

window.buscarActividad = function() {
    const input = document.getElementById('buscadorActividades');
    const query = input.value.toLowerCase().trim();
    if (!query) return;

    const cards = document.querySelectorAll('.task-card');
    let encontrado = null;
    for (let card of cards) {
        const name = card.getAttribute('data-name') || '';
        if (name.includes(query)) { encontrado = card; break; }
    }

    if (encontrado) {
        input.value = '';
        window.filtrarActividades();
        encontrado.scrollIntoView({ behavior:'smooth', block:'center' });
        encontrado.classList.remove('card-destello');
        void encontrado.offsetWidth;
        encontrado.classList.add('card-destello');
        setTimeout(() => encontrado.classList.remove('card-destello'), 3000);
    } else {
        input.value = '';
        window.filtrarActividades();
    }
};

function abrirModalFecha(campo, fechaActual) {
    CAMPO_FECHA_ACTUAL = campo;
    document.getElementById('tituloFecha').textContent =
        campo === 'fecha_inicio' ? '📅 Modificar fecha de inicio' : '🏁 Modificar fecha de fin';
    document.getElementById('inputFecha').value = fechaActual || '';

    const btn = document.getElementById('btnGuardarFecha');
    btn.disabled = false;
    btn.textContent = '✓ Guardar';

    document.getElementById('modalFecha').classList.remove('hidden');
    setTimeout(() => document.getElementById('inputFecha').focus(), 100);
}

function cerrarModalFecha() {
    document.getElementById('modalFecha').classList.add('hidden');
    CAMPO_FECHA_ACTUAL = null;
}

async function guardarFecha() {
    if (!CAMPO_FECHA_ACTUAL) return;
    const fecha = document.getElementById('inputFecha').value;
    const btn = document.getElementById('btnGuardarFecha');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';

    try {
        const res = await fetch('api/update_project_dates.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, campo: CAMPO_FECHA_ACTUAL, fecha: fecha || null })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '✓ Guardar';
        } else {
            cerrarModalFecha();
            document.getElementById('modalFecha').style.display = 'none';

            mostrarToast('Fecha del proyecto actualizada correctamente.', '✅');

            setTimeout(() => location.reload(), 1200);
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '✓ Guardar';
    }
}

function abrirModalFechaActividad(idActividad, campo, fechaActual) {
    ID_ACTIVIDAD_FECHA = idActividad;
    CAMPO_FECHA_ACTIVIDAD = campo;
    document.getElementById('tituloFechaActividad').textContent =
        campo === 'fecha_inicio' ? '📅 Modificar fecha de inicio' : '🏁 Modificar fecha límite';
    document.getElementById('inputFechaActividad').value = fechaActual || '';

    const btn = document.getElementById('btnGuardarFechaActividad');
    btn.disabled = false;
    btn.textContent = '✓ Guardar';

    document.getElementById('modalFechaActividad').classList.remove('hidden');
    setTimeout(() => document.getElementById('inputFechaActividad').focus(), 100);
}

function cerrarModalFechaActividad() {
    document.getElementById('modalFechaActividad').classList.add('hidden');
    CAMPO_FECHA_ACTIVIDAD = null;
    ID_ACTIVIDAD_FECHA = null;
}

async function guardarFechaActividad() {
    if (!CAMPO_FECHA_ACTIVIDAD || !ID_ACTIVIDAD_FECHA) return;
    const fecha = document.getElementById('inputFechaActividad').value;
    const btn = document.getElementById('btnGuardarFechaActividad');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';

    try {
        const res = await fetch('api/update_activity_dates.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                id_actividad: ID_ACTIVIDAD_FECHA,
                campo: CAMPO_FECHA_ACTIVIDAD,
                fecha: fecha || null
            })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '✓ Guardar';
        } else {
            cerrarModalFechaActividad();
            document.getElementById('modalFechaActividad').style.display = 'none';

            mostrarToast('Fecha de la actividad actualizada correctamente.', '✅');

            setTimeout(() => location.reload(), 1200);
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '✓ Guardar';
    }
}

function toggleEstadoMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('estadoMenu');
    if (!menu) return;
    menu.classList.toggle('open');
}

async function cambiarEstadoActividad(id, nuevoEstado, e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('estadoMenu');
    if (menu) menu.classList.remove('open');

    try {
        const res = await fetch('api/update_activity_status.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: id, estado: nuevoEstado })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            return;
        }

        mostrarToast('Estado de la actividad actualizado.', '✅');
        setTimeout(() => location.reload(), 1200);
    } catch (err) {
        mostrarMensaje('error','❌ Error', err.message);
    }
}

document.addEventListener('click', () => {
    const menu = document.getElementById('estadoMenu');
    if (menu) menu.classList.remove('open');
});

function abrirModalNuevaActividad() {
    if (!PROYECTO_OPERATIVO) {
        let msg = 'No puedes crear actividades en este momento.';
        if (PROYECTO_ARCHIVADO) msg = 'El proyecto está archivado. Desarchívalo para crear actividades.';
        else if (PROYECTO_PROGRAMADO) msg = 'El proyecto aún no se ha abierto.';
        else if (PROYECTO_BLOQUEADO) msg = 'El proyecto está bloqueado.';
        mostrarMensaje('error', PROYECTO_ARCHIVADO ? '📦 Proyecto archivado' : (PROYECTO_PROGRAMADO ? '⏳ Proyecto programado' : '🔒 Proyecto bloqueado'), msg);
        return;
    }
    document.getElementById('actTitle').value = '';
    document.getElementById('actDesc').value = '';
    document.getElementById('actFecha').value = '';
    document.getElementById('actFechaInicio').value = '';
    document.getElementById('actPrioridad').value = 'media';
    document.getElementById('actAsignado').value = '';
    document.getElementById('actPorcentaje').value = '0';
    document.getElementById('porcentajeBar').style.width = '0%';
    document.getElementById('porcentajeBar').style.background = '#78BE20';
    document.getElementById('modalActividad').classList.remove('hidden');
    aplicarColorSelectPrioridad();
    setTimeout(() => document.getElementById('actTitle').focus(), 100);
}

function aplicarColorSelectPrioridad() {
    const sel = document.getElementById('actPrioridad');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const color = opt?.dataset?.color || PRIO_COLORS.media;
    sel.style.color = color;
    sel.style.fontWeight = '700';
}

function cerrarModalActividad() {
    document.getElementById('modalActividad').classList.add('hidden');
}

async function crearActividad() {
    const titulo = document.getElementById('actTitle').value.trim();
    const descripcion = document.getElementById('actDesc').value.trim();
    const fecha_inicio = document.getElementById('actFechaInicio').value;
    const fecha_limite = document.getElementById('actFecha').value;
    const prioridad = document.getElementById('actPrioridad').value;
    const asignado = document.getElementById('actAsignado').value;
    const porcentaje = parseFloat(document.getElementById('actPorcentaje').value) || 0;

    if (!titulo) { mostrarMensaje('error','⚠️ Falta nombre','Escribe un nombre para la actividad.'); return; }
    if (fecha_inicio && fecha_limite && fecha_inicio > fecha_limite) {
        mostrarMensaje('error','⚠️ Fechas inválidas','La fecha de inicio no puede ser posterior a la fecha límite.');
        return;
    }
    if (porcentaje < 0 || porcentaje > 100) {
        mostrarMensaje('error','⚠️ Porcentaje inválido','El porcentaje debe estar entre 0 y 100.');
        return;
    }

    const res = await fetch('api/create_activity.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            id_proyecto: PROYECTO_ID,
            titulo, descripcion,
            fecha: fecha_limite,
            fecha_inicio,
            prioridad,
            asignado_a: asignado || null,
            porcentaje_aporte: porcentaje
        })
    });
    const json = await res.json();
    if (json.error) mostrarMensaje('error','❌ Error', json.error);
    else location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('actPorcentaje');
    const bar = document.getElementById('porcentajeBar');
    if (input && bar) {
        input.addEventListener('input', () => {
            const pct = Math.min(100, Math.max(0, parseFloat(input.value) || 0));
            bar.style.width = pct + '%';
            bar.style.background = pct >= 100 ? '#027a35' : (pct >= 50 ? '#266cb4' : '#78BE20');
        });
    }
    const selPrio = document.getElementById('actPrioridad');
    if (selPrio) selPrio.addEventListener('change', aplicarColorSelectPrioridad);
    actualizarTotalesModal();
});

function abrirModalPorcentajes() {
    if (!PROYECTO_OPERATIVO) {
        mostrarMensaje('error','⏳','No puedes ajustar porcentajes ahora.');
        return;
    }
    document.getElementById('modalPorcentajes').classList.remove('hidden');
    actualizarTotalesModal();
}

function cerrarModalPorcentajes() {
    document.getElementById('modalPorcentajes').classList.add('hidden');
}

function actualizarTotalesModal() {
    let total = 0;
    document.querySelectorAll('.pct-input').forEach(i => total += parseFloat(i.value) || 0);
    const totalEl = document.getElementById('pctTotal');
    const summary = document.getElementById('pctSummary');
    const btn = document.getElementById('btnGuardarPorcentajes');
    if (totalEl) totalEl.textContent = total.toFixed(2);
    if (summary) {
        summary.classList.remove('ok','warn','error');
        summary.classList.add(total === 100 ? 'ok' : (total < 100 ? 'warn' : 'error'));
    }
    if (btn) {
        btn.disabled = total > 100;
        btn.style.opacity = total > 100 ? '0.5' : '1';
        btn.style.cursor = total > 100 ? 'not-allowed' : 'pointer';
    }
}

document.addEventListener('input', (e) => {
    if (e.target.classList.contains('pct-input')) actualizarTotalesModal();
});

async function guardarPorcentajes() {
    const cambios = [];
    let total = 0;
    document.querySelectorAll('.pct-input').forEach(i => {
        const pct = parseFloat(i.value) || 0;
        total += pct;
        cambios.push({ id_actividad: i.dataset.id, porcentaje_aporte: pct });
    });
    if (total > 100) {
        mostrarMensaje('error','⚠️ Excede 100%','La suma no puede exceder 100%. Actual: ' + total.toFixed(2) + '%');
        return;
    }
    const btn = document.getElementById('btnGuardarPorcentajes');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const res = await fetch('api/update_activity_percentages.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, cambios })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '✓ Guardar cambios';
        } else {
            mostrarMensaje('ok','✅ Porcentajes actualizados','Los porcentajes se guardaron correctamente.', () => location.reload());
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '✓ Guardar cambios';
    }
}

function archivarProyecto() {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes archivar un proyecto no operativo.'); return; }
    mostrarConfirmacion('📦 Archivar proyecto','¿Archivar este proyecto? Podrás encontrarlo después en "Proyectos archivados".', async () => {
        const res = await fetch('api/archive_project.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'archivar' })
        });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto archivado','El proyecto fue archivado.', () => location.href = 'projects.php?archivados=1');
    });
}

function desarchivarProyecto() {
    if (!PROYECTO_ARCHIVADO) { mostrarMensaje('error','⏳','Este proyecto no está archivado.'); return; }
    mostrarConfirmacion('📂 Desarchivar proyecto','¿Desarchivar este proyecto? Volverá a la lista de proyectos activos.', async () => {
        const res = await fetch('api/unarchive_project.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID })
        });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto desarchivado','El proyecto fue desarchivado y ya está activo.', () => location.href = 'projects.php');
    });
}

function eliminarProyecto() {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes eliminar un proyecto no operativo.'); return; }
    mostrarConfirmacion('🗑️ Eliminar proyecto','¿Eliminar este proyecto? Esta acción es definitiva y no se puede deshacer.', async () => {
        const res = await fetch('api/delete_project.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'eliminar' })
        });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto eliminado','El proyecto fue eliminado permanentemente.', () => location.href = 'projects.php');
    });
}

function abrirModalInvitar() {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes invitar usuarios ahora.'); return; }
    document.getElementById('modalInvitar').classList.remove('hidden');
}

function cerrarModalInvitar() {
    document.getElementById('modalInvitar').classList.add('hidden');
}

function validarFormInvite() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const valido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && rol;
    document.getElementById('btnEnviarInvitacion').disabled = !valido;
}

function copiarLink() {
    const input = document.getElementById('inviteLink');
    input.select();
    document.execCommand('copy');
    mostrarMensaje('ok','✅ Link copiado','El link está en tu portapapeles.');
}

async function enviarInvitacion() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const btn = document.getElementById('btnEnviarInvitacion');
    const status = document.getElementById('sendStatus');
    if (!email || !rol) return;
    btn.disabled = true; btn.textContent = '⏳ Enviando...';
    status.className = 'send-status'; status.textContent = '';
    try {
        const res = await fetch('api/invite_user.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, correo: email, rol })
        });
        const json = await res.json();
        if (json.error) {
            status.className = 'send-status error';
            status.textContent = '❌ ' + json.error;
        } else {
            status.className = 'send-status ok';
            status.textContent = '✅ Invitación enviada a ' + email;
            invitaciones.push({ email, rol });
            renderInvitaciones();
            document.getElementById('inviteEmail').value = '';
            document.getElementById('inviteRol').value = '';
        }
    } catch (e) {
        status.className = 'send-status error';
        status.textContent = '❌ Error: ' + e.message;
    }
    btn.disabled = false; btn.textContent = '✉️ Enviar invitación';
}

function renderInvitaciones() {
    const cont = document.getElementById('inviteList');
    if (!invitaciones.length) { cont.innerHTML = ''; return; }
    cont.innerHTML = invitaciones.map(i => `
        <div class="invite-item">✉️ <b>${escapeHtml(i.email)}</b><span class="invite-item-rol">${i.rol}</span></div>
    `).join('');
}

async function abrirDetalleActividad(id) {
    ACTIVIDAD_ID_ACTUAL = id;
    const modal = document.getElementById('modalDetalleActividad');
    const content = document.getElementById('actividadContent');
    content.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8;">Cargando...</div>';
    modal.classList.remove('hidden');
    try {
        const res = await fetch('api/activity_detail.php?id=' + id);
        const data = await res.json();
        if (data.error) {
            content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">' + data.error + '</div>';
            return;
        }
        renderDetalleActividad(data);
    } catch (e) {
        content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">Error: ' + e.message + '</div>';
    }
}

function renderDetalleActividad(data) {
    const a = data.actividad;
    const adjuntos = data.adjuntos || [];
    const comentarios = data.comentarios || [];
    const userRol = data.user_rol;
    const pct = parseFloat(a.porcentaje_aporte) || 0;

    const prioColor = PRIO_COLORS[a.prioridad] || PRIO_COLORS.media;
    const prioLabel = PRIO_LABELS[a.prioridad] || PRIO_LABELS.media;

    const estadoLabel = ESTADO_LABELS[a.estado] || a.estado;
    const estadoIcon  = ESTADO_ICONS[a.estado] || '📝';
    const estadoColor = ESTADO_COLORS[a.estado] || '#94a3b8';

    const estaBloqueada = a.estado === 'bloqueado';
    const proyectoEstaBloqueado = PROYECTO_BLOQUEADO;
    const proyectoEstaArchivado = PROYECTO_ARCHIVADO;
    const proyectoEstaProgramado = PROYECTO_PROGRAMADO;

    const puedeSubir = (userRol === 'administrador' || userRol === 'responsable') && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeComentar = !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeEliminar = userRol === 'administrador' && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeBloquear = userRol === 'administrador' && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeDesbloquear = userRol === 'administrador' && estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeEditarPrioridad = (userRol === 'administrador' || userRol === 'responsable') && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeAgregarResponsable = (userRol === 'administrador' || userRol === 'responsable') && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeEditarFechas = (userRol === 'administrador' || userRol === 'responsable') && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;
    const puedeEditarEstado = (userRol === 'administrador' || userRol === 'responsable') && !estaBloqueada && !proyectoEstaBloqueado && !proyectoEstaProgramado && !proyectoEstaArchivado;

    const radius = 35;
    const circumference = 2 * Math.PI * radius;
    const dashValue = (pct / 100) * circumference;
    const dashResto = circumference - dashValue;

    let responsablesHtml = '';
    if (a.responsables && a.responsables.length) {
        a.responsables.forEach(r => {
            const ini = inicialesJS(r.nombre_completo);
            responsablesHtml += `<span class="resp-avatar-mini" title="${escapeHtml(r.nombre_completo)}">${ini}</span><span class="resp-name">${escapeHtml(r.nombre_completo)}</span>`;
        });
        if (puedeAgregarResponsable) {
            responsablesHtml += `<div class="sin-asignar-wrapper" onclick="abrirModalAgregarResponsable(${a.id_actividad})" title="Agregar otro responsable"><span class="sin-asignar-text" style="padding:2px 8px; font-size:9pt;"><span class="sin-asignar-default">+ Agregar</span><span class="sin-asignar-btn-label">➕ Agregar</span></span></div>`;
        }
    } else {
        if (puedeAgregarResponsable) {
            responsablesHtml = `<div class="sin-asignar-wrapper" onclick="abrirModalAgregarResponsable(${a.id_actividad})" title="Agregar responsable"><span class="sin-asignar-text"><span class="sin-asignar-default">Sin asignar</span><span class="sin-asignar-btn-label">➕ Agregar responsable</span></span></div>`;
        } else {
            responsablesHtml = '<span style="color:#94a3b8;">Sin asignar</span>';
        }
    }

    let adjuntosHtml = '';
    if (adjuntos.length) {
        adjuntos.forEach(ad => {
            let icon = '📄', clase = '', url = '';
            if (ad.tipo === 'enlace') { icon = '🔗'; clase = 'enlace'; url = ad.url_externa; }
            else if (ad.tipo === 'imagen') { icon = '🖼'; clase = 'imagen'; url = '/' + ad.ruta_archivo; }
            else { icon = '📄'; url = '/' + ad.ruta_archivo; }
            const nombre = ad.tipo === 'enlace' ? ad.url_externa : ad.nombre_archivo;
            adjuntosHtml += `<a class="adjunto-item ${clase}" href="${escapeHtml(url)}" target="_blank" rel="noopener"><span class="adjunto-icon">${icon}</span><span class="adjunto-name">${escapeHtml(nombre)}</span></a>`;
        });
    } else {
        adjuntosHtml = '<span style="color:#94a3b8;font-size:10pt;font-style:italic;">Sin evidencias</span>';
    }

    let comentariosHtml = '';
    if (comentarios.length) {
        comentarios.forEach(c => {
            comentariosHtml += `<div class="comentario-item"><div class="comentario-meta"><b>${escapeHtml(c.nombre_completo)}</b><span>·</span><span>${new Date(c.creado_en).toLocaleString('es-MX')}</span></div><div class="comentario-texto">${escapeHtml(c.contenido)}</div></div>`;
        });
    } else {
        comentariosHtml = '<span style="color:#94a3b8;font-size:10pt;font-style:italic;">Sin comentarios</span>';
    }

    let avisoBloqueo = '';
    if (estaBloqueada) {
        avisoBloqueo = `<div class="actividad-bloqueo-aviso"><div class="bloqueo-icon">🔒</div><div class="bloqueo-text"><b>Actividad bloqueada</b>Motivo: ${escapeHtml(a.motivo_bloqueo || 'Sin motivo especificado')}</div></div>`;
    } else if (proyectoEstaArchivado) {
        avisoBloqueo = `<div class="actividad-bloqueo-aviso" style="background:#fffbe6; border-bottom-color:#FDC60A;"><div class="bloqueo-icon">📦</div><div class="bloqueo-text" style="color:#5c4500;"><b style="color:#b58900;">Proyecto archivado</b>El proyecto está archivado. No se puede modificar ni comentar hasta desarchivarlo.</div></div>`;
    } else if (proyectoEstaProgramado) {
        avisoBloqueo = `<div class="actividad-bloqueo-aviso" style="background:#fff7ed; border-bottom-color:#F08217;"><div class="bloqueo-icon">⏳</div><div class="bloqueo-text" style="color:#78350f;"><b style="color:#F08217;">Proyecto programado</b>Este proyecto aún no se ha abierto.</div></div>`;
    } else if (proyectoEstaBloqueado) {
        avisoBloqueo = `<div class="actividad-bloqueo-aviso"><div class="bloqueo-icon">🔒</div><div class="bloqueo-text"><b>Proyecto bloqueado</b>No se pueden subir archivos ni comentar hasta desbloquear el proyecto.</div></div>`;
    }

    let prioridadHtml;
    if (puedeEditarPrioridad) {
        prioridadHtml = `
            <div style="position:relative; display:inline-block;">
                <span class="prioridad-selector" id="prioridadSelector" onclick="togglePrioridadMenu(event)" title="Cambiar prioridad">
                    <span class="prioridad-default" style="color:${prioColor}; font-weight:600;">
                        <span class="prioridad-dot" style="background:${prioColor};"></span> ${prioLabel}
                    </span>
                    <span class="prioridad-hover">✏️ Cambiar prioridad</span>
                </span>
                <div class="prioridad-menu" id="prioridadMenu">
                    <div class="prioridad-opcion ${a.prioridad === 'baja' ? 'activa' : ''}" data-prio="baja" onclick="cambiarPrioridad('${a.id_actividad}', 'baja', event)">
                        <span class="opcion-dot" style="background: ${PRIO_COLORS.baja};"></span><span>Baja</span><span class="check">✓</span>
                    </div>
                    <div class="prioridad-opcion ${a.prioridad === 'media' ? 'activa' : ''}" data-prio="media" onclick="cambiarPrioridad('${a.id_actividad}', 'media', event)">
                        <span class="opcion-dot" style="background: ${PRIO_COLORS.media};"></span><span>Media</span><span class="check">✓</span>
                    </div>
                    <div class="prioridad-opcion ${a.prioridad === 'alta' ? 'activa' : ''}" data-prio="alta" onclick="cambiarPrioridad('${a.id_actividad}', 'alta', event)">
                        <span class="opcion-dot" style="background: ${PRIO_COLORS.alta};"></span><span>Alta</span><span class="check">✓</span>
                    </div>
                    <div class="prioridad-opcion ${a.prioridad === 'critica' ? 'activa' : ''}" data-prio="critica" onclick="cambiarPrioridad('${a.id_actividad}', 'critica', event)">
                        <span class="opcion-dot" style="background: ${PRIO_COLORS.critica};"></span><span>Crítica</span><span class="check">✓</span>
                    </div>
                </div>
            </div>
        `;
    } else {
        prioridadHtml = `<span style="color:${prioColor}; font-weight:600;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${prioColor};margin-right:4px;"></span>${prioLabel}</span>`;
    }

    const fechaInicioHtml = puedeEditarFechas
        ? `<span class="fecha-editable-actividad" onclick="abrirModalFechaActividad(${a.id_actividad}, 'fecha_inicio', '${a.fecha_inicio || ''}')" title="Modificar fecha de inicio">
              <span class="fecha-default">📅 ${formatearFecha(a.fecha_inicio)}</span>
              <span class="fecha-hover">✏️ Modificar inicio</span>
           </span>`
        : `<span>📅 ${formatearFecha(a.fecha_inicio)}</span>`;

    const fechaLimiteHtml = puedeEditarFechas
        ? `<span class="fecha-editable-actividad" onclick="abrirModalFechaActividad(${a.id_actividad}, 'fecha_limite', '${a.fecha_limite || ''}')" title="Modificar fecha límite">
              <span class="fecha-default">🏁 ${formatearFecha(a.fecha_limite)}</span>
              <span class="fecha-hover">✏️ Modificar límite</span>
           </span>`
        : `<span>🏁 ${formatearFecha(a.fecha_limite)}</span>`;

    let estadoHtml;
    if (puedeEditarEstado) {
        estadoHtml = `
            <div style="position:relative; display:inline-block;">
                <span class="estado-editable-actividad" onclick="toggleEstadoMenu(event)" title="Cambiar estado">
                    <span class="estado-default" style="color:${estadoColor}; font-weight:600;">${estadoIcon} ${estadoLabel}</span>
                    <span class="estado-hover">✏️ Cambiar estado</span>
                </span>
                <div class="estado-menu" id="estadoMenu">
                    <div class="estado-opcion ${a.estado === 'por_hacer' ? 'activa' : ''}" data-estado="por_hacer" onclick="cambiarEstadoActividad(${a.id_actividad}, 'por_hacer', event)">
                        <span class="opcion-dot" style="background:${ESTADO_COLORS.por_hacer};"></span>
                        <span>📝 Por hacer</span>
                        <span class="check">✓</span>
                    </div>
                    <div class="estado-opcion ${a.estado === 'en_curso' ? 'activa' : ''}" data-estado="en_curso" onclick="cambiarEstadoActividad(${a.id_actividad}, 'en_curso', event)">
                        <span class="opcion-dot" style="background:${ESTADO_COLORS.en_curso};"></span>
                        <span>🔄 En curso</span>
                        <span class="check">✓</span>
                    </div>
                    <div class="estado-opcion ${a.estado === 'en_revision' ? 'activa' : ''}" data-estado="en_revision" onclick="cambiarEstadoActividad(${a.id_actividad}, 'en_revision', event)">
                        <span class="opcion-dot" style="background:${ESTADO_COLORS.en_revision};"></span>
                        <span>⏳ En revisión</span>
                        <span class="check">✓</span>
                    </div>
                    <div class="estado-opcion ${a.estado === 'completada' ? 'activa' : ''}" data-estado="completada" onclick="cambiarEstadoActividad(${a.id_actividad}, 'completada', event)">
                        <span class="opcion-dot" style="background:${ESTADO_COLORS.completada};"></span>
                        <span>✅ Completada</span>
                        <span class="check">✓</span>
                    </div>
                </div>
            </div>
        `;
    } else {
        estadoHtml = `<span style="color:${estadoColor}; font-weight:600;">${estadoIcon} ${estadoLabel}</span>`;
    }

    let html = `
        ${avisoBloqueo}
        <div class="actividad-header">
            <div class="actividad-pct-circle">
                <svg viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="6"/>
                    <circle cx="40" cy="40" r="${radius}" fill="none" stroke="#78BE20" stroke-width="6" stroke-dasharray="${dashValue} ${dashResto}" stroke-dashoffset="0" stroke-linecap="round"/>
                </svg>
                <span class="pct-value">${pct}%</span>
                <span class="pct-label">del proyecto</span>
            </div>

            <div class="actividad-header-info">
                <div class="actividad-header-title">${estaBloqueada ? '🔒 ' : ''}${escapeHtml(a.titulo)}</div>
                <div class="actividad-header-meta">
                    ${fechaInicioHtml}
                    <span>·</span>
                    ${fechaLimiteHtml}
                    <span>·</span>
                    ${prioridadHtml}
                    <span>·</span>
                    ${estadoHtml}
                </div>
                <div class="actividad-header-responsables">
                    <span style="font-weight:600;color:var(--rtp-guinda);">Responsables:</span>
                    ${responsablesHtml}
                </div>
            </div>

            <button class="actividad-close" onclick="cerrarDetalleActividad()">×</button>
        </div>

        <div class="actividad-body">
            <div class="actividad-section">
                <div class="actividad-section-title">📄 Descripción</div>
                <div class="actividad-descripcion">${a.descripcion ? escapeHtml(a.descripcion) : '<em style="color:#94a3b8;">Sin descripción</em>'}</div>
            </div>

            <div class="actividad-section">
                <div class="actividad-section-title">📎 Evidencias (${adjuntos.length})</div>
                <div class="adjuntos-list">${adjuntosHtml}</div>
            </div>

            <div class="actividad-section">
                <div class="actividad-section-title">💬 Comentarios (${comentarios.length})</div>
                <div class="comentarios-list">${comentariosHtml}</div>

                ${puedeComentar ? `
                    <div class="comentario-box">
                        <textarea id="nuevoComentario" placeholder="${estaBloqueada ? 'Comenta sobre el bloqueo (admin y responsable)...' : 'Escribe un comentario...'}"></textarea>
                        <div class="comentario-actions">
                            ${puedeSubir ? `
                                <div class="btn-cargar-wrapper">
                                    <button class="btn-cargar" id="btnCargar" onclick="toggleCargarMenu(event)">📎 Cargar</button>
                                    <div class="cargar-menu" id="cargarMenu">
                                        <button onclick="cargarArchivo('archivo')"><span class="menu-icon">📄</span> Subir archivo</button>
                                        <button onclick="cargarArchivo('imagen')"><span class="menu-icon">🖼</span> Subir imagen</button>
                                        <button onclick="cargarEnlace()"><span class="menu-icon">🔗</span> Pegar enlace</button>
                                    </div>
                                </div>
                            ` : (estaBloqueada || proyectoEstaBloqueado || proyectoEstaProgramado || proyectoEstaArchivado ? `<div class="btn-cargar-disabled" title="No puedes subir archivos ahora">🔒 Cargar</div>` : '')}
                            <button class="btn-enviar" id="btnEnviarComentario" onclick="enviarComentario()" disabled>📤 Enviar</button>
                        </div>
                    </div>
                    ${estaBloqueada ? `<div class="comentario-aviso-bloqueo">💡 <b>Nota:</b> Mientras la actividad esté bloqueada, los archivos están deshabilitados.</div>` : ''}
                ` : `<div class="permiso-aviso">${proyectoEstaArchivado ? '📦 El proyecto está archivado. Desarchívalo para poder comentar y subir archivos.' : '⏳ El proyecto aún no está disponible. No puedes comentar ni subir archivos hasta que se abra.'}</div>`}
            </div>
        </div>

        <div class="actividad-footer">
            <div class="actividad-footer-left">
                <button class="btn-modal secondary" onclick="cerrarDetalleActividad()">← Cerrar</button>
            </div>
            <div class="actividad-footer-right">
                ${puedeBloquear ? `<button class="btn-modal warning" onclick="bloquearActividad(${a.id_actividad})">🔒 Bloquear actividad</button>` : ''}
                ${puedeDesbloquear ? `<button class="btn-modal naranja" onclick="desbloquearActividad(${a.id_actividad})">🔓 Desbloquear actividad</button>` : ''}
                ${puedeEliminar ? `<button class="btn-modal danger" onclick="eliminarActividad(${a.id_actividad})">🗑️ Eliminar actividad</button>` : ''}
            </div>
        </div>
    `;

    document.getElementById('actividadContent').innerHTML = html;

    const ta = document.getElementById('nuevoComentario');
    if (ta) {
        ta.addEventListener('input', () => {
            document.getElementById('btnEnviarComentario').disabled = ta.value.trim().length === 0;
        });
        ta.addEventListener('keydown', e => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviarComentario();
        });
    }
}

function togglePrioridadMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('prioridadMenu');
    if (!menu) return;
    menu.classList.toggle('open');
}

async function cambiarPrioridad(id, nuevaPrio, e) {
    e.stopPropagation();
    const menu = document.getElementById('prioridadMenu');
    if (menu) menu.classList.remove('open');
    try {
        const res = await fetch('api/update_activity_priority.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: id, prioridad: nuevaPrio })
        });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); return; }

        mostrarToast('Nivel de prioridad actualizado.', '✅');
        setTimeout(() => location.reload(), 1200);
    } catch (err) {
        mostrarMensaje('error','❌ Error', err.message);
    }
}

document.addEventListener('click', () => {
    const menuPrio = document.getElementById('prioridadMenu');
    if (menuPrio) menuPrio.classList.remove('open');
    const menuEst = document.getElementById('estadoMenu');
    if (menuEst) menuEst.classList.remove('open');
});

function cerrarDetalleActividad() {
    document.getElementById('modalDetalleActividad').classList.add('hidden');
    ACTIVIDAD_ID_ACTUAL = null;
}

function inicialesJS(nombre) {
    if (!nombre) return '?';
    const partes = nombre.trim().split(/\s+/);
    if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
}

function formatearFecha(fecha) {
    if (!fecha) return '—';
    const d = new Date(fecha + 'T00:00:00');
    const dia = String(d.getDate()).padStart(2, '0');
    const mes = String(d.getMonth() + 1).padStart(2, '0');
    const anio = d.getFullYear();
    return `${dia}/${mes}/${anio}`;
}

function bloquearActividad(id) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes bloquear actividades ahora.'); return; }
    ID_BLOQUEAR = id;
    document.getElementById('motivoBloqueo').value = '';
    document.getElementById('errorMotivo').style.display = 'none';
    document.getElementById('modalBloquear').classList.remove('hidden');
    setTimeout(() => document.getElementById('motivoBloqueo').focus(), 100);
}

function cerrarModalBloquear() {
    document.getElementById('modalBloquear').classList.add('hidden');
    ID_BLOQUEAR = null;
}

async function confirmarBloqueo() {
    const motivo = document.getElementById('motivoBloqueo').value.trim();
    if (!motivo) { document.getElementById('errorMotivo').style.display = 'block'; return; }
    const btn = document.getElementById('btnConfirmarBloqueo');
    btn.disabled = true; btn.textContent = '⏳ Bloqueando...';
    try {
        const res = await fetch('api/block_activity.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: ID_BLOQUEAR, motivo })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '🔒 Bloquear';
        } else {
            cerrarModalBloquear();
            cerrarDetalleActividad();
            mostrarMensaje('ok','✅ Actividad bloqueada','La actividad fue movida a "Bloqueado".', () => location.reload());
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '🔒 Bloquear';
    }
}

function desbloquearActividad(id) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes desbloquear actividades ahora.'); return; }
    ID_DESBLOQUEAR = id;
    document.getElementById('nuevaFechaDesbloqueo').value = '';
    document.getElementById('modalDesbloquear').classList.remove('hidden');
}

function cerrarModalDesbloquear() {
    document.getElementById('modalDesbloquear').classList.add('hidden');
    ID_DESBLOQUEAR = null;
}

async function confirmarDesbloqueo() {
    const nuevaFecha = document.getElementById('nuevaFechaDesbloqueo').value;
    const btn = document.getElementById('btnConfirmarDesbloqueo');
    btn.disabled = true; btn.textContent = '⏳ Desbloqueando...';
    try {
        const res = await fetch('api/unblock_activity.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: ID_DESBLOQUEAR, nueva_fecha: nuevaFecha || null })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '🔓 Desbloquear';
        } else {
            cerrarModalDesbloquear();
            cerrarDetalleActividad();
            mostrarMensaje('ok','✅ Actividad desbloqueada','La actividad volvió a: ' + (json.estado_vuelve || 'su estado anterior'), () => location.reload());
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '🔓 Desbloquear';
    }
}

function abrirModalBloquearProyecto() {
    document.getElementById('motivoBloqueoProyecto').value = '';
    document.getElementById('errorMotivoProyecto').style.display = 'none';
    document.getElementById('modalBloquearProyecto').classList.remove('hidden');
    setTimeout(() => document.getElementById('motivoBloqueoProyecto').focus(), 100);
}

function cerrarModalBloquearProyecto() {
    document.getElementById('modalBloquearProyecto').classList.add('hidden');
}

async function confirmarBloqueoProyecto() {
    const motivo = document.getElementById('motivoBloqueoProyecto').value.trim();
    if (!motivo) { document.getElementById('errorMotivoProyecto').style.display = 'block'; return; }
    const btn = document.getElementById('btnConfirmarBloqueoProyecto');
    btn.disabled = true; btn.textContent = '⏳ Bloqueando...';
    try {
        const res = await fetch('api/block_project.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'bloquear', motivo })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '🔒 Bloquear proyecto';
        } else {
            cerrarModalBloquearProyecto();
            mostrarMensaje('ok','✅ Proyecto bloqueado','El proyecto fue bloqueado.', () => location.reload());
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '🔒 Bloquear proyecto';
    }
}

function abrirModalDesbloquearProyecto() {
    document.getElementById('nuevaFechaDesbloqueoProyecto').value = '<?= h($proyecto['fecha_fin'] ?? '') ?>';
    document.getElementById('modalDesbloquearProyecto').classList.remove('hidden');
}

function cerrarModalDesbloquearProyecto() {
    document.getElementById('modalDesbloquearProyecto').classList.add('hidden');
}

async function confirmarDesbloqueoProyecto() {
    const nuevaFecha = document.getElementById('nuevaFechaDesbloqueoProyecto').value;
    const fechaOriginal = '<?= h($proyecto['fecha_fin'] ?? '') ?>';
    const fechaFinal = (nuevaFecha && nuevaFecha !== fechaOriginal) ? nuevaFecha : null;
    const btn = document.getElementById('btnConfirmarDesbloqueoProyecto');
    btn.disabled = true; btn.textContent = '⏳ Desbloqueando...';
    try {
        const res = await fetch('api/block_project.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'desbloquear', nueva_fecha: fechaFinal })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error','❌ Error', json.error);
            btn.disabled = false; btn.textContent = '🔓 Desbloquear proyecto';
        } else {
            cerrarModalDesbloquearProyecto();
            mostrarMensaje('ok','✅ Proyecto desbloqueado', json.fecha_reajustada ? 'El proyecto fue desbloqueado y la fecha se actualizó.' : 'El proyecto fue desbloqueado.', () => location.reload());
        }
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
        btn.disabled = false; btn.textContent = '🔓 Desbloquear proyecto';
    }
}

function abrirModalHistorial() {
    document.getElementById('modalHistorial').classList.remove('hidden');
    document.querySelectorAll('.historial-tab').forEach(t => t.classList.remove('activo'));
    document.querySelector('.historial-tab[data-filtro="todos"]').classList.add('activo');
    cargarHistorial('todos');
}

function cerrarModalHistorial() {
    document.getElementById('modalHistorial').classList.add('hidden');
}

async function cargarHistorial(filtro = 'todos', tabEl = null) {
    const lista = document.getElementById('historialList');
    lista.innerHTML = '<div class="historial-loading">Cargando historial...</div>';
    if (tabEl) {
        document.querySelectorAll('.historial-tab').forEach(t => t.classList.remove('activo'));
        tabEl.classList.add('activo');
    }
    try {
        const res = await fetch(`api/project_history.php?id_proyecto=${PROYECTO_ID}&filtro=${filtro}`);
        const data = await res.json();
        if (data.error) {
            lista.innerHTML = `<div class="historial-empty">❌ ${escapeHtml(data.error)}</div>`;
            return;
        }
        const conteo = data.conteo || {};
        const totalEventos = Object.values(conteo).reduce((a, b) => a + b, 0);
        actualizarBadge('badge-todos', totalEventos);
        actualizarBadge('badge-creaciones', conteo.creacion || 0);
        actualizarBadge('badge-eliminaciones', conteo.eliminacion || 0);
        actualizarBadge('badge-usuarios', conteo.usuario || 0);
        actualizarBadge('badge-bloqueos', conteo.bloqueo || 0);
        actualizarBadge('badge-ediciones', conteo.edicion || 0);

        const eventos = data.eventos || [];
        if (!eventos.length) {
            lista.innerHTML = '<div class="historial-empty">📭 No hay eventos en este filtro todavía.</div>';
            return;
        }
        const iconos = { creacion:'✨', eliminacion:'🗑️', usuario:'👤', bloqueo:'🔒', edicion:'✏️', movimiento:'🔄' };
        lista.innerHTML = eventos.map(ev => {
            const desc = ev.descripcion || 'Sin descripción';
            let tipo = ev.tipo || 'edicion';
            if (desc.toLowerCase().includes('movido manualmente')) tipo = 'movimiento';
            const icono = iconos[tipo] || '📌';
            let fecha = '—';
            if (ev.creado_en) {
                const d = new Date(ev.creado_en);
                if (!isNaN(d.getTime())) {
                    fecha = d.toLocaleString('es-MX', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
                }
            }
            const usuario = ev.usuario_nombre ? escapeHtml(ev.usuario_nombre) : 'Sistema';
            return `<div class="historial-item ${tipo}"><div class="historial-icon">${icono}</div><div class="historial-content"><div class="historial-desc">${escapeHtml(desc)}</div><div class="historial-meta"><span>🕐 ${fecha}</span><span>👤 <b>${usuario}</b></span></div></div></div>`;
        }).join('');
    } catch (e) {
        lista.innerHTML = `<div class="historial-empty">❌ Error: ${escapeHtml(e.message)}</div>`;
    }
}

function actualizarBadge(id, valor) {
    const el = document.getElementById(id);
    if (el) el.textContent = valor;
}

let RESPONSABLE_ID_ACTIVIDAD = null;
const responsablesAgregados = [];

function abrirModalAgregarResponsable(idActividad) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes agregar responsables ahora.'); return; }
    RESPONSABLE_ID_ACTIVIDAD = idActividad;
    document.getElementById('respEmail').value = '';
    document.getElementById('respRol').value = '';
    document.getElementById('respStatus').className = 'send-status';
    document.getElementById('respStatus').textContent = '';
    document.getElementById('btnAgregarResponsable').disabled = true;
    document.getElementById('respList').innerHTML = '';
    responsablesAgregados.length = 0;
    document.getElementById('modalAgregarResponsable').classList.remove('hidden');
    setTimeout(() => document.getElementById('respEmail').focus(), 100);
}

function cerrarModalAgregarResponsable() {
    document.getElementById('modalAgregarResponsable').classList.add('hidden');
    RESPONSABLE_ID_ACTIVIDAD = null;
}

function validarFormResponsable() {
    const email = document.getElementById('respEmail').value.trim();
    const rol = document.getElementById('respRol').value;
    document.getElementById('btnAgregarResponsable').disabled = !(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && rol);
}

async function agregarResponsable() {
    const email = document.getElementById('respEmail').value.trim();
    const rol = document.getElementById('respRol').value;
    const btn = document.getElementById('btnAgregarResponsable');
    const status = document.getElementById('respStatus');
    if (!email || !rol || !RESPONSABLE_ID_ACTIVIDAD) return;
    btn.disabled = true; btn.textContent = '⏳ Agregando...';
    status.className = 'send-status'; status.textContent = '';
    try {
        const res = await fetch('api/assign_responsable.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: RESPONSABLE_ID_ACTIVIDAD, id_proyecto: PROYECTO_ID, correo: email, rol })
        });
        const json = await res.json();
        if (json.error) {
            status.className = 'send-status error';
            status.textContent = '❌ ' + json.error;
        } else {
            status.className = 'send-status ok';
            status.textContent = '✅ ' + (json.mensaje || 'Responsable agregado: ' + email);
            responsablesAgregados.push({ email, rol });
            renderResponsablesAgregados();
            document.getElementById('respEmail').value = '';
            document.getElementById('respRol').value = '';
            validarFormResponsable();
            setTimeout(() => {
                const idGuardado = RESPONSABLE_ID_ACTIVIDAD;
                cerrarModalAgregarResponsable();
                abrirDetalleActividad(idGuardado);
            }, 1200);
        }
    } catch (e) {
        status.className = 'send-status error';
        status.textContent = '❌ Error: ' + e.message;
    }
    btn.disabled = false; btn.textContent = '✉️ Agregar responsable';
}

function renderResponsablesAgregados() {
    const cont = document.getElementById('respList');
    if (!responsablesAgregados.length) { cont.innerHTML = ''; return; }
    cont.innerHTML = responsablesAgregados.map(i => `<div class="invite-item">👤 <b>${escapeHtml(i.email)}</b><span class="invite-item-rol">${i.rol}</span></div>`).join('');
}

function mostrarMensaje(tipo, titulo, texto, callback) {
    CALLBACK_MENSAJE = callback || null;
    document.getElementById('mensajeIcon').textContent = tipo === 'ok' ? '✅' : (tipo === 'error' ? '❌' : 'ℹ️');
    document.getElementById('mensajeTitulo').textContent = titulo;
    document.getElementById('mensajeTexto').textContent = texto;
    document.getElementById('modalMensaje').classList.remove('hidden');
}

function cerrarModalMensaje() {
    document.getElementById('modalMensaje').classList.add('hidden');
    if (CALLBACK_MENSAJE) {
        const cb = CALLBACK_MENSAJE;
        CALLBACK_MENSAJE = null;
        cb();
    }
}

function toggleCargarMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('cargarMenu');
    const btn = document.getElementById('btnCargar');
    if (!menu) return;
    menu.classList.toggle('open');
    btn.classList.toggle('open');
}

document.addEventListener('click', () => {
    const menu = document.getElementById('cargarMenu');
    const btn = document.getElementById('btnCargar');
    if (menu) menu.classList.remove('open');
    if (btn) btn.classList.remove('open');
});

function cargarArchivo(tipo) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = tipo === 'imagen' ? 'image/*' : '*/*';
    input.onchange = () => subirArchivo(input.files[0], tipo);
    input.click();
}

async function subirArchivo(file, tipo) {
    if (!file) return;
    const fd = new FormData();
    fd.append('id_actividad', ACTIVIDAD_ID_ACTUAL);
    fd.append('tipo', tipo);
    fd.append('archivo', file);
    try {
        const res = await fetch('api/upload_attachment.php', { method:'POST', body: fd });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else abrirDetalleActividad(ACTIVIDAD_ID_ACTUAL);
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
    }
}

function cargarEnlace() {
    const modal = document.getElementById('modalEnlace');
    if (!modal) {
        const html = `<div id="modalEnlace" class="modal hidden" style="z-index:3500;"><div class="modal-content" style="max-width:500px;"><span class="close" onclick="document.getElementById('modalEnlace').classList.add('hidden')">×</span><h2>🔗 Pegar enlace</h2><input type="url" class="input" id="inputEnlace" placeholder="https://ejemplo.com"><div style="display:flex; gap:10px; margin-top:16px;"><button class="btn btn-outline" onclick="document.getElementById('modalEnlace').classList.add('hidden')" style="flex:1;">← Cancelar</button><button class="btn" onclick="guardarEnlace()" style="flex:1;">✓ Guardar</button></div></div></div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    }
    document.getElementById('modalEnlace').classList.remove('hidden');
    document.getElementById('inputEnlace').value = '';
    setTimeout(() => document.getElementById('inputEnlace').focus(), 100);
}

function guardarEnlace() {
    const url = document.getElementById('inputEnlace').value.trim();
    if (!url) { mostrarMensaje('error','⚠️ URL vacía','Escribe un enlace válido.'); return; }
    const fd = new FormData();
    fd.append('id_actividad', ACTIVIDAD_ID_ACTUAL);
    fd.append('tipo', 'enlace');
    fd.append('url', url);
    fetch('api/upload_attachment.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(json => {
            if (json.error) mostrarMensaje('error','❌ Error', json.error);
            else {
                document.getElementById('modalEnlace').classList.add('hidden');
                abrirDetalleActividad(ACTIVIDAD_ID_ACTUAL);
            }
        });
}

async function enviarComentario() {
    const ta = document.getElementById('nuevoComentario');
    if (!ta) return;
    const contenido = ta.value.trim();
    if (!contenido) return;
    const fd = new FormData();
    fd.append('id_actividad', ACTIVIDAD_ID_ACTUAL);
    fd.append('contenido', contenido);
    try {
        const res = await fetch('api/add_comment.php', { method:'POST', body: fd });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else abrirDetalleActividad(ACTIVIDAD_ID_ACTUAL);
    } catch (e) {
        mostrarMensaje('error','❌ Error', e.message);
    }
}

function eliminarActividad(id) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes eliminar actividades ahora.'); return; }
    mostrarConfirmacion('🗑️ Eliminar actividad','¿Eliminar esta actividad? Esta acción se puede deshacer desde el administrador.', async () => {
        const res = await fetch('api/delete_activity.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id_actividad: id, accion: 'eliminar' })
        });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else location.reload();
    });
}

function mostrarConfirmacion(titulo, texto, callback) {
    let modal = document.getElementById('modalConfirmar');
    if (!modal) {
        const html = `<div id="modalConfirmar" class="modal hidden" style="z-index:3500;"><div class="modal-content" style="max-width:480px; text-align:center;"><div style="font-size:40pt; margin-bottom:10px;">⚠️</div><h2 id="confirmarTitulo" style="color:var(--rtp-guinda); margin-bottom:10px;">Confirmar</h2><p id="confirmarTexto" style="color:var(--rtp-gris); font-size:11pt; margin-bottom:20px;"></p><div style="display:flex; gap:10px;"><button class="btn btn-outline" onclick="cerrarModalConfirmar()" style="flex:1;">← Cancelar</button><button class="btn" id="btnConfirmarSi" style="flex:1; background:#E5074C;">Sí, continuar</button></div></div></div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    }
    document.getElementById('confirmarTitulo').textContent = titulo;
    document.getElementById('confirmarTexto').textContent = texto;
    document.getElementById('modalConfirmar').classList.remove('hidden');
    document.getElementById('btnConfirmarSi').onclick = () => {
        cerrarModalConfirmar();
        callback();
    };
}

function cerrarModalConfirmar() {
    document.getElementById('modalConfirmar').classList.add('hidden');
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModalActividad();
        cerrarModalInvitar();
        cerrarModalPorcentajes();
        cerrarDetalleActividad();
        cerrarModalBloquear();
        cerrarModalDesbloquear();
        cerrarModalBloquearProyecto();
        cerrarModalDesbloquearProyecto();
        cerrarModalHistorial();
        cerrarModalAgregarResponsable();
        cerrarModalFecha();
        cerrarModalFechaActividad();
        cerrarModalMensaje();
        cerrarModalConfirmar();
    }
});

function cerrarAlClicFuera(idModal, fnCerrar) {
    const el = document.getElementById(idModal);
    if (!el) return;
    el.addEventListener('click', function (e) { if (e.target === this) fnCerrar(); });
}

cerrarAlClicFuera('modalActividad', cerrarModalActividad);
cerrarAlClicFuera('modalInvitar', cerrarModalInvitar);
cerrarAlClicFuera('modalPorcentajes', cerrarModalPorcentajes);
cerrarAlClicFuera('modalDetalleActividad', cerrarDetalleActividad);
cerrarAlClicFuera('modalBloquear', cerrarModalBloquear);
cerrarAlClicFuera('modalDesbloquear', cerrarModalDesbloquear);
cerrarAlClicFuera('modalBloquearProyecto', cerrarModalBloquearProyecto);
cerrarAlClicFuera('modalDesbloquearProyecto', cerrarModalDesbloquearProyecto);
cerrarAlClicFuera('modalHistorial', cerrarModalHistorial);
cerrarAlClicFuera('modalAgregarResponsable', cerrarModalAgregarResponsable);
cerrarAlClicFuera('modalFecha', cerrarModalFecha);
cerrarAlClicFuera('modalFechaActividad', cerrarModalFechaActividad);
</script>
</body>
</html>