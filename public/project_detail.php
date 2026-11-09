<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/project_state.php';
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
    'programado'  => [],
    'bloqueado'   => []
];
foreach ($actividades as $a) {
    if (isset($columnas[$a['estado']])) {
        $columnas[$a['estado']][] = $a;
    } else {
        $columnas['por_hacer'][] = $a;
    }
}

$total_porcentaje = 0;
foreach ($actividades as $a) {
    $total_porcentaje += (float)($a['porcentaje_aporte'] ?? 0);
}

$stmt = db()->prepare("
    SELECT m.*, u.nombre_completo, u.correo, u.rol
    FROM gestion_miembros_proyecto m
    JOIN gestion_usuarios u ON u.id_usuario = m.id_usuario
    WHERE m.id_proyecto = ? AND u.eliminado = FALSE AND u.activo = TRUE
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

$proyecto_operativo = !$proyecto_bloqueado && !$proyecto_programado && !$proyecto_archivado;
$col_manual = !empty($proyecto['columna_manual']) ? $proyecto['columna_manual'] : null;

if ($proyecto_archivado) {
    $etapa_codigo = 'archivado'; $etapa_label = 'Archivado'; $etapa_color = '#FDC60A';
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

$puede_cambiar_etapa = ($user['rol'] === 'administrador' || $user['rol'] === 'responsable') && $proyecto_operativo;

function iniciales($nombre) {
    if (!$nombre) return '?';
    $partes = preg_split('/\s+/', trim($nombre));
    if (count($partes) === 1) return strtoupper(substr($partes[0], 0, 2));
    return strtoupper(substr($partes[0], 0, 1) . substr($partes[count($partes) - 1], 0, 1));
}

function color_progreso($porcentaje) {
    if ($porcentaje >= 100) return '#027a35';
    if ($porcentaje >= 60)  return '#266cb4';
    if ($porcentaje >= 30)  return '#F08217';
    return '#94a3b8';
}

function renderTaskCard($a, $proyecto_bloqueado = false, $user_rol = 'visualizador', $id_proyecto_global = 0, $miembros_proyecto = []) {
    $estaBloqueada = ($a['estado'] === 'bloqueado');
    $estaProgramada = ($a['estado'] === 'programado');
    $clase = $estaBloqueada ? 'card-bloqueado' : ($estaProgramada ? 'card-programado' : '');

    $pct = (float)($a['porcentaje_aporte'] ?? 0);
    $pctVisible = min(100, max(0, $pct));
    $resto = 100 - $pctVisible;
    $color = color_progreso($pct);

    $responsables = $a['responsables'] ?? [];
    $total_resp = count($responsables);
    $primer_resp = $responsables[0]['nombre_completo'] ?? null;
    $primer_resp_id = $responsables[0]['id_usuario'] ?? null;
    $iniciales_resp = iniciales($primer_resp);

    $puede_arrastrar = false;
    if (!$proyecto_bloqueado) {
        if ($user_rol === 'administrador') {
            $puede_arrastrar = true;
        } elseif ($user_rol === 'responsable') {
            $estadosPermitidos = ['por_hacer', 'en_curso', 'en_revision'];
            $puede_arrastrar = in_array($a['estado'], $estadosPermitidos, true);
        }
    }

    $puede_agregar_responsable = ($user_rol === 'administrador' || $user_rol === 'responsable');
    $miembros_json = htmlspecialchars(json_encode($miembros_proyecto), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="project-card <?= $clase ?>"
         id="actividad-<?= (int)$a['id_actividad'] ?>"
         data-id="<?= (int)$a['id_actividad'] ?>"
         data-estado="<?= $a['estado'] ?>"
         data-name="<?= h(strtolower($a['titulo'] . ' ' . ($a['descripcion'] ?? ''))) ?>"
         <?= $puede_arrastrar ? 'draggable="false"' : '' ?>
         onclick="abrirDetalleActividad(<?= $a['id_actividad'] ?>)">

        <?php if ($puede_arrastrar): ?>
            <div class="drag-handle" data-drag="true" title="Arrastra para mover" onclick="event.stopPropagation();">
                <span class="bar bar-1"></span>
                <span class="bar bar-2"></span>
                <span class="bar bar-3"></span>
            </div>
        <?php endif; ?>

        <?php if ($estaBloqueada): ?>
            <span class="bloqueo-badge" title="Actividad bloqueada">🔒</span>
        <?php elseif ($estaProgramada): ?>
            <span class="bloqueo-badge badge-programado" title="Actividad programada">⏳</span>
        <?php endif; ?>

        <div class="project-card-body">
            <div class="project-card-pct-ring" title="<?= $pct ?>% del proyecto">
                <svg viewBox="0 0 36 36" width="42" height="42">
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="<?= $estaProgramada ? '#F08217' : $color ?>" stroke-width="3"
                            stroke-dasharray="<?= $pctVisible ?> <?= $resto ?>"
                            stroke-dashoffset="0"
                            stroke-linecap="round"/>
                </svg>
                <span class="project-card-pct-text" style="<?= $estaProgramada ? 'color:#F08217;' : '' ?>"><?= $pct ?>%</span>
            </div>

            <div class="project-card-title"><?= h($a['titulo']) ?></div>
        </div>

        <div class="project-card-dates">
            <?php if ($estaBloqueada): ?>
                <span class="priority-dot" style="background:#E5074C;"></span>
                <span style="color:#E5074C; font-weight:700;">
                    🔒 Motivo: <?= h(substr($a['motivo_bloqueo'] ?? 'Sin especificar', 0, 28)) ?>...
                </span>
            <?php elseif ($estaProgramada): ?>
                <span class="priority-dot" style="background:#F08217;"></span>
                <span style="color:#F08217; font-weight:700;">
                    ⏳ Se abre: <?= h(date('d/m/Y', strtotime($a['fecha_inicio'] ?? 'now'))) ?>
                </span>
            <?php else: ?>
                <?php if (!empty($a['fecha_inicio'])): ?>
                    <span class="date-inicio">
                        <span class="priority-dot" style="background:#94a3b8;"></span>
                        Inicio: <?= h(fmt_date($a['fecha_inicio'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($a['fecha_limite'])): ?>
                    <span class="date-fin">
                        <span class="priority-dot" style="background:<?= $color ?>;"></span>
                        Vence: <?= h(fmt_date($a['fecha_limite'])) ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="project-card-footer">
            <div class="project-card-responsables" id="resp-container-<?= (int)$a['id_actividad'] ?>">
                <?php if ($total_resp > 0 && $primer_resp && $primer_resp_id): ?>
                    <?php if ($total_resp === 1): ?>
                        <div class="project-card-avatar"
                             title="<?= h($primer_resp) ?> · Ver resumen"
                             onclick="event.stopPropagation(); abrirResumenUsuario(<?= (int)$primer_resp_id ?>, <?= (int)$id_proyecto_global ?>)">
                            <?= h($iniciales_resp) ?>
                        </div>
                    <?php else: ?>
                        <div class="project-card-avatar group-avatar"
                             title="Ver responsables (<?= $total_resp ?>)"
                             onclick="event.stopPropagation(); abrirModalEquipoActividad(<?= (int)$a['id_actividad'] ?>, '<?= h(addslashes($a['titulo'])) ?>')">
                            👥
                        </div>
                        <span class="group-count"><?= $total_resp ?></span>
                        <script>
                            if (!window.EQUIPOS_ACTIVIDADES) window.EQUIPOS_ACTIVIDADES = {};
                            window.EQUIPOS_ACTIVIDADES[<?= (int)$a['id_actividad'] ?>] = <?= json_encode($responsables) ?>;
                        </script>
                    <?php endif; ?>
                <?php elseif ($puede_agregar_responsable): ?>
                    <div class="sin-responsable-btn"
                         title="Añadir responsable"
                         onclick="event.stopPropagation(); abrirModalAñadirResponsableTarjeta(<?= (int)$a['id_actividad'] ?>, '<?= $miembros_json ?>')">
                        + Añadir
                    </div>
                <?php else: ?>
                    <div class="sin-responsable-txt" title="Sin asignar">?</div>
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
/* ============ ESTILOS GENERALES ============ */
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

.etapa-selector-wrap { position:relative; display:inline-block; }
.etapa-selector { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:999px; font-weight:700; font-size:13pt; color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.18); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; cursor:pointer; transition:all .2s ease; border:none; font-family:inherit; }
.etapa-selector .dot-badge { width:11px; height:11px; border-radius:50%; background:#fff; opacity:.85; flex-shrink:0; }
.etapa-selector .etapa-hover-hint { display:none; font-size:9pt; opacity:.9; margin-left:4px; }
.etapa-selector.editable:hover { background:#78BE20 !important; transform:translateY(-2px); box-shadow:0 8px 20px rgba(120,190,32,.45); }
.etapa-selector.editable:hover .etapa-hover-hint { display:inline; }

.etapa-menu { position:absolute; top:calc(100% + 8px); right:0; background:#fff; border-radius:12px; box-shadow:0 12px 34px rgba(0,0,0,.18); padding:6px; min-width:200px; z-index:650; display:none; border-top:3px solid var(--rtp-verde); animation:slideDownPrio .15s ease; }
.etapa-menu.open { display:block; }
.etapa-opcion { display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:8px; cursor:pointer; font-size:10.5pt; font-weight:600; color:var(--rtp-gris); transition:all .15s ease; user-select:none; }
.etapa-opcion .opcion-dot { width:11px; height:11px; border-radius:50%; flex-shrink:0; }
.etapa-opcion .check { margin-left:auto; opacity:0; font-weight:700; }
.etapa-opcion.activa .check { opacity:1; }
.etapa-opcion:hover { background:#ecf9d9; color:var(--rtp-verde-dark); }

.badge-etapa { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:999px; font-weight:700; font-size:13pt; color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.18); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
.badge-etapa .dot-badge { width:11px; height:11px; border-radius:50%; background:#fff; opacity:.85; flex-shrink:0; }
.project-header-status { display:flex; flex-direction:column; align-items:flex-end; gap:16px; flex-shrink:0; }

.project-header-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; flex-shrink:0; }
.project-header-actions .btn-outline { background:var(--rtp-guinda) !important; color:#fff !important; border:2px solid #7a1a33 !important; transition:all .2s ease; }
.project-header-actions .btn-outline:hover { background:var(--rtp-verde) !important; border-color:var(--rtp-verde) !important; color:#fff !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }
.project-header-actions .btn-historial { background:var(--rtp-guinda) !important; color:#fff !important; border:none !important; }
.project-header-actions .btn-historial:hover { background:#7a1a33 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }
.project-header-actions .btn-invitar { background:var(--rtp-guinda) !important; color:#fff !important; border:none !important; }
.project-header-actions .btn-invitar:hover { background:#7a1a33 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35),0 2px 6px rgba(120,190,32,.2); }

.banner-bloqueado { background:linear-gradient(135deg,#fde8ec 0%,#fbd5de 100%); border-radius:12px; padding:16px 22px; margin-bottom:20px; border-left:5px solid #E5074C; display:flex; align-items:center; gap:16px; box-shadow:0 2px 8px rgba(229,7,76,.15); }
.banner-bloqueado .icono { font-size:28pt; flex-shrink:0; }
.banner-bloqueado .texto { flex:1; color:#991b1b; }
.banner-bloqueado .texto b { display:block; color:#E5074C; font-size:13pt; margin-bottom:4px; }
.banner-bloqueado .texto small { font-size:10pt; color:#991b1b; opacity:.85; }
.banner-bloqueado .banner-action { flex-shrink:0; background:#FF0033; color:#fff; border:none; padding:12px 20px; border-radius:8px; font-family:inherit; font-weight:700; font-size:10.5pt; cursor:pointer; transition:all .2s ease; box-shadow:0 4px 12px rgba(255,0,51,.35); white-space:nowrap; }
.banner-bloqueado .banner-action:hover { background:#e0002d; transform:translateY(-2px); box-shadow:0 8px 20px rgba(255,0,51,.5); }

.banner-programado { background:linear-gradient(135deg,#fff7ed 0%,#ffedd5 100%); border-radius:12px; padding:18px 24px; margin-bottom:20px; border-left:5px solid #F08217; display:flex; align-items:center; gap:18px; box-shadow:0 2px 8px rgba(240,130,23,.15); }
.banner-programado .icono { font-size:32pt; flex-shrink:0; }
.banner-programado .texto { flex:1; color:#78350f; }
.banner-programado .texto b { display:block; color:#F08217; font-size:14pt; margin-bottom:6px; }
.banner-programado .texto small { font-size:11pt; color:#78350f; opacity:.9; line-height:1.6; display:block; }
.banner-programado .texto small strong { color:#9F2241; font-weight:700; }
.banner-programado .banner-action { flex-shrink:0; background:#F08217; color:#fff; border:none; padding:12px 20px; border-radius:8px; font-family:inherit; font-weight:700; font-size:10.5pt; cursor:pointer; transition:all .2s ease; box-shadow:0 4px 12px rgba(240,130,23,.4); white-space:nowrap; }
.banner-programado .banner-action:hover { background:#d97412; transform:translateY(-2px); box-shadow:0 8px 20px rgba(240,130,23,.55); }

.banner-archivado { background: linear-gradient(135deg, #fffbe6 0%, #fff3bf 100%); border-radius: 12px; padding: 16px 22px; margin-bottom: 20px; border-left: 5px solid #FDC60A; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 8px rgba(253, 198, 10, .25); }
.banner-archivado .icono { font-size: 28pt; flex-shrink: 0; }
.banner-archivado .texto { flex: 1; color: #5c4500; }
.banner-archivado .texto b { display: block; color: #b58900; font-size: 13pt; margin-bottom: 4px; }
.banner-archivado .texto small { font-size: 10pt; color: #5c4500; opacity: .85; }
.banner-archivado .banner-action { flex-shrink: 0; background: #F08217; color: #fff; border: none; padding: 12px 20px; border-radius: 8px; font-family: inherit; font-weight: 700; font-size: 10.5pt; cursor: pointer; transition: all .2s ease; box-shadow: 0 4px 12px rgba(240, 130, 23, .4); white-space: nowrap; }
.banner-archivado .banner-action:hover { background: #d97412; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(240, 130, 23, .55); }

.total-bar { background:#fff; border-radius:12px; padding:16px 22px; box-shadow:0 2px 8px rgba(0,0,0,.05); border-left:5px solid <?= $total_porcentaje >= 100 ? '#027a35' : ($total_porcentaje >= 50 ? '#F08217' : '#94a3b8') ?>; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
.total-bar-info { display:flex; flex-direction:column; gap:4px; }
.total-bar-title { font-size:11pt; font-weight:700; color:var(--rtp-guinda); }
.total-bar-sub { font-size:10pt; color:var(--rtp-gris); }
.total-bar-btn { background:var(--rtp-guinda); color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; transition:all .2s ease; font-family:inherit; }
.total-bar-btn:hover { background:#7a1a33; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.35); }

.info-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
.info-bar .spacer { flex:1; }
.info-bar .btn { transition:all .2s ease; }

.btn-nueva-actividad { background:#78BE20 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-nueva-actividad:hover { background:#5fa015 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(120,190,32,.4); }
.btn-archivar { background:#FDC60A !important; color:#3a2f00 !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-archivar:hover { background:#e5b100 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(253,198,10,.45); }
.btn-eliminar { background:#F08217 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-eliminar:hover { background:#d97412 !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(240,130,23,.45); }
.btn-bloquear { background:#FF0033 !important; color:#fff !important; border:none !important; padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-bloquear:hover { background:#e0002d !important; transform:translateY(-2px); box-shadow:0 6px 16px rgba(255,0,51,.5); }

.projects-search { margin-bottom:20px; }
.projects-search input { width:100%; max-width:400px; padding:12px 16px; border:1px solid var(--border); border-radius:10px; font-size:12pt; font-family:inherit; color:var(--rtp-gris); background:#fff; }
.projects-search input:focus { outline:none; border-color:var(--rtp-verde); box-shadow:0 0 0 3px rgba(120,190,32,.15); }

@keyframes destelloBusqueda { 0%{box-shadow:0 0 0 0 rgba(120,190,32,.8);transform:scale(1);} 50%{box-shadow:0 0 0 20px rgba(120,190,32,0),0 0 20px 5px rgba(120,190,32,.5);transform:scale(1.03);} 100%{box-shadow:0 0 0 0 rgba(120,190,32,0),0 0 0 0 rgba(120,190,32,0);transform:scale(1);} }
.card-destello { animation:destelloBusqueda 1s cubic-bezier(.22,1,.36,1) 3; border-color:var(--rtp-verde) !important; z-index:10; }

/* ============================================================
   TABLERO KANBAN
   ============================================================ */
.kanban-board {
    display: grid;
    grid-template-columns: repeat(6, 280px);
    gap: 16px;
    margin-bottom: 24px;
    align-items: stretch;
    overflow-x: auto;
    padding-bottom: 10px;
}
.kanban-col {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    height: 700px;
    display: flex;
    flex-direction: column;
    border: 1px solid transparent;
    transition: box-shadow .3s cubic-bezier(0.22, 1, 0.36, 1), background .3s ease, border-color .3s ease;
}
.kanban-col .board-cards-task {
    flex: 1;
    overflow-y: auto;
    padding-right: 2px;
}
.kanban-col .board-cards-task::-webkit-scrollbar { width: 5px; }
.kanban-col .board-cards-task::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }

.kanban-col[data-col="programado"],
.kanban-col[data-col="bloqueado"] {
    background: #fef2f4 !important;
    border: 1px dashed #fbc5d2 !important;
}

.kanban-col-header { display:flex; align-items:center; gap:8px; padding-bottom:12px; border-bottom:2px solid #e2e8f0; margin-bottom:12px; flex-shrink: 0; }
.kanban-col-header .dot { width:10px; height:10px; border-radius:50%; }
.kanban-col-header .title { font-weight:700; font-size:11pt; color:var(--rtp-guinda); flex:1; }
.kanban-col-header .count { background:#e2e8f0; color:#475569; padding:2px 10px; border-radius:999px; font-size:9.5pt; font-weight:700; }

/* ============================================================
   DISEÑO DE TARJETAS (igual que projects.php)
   ============================================================ */
.project-card {
    background: white;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid var(--rtp-verde);
    margin-bottom: 10px;
    transition: transform .25s cubic-bezier(0.22, 1, 0.36, 1), box-shadow .25s ease, border-color .25s ease, background .25s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    padding-left: 0;
    will-change: transform;
}
.project-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.2), 0 0 0 1px rgba(120, 190, 32, 0.35);
    border-color: var(--rtp-verde);
    background: #fbfff5;
}
.drag-handle {
    position: absolute; left: 0; top: 0; bottom: 0; width: 20px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 4px; cursor: grab; z-index: 3; user-select: none;
    padding: 0 0 0 4px; transition: padding-left .2s ease;
}
.drag-handle:hover { padding-left: 6px; }
.drag-handle:active { cursor: grabbing; }
.drag-handle .bar {
    display: block; width: 10px; height: 2px; background: #cbd5e1; border-radius: 2px;
    transition: background .2s ease, width .2s ease, transform .2s ease;
    transform-origin: left center;
}
.drag-handle:hover .bar { background: var(--rtp-verde); width: 12px; }
.drag-handle:hover .bar-1 { transition-delay: 0ms; transform: scaleX(1.15) translateX(-1px); }
.drag-handle:hover .bar-2 { transition-delay: 60ms; transform: scaleX(1.2) translateX(0); }
.drag-handle:hover .bar-3 { transition-delay: 120ms; transform: scaleX(1.15) translateX(-1px); }

.project-card.card-bloqueado {
    border-left-color: #E5074C;
    background: #fde8ec;
    border-color: #fbd5de;
    cursor: pointer;
}
.project-card.card-bloqueado:hover {
    box-shadow: 0 6px 16px rgba(229, 7, 76, 0.25);
    border-color: #E5074C;
    background: #fde8ec;
}
.project-card.card-programado { border-left-color: #F08217; background: #fff7ed; border-color: #fed7aa; }
.project-card.card-programado:hover { box-shadow: 0 6px 16px rgba(240, 130, 23, 0.25); border-color: #F08217; background: #fff7ed; }

.bloqueo-badge {
    position: absolute; top: 8px; right: 8px; font-size: 11pt;
    background: white; border-radius: 50%; width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 6px rgba(229, 7, 76, 0.3); z-index: 2;
}
.bloqueo-badge.badge-programado { box-shadow: 0 2px 6px rgba(240, 130, 23, 0.35); }

.project-card-body {
    padding: 12px 14px 10px 26px;
    position: relative;
    display: flex; align-items: center; gap: 12px;
}
.project-card.card-bloqueado .project-card-body,
.project-card.card-programado .project-card-body {
    padding-left: 14px;
}
.project-card-title {
    font-weight: 600; color: var(--rtp-negro); font-size: 10.5pt; line-height: 1.3;
    flex: 1; min-width: 0; padding-right: 30px;
}
.project-card.card-bloqueado .project-card-title,
.project-card.card-programado .project-card-title { padding-right: 36px; }
.project-card-pct-ring {
    position: relative; width: 42px; height: 42px;
    display: flex; align-items: center; justify-content: center;
    cursor: help; flex-shrink: 0;
}
.project-card-pct-ring svg { position: absolute; top: 0; left: 0; transform: rotate(-90deg); }
.project-card-pct-text {
    position: relative; font-size: 8pt; font-weight: 700; color: var(--rtp-verde-dark); z-index: 1;
}

/* FECHAS EN LA TARJETA (igual que projects.php) */
.project-card-dates {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 6px 14px 6px 26px;
    font-size: 8.5pt;
    border-top: 1px dashed #e2e8f0;
}
.project-card.card-bloqueado .project-card-dates,
.project-card.card-programado .project-card-dates {
    padding-left: 14px;
}
.project-card-dates .priority-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0;
}
.project-card-dates .date-inicio,
.project-card-dates .date-fin {
    display: inline-flex; align-items: center; gap: 4px;
    white-space: nowrap;
}
.project-card-dates .date-inicio { color: #64748b; font-weight: 600; }
.project-card-dates .date-fin { color: var(--rtp-gris); font-weight: 600; }

.project-card-footer {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    padding: 8px 14px 10px 26px; border-top: 1px dashed #e2e8f0; background: #fafbfc;
}
.project-card.card-bloqueado .project-card-footer,
.project-card.card-programado .project-card-footer {
    padding-left: 14px;
}
.priority-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0;
}
.project-card-responsables {
    display: flex; align-items: center; gap: 6px;
    padding: 2px 2px; max-width: 100%;
    flex-shrink: 1;
    overflow-x: auto; overflow-y: hidden;
    scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent;
}
.project-card-responsables::-webkit-scrollbar { height: 4px; }
.project-card-responsables::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }

.project-card-avatar {
    width: 28px; height: 28px; border-radius: 50%; background: var(--rtp-verde);
    color: white; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 8pt; border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,.12); flex-shrink: 0;
    transition: transform .2s cubic-bezier(0.34, 1.56, 0.64, 1);
    cursor: pointer;
}
.project-card-avatar:hover { transform: scale(1.15); z-index: 2; position: relative; }
.project-card.card-bloqueado .project-card-avatar { background: #E5074C; }
.project-card.card-programado .project-card-avatar { background: #F08217; }

.group-avatar {
    background: #e2e8f0 !important;
    color: #475569 !important;
    font-size: 12pt !important;
    border-color: #fff !important;
    box-shadow: 0 2px 6px rgba(0,0,0,.15) !important;
}
.group-avatar:hover {
    background: var(--rtp-verde) !important;
    color: #fff !important;
    transform: scale(1.1) !important;
}
.group-count {
    font-size: 8.5pt;
    font-weight: 700;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
}

.sin-responsable-btn {
    font-size: 8.5pt; font-weight: 700; color: var(--rtp-verde-dark);
    background: #ecf9d9; padding: 4px 10px; border-radius: 999px;
    border: 1.5px dashed var(--rtp-verde); cursor: pointer; white-space: nowrap;
    transition: all .2s ease; display: flex; align-items: center; justify-content: center;
}
.sin-responsable-btn:hover {
    background: var(--rtp-verde); color: #fff; transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(120,190,32,.3);
}
.sin-responsable-txt {
    color: #94a3b8; font-size: 8pt; font-weight: 700;
    background: #f1f5f9; width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; border: 2px solid #fff;
}

.project-card.hidden { display:none; }

.project-card.drag-ghost {
    position: fixed !important; pointer-events: none !important; z-index: 9999 !important;
    margin: 0 !important; opacity: 1; transform: scale(1.05) !important;
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.5), 0 24px 52px rgba(120, 190, 32, 0.5), 0 10px 24px rgba(0, 0, 0, 0.22) !important;
    border: 2px solid #78BE20 !important;
    background: #fbfff5 !important;
    cursor: grabbing;
}
.project-card.drag-origin { display: none; }

/* ============================================================
   BRILLO DE COLUMNA AL ARRASTRAR
   ============================================================ */
@keyframes columnaBrilloPulse {
    0%, 100% {
        box-shadow: 0 0 0 2px rgba(120, 190, 32, 0.25),
                    0 0 32px rgba(120, 190, 32, 0.35),
                    0 0 60px rgba(120, 190, 32, 0.15);
        border-color: rgba(120, 190, 32, 0.55);
    }
    50% {
        box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.35),
                    0 0 42px rgba(120, 190, 32, 0.5),
                    0 0 80px rgba(120, 190, 32, 0.25);
        border-color: rgba(120, 190, 32, 0.85);
    }
}

.kanban-col.drag-over-target-task {
    background: #fbfef5 !important;
    border: 1px solid rgba(120, 190, 32, 0.55) !important;
    animation: columnaBrilloPulse 1.6s ease-in-out infinite;
    z-index: 2;
}

.drag-placeholder-task {
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #ffffff 0%, #f4ffdf 35%, #e3f7c2 70%, #cdefa0 100%);
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.18), 0 4px 18px rgba(120, 190, 32, 0.25), inset 0 0 24px rgba(120, 190, 32, 0.12);
    position: relative; flex-shrink: 0; overflow: hidden;
    animation: placeholderPulseTask 1.8s ease-in-out infinite;
    transition: height .25s cubic-bezier(0.22, 1, 0.36, 1);
    margin-bottom: 10px;
}
.drag-placeholder-task::before {
    content: ''; position: absolute; inset: 0; border-radius: 10px;
    background: radial-gradient(circle at 30% 40%, rgba(120, 190, 32, 0.22) 0%, rgba(120, 190, 32, 0.08) 40%, transparent 75%);
    pointer-events: none; animation: placeholderGlowTask 2.4s ease-in-out infinite;
}
.drag-placeholder-task::after {
    content: ''; position: absolute; top: 0; left: -60%; width: 60%; height: 100%;
    background: linear-gradient(100deg, transparent 0%, rgba(255, 255, 255, 0.7) 45%, rgba(255, 255, 255, 0.95) 50%, rgba(255, 255, 255, 0.7) 55%, transparent 100%);
    transform: skewX(-20deg); animation: placeholderShimmerTask 2.8s ease-in-out infinite; pointer-events: none;
}
@keyframes placeholderPulseTask {
    0%, 100% { box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.18), 0 4px 18px rgba(120, 190, 32, 0.25), inset 0 0 24px rgba(120, 190, 32, 0.12); }
    50% { box-shadow: 0 0 0 6px rgba(120, 190, 32, 0.1), 0 6px 26px rgba(120, 190, 32, 0.4), inset 0 0 34px rgba(120, 190, 32, 0.22); }
}
@keyframes placeholderGlowTask { 0%, 100% { opacity: 0.8; } 50% { opacity: 1; } }
@keyframes placeholderShimmerTask { 0% { left: -60%; } 55% { left: 120%; } 100% { left: 120%; } }

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

#modalActividad .modal-content { max-width: 560px; border-top: 5px solid var(--rtp-verde); padding: 28px 32px 24px; border-radius: 16px; box-shadow: 0 25px 70px rgba(0,0,0,.25); background: #fff; max-height: 92vh; overflow-y: auto; }
#modalActividad h2 { color: var(--rtp-guinda); font-size: 16pt; margin: 0 0 4px 0; font-weight: 800; letter-spacing: -.3px; display: flex; align-items: center; gap: 10px; }
#modalActividad .subtitulo-modal { color: #64748b; font-size: 10.5pt; margin-bottom: 22px; line-height: 1.5; }
#modalActividad .form-section { background: #f8fafc; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; border-left: 4px solid var(--rtp-verde); }
#modalActividad .form-section-title { font-size: 10pt; font-weight: 700; color: var(--rtp-guinda); text-transform: uppercase; letter-spacing: .6px; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
#modalActividad .input, #modalActividad textarea.input { width: 100%; padding: 11px 14px; border: 1.5px solid #e2e8f0; border-radius: 9px; font-size: 11pt; font-family: inherit; color: var(--rtp-negro); background: #fff; transition: all .15s ease; box-sizing: border-box; }
#modalActividad .input:focus, #modalActividad textarea.input:focus { outline: none; border-color: var(--rtp-verde); box-shadow: 0 0 0 3px rgba(120,190,32,.15); }
#modalActividad .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
#modalActividad .form-label { display: block; font-size: 10.5pt; color: #475569; font-weight: 600; margin-bottom: 7px; }
#modalActividad .modal-acciones { display: flex; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #e2e8f0; }
#modalActividad .modal-acciones .btn { flex: 1; padding: 12px 18px; font-size: 11pt; font-weight: 700; border-radius: 10px; transition: all .2s ease; }
#modalActividad .btn-crear-actividad { background: var(--rtp-verde) !important; color: #fff !important; border: none !important; }
#modalActividad .btn-crear-actividad:hover { background: var(--rtp-verde-dark) !important; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(120,190,32,.4); }
#modalActividad .btn-regresar-actividad { background: #fff; color: var(--rtp-gris); border: 2px solid #e2e8f0; }
#modalActividad .btn-regresar-actividad:hover { border-color: var(--rtp-guinda); color: var(--rtp-guinda); }

.prioridad-picker-wrap { position: relative; }
.prioridad-picker-trigger { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 12px 16px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 9px; cursor: pointer; font-family: inherit; font-size: 11pt; font-weight: 600; color: var(--rtp-negro); transition: all .15s ease; box-sizing: border-box; }
.prioridad-picker-trigger:hover { border-color: var(--rtp-verde); background: #f8fef0; }
.prioridad-picker-trigger.open { border-color: var(--rtp-verde); box-shadow: 0 0 0 3px rgba(120,190,32,.15); }
.prioridad-picker-trigger .prioridad-trigger-left { display: flex; align-items: center; gap: 10px; }
.prioridad-picker-trigger .prioridad-trigger-arrow { color: #94a3b8; font-size: 9pt; transition: transform .2s ease; }
.prioridad-picker-trigger.open .prioridad-trigger-arrow { transform: rotate(180deg); }
.prioridad-picker-menu { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #fff; border-radius: 12px; box-shadow: 0 14px 40px rgba(0,0,0,.18), 0 0 0 1px rgba(0,0,0,.04); padding: 6px; z-index: 700; display: none; border-top: 3px solid var(--rtp-verde); animation: slideDownPrio .18s ease; }
.prioridad-picker-menu.open { display: block; }
.prioridad-picker-opcion { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 8px; cursor: pointer; font-size: 11pt; font-weight: 600; color: var(--rtp-gris); transition: all .15s ease; user-select: none; }
.prioridad-picker-opcion .opcion-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.prioridad-picker-opcion .check { margin-left: auto; opacity: 0; font-weight: 700; font-size: 12pt; }
.prioridad-picker-opcion.activa .check { opacity: 1; }
.prioridad-picker-opcion:hover { background: #ecf9d9; color: var(--rtp-verde-dark); }
.prioridad-picker-opcion[data-prio="baja"]:hover { background:#e6f4ea; color:#027a35; }
.prioridad-picker-opcion[data-prio="media"]:hover { background:#fef9e7; color:#8a6d00; }
.prioridad-picker-opcion[data-prio="alta"]:hover { background:#fff3e0; color:#9a3412; }
.prioridad-picker-opcion[data-prio="critica"]:hover { background:#fde8ec; color:#991b1b; }

/* ============================================================
   MODAL DE RESUMEN DE USUARIO (SCROLL CORREGIDO)
   ============================================================ */
#modalResumenUsuario {
    padding: 15px;
    align-items: center;
    justify-content: center;
}
#modalResumenUsuario .modal-content {
    max-width: 920px;
    width: 100%;
    max-height: 95vh;
    padding: 0;
    border-radius: 20px;
    border-top: none;
    display: block;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 0 25px 80px rgba(0,0,0,0.25);
    scrollbar-width: auto;
    scrollbar-color: #78BE20 #f1f5f9;
}
#modalResumenUsuario .modal-content::-webkit-scrollbar { width: 14px; }
#modalResumenUsuario .modal-content::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
#modalResumenUsuario .modal-content::-webkit-scrollbar-thumb { background: #78BE20; border-radius: 8px; border: 3px solid #f1f5f9; transition: background .2s ease; }
#modalResumenUsuario .modal-content::-webkit-scrollbar-thumb:hover { background: #5fa015; }

#modalResumenUsuario .close {
    z-index: 10;
    color: #fff;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    font-size: 28pt;
    position: absolute;
    top: 10px;
    right: 16px;
    opacity: 0.8;
}
#modalResumenUsuario .close:hover { opacity: 1; }

.resumen-hero {
    background: linear-gradient(135deg, var(--rtp-guinda) 0%, #5a0f24 100%);
    padding: 24px 36px 32px;
    color: #fff;
    position: relative;
}
.resumen-hero::after {
    content: '';
    position: absolute;
    bottom: -1px; left: 0; right: 0; height: 24px;
    background: #fff;
    border-radius: 24px 24px 0 0;
}
.resumen-hero-inner { display: flex; align-items: center; gap: 24px; position: relative; z-index: 2; }
.resumen-user-avatar-big {
    width: 80px; height: 80px; border-radius: 50%;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 24pt; color: var(--rtp-guinda);
    box-shadow: 0 6px 18px rgba(0,0,0,0.3);
    border: 3px solid rgba(255,255,255,0.5);
    flex-shrink: 0;
}
.resumen-user-meta { flex: 1; min-width: 0; }
.resumen-user-name-big {
    font-size: 18pt; font-weight: 800; margin-bottom: 4px; line-height: 1.2;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.resumen-user-email-big { font-size: 11pt; color: rgba(255,255,255,0.85); word-break: break-all; margin-bottom: 8px; }
.resumen-rol-badge {
    background: rgba(255,255,255,0.2);
    color: #fff;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 9pt;
    font-weight: 700;
    text-transform: capitalize;
}

.resumen-body { padding: 0 36px 24px; }

.resumen-dashboard {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 24px;
    align-items: center;
    margin-top: -10px;
    margin-bottom: 24px;
}
.resumen-progress-card {
    background: #f8fafc;
    border-radius: 14px;
    padding: 20px;
    display: flex; flex-direction: column; align-items: center;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.resumen-progress-ring-big { position: relative; width: 120px; height: 120px; display: flex; align-items: center; justify-content: center; }
.resumen-progress-ring-big svg { position: absolute; top: 0; left: 0; width: 120px; height: 120px; transform: rotate(-90deg); }
.resumen-progress-text-big { position: relative; font-size: 22pt; font-weight: 800; z-index: 1; }
.resumen-progress-label { font-size: 9pt; color: #64748b; text-transform: uppercase; font-weight: 700; margin-top: 10px; text-align: center; }

.resumen-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.resumen-stat-card {
    background: #fff;
    border-radius: 10px;
    padding: 16px 12px;
    text-align: center;
    border: 1px solid #e2e8f0;
    border-left-width: 4px;
    transition: all .2s ease;
    cursor: pointer;
    user-select: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
.resumen-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    border-color: #cbd5e1;
}
.resumen-stat-card.filtro-activo {
    background: #f8fafc;
    box-shadow: inset 0 0 0 2px var(--rtp-guinda);
    border-color: var(--rtp-guinda);
}
.resumen-stat-num { display: block; font-size: 18pt; font-weight: 800; line-height: 1; margin-bottom: 6px; }
.resumen-stat-lbl { display: block; font-size: 8.5pt; color: #64748b; text-transform: uppercase; font-weight: 700; }

.resumen-alertas-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}
.resumen-alertas-title {
    font-size: 11pt; font-weight: 800; color: var(--rtp-guinda);
    text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
}
.resumen-alerta-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: #fff7ed;
    border-radius: 8px;
    border-left: 4px solid #F08217;
    margin-bottom: 8px;
    font-size: 10.5pt;
}
.resumen-alerta-item.bloqueo { background: #fde8ec; border-left-color: #E5074C; }
.resumen-alerta-item.vencida { background: #fef2f2; border-left-color: #dc2626; }
.resumen-alerta-txt { flex: 1; color: var(--rtp-negro); font-weight: 600; line-height: 1.3; }
.resumen-alerta-txt span { display: block; font-size: 9pt; color: #64748b; font-weight: 400; margin-top: 2px; }
.resumen-alertas-vacio { text-align: center; color: #027a35; font-weight: 600; padding: 12px; font-size: 10.5pt; background: #f0faf1; border-radius: 8px; border: 1px dashed #a7f3d0; }

.resumen-actividades-section { margin-top: 6px; }
.resumen-actividades-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}
.resumen-actividades-header h3 {
    margin: 0; font-size: 11pt; font-weight: 800; color: var(--rtp-guinda);
    text-transform: uppercase;
}
.resumen-actividades-count {
    background: #ecf9d9; color: var(--rtp-verde-dark);
    padding: 4px 12px; border-radius: 999px; font-size: 9pt; font-weight: 700;
}
.btn-ver-todas {
    background: #f1f5f9; color: #475569; border: none; padding: 4px 12px; border-radius: 999px; font-size: 9pt; font-weight: 700; cursor: pointer; transition: all .15s;
}
.btn-ver-todas:hover { background: #e2e8f0; color: var(--rtp-guinda); }

.resumen-acts-list {
    display: flex; flex-direction: column; gap: 10px;
    padding-right: 4px;
}
.resumen-act-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-left-width: 4px;
    border-radius: 10px;
    transition: transform .15s ease, box-shadow .15s ease;
}
.resumen-act-item:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.resumen-act-info { flex: 1; min-width: 0; }
.resumen-act-title { font-size: 10.5pt; font-weight: 700; color: var(--rtp-negro); margin-bottom: 2px; }
.resumen-act-meta { font-size: 8.5pt; color: #64748b; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.resumen-act-pct {
    background: #ecf9d9; color: var(--rtp-verde-dark);
    padding: 4px 10px; border-radius: 999px;
    font-size: 9.5pt; font-weight: 800; white-space: nowrap;
}
.resumen-empty-acts {
    background: #f8fafc;
    padding: 30px 20px;
    border-radius: 10px;
    text-align: center;
    color: #94a3b8;
    font-style: italic;
    font-size: 10.5pt;
    border: 2px dashed #e2e8f0;
}
.resumen-empty-acts .icon { font-size: 26pt; display: block; margin-bottom: 10px; }
.resumen-footer { padding: 14px 36px; border-top: 1px solid #e2e8f0; background: #fff; }

@media (max-width: 800px) {
    .resumen-dashboard { grid-template-columns: 1fr; gap: 16px; margin-top: 0; }
    .resumen-progress-card { flex-direction: row; justify-content: center; gap: 16px; padding: 16px; }
    .resumen-progress-ring-big { width: 80px; height: 80px; }
    .resumen-progress-ring-big svg { width: 80px; height: 80px; }
    .resumen-progress-text-big { font-size: 16pt; }
    .resumen-hero { padding: 20px 24px 28px; }
    .resumen-body, .resumen-footer { padding-left: 20px; padding-right: 20px; }
    .resumen-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
}

#modalDragProgramado .modal-content {
    max-width: 540px;
    border-top: 5px solid #F08217;
    padding: 28px 30px 24px;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.28);
}
#modalDragProgramado h2 {
    color: #F08217;
    font-size: 16pt;
    margin: 0 0 6px 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}
#modalDragProgramado .drag-sub {
    color: #78350f;
    font-size: 10.5pt;
    line-height: 1.6;
    margin-bottom: 18px;
    background: #fff7ed;
    border-left: 4px solid #F08217;
    border-radius: 10px;
    padding: 14px 16px;
}
#modalDragProgramado .drag-sub b { color: #c2410c; }
#modalDragProgramado .drag-aviso {
    background: #fef3c7;
    color: #78350f;
    border-left: 4px solid #F08217;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 10pt;
    line-height: 1.5;
    margin-bottom: 16px;
}
#modalDragProgramado .drag-aviso b { color: #9a3412; }
#modalDragProgramado .drag-countdown-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px 18px;
    border-radius: 10px;
    border: none;
    font-family: inherit;
    font-size: 11pt;
    font-weight: 700;
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    transition: all .25s ease;
    margin-bottom: 14px;
}
#modalDragProgramado .drag-countdown-btn.ready {
    background: #F08217;
    color: #fff;
    cursor: pointer;
    box-shadow: 0 6px 16px rgba(240,130,23,.4);
}
#modalDragProgramado .drag-countdown-btn.ready:hover {
    background: #d97412;
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(240,130,23,.55);
}
#modalDragProgramado .drag-fecha-wrap {
    display: none;
    background: #f8fafc;
    border-left: 4px solid #F08217;
    border-radius: 10px;
    padding: 16px;
    margin-top: 4px;
}
#modalDragProgramado .drag-fecha-wrap.visible { display: block; animation: slideDownPrio .2s ease; }
#modalDragProgramado .drag-fecha-wrap label {
    display: block;
    font-size: 10.5pt;
    color: #475569;
    font-weight: 600;
    margin-bottom: 8px;
}
#modalDragProgramado .drag-fecha-wrap input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    font-size: 12pt;
    font-family: inherit;
    color: var(--rtp-negro);
    box-sizing: border-box;
}
#modalDragProgramado .drag-fecha-wrap input:focus {
    outline: none;
    border-color: #F08217;
    box-shadow: 0 0 0 3px rgba(240,130,23,.18);
}
#modalDragProgramado .drag-fecha-hint {
    font-size: 9.5pt;
    color: #94a3b8;
    margin-top: 6px;
    line-height: 1.4;
}
#modalDragProgramado .drag-acciones {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
}
#modalDragProgramado .drag-acciones .btn { flex: 1; padding: 12px 18px; font-size: 11pt; font-weight: 700; border-radius: 10px; }
#modalDragProgramado .btn-confirmar-programar {
    background: #F08217 !important;
    color: #fff !important;
    border: none !important;
}
#modalDragProgramado .btn-confirmar-programar:hover {
    background: #d97412 !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(240,130,23,.4);
}
#modalDragProgramado .btn-confirmar-programar:disabled {
    background: #f1f5f9 !important;
    color: #94a3b8 !important;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

#modalDragBloqueado .modal-content {
    max-width: 520px;
    border-top: 5px solid #E5074C;
    padding: 28px 30px 24px;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.28);
}
#modalDragBloqueado h2 {
    color: #E5074C;
    font-size: 16pt;
    margin: 0 0 8px 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}
#modalDragBloqueado .drag-sub {
    color: #991b1b;
    font-size: 10.5pt;
    line-height: 1.6;
    margin-bottom: 18px;
    background: #fde8ec;
    border-left: 4px solid #E5074C;
    border-radius: 10px;
    padding: 14px 16px;
}
#modalDragBloqueado .drag-sub b { color: #E5074C; }
#modalDragBloqueado .drag-motivo-wrap {
    display: none;
    margin-top: 6px;
}
#modalDragBloqueado .drag-motivo-wrap.visible { display: block; animation: slideDownPrio .2s ease; }
#modalDragBloqueado .drag-motivo-wrap label {
    display: block;
    font-size: 10.5pt;
    color: #475569;
    font-weight: 600;
    margin-bottom: 8px;
}
#modalDragBloqueado textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    font-size: 11pt;
    font-family: inherit;
    min-height: 90px;
    resize: vertical;
    box-sizing: border-box;
    color: var(--rtp-negro);
}
#modalDragBloqueado textarea:focus {
    outline: none;
    border-color: #E5074C;
    box-shadow: 0 0 0 3px rgba(229,7,76,.18);
}
#modalDragBloqueado .drag-motivo-error {
    display: none;
    background: #fde8ec;
    color: #991b1b;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 10pt;
    margin-top: 10px;
    border-left: 4px solid #E5074C;
}
#modalDragBloqueado .drag-motivo-error.visible { display: block; }
#modalDragBloqueado .drag-acciones {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
}
#modalDragBloqueado .drag-acciones .btn { flex: 1; padding: 12px 18px; font-size: 11pt; font-weight: 700; border-radius: 10px; }
#modalDragBloqueado .btn-si-bloquear {
    background: #E5074C !important;
    color: #fff !important;
    border: none !important;
}
#modalDragBloqueado .btn-si-bloquear:hover {
    background: #b8063c !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(229,7,76,.45);
}
#modalDragBloqueado .btn-confirmar-bloqueo {
    background: #E5074C !important;
    color: #fff !important;
    border: none !important;
    display: none;
}
#modalDragBloqueado .btn-confirmar-bloqueo.visible { display: inline-flex; align-items: center; justify-content: center; }
#modalDragBloqueado .btn-confirmar-bloqueo:hover {
    background: #b8063c !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(229,7,76,.45);
}

#modalAperturarActividad .modal-content {
    max-width: 520px;
    border-top: 5px solid #266cb4;
    padding: 28px 30px 24px;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.28);
}
#modalAperturarActividad h2 {
    color: #266cb4;
    font-size: 16pt;
    margin: 0 0 8px 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}
#modalAperturarActividad .apertura-sub {
    color: #1e3a5f;
    font-size: 10.5pt;
    line-height: 1.6;
    margin-bottom: 18px;
    background: #eef6fd;
    border-left: 4px solid #266cb4;
    border-radius: 10px;
    padding: 14px 16px;
}
#modalAperturarActividad .apertura-sub b { color: #266cb4; }
#modalAperturarActividad .apertura-countdown-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px 18px;
    border-radius: 10px;
    border: none;
    font-family: inherit;
    font-size: 11pt;
    font-weight: 700;
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    transition: all .25s ease;
    margin-bottom: 14px;
}
#modalAperturarActividad .apertura-countdown-btn.ready {
    background: #266cb4;
    color: #fff;
    cursor: pointer;
    box-shadow: 0 6px 16px rgba(38,108,180,.4);
}
#modalAperturarActividad .apertura-countdown-btn.ready:hover {
    background: #1f5a97;
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(38,108,180,.55);
}
#modalAperturarActividad .apertura-acciones {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
}
#modalAperturarActividad .apertura-acciones .btn { flex: 1; padding: 12px 18px; font-size: 11pt; font-weight: 700; border-radius: 10px; }
#modalAperturarActividad .btn-confirmar-aperturar {
    background: #266cb4 !important;
    color: #fff !important;
    border: none !important;
}
#modalAperturarActividad .btn-confirmar-aperturar:hover {
    background: #1f5a97 !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(38,108,180,.45);
}
#modalAperturarActividad .btn-confirmar-aperturar:disabled {
    background: #f1f5f9 !important;
    color: #94a3b8 !important;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.miembro-resp-item:hover {
    border-color: var(--rtp-verde) !important;
    background: #ecf9d9 !important;
}
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
                    <small>Este proyecto está archivado desde el <strong><?= h(fmt_date($proyecto['archivado_en'] ?? '')) ?></strong>. Está en modo solo lectura.</small>
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
                    <small>Motivo: <strong><?= h($proyecto['motivo_bloqueo'] ?? 'Sin especificar') ?></strong> · Bloqueado el <?= h(fmt_date($proyecto['bloqueado_en'])) ?></small>
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
                    <small>Este proyecto se abrirá automáticamente el <strong><?= h(date('d/m/Y', $fecha_apertura_ts)) ?></strong> a las <strong><?= h(date('H:i', $fecha_apertura_ts)) ?></strong>.</small>
                </div>
                <?php if ($user['rol'] === 'administrador'): ?>
                    <button class="banner-action" onclick="abrirModalModificarApertura()">📅 Modificar fecha</button>
                <?php endif; ?>
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
                        <button class="etapa-selector editable" style="background: <?= $etapa_color ?>;" onclick="toggleEtapaMenu(event)" title="Cambiar estado del proyecto">
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
                                ['codigo' => 'programado',  'label' => 'Programado',  'color' => '#F08217', 'icon' => '⏳'],
                                ['codigo' => 'finalizado',  'label' => 'Finalizado',  'color' => '#027a35', 'icon' => '✅'],
                            ];
                            foreach ($etapas_menu as $e):
                                $activa = ($etapa_codigo === $e['codigo']);
                            ?>
                                <div class="etapa-opcion <?= $activa ? 'activa' : '' ?>" data-etapa="<?= $e['codigo'] ?>" onclick="cambiarEtapaProyecto('<?= $e['codigo'] ?>', '<?= $e['label'] ?>', event)">
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
            <input type="text" id="buscadorActividades" placeholder="🔍 Buscar actividad..." oninput="filtrarActividades()" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarActividad();}">
        </div>

        <div class="kanban-board">
            <div class="kanban-col" data-col="por_hacer">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#94a3b8;"></span>
                    <span class="title">Por hacer</span>
                    <span class="count"><?= count($columnas['por_hacer']) ?></span>
                </div>
                <div class="board-cards-task" data-col="por_hacer" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['por_hacer'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
            </div>
            <div class="kanban-col" data-col="en_curso">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#266cb4;"></span>
                    <span class="title">En curso</span>
                    <span class="count"><?= count($columnas['en_curso']) ?></span>
                </div>
                <div class="board-cards-task" data-col="en_curso" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['en_curso'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
            </div>
            <div class="kanban-col" data-col="en_revision">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#FDC60A;"></span>
                    <span class="title">En revisión</span>
                    <span class="count"><?= count($columnas['en_revision']) ?></span>
                </div>
                <div class="board-cards-task" data-col="en_revision" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['en_revision'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
            </div>
            <div class="kanban-col" data-col="completada">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#027a35;"></span>
                    <span class="title">Completada</span>
                    <span class="count"><?= count($columnas['completada']) ?></span>
                </div>
                <div class="board-cards-task" data-col="completada" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['completada'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
            </div>
            <div class="kanban-col" data-col="programado">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#F08217;"></span>
                    <span class="title">Programado</span>
                    <span class="count"><?= count($columnas['programado']) ?></span>
                </div>
                <div class="board-cards-task" data-col="programado" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['programado'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
            </div>
            <div class="kanban-col" data-col="bloqueado">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#E5074C;"></span>
                    <span class="title">Bloqueado</span>
                    <span class="count"><?= count($columnas['bloqueado']) ?></span>
                </div>
                <div class="board-cards-task" data-col="bloqueado" style="display:flex; flex-direction:column; gap:10px; min-height:40px;">
                    <?php foreach ($columnas['bloqueado'] as $a): ?><?php renderTaskCard($a, $proyecto_bloqueado, $user['rol'], $id_proyecto, $miembros); ?><?php endforeach; ?>
                </div>
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
        <h2>✨ Nueva actividad</h2>
        <p class="subtitulo-modal">Completa los datos para registrar una nueva actividad en el proyecto.</p>
        <div class="form-section">
            <div class="form-section-title">📝 Información básica</div>
            <label class="form-label"><span class="form-label-icono">🏷️</span> Nombre de la actividad</label>
            <input type="text" class="input" id="actTitle" placeholder="Ej: Diseñar wireframes del módulo de pagos" maxlength="200" style="margin-bottom:12px;">
            <label class="form-label"><span class="form-label-icono">📄</span> Descripción (opcional)</label>
            <textarea class="input" id="actDesc" placeholder="Describe el alcance, entregables o criterios de aceptación..." style="min-height:70px;"></textarea>
        </div>
        <div class="form-section">
            <div class="form-section-title">📅 Fechas y Disponibilidad</div>
            <div style="display:flex; gap:10px; margin-bottom:12px; background:#e2e8f0; padding:4px; border-radius:9px;">
                <button type="button" id="btnDispAhora" onclick="cambiarDisponibilidad('ahora')" style="flex:1; padding:8px; border:none; border-radius:6px; font-weight:700; cursor:pointer; background:#fff; color:var(--rtp-verde-dark); box-shadow:0 1px 3px rgba(0,0,0,.1); transition:all .2s;">⚡ Disponible ahora</button>
                <button type="button" id="btnDispProgramar" onclick="cambiarDisponibilidad('programar')" style="flex:1; padding:8px; border:none; border-radius:6px; font-weight:700; cursor:pointer; background:transparent; color:#64748b; transition:all .2s;">⏳ Programar apertura</button>
            </div>
            <div id="contenedorFechaInicio" style="display:none; margin-bottom:12px;">
                <label class="form-label"><span class="form-label-icono">⏳</span> Fecha y hora de apertura</label>
                <input type="datetime-local" class="input" id="actFechaInicioProgramada">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label class="form-label"><span class="form-label-icono">🏁</span> Fecha límite</label>
                    <input type="date" class="input" id="actFecha">
                </div>
            </div>
        </div>
        <div class="form-section">
            <div class="form-section-title">🎯 Clasificación</div>
            <div class="form-row">
                <div>
                    <label class="form-label"><span class="form-label-icono">⚡</span> Prioridad</label>
                    <div class="prioridad-picker-wrap" id="prioridadPickerWrap">
                        <button type="button" class="prioridad-picker-trigger" id="prioridadPickerTrigger" onclick="togglePrioridadPicker(event)">
                            <span class="prioridad-trigger-left">
                                <span class="prioridad-dot" id="prioridadTriggerDot" style="background:#FDC60A; width:11px; height:11px; border-radius:50%; margin:0;"></span>
                                <span id="prioridadTriggerLabel">Media</span>
                            </span>
                            <span class="prioridad-trigger-arrow">▼</span>
                        </button>
                        <div class="prioridad-picker-menu" id="prioridadPickerMenu">
                            <div class="prioridad-picker-opcion" data-prio="baja" onclick="elegirPrioridadPicker('baja', event)"><span class="opcion-dot" style="background:#027a35;"></span><span>Baja</span><span class="check">✓</span></div>
                            <div class="prioridad-picker-opcion activa" data-prio="media" onclick="elegirPrioridadPicker('media', event)"><span class="opcion-dot" style="background:#FDC60A;"></span><span>Media</span><span class="check">✓</span></div>
                            <div class="prioridad-picker-opcion" data-prio="alta" onclick="elegirPrioridadPicker('alta', event)"><span class="opcion-dot" style="background:#F08217;"></span><span>Alta</span><span class="check">✓</span></div>
                            <div class="prioridad-picker-opcion" data-prio="critica" onclick="elegirPrioridadPicker('critica', event)"><span class="opcion-dot" style="background:#E5074C;"></span><span>Crítica</span><span class="check">✓</span></div>
                        </div>
                    </div>
                    <input type="hidden" id="actPrioridad" value="media">
                </div>
                <div>
                    <label class="form-label"><span class="form-label-icono">👤</span> Asignar a</label>
                    <select class="input" id="actAsignado">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($miembros as $m): ?>
                            <option value="<?= $m['id_usuario'] ?>"><?= h($m['nombre_completo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="form-section">
            <div class="form-section-title">📊 Aporte al proyecto</div>
            <div class="porcentaje-box" style="margin-bottom:0;">
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
        </div>
        <div class="modal-acciones">
            <button class="btn btn-regresar-actividad" onclick="cerrarModalActividad()">← Regresar</button>
            <button class="btn btn-crear-actividad" onclick="crearActividad()">✓ Crear actividad</button>
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
                        <span style="color:#94a3b8; font-size:9pt; display:block;"><?= h(status_label($a['estado'])) ?> · 📅 <?= h(fmt_date($a['fecha_limite'])) ?></span>
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

<!-- ============ MODAL: MODIFICAR FECHA DE APERTURA ============ -->
<div id="modalModificarApertura" class="modal hidden" style="z-index:3200;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalModificarApertura()">×</span>
        <h2 style="color:#F08217;">📅 Modificar apertura del proyecto</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:12px;">
            Este proyecto está programado para abrirse el
            <b><?= h(date('d/m/Y', $fecha_apertura_ts)) ?></b> a las
            <b><?= h(date('H:i', $fecha_apertura_ts)) ?></b>.
            ¿Qué deseas hacer?
        </p>
        <div id="aperturaVistaPrincipal">
            <div class="apertura-opciones">
                <button class="apertura-opcion" onclick="mostrarFormAperturaAhora()">
                    <span class="apertura-icon">⚡</span>
                    <div class="apertura-info">
                        <b>Aperturar ahora</b>
                        <small>El proyecto estará disponible inmediatamente. Podrás crear actividades y trabajar en él.</small>
                    </div>
                </button>
                <button class="apertura-opcion danger" onclick="mostrarFormModificarFecha()">
                    <span class="apertura-icon">📅</span>
                    <div class="apertura-info">
                        <b>Modificar fecha</b>
                        <small>Cambia la fecha y hora de apertura. Puede ser antes o después de la fecha actual.</small>
                    </div>
                </button>
            </div>
            <button class="btn btn-outline" onclick="cerrarModalModificarApertura()" style="width:100%;">← Cancelar</button>
        </div>
        <div id="aperturaVistaAhora" style="display:none;">
            <div class="modal-apertura-campo" style="border-left-color:#027a35; background:#f0faf1;">
                <div style="font-size:32pt; text-align:center; margin-bottom:8px;">⚡</div>
                <p style="font-size:11pt; color:#065f46; text-align:center; line-height:1.5; margin:0;">
                    <b>¿Aperturar el proyecto ahora mismo?</b><br>
                    El proyecto estará disponible inmediatamente para crear actividades, subir archivos y comentar.
                </p>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button class="btn btn-outline" onclick="volverVistaPrincipalApertura()" style="flex:1;">← Regresar</button>
                <button class="btn" id="btnAperturarAhora" onclick="aperturarAhoraProyecto()" style="flex:1; background:#027a35;">⚡ Aperturar ahora</button>
            </div>
        </div>
        <div id="aperturaVistaFecha" style="display:none;">
            <div class="modal-apertura-campo">
                <label for="inputNuevaApertura">Nueva fecha y hora de apertura:</label>
                <input type="datetime-local" id="inputNuevaApertura" value="<?= h(!empty($proyecto['fecha_apertura']) ? date('Y-m-d\TH:i', $fecha_apertura_ts) : '') ?>">
                <span class="hint">Puede ser una fecha futura (reprogramar) o una fecha en el pasado (apertura inmediata al guardar).</span>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button class="btn btn-outline" onclick="volverVistaPrincipalApertura()" style="flex:1;">← Regresar</button>
                <button class="btn" id="btnGuardarNuevaApertura" onclick="guardarNuevaFechaApertura()" style="flex:1; background:#F08217;">💾 Guardar nueva fecha</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ MODAL: AGREGAR RESPONSABLE ============ -->
<div id="modalAgregarResponsable" class="modal hidden" style="z-index:3200;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalAgregarResponsable()">×</span>
        <h2>👤 Agregar responsable</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:18px;">
            Selecciona un miembro del proyecto y el rol que tendrá en esta actividad.
        </p>

        <div id="respMiembrosLista" style="display:flex; flex-direction:column; gap:8px; max-height:240px; overflow-y:auto; margin-bottom:16px;">
            <div style="text-align:center; color:#94a3b8; padding:20px;">Cargando miembros...</div>
        </div>

        <div id="respRolWrap" style="display:none; margin-bottom:16px;">
            <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">
                Rol que tendrá en la actividad:
            </label>
            <select class="input" id="respRolActividad" onchange="validarFormResponsable()">
                <option value="">— Selecciona un rol —</option>
                <option value="responsable">Responsable</option>
                <option value="administrador">Administrador</option>
                <option value="visualizador">Visualizador</option>
            </select>
        </div>

        <button class="btn" id="btnAgregarResponsable" disabled onclick="agregarResponsable()" style="width:100%;">
            ✓ Asignar actividad
        </button>

        <div style="display:flex; align-items:center; gap:10px; margin:18px 0 12px;">
            <div style="flex:1; height:1px; background:#e2e8f0;"></div>
            <span style="font-size:9.5pt; color:#94a3b8; font-weight:600;">O</span>
            <div style="flex:1; height:1px; background:#e2e8f0;"></div>
        </div>

        <button class="btn btn-outline" id="btnToggleInvitarCorreo" onclick="toggleInvitarCorreo()" style="width:100%;">
            ✉️ Invitar por correo
        </button>

        <div id="invitarCorreoWrap" style="display:none; margin-top:14px; padding-top:14px; border-top:1px dashed #e2e8f0;">
            <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">
                Correo del usuario a invitar:
            </label>
            <input type="email" class="input" id="respEmail" placeholder="correo@ejemplo.com" oninput="validarFormInviteResponsable()" style="margin-bottom:10px;">
            <label style="display:block; margin-bottom:6px; font-size:10.5pt; color:var(--rtp-gris); font-weight:600;">
                Rol:
            </label>
            <select class="input" id="respRol" onchange="validarFormInviteResponsable()" style="margin-bottom:12px;">
                <option value="">— Selecciona un rol —</option>
                <option value="responsable">Responsable</option>
                <option value="administrador">Administrador</option>
                <option value="visualizador">Visualizador</option>
            </select>
            <button class="btn" id="btnEnviarInvitacionResponsable" disabled onclick="enviarInvitacionResponsable()" style="width:100%;">
                ✉️ Enviar invitación
            </button>
            <div class="send-status" id="respStatus" style="margin-top:10px;"></div>
        </div>

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

<!-- ============ MODAL: RESUMEN DE USUARIO EN EL PROYECTO ============ -->
<div id="modalResumenUsuario" class="modal hidden" style="z-index:3400;">
    <div class="modal-content">
        <span class="close" onclick="cerrarResumenUsuario()">×</span>
        <div id="resumenUsuarioBody" style="display:flex; flex-direction:column; height:100%;">
            <!-- El contenido se inyecta dinámicamente -->
        </div>
    </div>
</div>

<!-- ============ MODAL: AÑADIR RESPONSABLE DESDE TARJETA ============ -->
<div id="modalAñadirResponsableTarjeta" class="modal hidden" style="z-index:3500;">
    <div class="modal-content" style="max-width:480px;">
        <span class="close" onclick="cerrarModalAñadirResponsableTarjeta()">×</span>
        <h2 style="color:var(--rtp-guinda); margin-bottom:6px;">👤 Añadir responsable</h2>
        <p style="color:var(--rtp-gris); font-size:10.5pt; margin-bottom:20px;">Selecciona un miembro del proyecto para asignarlo a esta actividad.</p>
        <div id="listaMiembrosTarjeta" style="display:flex; flex-direction:column; gap:8px; max-height:300px; overflow-y:auto;"></div>
        <div style="display:flex; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <button class="btn btn-outline" onclick="cerrarModalAñadirResponsableTarjeta()" style="flex:1;">← Cancelar</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: EQUIPO DE LA ACTIVIDAD ============ -->
<div id="modalEquipoActividad" class="modal hidden" style="z-index:3600;">
    <div class="modal-content" style="max-width:420px; border-top:5px solid var(--rtp-verde); padding:20px 24px;">
        <span class="close" onclick="cerrarModalEquipoActividad()">×</span>
        <h2 style="color:var(--rtp-guinda); font-size:15pt; font-weight:800; margin-bottom:4px;">👥 Responsables</h2>
        <p style="color:#64748b; font-size:10.5pt; margin-bottom:16px;" id="equipoActSubtitulo">Actividad</p>
        <div id="equipoActLista" style="display:flex; flex-direction:column; gap:10px; max-height:350px; overflow-y:auto;"></div>
        <div style="margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalEquipoActividad()" style="width:100%; padding:10px; font-weight:600; background:#f8fafc; border:1px solid #e2e8f0; cursor:pointer; color:#475569; font-family:inherit;">← Cerrar</button>
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

<!-- ============ MODAL: DRAG → PROGRAMADO ============ -->
<div id="modalDragProgramado" class="modal hidden" style="z-index:4500;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalDragProgramado()">×</span>
        <h2>⏳ Programar actividad</h2>
        <div class="drag-sub">
            Esta actividad estaba <b>en proceso de realización</b>. Si se coloca como <b>Programado</b>, se pospondrá su apertura y la edición de la actividad hasta la nueva fecha que asignes.
        </div>
        <div class="drag-aviso">
            💡 <b>Nota:</b> La actividad seguirá visible en el tablero, pero no se podrá trabajar en ella hasta la fecha de apertura.
        </div>
        <button type="button" id="btnCountdownProgramar" class="drag-countdown-btn" disabled onclick="mostrarFechaProgramar()">
            <span id="countdownTexto">Espera 8s...</span>
        </button>
        <div class="drag-fecha-wrap" id="dragFechaWrap">
            <label for="inputFechaProgramar">📅 Nueva fecha y hora de apertura</label>
            <input type="datetime-local" id="inputFechaProgramar">
            <div class="drag-fecha-hint">
                Al guardar, la actividad se moverá a la columna <b>Programado</b> y quedará bloqueada para edición hasta esa fecha.
            </div>
        </div>
        <div class="drag-acciones">
            <button class="btn btn-outline" onclick="cerrarModalDragProgramado()">← Cancelar</button>
            <button class="btn btn-confirmar-programar" id="btnConfirmarProgramar" disabled onclick="guardarProgramacionDrag()">💾 Programar actividad</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DRAG → BLOQUEADO ============ -->
<div id="modalDragBloqueado" class="modal hidden" style="z-index:4500;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalDragBloqueado()">×</span>
        <h2>🔒 Bloquear actividad</h2>
        <div class="drag-sub">
            ¿Seguro que quieres <b>bloquear esta actividad</b>?<br>
            La actividad se moverá a la columna <b>Bloqueado</b> y no se podrá trabajar en ella hasta que la desbloquees.
        </div>
        <div class="drag-motivo-wrap" id="dragMotivoWrap">
            <label for="motivoDragBloqueo">Motivo del bloqueo:</label>
            <textarea id="motivoDragBloqueo" placeholder="Explica por qué se bloquea esta actividad..."></textarea>
            <div class="drag-motivo-error" id="dragMotivoError">⚠️ El motivo es obligatorio</div>
        </div>
        <div class="drag-acciones">
            <button class="btn btn-outline" onclick="cerrarModalDragBloqueado()">← Cancelar</button>
            <button class="btn btn-si-bloquear" id="btnSiBloquear" onclick="habilitarMotivoDragBloqueo()">Sí, bloquear</button>
            <button class="btn btn-confirmar-bloqueo" id="btnConfirmarBloqueoDrag" onclick="guardarBloqueoDrag()">🔒 Confirmar bloqueo</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: SALIR DE PROGRAMADO/BLOQUEADO → APERTURAR ============ -->
<div id="modalAperturarActividad" class="modal hidden" style="z-index:4600;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalAperturarActividad()">×</span>
        <h2>⚡ Aperturar actividad</h2>
        <div class="apertura-sub">
            ¿Deseas que <b>esta actividad se aperture ahora mismo</b>?<br>
            Se moverá a la columna <b id="aperturarDestinoLabel">—</b> y quedará disponible para trabajar en ella inmediatamente.
        </div>
        <button type="button" id="btnCountdownAperturar" class="apertura-countdown-btn" disabled onclick="confirmarAperturarActividad()">
            <span id="countdownAperturarTexto">Espera 5s...</span>
        </button>
        <div class="apertura-acciones">
            <button class="btn btn-outline" onclick="cerrarModalAperturarActividad()">← Cancelar</button>
            <button class="btn btn-confirmar-aperturar" id="btnConfirmarAperturar" disabled onclick="confirmarAperturarActividad()">✅ Sí, aperturar ahora mismo</button>
        </div>
    </div>
</div>

<script>
const PROYECTO_ID = <?= (int)$id_proyecto ?>;
const USER_ROL = '<?= h($user['rol']) ?>';
const PROYECTO_BLOQUEADO = <?= $proyecto_bloqueado ? 'true' : 'false' ?>;
const PROYECTO_ARCHIVADO = <?= $proyecto_archivado ? 'true' : 'false' ?>;
const PROYECTO_PROGRAMADO = <?= $proyecto_programado ? 'true' : 'false' ?>;
const PROYECTO_OPERATIVO = <?= $proyecto_operativo ? 'true' : 'false' ?>;
const PUEDE_REORDENAR = <?= (($user['rol'] === 'administrador' || $user['rol'] === 'responsable') && $proyecto_operativo) ? 'true' : 'false' ?>;
const invitaciones = [];

const MIEMBROS_PROYECTO = <?= json_encode(array_map(function($m){
    return [
        'id_usuario'      => (int)$m['id_usuario'],
        'nombre_completo' => $m['nombre_completo'],
        'correo'          => $m['correo'],
        'rol_miembro'     => $m['rol_miembro'] ?? null,
    ];
}, $miembros), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

let ACTIVIDAD_ID_ACTUAL = null;
let ID_BLOQUEAR = null;
let ID_DESBLOQUEAR = null;
let CALLBACK_MENSAJE = null;
let CAMPO_FECHA_ACTUAL = null;
let CAMPO_FECHA_ACTIVIDAD = null;
let ID_ACTIVIDAD_FECHA = null;
let MODO_DISPONIBILIDAD = 'ahora';
let ACTIVIDAD_ID_TARJETA_RESP = null;

let DRAG_ACTIVIDAD_PROGRAMAR = null;
let DRAG_ACTIVIDAD_BLOQUEAR = null;
let COUNTDOWN_INTERVAL = null;

let APERTURAR_ACTIVIDAD_ID = null;
let APERTURAR_COLUMNA_DESTINO = null;
let APERTURAR_ESTADO_ANTERIOR = null;
let COUNTDOWN_APERTURAR_INTERVAL = null;

const PRIO_COLORS = { baja:'#027a35', media:'#FDC60A', alta:'#F08217', critica:'#E5074C' };
const PRIO_LABELS = { baja:'Baja', media:'Media', alta:'Alta', critica:'Crítica' };
const ESTADO_COLORS = { por_hacer:'#94a3b8', en_curso:'#266cb4', en_revision:'#FDC60A', programado:'#F08217', completada:'#027a35', bloqueado:'#E5074C' };
const ESTADO_LABELS = { por_hacer:'Por hacer', en_curso:'En curso', en_revision:'En revisión', programado:'Programado', completada:'Completada', bloqueado:'Bloqueado' };
const ESTADO_ICONS  = { por_hacer:'📝', en_curso:'🔄', en_revision:'⏳', programado:'⏳', completada:'✅', bloqueado:'🔒' };

function cambiarDisponibilidad(modo) {
    MODO_DISPONIBILIDAD = modo;
    const btnAhora = document.getElementById('btnDispAhora');
    const btnProg = document.getElementById('btnDispProgramar');
    const contFecha = document.getElementById('contenedorFechaInicio');
    if (modo === 'ahora') {
        btnAhora.style.background = '#fff';
        btnAhora.style.color = 'var(--rtp-verde-dark)';
        btnAhora.style.boxShadow = '0 1px 3px rgba(0,0,0,.1)';
        btnProg.style.background = 'transparent';
        btnProg.style.color = '#64748b';
        btnProg.style.boxShadow = 'none';
        contFecha.style.display = 'none';
    } else {
        btnProg.style.background = '#fff';
        btnProg.style.color = '#c2410c';
        btnProg.style.boxShadow = '0 1px 3px rgba(0,0,0,.1)';
        btnAhora.style.background = 'transparent';
        btnAhora.style.color = '#64748b';
        btnAhora.style.boxShadow = 'none';
        contFecha.style.display = 'block';
    }
}

function togglePrioridadPicker(event) {
    if (event) event.stopPropagation();
    const trigger = document.getElementById('prioridadPickerTrigger');
    const menu = document.getElementById('prioridadPickerMenu');
    if (!trigger || !menu) return;
    trigger.classList.toggle('open');
    menu.classList.toggle('open');
}

function elegirPrioridadPicker(prio, event) {
    if (event) event.stopPropagation();
    const valorInput = document.getElementById('actPrioridad');
    const triggerDot = document.getElementById('prioridadTriggerDot');
    const triggerLabel = document.getElementById('prioridadTriggerLabel');
    const trigger = document.getElementById('prioridadPickerTrigger');
    const menu = document.getElementById('prioridadPickerMenu');
    if (!valorInput) return;
    valorInput.value = prio;
    triggerDot.style.background = PRIO_COLORS[prio];
    triggerLabel.textContent = PRIO_LABELS[prio];
    menu.querySelectorAll('.prioridad-picker-opcion').forEach(o => {
        if (o.dataset.prio === prio) { o.classList.add('activa'); o.querySelector('.check').style.opacity = '1'; }
        else { o.classList.remove('activa'); o.querySelector('.check').style.opacity = '0'; }
    });
    trigger.classList.remove('open');
    menu.classList.remove('open');
}

document.addEventListener('click', (e) => {
    const wrap = document.getElementById('prioridadPickerWrap');
    if (wrap && !wrap.contains(e.target)) {
        const trigger = document.getElementById('prioridadPickerTrigger');
        const menu = document.getElementById('prioridadPickerMenu');
        if (trigger) trigger.classList.remove('open');
        if (menu) menu.classList.remove('open');
    }
});

function resetPrioridadPicker() {
    const valorInput = document.getElementById('actPrioridad');
    const triggerDot = document.getElementById('prioridadTriggerDot');
    const triggerLabel = document.getElementById('prioridadTriggerLabel');
    const trigger = document.getElementById('prioridadPickerTrigger');
    const menu = document.getElementById('prioridadPickerMenu');
    if (!valorInput) return;
    valorInput.value = 'media';
    triggerDot.style.background = PRIO_COLORS.media;
    triggerLabel.textContent = PRIO_LABELS.media;
    menu.querySelectorAll('.prioridad-picker-opcion').forEach(o => {
        if (o.dataset.prio === 'media') { o.classList.add('activa'); o.querySelector('.check').style.opacity = '1'; }
        else { o.classList.remove('activa'); o.querySelector('.check').style.opacity = '0'; }
    });
    trigger.classList.remove('open');
    menu.classList.remove('open');
}

function abrirModalModificarApertura() {
    if (USER_ROL !== 'administrador') { mostrarMensaje('error','⛔ Sin permiso','Solo el administrador puede modificar la fecha de apertura.'); return; }
    document.getElementById('aperturaVistaPrincipal').style.display = 'block';
    document.getElementById('aperturaVistaAhora').style.display = 'none';
    document.getElementById('aperturaVistaFecha').style.display = 'none';
    document.getElementById('modalModificarApertura').classList.remove('hidden');
}

function cerrarModalModificarApertura() { document.getElementById('modalModificarApertura').classList.add('hidden'); }
function mostrarFormAperturaAhora() { document.getElementById('aperturaVistaPrincipal').style.display = 'none'; document.getElementById('aperturaVistaAhora').style.display = 'block'; document.getElementById('aperturaVistaFecha').style.display = 'none'; }
function mostrarFormModificarFecha() { document.getElementById('aperturaVistaPrincipal').style.display = 'none'; document.getElementById('aperturaVistaAhora').style.display = 'none'; document.getElementById('aperturaVistaFecha').style.display = 'block'; }
function volverVistaPrincipalApertura() { document.getElementById('aperturaVistaPrincipal').style.display = 'block'; document.getElementById('aperturaVistaAhora').style.display = 'none'; document.getElementById('aperturaVistaFecha').style.display = 'none'; }

async function aperturarAhoraProyecto() {
    const btn = document.getElementById('btnAperturarAhora');
    btn.disabled = true; btn.textContent = '⏳ Aperturando...';
    try {
        const res = await fetch('api/open_project_now.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_proyecto: PROYECTO_ID }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '⚡ Aperturar ahora'; return; }
        cerrarModalModificarApertura();
        mostrarMensaje('ok','✅ Proyecto aperturado','El proyecto ahora está disponible.', () => location.reload());
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '⚡ Aperturar ahora'; }
}

async function guardarNuevaFechaApertura() {
    const valor = document.getElementById('inputNuevaApertura').value;
    if (!valor) { mostrarMensaje('error','⚠️ Falta fecha','Selecciona una fecha y hora de apertura.'); return; }
    const fechaFormateada = valor.replace('T', ' ') + ':00';
    const btn = document.getElementById('btnGuardarNuevaApertura');
    btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const res = await fetch('api/update_project_opening.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_proyecto: PROYECTO_ID, fecha_apertura: fechaFormateada }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '💾 Guardar nueva fecha'; return; }
        cerrarModalModificarApertura();
        mostrarMensaje('ok','✅ Fecha de apertura actualizada','La nueva fecha se guardó correctamente.', () => location.reload());
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '💾 Guardar nueva fecha'; }
}

function mostrarToast(mensaje, icono = '✅') {
    const overlay = document.getElementById('toastOverlay');
    if (!overlay) return;

    overlay.style.position = 'fixed';
    overlay.style.top = '24px';
    overlay.style.left = '50%';
    overlay.style.transform = 'translateX(-50%)';
    overlay.style.zIndex = '99999';
    overlay.style.display = 'flex';
    overlay.style.justifyContent = 'center';
    overlay.style.pointerEvents = 'none';
    overlay.style.width = 'auto';
    overlay.style.height = 'auto';
    overlay.style.background = 'transparent';

    overlay.innerHTML = '';
    const toast = document.createElement('div');
    toast.className = 'toast-box';
    toast.style.display = 'flex';
    toast.style.alignItems = 'center';
    toast.style.gap = '10px';
    toast.style.background = '#fff';
    toast.style.color = 'var(--rtp-negro, #1e293b)';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '12px';
    toast.style.boxShadow = '0 10px 30px rgba(0,0,0,.15), 0 0 0 1px rgba(0,0,0,.04)';
    toast.style.borderLeft = '4px solid var(--rtp-verde, #78BE20)';
    toast.style.fontSize = '10.5pt';
    toast.style.fontWeight = '600';
    toast.style.fontFamily = 'inherit';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-12px)';
    toast.style.transition = 'opacity .3s ease, transform .3s ease';
    toast.style.pointerEvents = 'auto';
    toast.style.minWidth = '260px';
    toast.style.maxWidth = '90vw';
    toast.innerHTML = `<div class="toast-icon" style="font-size:16pt; flex-shrink:0;">${icono}</div><div class="toast-text" style="flex:1; line-height:1.4;">${escapeHtml(mensaje)}</div>`;
    overlay.appendChild(toast);

    void toast.offsetWidth;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-12px)';
        setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
    }, 3000);
}

window.toggleEtapaMenu = function(event) { if (event) event.stopPropagation(); const menu = document.getElementById('etapaMenu'); if (!menu) return; menu.classList.toggle('open'); };

window.cambiarEtapaProyecto = async function(codigo, label, event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('etapaMenu'); if (menu) menu.classList.remove('open');
    
    // Si selecciona "Programado", abrimos el modal de programación de proyecto
    if (codigo === 'programado') {
        abrirModalDragProgramadoProyecto();
        return;
    }

    try {
        const res = await fetch('api/update_project_status.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_proyecto: PROYECTO_ID, columna_manual: codigo }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error', '❌ Error', json.error); return; }
        const nombreProyecto = <?= json_encode($proyecto['nombre']) ?>;
        const msj = 'El proyecto "' + nombreProyecto + '" cambió su estado a: ' + label;
        try { sessionStorage.setItem('rtp_toast_pending', JSON.stringify({ mensaje: msj, icono: '✅' })); } catch (e) {}
        window.location.href = 'project_detail.php?id=' + PROYECTO_ID;
    } catch (err) { mostrarMensaje('error', '❌ Error', err.message); }
};

document.addEventListener('click', () => { const menu = document.getElementById('etapaMenu'); if (menu) menu.classList.remove('open'); });

window.filtrarActividades = function() {
    const query = document.getElementById('buscadorActividades').value.toLowerCase().trim();
    document.querySelectorAll('.project-card').forEach(card => {
        const name = card.getAttribute('data-name') || '';
        card.classList.toggle('hidden', !name.includes(query));
    });
};

window.buscarActividad = function() {
    const input = document.getElementById('buscadorActividades');
    const query = input.value.toLowerCase().trim();
    if (!query) return;
    const cards = document.querySelectorAll('.project-card');
    let encontrado = null;
    for (let card of cards) {
        const name = card.getAttribute('data-name') || '';
        if (name.includes(query)) { encontrado = card; break; }
    }
    if (encontrado) { input.value = ''; window.filtrarActividades(); encontrado.scrollIntoView({ behavior:'smooth', block:'center' }); encontrado.classList.remove('card-destello'); void encontrado.offsetWidth; encontrado.classList.add('card-destello'); setTimeout(() => encontrado.classList.remove('card-destello'), 3000); }
    else { input.value = ''; window.filtrarActividades(); }
};

function abrirModalFecha(campo, fechaActual) {
    CAMPO_FECHA_ACTUAL = campo;
    document.getElementById('tituloFecha').textContent = campo === 'fecha_inicio' ? '📅 Modificar fecha de inicio' : '🏁 Modificar fecha de fin';
    document.getElementById('inputFecha').value = fechaActual || '';
    const btn = document.getElementById('btnGuardarFecha'); btn.disabled = false; btn.textContent = '✓ Guardar';
    document.getElementById('modalFecha').classList.remove('hidden');
    setTimeout(() => document.getElementById('inputFecha').focus(), 100);
}

function cerrarModalFecha() { document.getElementById('modalFecha').classList.add('hidden'); CAMPO_FECHA_ACTUAL = null; }

async function guardarFecha() {
    if (!CAMPO_FECHA_ACTUAL) return;
    const fecha = document.getElementById('inputFecha').value;
    const btn = document.getElementById('btnGuardarFecha'); btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const res = await fetch('api/update_project_dates.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, campo: CAMPO_FECHA_ACTUAL, fecha: fecha || null }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '✓ Guardar'; }
        else { cerrarModalFecha(); document.getElementById('modalFecha').style.display = 'none'; mostrarToast('Fecha del proyecto actualizada correctamente.', '✅'); setTimeout(() => location.reload(), 1200); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '✓ Guardar'; }
}

function abrirModalFechaActividad(idActividad, campo, fechaActual) {
    ID_ACTIVIDAD_FECHA = idActividad; CAMPO_FECHA_ACTIVIDAD = campo;
    document.getElementById('tituloFechaActividad').textContent = campo === 'fecha_inicio' ? '📅 Modificar fecha de inicio' : '🏁 Modificar fecha límite';
    document.getElementById('inputFechaActividad').value = fechaActual || '';
    const btn = document.getElementById('btnGuardarFechaActividad'); btn.disabled = false; btn.textContent = '✓ Guardar';
    document.getElementById('modalFechaActividad').classList.remove('hidden');
    setTimeout(() => document.getElementById('inputFechaActividad').focus(), 100);
}

function cerrarModalFechaActividad() { document.getElementById('modalFechaActividad').classList.add('hidden'); CAMPO_FECHA_ACTIVIDAD = null; ID_ACTIVIDAD_FECHA = null; }

async function guardarFechaActividad() {
    if (!CAMPO_FECHA_ACTIVIDAD || !ID_ACTIVIDAD_FECHA) return;
    const fecha = document.getElementById('inputFechaActividad').value;
    const btn = document.getElementById('btnGuardarFechaActividad'); btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const res = await fetch('api/update_activity_dates.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: ID_ACTIVIDAD_FECHA, campo: CAMPO_FECHA_ACTIVIDAD, fecha: fecha || null }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '✓ Guardar'; }
        else { cerrarModalFechaActividad(); document.getElementById('modalFechaActividad').style.display = 'none'; mostrarToast('Fecha de la actividad actualizada correctamente.', '✅'); setTimeout(() => location.reload(), 1200); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '✓ Guardar'; }
}

function toggleEstadoMenu(e) { e.stopPropagation(); const menu = document.getElementById('estadoMenu'); if (!menu) return; menu.classList.toggle('open'); }

async function cambiarEstadoActividad(id, nuevoEstado, e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('estadoMenu'); if (menu) menu.classList.remove('open');
    try {
        const res = await fetch('api/update_activity_status.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: id, estado: nuevoEstado }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); return; }
        mostrarToast('Estado de la actividad actualizado.', '✅');
        setTimeout(() => location.reload(), 1200);
    } catch (err) { mostrarMensaje('error','❌ Error', err.message); }
}

document.addEventListener('click', () => { const menu = document.getElementById('estadoMenu'); if (menu) menu.classList.remove('open'); });

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
    document.getElementById('actFechaInicioProgramada').value = '';
    document.getElementById('actAsignado').value = '';
    document.getElementById('actPorcentaje').value = '0';
    document.getElementById('porcentajeBar').style.width = '0%';
    document.getElementById('porcentajeBar').style.background = '#78BE20';
    resetPrioridadPicker();
    cambiarDisponibilidad('ahora');
    document.getElementById('modalActividad').classList.remove('hidden');
    setTimeout(() => document.getElementById('actTitle').focus(), 100);
}

function cerrarModalActividad() { document.getElementById('modalActividad').classList.add('hidden'); }

async function crearActividad() {
    const titulo = document.getElementById('actTitle').value.trim();
    const descripcion = document.getElementById('actDesc').value.trim();
    const fecha_limite = document.getElementById('actFecha').value;
    const prioridad = document.getElementById('actPrioridad').value;
    const asignado = document.getElementById('actAsignado').value;
    const porcentaje = parseFloat(document.getElementById('actPorcentaje').value) || 0;
    let fecha_inicio = null; let estado = 'por_hacer';
    if (MODO_DISPONIBILIDAD === 'programar') {
        fecha_inicio = document.getElementById('actFechaInicioProgramada').value;
        estado = 'programado';
        if (!fecha_inicio) { mostrarMensaje('error','⚠️ Falta fecha','Selecciona la fecha y hora de apertura para la actividad programada.'); return; }
    }
    if (!titulo) { mostrarMensaje('error','⚠️ Falta nombre','Escribe un nombre para la actividad.'); return; }
    if (fecha_inicio && fecha_limite && fecha_inicio.split('T')[0] > fecha_limite) { mostrarMensaje('error','⚠️ Fechas inválidas','La fecha de apertura no puede ser posterior a la fecha límite.'); return; }
    if (porcentaje < 0 || porcentaje > 100) { mostrarMensaje('error','⚠️ Porcentaje inválido','El porcentaje debe estar entre 0 y 100.'); return; }
    const res = await fetch('api/create_activity.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, titulo, descripcion, fecha: fecha_limite, fecha_inicio: fecha_inicio, prioridad, asignado_a: asignado || null, porcentaje_aporte: porcentaje, estado: estado }) });
    const json = await res.json();
    if (json.error) mostrarMensaje('error','❌ Error', json.error);
    else location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('actPorcentaje');
    const bar = document.getElementById('porcentajeBar');
    if (input && bar) { input.addEventListener('input', () => { const pct = Math.min(100, Math.max(0, parseFloat(input.value) || 0)); bar.style.width = pct + '%'; bar.style.background = pct >= 100 ? '#027a35' : (pct >= 50 ? '#266cb4' : '#78BE20'); }); }
    actualizarTotalesModal();
});

function abrirModalPorcentajes() { if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes ajustar porcentajes ahora.'); return; } document.getElementById('modalPorcentajes').classList.remove('hidden'); actualizarTotalesModal(); }
function cerrarModalPorcentajes() { document.getElementById('modalPorcentajes').classList.add('hidden'); }

function actualizarTotalesModal() {
    let total = 0;
    document.querySelectorAll('.pct-input').forEach(i => total += parseFloat(i.value) || 0);
    const totalEl = document.getElementById('pctTotal');
    const summary = document.getElementById('pctSummary');
    const btn = document.getElementById('btnGuardarPorcentajes');
    if (totalEl) totalEl.textContent = total.toFixed(2);
    if (summary) { summary.classList.remove('ok','warn','error'); summary.classList.add(total === 100 ? 'ok' : (total < 100 ? 'warn' : 'error')); }
    if (btn) { btn.disabled = total > 100; btn.style.opacity = total > 100 ? '0.5' : '1'; btn.style.cursor = total > 100 ? 'not-allowed' : 'pointer'; }
}

document.addEventListener('input', (e) => { if (e.target.classList.contains('pct-input')) actualizarTotalesModal(); });

async function guardarPorcentajes() {
    const cambios = []; let total = 0;
    document.querySelectorAll('.pct-input').forEach(i => { const pct = parseFloat(i.value) || 0; total += pct; cambios.push({ id_actividad: i.dataset.id, porcentaje_aporte: pct }); });
    if (total > 100) { mostrarMensaje('error','⚠️ Excede 100%','La suma no puede exceder 100%. Actual: ' + total.toFixed(2) + '%'); return; }
    const btn = document.getElementById('btnGuardarPorcentajes'); btn.disabled = true; btn.textContent = '⏳ Guardando...';
    try {
        const res = await fetch('api/update_activity_percentages.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, cambios }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '✓ Guardar cambios'; }
        else { mostrarMensaje('ok','✅ Porcentajes actualizados','Los porcentajes se guardaron correctamente.', () => location.reload()); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '✓ Guardar cambios'; }
}

function archivarProyecto() {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes archivar un proyecto no operativo.'); return; }
    mostrarConfirmacion('📦 Archivar proyecto','¿Archivar este proyecto? Podrás encontrarlo después en "Proyectos archivados".', async () => {
        const res = await fetch('api/archive_project.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'archivar' }) });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto archivado','El proyecto fue archivado.', () => location.href = 'projects.php?archivados=1');
    });
}

function desarchivarProyecto() {
    if (!PROYECTO_ARCHIVADO) { mostrarMensaje('error','⏳','Este proyecto no está archivado.'); return; }
    mostrarConfirmacion('📂 Desarchivar proyecto','¿Desarchivar este proyecto? Volverá a la lista de proyectos activos.', async () => {
        const res = await fetch('api/unarchive_project.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID }) });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto desarchivado','El proyecto fue desarchivado y ya está activo.', () => location.href = 'projects.php');
    });
}

function eliminarProyecto() {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes eliminar un proyecto no operativo.'); return; }
    mostrarConfirmacion('🗑️ Eliminar proyecto','¿Eliminar este proyecto? Esta acción es definitiva y no se puede deshacer.', async () => {
        const res = await fetch('api/delete_project.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'eliminar' }) });
        const json = await res.json();
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else mostrarMensaje('ok','✅ Proyecto eliminado','El proyecto fue eliminado permanentemente.', () => location.href = 'projects.php');
    });
}

function abrirModalInvitar() { if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes invitar usuarios ahora.'); return; } document.getElementById('modalInvitar').classList.remove('hidden'); }
function cerrarModalInvitar() { document.getElementById('modalInvitar').classList.add('hidden'); }
function validarFormInvite() { const email = document.getElementById('inviteEmail').value.trim(); const rol = document.getElementById('inviteRol').value; const valido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && rol; document.getElementById('btnEnviarInvitacion').disabled = !valido; }
function copiarLink() { const input = document.getElementById('inviteLink'); input.select(); document.execCommand('copy'); mostrarMensaje('ok','✅ Link copiado','El link está en tu portapapeles.'); }

async function enviarInvitacion() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const btn = document.getElementById('btnEnviarInvitacion');
    const status = document.getElementById('sendStatus');
    if (!email || !rol) return;
    btn.disabled = true; btn.textContent = '⏳ Enviando...';
    status.className = 'send-status'; status.textContent = '';
    try {
        const res = await fetch('api/invite_user.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, correo: email, rol }) });
        const json = await res.json();
        if (json.error) { status.className = 'send-status error'; status.textContent = '❌ ' + json.error; }
        else { status.className = 'send-status ok'; status.textContent = '✅ Invitación enviada a ' + email; invitaciones.push({ email, rol }); renderInvitaciones(); document.getElementById('inviteEmail').value = ''; document.getElementById('inviteRol').value = ''; }
    } catch (e) { status.className = 'send-status error'; status.textContent = '❌ Error: ' + e.message; }
    btn.disabled = false; btn.textContent = '✉️ Enviar invitación';
}

function renderInvitaciones() { const cont = document.getElementById('inviteList'); if (!invitaciones.length) { cont.innerHTML = ''; return; } cont.innerHTML = invitaciones.map(i => `<div class="invite-item">✉️ <b>${escapeHtml(i.email)}</b><span class="invite-item-rol">${i.rol}</span></div>`).join(''); }

async function abrirDetalleActividad(id) {
    ACTIVIDAD_ID_ACTUAL = id;
    const modal = document.getElementById('modalDetalleActividad');
    const content = document.getElementById('actividadContent');
    content.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8;">Cargando...</div>';
    modal.classList.remove('hidden');
    try {
        const res = await fetch('api/activity_detail.php?id=' + id);
        const data = await res.json();
        if (data.error) { content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">' + data.error + '</div>'; return; }
        renderDetalleActividad(data);
    } catch (e) { content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">Error: ' + e.message + '</div>'; }
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
    } else { adjuntosHtml = '<span style="color:#94a3b8;font-size:10pt;font-style:italic;">Sin evidencias</span>'; }

    let comentariosHtml = '';
    if (comentarios.length) {
        comentarios.forEach(c => { comentariosHtml += `<div class="comentario-item"><div class="comentario-meta"><b>${escapeHtml(c.nombre_completo)}</b><span>·</span><span>${new Date(c.creado_en).toLocaleString('es-MX')}</span></div><div class="comentario-texto">${escapeHtml(c.contenido)}</div></div>`; });
    } else { comentariosHtml = '<span style="color:#94a3b8;font-size:10pt;font-style:italic;">Sin comentarios</span>'; }

    let avisoBloqueo = '';
    if (estaBloqueada) { avisoBloqueo = `<div class="actividad-bloqueo-aviso"><div class="bloqueo-icon">🔒</div><div class="bloqueo-text"><b>Actividad bloqueada</b>Motivo: ${escapeHtml(a.motivo_bloqueo || 'Sin motivo especificado')}</div></div>`; }
    else if (proyectoEstaArchivado) { avisoBloqueo = `<div class="actividad-bloqueo-aviso" style="background:#fffbe6; border-bottom-color:#FDC60A;"><div class="bloqueo-icon">📦</div><div class="bloqueo-text" style="color:#5c4500;"><b style="color:#b58900;">Proyecto archivado</b>El proyecto está archivado. No se puede modificar ni comentar hasta desarchivarlo.</div></div>`; }
    else if (proyectoEstaProgramado) { avisoBloqueo = `<div class="actividad-bloqueo-aviso" style="background:#fff7ed; border-bottom-color:#F08217;"><div class="bloqueo-icon">⏳</div><div class="bloqueo-text" style="color:#78350f;"><b style="color:#F08217;">Proyecto programado</b>Este proyecto aún no se ha abierto.</div></div>`; }
    else if (proyectoEstaBloqueado) { avisoBloqueo = `<div class="actividad-bloqueo-aviso"><div class="bloqueo-icon">🔒</div><div class="bloqueo-text"><b>Proyecto bloqueado</b>No se pueden subir archivos ni comentar hasta desbloquear el proyecto.</div></div>`; }

    let prioridadHtml;
    if (puedeEditarPrioridad) {
        prioridadHtml = `
            <div style="position:relative; display:inline-block;">
                <span class="prioridad-selector" id="prioridadSelector" onclick="togglePrioridadMenu(event)" title="Cambiar prioridad">
                    <span class="prioridad-default" style="color:${prioColor}; font-weight:600;"><span class="prioridad-dot" style="background:${prioColor};"></span> ${prioLabel}</span>
                    <span class="prioridad-hover">✏️ Cambiar prioridad</span>
                </span>
                <div class="prioridad-menu" id="prioridadMenu">
                    <div class="prioridad-opcion ${a.prioridad === 'baja' ? 'activa' : ''}" data-prio="baja" onclick="cambiarPrioridad('${a.id_actividad}', 'baja', event)"><span class="opcion-dot" style="background: ${PRIO_COLORS.baja};"></span><span>Baja</span><span class="check">✓</span></div>
                    <div class="prioridad-opcion ${a.prioridad === 'media' ? 'activa' : ''}" data-prio="media" onclick="cambiarPrioridad('${a.id_actividad}', 'media', event)"><span class="opcion-dot" style="background: ${PRIO_COLORS.media};"></span><span>Media</span><span class="check">✓</span></div>
                    <div class="prioridad-opcion ${a.prioridad === 'alta' ? 'activa' : ''}" data-prio="alta" onclick="cambiarPrioridad('${a.id_actividad}', 'alta', event)"><span class="opcion-dot" style="background: ${PRIO_COLORS.alta};"></span><span>Alta</span><span class="check">✓</span></div>
                    <div class="prioridad-opcion ${a.prioridad === 'critica' ? 'activa' : ''}" data-prio="critica" onclick="cambiarPrioridad('${a.id_actividad}', 'critica', event)"><span class="opcion-dot" style="background: ${PRIO_COLORS.critica};"></span><span>Crítica</span><span class="check">✓</span></div>
                </div>
            </div>
        `;
    } else { prioridadHtml = `<span style="color:${prioColor}; font-weight:600;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${prioColor};margin-right:4px;"></span>${prioLabel}</span>`; }

    const fechaInicioHtml = puedeEditarFechas ? `<span class="fecha-editable-actividad" onclick="abrirModalFechaActividad(${a.id_actividad}, 'fecha_inicio', '${a.fecha_inicio || ''}')" title="Modificar fecha de inicio"><span class="fecha-default">📅 ${formatearFecha(a.fecha_inicio)}</span><span class="fecha-hover">✏️ Modificar inicio</span></span>` : `<span>📅 ${formatearFecha(a.fecha_inicio)}</span>`;
    const fechaLimiteHtml = puedeEditarFechas ? `<span class="fecha-editable-actividad" onclick="abrirModalFechaActividad(${a.id_actividad}, 'fecha_limite', '${a.fecha_limite || ''}')" title="Modificar fecha límite"><span class="fecha-default">🏁 ${formatearFecha(a.fecha_limite)}</span><span class="fecha-hover">✏️ Modificar límite</span></span>` : `<span>🏁 ${formatearFecha(a.fecha_limite)}</span>`;

    let estadoHtml;
    if (puedeEditarEstado) {
        estadoHtml = `
            <div style="position:relative; display:inline-block;">
                <span class="estado-editable-actividad" onclick="toggleEstadoMenu(event)" title="Cambiar estado">
                    <span class="estado-default" style="color:${estadoColor}; font-weight:600;">${estadoIcon} ${estadoLabel}</span>
                    <span class="estado-hover">✏️ Cambiar estado</span>
                </span>
                <div class="estado-menu" id="estadoMenu">
                    <div class="estado-opcion ${a.estado === 'por_hacer' ? 'activa' : ''}" data-estado="por_hacer" onclick="cambiarEstadoActividad(${a.id_actividad}, 'por_hacer', event)"><span class="opcion-dot" style="background:${ESTADO_COLORS.por_hacer};"></span><span>📝 Por hacer</span><span class="check">✓</span></div>
                    <div class="estado-opcion ${a.estado === 'en_curso' ? 'activa' : ''}" data-estado="en_curso" onclick="cambiarEstadoActividad(${a.id_actividad}, 'en_curso', event)"><span class="opcion-dot" style="background:${ESTADO_COLORS.en_curso};"></span><span>🔄 En curso</span><span class="check">✓</span></div>
                    <div class="estado-opcion ${a.estado === 'en_revision' ? 'activa' : ''}" data-estado="en_revision" onclick="cambiarEstadoActividad(${a.id_actividad}, 'en_revision', event)"><span class="opcion-dot" style="background:${ESTADO_COLORS.en_revision};"></span><span>⏳ En revisión</span><span class="check">✓</span></div>
                    <div class="estado-opcion ${a.estado === 'programado' ? 'activa' : ''}" data-estado="programado" onclick="cambiarEstadoActividad(${a.id_actividad}, 'programado', event)"><span class="opcion-dot" style="background:${ESTADO_COLORS.programado};"></span><span>⏳ Programado</span><span class="check">✓</span></div>
                    <div class="estado-opcion ${a.estado === 'completada' ? 'activa' : ''}" data-estado="completada" onclick="cambiarEstadoActividad(${a.id_actividad}, 'completada', event)"><span class="opcion-dot" style="background:${ESTADO_COLORS.completada};"></span><span>✅ Completada</span><span class="check">✓</span></div>
                </div>
            </div>
        `;
    } else { estadoHtml = `<span style="color:${estadoColor}; font-weight:600;">${estadoIcon} ${estadoLabel}</span>`; }

    let html = `
        ${avisoBloqueo}
        <div class="actividad-header">
            <div class="actividad-pct-circle">
                <svg viewBox="0 0 80 80"><circle cx="40" cy="40" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="6"/><circle cx="40" cy="40" r="${radius}" fill="none" stroke="#78BE20" stroke-width="6" stroke-dasharray="${dashValue} ${dashResto}" stroke-dashoffset="0" stroke-linecap="round"/></svg>
                <span class="pct-value">${pct}%</span>
                <span class="pct-label">del proyecto</span>
            </div>
            <div class="actividad-header-info">
                <div class="actividad-header-title">${estaBloqueada ? '🔒 ' : ''}${escapeHtml(a.titulo)}</div>
                <div class="actividad-header-meta">
                    ${fechaInicioHtml}<span>·</span>${fechaLimiteHtml}<span>·</span>${prioridadHtml}<span>·</span>${estadoHtml}
                </div>
                <div class="actividad-header-responsables">
                    <span style="font-weight:600;color:var(--rtp-guinda);">Responsables:</span> ${responsablesHtml}
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
                            ${puedeSubir ? `<div class="btn-cargar-wrapper"><button class="btn-cargar" id="btnCargar" onclick="toggleCargarMenu(event)">📎 Cargar</button><div class="cargar-menu" id="cargarMenu"><button onclick="cargarArchivo('archivo')"><span class="menu-icon">📄</span> Subir archivo</button><button onclick="cargarArchivo('imagen')"><span class="menu-icon">🖼</span> Subir imagen</button><button onclick="cargarEnlace()"><span class="menu-icon">🔗</span> Pegar enlace</button></div></div>` : (estaBloqueada || proyectoEstaBloqueado || proyectoEstaProgramado || proyectoEstaArchivado ? `<div class="btn-cargar-disabled" title="No puedes subir archivos ahora">🔒 Cargar</div>` : '')}
                            <button class="btn-enviar" id="btnEnviarComentario" onclick="enviarComentario()" disabled>📤 Enviar</button>
                        </div>
                    </div>
                    ${estaBloqueada ? `<div class="comentario-aviso-bloqueo">💡 <b>Nota:</b> Mientras la actividad esté bloqueada, los archivos están deshabilitados.</div>` : ''}
                ` : `<div class="permiso-aviso">${proyectoEstaArchivado ? '📦 El proyecto está archivado. Desarchívalo para poder comentar y subir archivos.' : '⏳ El proyecto aún no está disponible. No puedes comentar ni subir archivos hasta que se abra.'}</div>`}
            </div>
        </div>
        <div class="actividad-footer">
            <div class="actividad-footer-left"><button class="btn-modal secondary" onclick="cerrarDetalleActividad()">← Cerrar</button></div>
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
        ta.addEventListener('input', () => { document.getElementById('btnEnviarComentario').disabled = ta.value.trim().length === 0; });
        ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviarComentario(); });
    }
}

function togglePrioridadMenu(e) { e.stopPropagation(); const menu = document.getElementById('prioridadMenu'); if (!menu) return; menu.classList.toggle('open'); }

async function cambiarPrioridad(id, nuevaPrio, e) {
    e.stopPropagation();
    const menu = document.getElementById('prioridadMenu'); if (menu) menu.classList.remove('open');
    try {
        const res = await fetch('api/update_activity_priority.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: id, prioridad: nuevaPrio }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); return; }
        mostrarToast('Nivel de prioridad actualizado.', '✅');
        setTimeout(() => location.reload(), 1200);
    } catch (err) { mostrarMensaje('error','❌ Error', err.message); }
}

document.addEventListener('click', () => { const menuPrio = document.getElementById('prioridadMenu'); if (menuPrio) menuPrio.classList.remove('open'); const menuEst = document.getElementById('estadoMenu'); if (menuEst) menuEst.classList.remove('open'); });

function cerrarDetalleActividad() { document.getElementById('modalDetalleActividad').classList.add('hidden'); ACTIVIDAD_ID_ACTUAL = null; }

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

function cerrarModalBloquear() { document.getElementById('modalBloquear').classList.add('hidden'); ID_BLOQUEAR = null; }

async function confirmarBloqueo() {
    const motivo = document.getElementById('motivoBloqueo').value.trim();
    if (!motivo) { document.getElementById('errorMotivo').style.display = 'block'; return; }
    const btn = document.getElementById('btnConfirmarBloqueo'); btn.disabled = true; btn.textContent = '⏳ Bloqueando...';
    try {
        const res = await fetch('api/block_activity.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: ID_BLOQUEAR, motivo }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '🔒 Bloquear'; }
        else { cerrarModalBloquear(); cerrarDetalleActividad(); mostrarMensaje('ok','✅ Actividad bloqueada','La actividad fue movida a "Bloqueado".', () => location.reload()); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '🔒 Bloquear'; }
}

function desbloquearActividad(id) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes desbloquear actividades ahora.'); return; }
    ID_DESBLOQUEAR = id;
    document.getElementById('nuevaFechaDesbloqueo').value = '';
    document.getElementById('modalDesbloquear').classList.remove('hidden');
}

function cerrarModalDesbloquear() { document.getElementById('modalDesbloquear').classList.add('hidden'); ID_DESBLOQUEAR = null; }

async function confirmarDesbloqueo() {
    const nuevaFecha = document.getElementById('nuevaFechaDesbloqueo').value;
    const btn = document.getElementById('btnConfirmarDesbloqueo'); btn.disabled = true; btn.textContent = '⏳ Desbloqueando...';
    try {
        const res = await fetch('api/unblock_activity.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: ID_DESBLOQUEAR, nueva_fecha: nuevaFecha || null }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '🔓 Desbloquear'; }
        else { cerrarModalDesbloquear(); cerrarDetalleActividad(); mostrarMensaje('ok','✅ Actividad desbloqueada','La actividad volvió a: ' + (json.estado_vuelve || 'su estado anterior'), () => location.reload()); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '🔓 Desbloquear'; }
}

function abrirModalBloquearProyecto() {
    document.getElementById('motivoBloqueoProyecto').value = '';
    document.getElementById('errorMotivoProyecto').style.display = 'none';
    document.getElementById('modalBloquearProyecto').classList.remove('hidden');
    setTimeout(() => document.getElementById('motivoBloqueoProyecto').focus(), 100);
}

function cerrarModalBloquearProyecto() { document.getElementById('modalBloquearProyecto').classList.add('hidden'); }

async function confirmarBloqueoProyecto() {
    const motivo = document.getElementById('motivoBloqueoProyecto').value.trim();
    if (!motivo) { document.getElementById('errorMotivoProyecto').style.display = 'block'; return; }
    const btn = document.getElementById('btnConfirmarBloqueoProyecto'); btn.disabled = true; btn.textContent = '⏳ Bloqueando...';
    try {
        const res = await fetch('api/block_project.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'bloquear', motivo }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '🔒 Bloquear proyecto'; }
        else { cerrarModalBloquearProyecto(); mostrarMensaje('ok','✅ Proyecto bloqueado','El proyecto fue bloqueado.', () => location.reload()); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '🔒 Bloquear proyecto'; }
}

function abrirModalDesbloquearProyecto() { document.getElementById('nuevaFechaDesbloqueoProyecto').value = '<?= h($proyecto['fecha_fin'] ?? '') ?>'; document.getElementById('modalDesbloquearProyecto').classList.remove('hidden'); }
function cerrarModalDesbloquearProyecto() { document.getElementById('modalDesbloquearProyecto').classList.add('hidden'); }

async function confirmarDesbloqueoProyecto() {
    const nuevaFecha = document.getElementById('nuevaFechaDesbloqueoProyecto').value;
    const fechaOriginal = '<?= h($proyecto['fecha_fin'] ?? '') ?>';
    const fechaFinal = (nuevaFecha && nuevaFecha !== fechaOriginal) ? nuevaFecha : null;
    const btn = document.getElementById('btnConfirmarDesbloqueoProyecto'); btn.disabled = true; btn.textContent = '⏳ Desbloqueando...';
    try {
        const res = await fetch('api/block_project.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'desbloquear', nueva_fecha: fechaFinal }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); btn.disabled = false; btn.textContent = '🔓 Desbloquear proyecto'; }
        else { cerrarModalDesbloquearProyecto(); mostrarMensaje('ok','✅ Proyecto desbloqueado', json.fecha_reajustada ? 'El proyecto fue desbloqueado y la fecha se actualizó.' : 'El proyecto fue desbloqueado.', () => location.reload()); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); btn.disabled = false; btn.textContent = '🔓 Desbloquear proyecto'; }
}

function abrirModalHistorial() {
    document.getElementById('modalHistorial').classList.remove('hidden');
    document.querySelectorAll('.historial-tab').forEach(t => t.classList.remove('activo'));
    document.querySelector('.historial-tab[data-filtro="todos"]').classList.add('activo');
    cargarHistorial('todos');
}

function cerrarModalHistorial() { document.getElementById('modalHistorial').classList.add('hidden'); }

async function cargarHistorial(filtro = 'todos', tabEl = null) {
    const lista = document.getElementById('historialList');
    lista.innerHTML = '<div class="historial-loading">Cargando historial...</div>';
    if (tabEl) { document.querySelectorAll('.historial-tab').forEach(t => t.classList.remove('activo')); tabEl.classList.add('activo'); }
    try {
        const res = await fetch(`api/project_history.php?id_proyecto=${PROYECTO_ID}&filtro=${filtro}`);
        const data = await res.json();
        if (data.error) { lista.innerHTML = `<div class="historial-empty">❌ ${escapeHtml(data.error)}</div>`; return; }
        const conteo = data.conteo || {};
        const totalEventos = Object.values(conteo).reduce((a, b) => a + b, 0);
        actualizarBadge('badge-todos', totalEventos);
        actualizarBadge('badge-creaciones', conteo.creacion || 0);
        actualizarBadge('badge-eliminaciones', conteo.eliminacion || 0);
        actualizarBadge('badge-usuarios', conteo.usuario || 0);
        actualizarBadge('badge-bloqueos', conteo.bloqueo || 0);
        actualizarBadge('badge-ediciones', conteo.edicion || 0);
        const eventos = data.eventos || [];
        if (!eventos.length) { lista.innerHTML = '<div class="historial-empty">📭 No hay eventos en este filtro todavía.</div>'; return; }
        const iconos = { creacion:'✨', eliminacion:'🗑️', usuario:'👤', bloqueo:'🔒', edicion:'✏️', movimiento:'🔄' };
        lista.innerHTML = eventos.map(ev => {
            const desc = ev.descripcion || 'Sin descripción';
            let tipo = ev.tipo || 'edicion';
            if (desc.toLowerCase().includes('movido manualmente')) tipo = 'movimiento';
            const icono = iconos[tipo] || '📌';
            let fecha = '—';
            if (ev.creado_en) { const d = new Date(ev.creado_en); if (!isNaN(d.getTime())) fecha = d.toLocaleString('es-MX', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }); }
            const usuario = ev.usuario_nombre ? escapeHtml(ev.usuario_nombre) : 'Sistema';
            return `<div class="historial-item ${tipo}"><div class="historial-icon">${icono}</div><div class="historial-content"><div class="historial-desc">${escapeHtml(desc)}</div><div class="historial-meta"><span>🕐 ${fecha}</span><span>👤 <b>${usuario}</b></span></div></div></div>`;
        }).join('');
    } catch (e) { lista.innerHTML = `<div class="historial-empty">❌ Error: ${escapeHtml(e.message)}</div>`; }
}

function actualizarBadge(id, valor) { const el = document.getElementById(id); if (el) el.textContent = valor; }

let RESPONSABLE_ID_ACTIVIDAD = null;
let RESPONSABLE_SELECCIONADO = null;

function abrirModalAgregarResponsable(idActividad) {
    if (!PROYECTO_OPERATIVO) {
        mostrarMensaje('error', '⏳', 'No puedes agregar responsables ahora.');
        return;
    }
    RESPONSABLE_ID_ACTIVIDAD = idActividad;
    RESPONSABLE_SELECCIONADO = null;

    document.getElementById('respRolWrap').style.display = 'none';
    document.getElementById('respRolActividad').value = '';
    document.getElementById('btnAgregarResponsable').disabled = true;
    document.getElementById('invitarCorreoWrap').style.display = 'none';
    document.getElementById('btnToggleInvitarCorreo').textContent = '✉️ Invitar por correo';
    document.getElementById('respEmail').value = '';
    document.getElementById('respRol').value = '';
    document.getElementById('respStatus').className = 'send-status';
    document.getElementById('respStatus').textContent = '';
    document.getElementById('btnEnviarInvitacionResponsable').disabled = true;

    renderMiembrosResponsable();

    document.getElementById('modalAgregarResponsable').classList.remove('hidden');
}

function cerrarModalAgregarResponsable() {
    document.getElementById('modalAgregarResponsable').classList.add('hidden');
    RESPONSABLE_ID_ACTIVIDAD = null;
    RESPONSABLE_SELECCIONADO = null;
}

function renderMiembrosResponsable() {
    const cont = document.getElementById('respMiembrosLista');
    const miembros = Array.isArray(MIEMBROS_PROYECTO) ? MIEMBROS_PROYECTO : [];

    if (!miembros.length) {
        cont.innerHTML = '<div style="text-align:center; color:#94a3b8; font-style:italic; padding:20px;">No hay miembros en este proyecto.</div>';
        return;
    }

    cont.innerHTML = '';
    miembros.forEach(m => {
        const ini = inicialesJS(m.nombre_completo);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'miembro-resp-item';
        btn.dataset.id = m.id_usuario;
        btn.style.cssText = [
            'display:flex','align-items:center','gap:12px','width:100%',
            'padding:10px 14px','background:#f8fafc','color:var(--rtp-negro)',
            'border:2px solid #e2e8f0','border-radius:10px','text-align:left',
            'cursor:pointer','transition:all .15s','font-family:inherit','font-weight:600'
        ].join(';');

        btn.innerHTML = `
            <span style="width:34px; height:34px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10pt; flex-shrink:0;">${ini}</span>
            <span style="flex:1; min-width:0;">
                <span style="display:block; font-size:10.5pt;">${escapeHtml(m.nombre_completo)}</span>
                <span style="display:block; font-size:9pt; color:#94a3b8; font-weight:400;">${escapeHtml(m.correo || '')}</span>
            </span>
            <span class="resp-check" style="font-size:14pt; color:var(--rtp-verde); opacity:0; transition:opacity .15s;">✓</span>
        `;

        btn.onclick = () => seleccionarMiembroResponsable(m, btn);
        cont.appendChild(btn);
    });
}

function seleccionarMiembroResponsable(miembro, btnEl) {
    RESPONSABLE_SELECCIONADO = miembro;

    document.querySelectorAll('.miembro-resp-item').forEach(el => {
        el.style.borderColor = '#e2e8f0';
        el.style.background = '#f8fafc';
        const chk = el.querySelector('.resp-check');
        if (chk) chk.style.opacity = '0';
    });

    btnEl.style.borderColor = 'var(--rtp-verde)';
    btnEl.style.background = '#ecf9d9';
    const chk = btnEl.querySelector('.resp-check');
    if (chk) chk.style.opacity = '1';

    document.getElementById('respRolWrap').style.display = 'block';
    document.getElementById('respRolActividad').value = '';

    validarFormResponsable();
}

function validarFormResponsable() {
    const rol = document.getElementById('respRolActividad').value;
    const ok = !!RESPONSABLE_SELECCIONADO && !!rol;
    document.getElementById('btnAgregarResponsable').disabled = !ok;
    document.getElementById('btnAgregarResponsable').style.opacity = ok ? '1' : '0.5';
    document.getElementById('btnAgregarResponsable').style.cursor = ok ? 'pointer' : 'not-allowed';
}

async function agregarResponsable() {
    const rol = document.getElementById('respRolActividad').value;
    if (!RESPONSABLE_SELECCIONADO || !rol || !RESPONSABLE_ID_ACTIVIDAD) return;

    const btn = document.getElementById('btnAgregarResponsable');
    btn.disabled = true;
    btn.textContent = '⏳ Asignando...';

    try {
        const res = await fetch('api/assign_responsable_by_id.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_actividad: RESPONSABLE_ID_ACTIVIDAD,
                id_proyecto: PROYECTO_ID,
                id_usuario: RESPONSABLE_SELECCIONADO.id_usuario,
                rol: rol
            })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error', '❌ Error', json.error);
            btn.disabled = false;
            btn.textContent = '✓ Asignar actividad';
            return;
        }
        const nombre = RESPONSABLE_SELECCIONADO.nombre_completo;
        const idGuardado = RESPONSABLE_ID_ACTIVIDAD;
        cerrarModalAgregarResponsable();
        mostrarToast('✅ ' + nombre + ' asignado como ' + rol + ' en la actividad.', '✅');
        setTimeout(() => abrirDetalleActividad(idGuardado), 800);
    } catch (e) {
        mostrarMensaje('error', '❌ Error', e.message);
        btn.disabled = false;
        btn.textContent = '✓ Asignar actividad';
    }
}

function toggleInvitarCorreo() {
    const wrap = document.getElementById('invitarCorreoWrap');
    const btn = document.getElementById('btnToggleInvitarCorreo');
    const visible = wrap.style.display === 'block';
    wrap.style.display = visible ? 'none' : 'block';
    btn.textContent = visible ? '✉️ Invitar por correo' : '✕ Ocultar invitación por correo';
    if (!visible) setTimeout(() => document.getElementById('respEmail').focus(), 100);
}

function validarFormInviteResponsable() {
    const email = document.getElementById('respEmail').value.trim();
    const rol = document.getElementById('respRol').value;
    const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && !!rol;
    document.getElementById('btnEnviarInvitacionResponsable').disabled = !ok;
}

async function enviarInvitacionResponsable() {
    const email = document.getElementById('respEmail').value.trim();
    const rol = document.getElementById('respRol').value;
    if (!email || !rol || !RESPONSABLE_ID_ACTIVIDAD) return;

    const btn = document.getElementById('btnEnviarInvitacionResponsable');
    const status = document.getElementById('respStatus');
    btn.disabled = true;
    btn.textContent = '⏳ Enviando...';
    status.className = 'send-status';
    status.textContent = '';

    try {
        const res = await fetch('api/assign_responsable.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_actividad: RESPONSABLE_ID_ACTIVIDAD,
                id_proyecto: PROYECTO_ID,
                correo: email,
                rol: rol
            })
        });
        const json = await res.json();
        if (json.error) {
            status.className = 'send-status error';
            status.textContent = '❌ ' + json.error;
        } else {
            status.className = 'send-status ok';
            status.textContent = '✅ ' + (json.mensaje || 'Invitación enviada a ' + email);
            document.getElementById('respEmail').value = '';
            document.getElementById('respRol').value = '';
            validarFormInviteResponsable();
        }
    } catch (e) {
        status.className = 'send-status error';
        status.textContent = '❌ Error: ' + e.message;
    }
    btn.disabled = false;
    btn.textContent = '✉️ Enviar invitación';
}

function abrirModalAñadirResponsableTarjeta(idActividad, miembrosJson) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes agregar responsables ahora.'); return; }
    ACTIVIDAD_ID_TARJETA_RESP = idActividad;
    let miembros = [];
    try { miembros = JSON.parse(miembrosJson); } catch (e) { console.error(e); }
    const contenedor = document.getElementById('listaMiembrosTarjeta');
    contenedor.innerHTML = '';
    if (!miembros || miembros.length === 0) {
        contenedor.innerHTML = '<div style="text-align:center; color:#94a3b8; font-style:italic; padding:20px;">No hay miembros en este proyecto.</div>';
    } else {
        miembros.forEach(m => {
            const ini = inicialesJS(m.nombre_completo);
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.style.cssText = 'display:flex; align-items:center; gap:12px; width:100%; padding:10px 14px; background:#f8fafc; color:var(--rtp-negro); border:2px solid #e2e8f0; border-radius:10px; text-align:left; cursor:pointer; transition:all .15s; font-family:inherit; font-weight:600;';
            btn.innerHTML = `
                <span style="width:32px; height:32px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10pt; flex-shrink:0;">${ini}</span>
                <span style="flex:1; min-width:0; font-size:10.5pt;">${escapeHtml(m.nombre_completo)}</span>
                <span style="font-size:14pt; color:var(--rtp-verde);">＋</span>
            `;
            btn.onmouseenter = () => { btn.style.borderColor = 'var(--rtp-verde)'; btn.style.background = '#ecf9d9'; };
            btn.onmouseleave = () => { btn.style.borderColor = '#e2e8f0'; btn.style.background = '#f8fafc'; };
            btn.onclick = () => guardarResponsableDesdeTarjeta(idActividad, m.id_usuario, m.nombre_completo);
            contenedor.appendChild(btn);
        });
    }
    document.getElementById('modalAñadirResponsableTarjeta').classList.remove('hidden');
}

function cerrarModalAñadirResponsableTarjeta() { document.getElementById('modalAñadirResponsableTarjeta').classList.add('hidden'); ACTIVIDAD_ID_TARJETA_RESP = null; }

async function guardarResponsableDesdeTarjeta(idActividad, idUsuario, nombreCompleto) {
    const contenedor = document.getElementById('listaMiembrosTarjeta');
    contenedor.innerHTML = '<div style="text-align:center; color:#94a3b8; padding:20px;">Guardando...</div>';
    try {
        const res = await fetch('api/assign_responsable_by_id.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: idActividad, id_proyecto: PROYECTO_ID, id_usuario: idUsuario }) });
        const json = await res.json();
        if (json.error) { mostrarMensaje('error','❌ Error', json.error); cerrarModalAñadirResponsableTarjeta(); }
        else { cerrarModalAñadirResponsableTarjeta(); mostrarToast('✅ ' + nombreCompleto + ' asignado como responsable.', '✅'); setTimeout(() => location.reload(), 1000); }
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); cerrarModalAñadirResponsableTarjeta(); }
}

/* ============================================================
   MODAL: EQUIPO DE LA ACTIVIDAD (para 2+ responsables)
   ============================================================ */
function abrirModalEquipoActividad(idActividad, titulo) {
    const lista = window.EQUIPOS_ACTIVIDADES && window.EQUIPOS_ACTIVIDADES[idActividad]
        ? window.EQUIPOS_ACTIVIDADES[idActividad]
        : [];

    document.getElementById('equipoActSubtitulo').textContent = titulo || 'Actividad';
    const cont = document.getElementById('equipoActLista');

    if (!lista || lista.length === 0) {
        cont.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8; font-style:italic;">No hay responsables.</div>';
    } else {
        cont.innerHTML = lista.map(r => {
            const ini = inicialesJS(r.nombre_completo);
            return `
                <div class="equipo-item" style="display:flex; align-items:center; gap:12px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all .15s;"
                     onclick="event.stopPropagation(); cerrarModalEquipoActividad(); abrirResumenUsuario(${r.id_usuario}, ${PROYECTO_ID})"
                     onmouseenter="this.style.background='#fff'; this.style.borderColor='var(--rtp-verde)'; this.style.boxShadow='0 4px 12px rgba(120,190,32,.15)';"
                     onmouseleave="this.style.background='#f8fafc'; this.style.borderColor='#e2e8f0'; this.style.boxShadow='none';">
                    <div style="width:36px; height:36px; border-radius:50%; background:var(--rtp-verde); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11pt; flex-shrink:0;">${ini}</div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:700; color:var(--rtp-negro); font-size:11pt; line-height:1.3;">${escapeHtml(r.nombre_completo)}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    document.getElementById('modalEquipoActividad').classList.remove('hidden');
}

function cerrarModalEquipoActividad() {
    document.getElementById('modalEquipoActividad').classList.add('hidden');
}

let RESUMEN_USUARIO_CACHE = null;

async function abrirResumenUsuario(idUsuario, idProyecto) {
    RESUMEN_USUARIO_CACHE = { idUsuario, idProyecto };
    const modal = document.getElementById('modalResumenUsuario');
    const body = document.getElementById('resumenUsuarioBody');
    body.innerHTML = '<div style="padding:60px; text-align:center; color:#94a3b8;">Cargando resumen...</div>';
    modal.classList.remove('hidden');
    try {
        const res = await fetch(`api/user_project_summary.php?id_usuario=${idUsuario}&id_proyecto=${idProyecto}`);
        const data = await res.json();
        if (data.error) { body.innerHTML = `<div style="padding:40px; text-align:center; color:#E5074C;">❌ ${escapeHtml(data.error)}</div>`; return; }
        renderResumenUsuario(data);
    } catch (e) { body.innerHTML = `<div style="padding:40px; text-align:center; color:#E5074C;">❌ Error: ${escapeHtml(e.message)}</div>`; }
}

function cerrarResumenUsuario() { document.getElementById('modalResumenUsuario').classList.add('hidden'); RESUMEN_USUARIO_CACHE = null; }

function renderResumenUsuario(data) {
    const u = data.usuario;
    const r = data.resumen;
    const acts = data.actividades || [];
    const rolMiembro = data.rol_miembro;
    const ini = inicialesJS(u.nombre_completo);
    const colorAvance = r.avance_usuario_pct >= 100 ? '#027a35' : r.avance_usuario_pct >= 60 ? '#266cb4' : r.avance_usuario_pct >= 30 ? '#F08217' : '#94a3b8';
    const radius = 42;
    const circ = 2 * Math.PI * radius;
    const dash = (r.avance_usuario_pct / 100) * circ;
    const resto = circ - dash;
    let rolHtml = rolMiembro ? `<span class="resumen-rol-badge">${escapeHtml(rolMiembro)}</span>` : '';

    const hoy = new Date(); hoy.setHours(0,0,0,0);
    const enDosSemanas = new Date(hoy); enDosSemanas.setDate(enDosSemanas.getDate() + 14);
    const alertas = [];

    acts.forEach(a => {
        if (a.estado === 'bloqueado') {
            alertas.push({ tipo: 'bloqueo', titulo: a.titulo, msg: 'Actividad bloqueada' });
        } else if (a.fecha_limite && a.estado !== 'completada') {
            const fechaLim = new Date(a.fecha_limite + 'T00:00:00');
            if (fechaLim < hoy) {
                alertas.push({ tipo: 'vencida', titulo: a.titulo, msg: 'Fecha límite vencida el ' + formatearFecha(a.fecha_limite) });
            } else if (fechaLim <= enDosSemanas) {
                alertas.push({ tipo: 'proxima', titulo: a.titulo, msg: 'Vence pronto (el ' + formatearFecha(a.fecha_limite) + ')' });
            }
        }
    });

    let alertasHtml = '';
    if (alertas.length > 0) {
        alertasHtml = alertas.map(al => `
            <div class="resumen-alerta-item ${al.tipo}">
                <div style="font-size:14pt;">${al.tipo === 'bloqueo' ? '🔒' : (al.tipo === 'vencida' ? '⚠️' : '⏳')}</div>
                <div class="resumen-alerta-txt">${escapeHtml(al.titulo)}<span>${al.msg}</span></div>
            </div>
        `).join('');
    } else {
        alertasHtml = `<div class="resumen-alertas-vacio">🌟 ¡Todo al día! No hay actividades en riesgo ni bloqueadas.</div>`;
    }

    window._resumenActsData = acts;

    const html = `
        <div class="resumen-hero">
            <div class="resumen-hero-inner">
                <div class="resumen-user-avatar-big">${ini}</div>
                <div class="resumen-user-meta">
                    <div class="resumen-user-name-big">${escapeHtml(u.nombre_completo)} ${rolHtml}</div>
                    <div class="resumen-user-email-big">${escapeHtml(u.correo)}</div>
                </div>
            </div>
        </div>
        <div class="resumen-body">
            <div class="resumen-dashboard">
                <div class="resumen-progress-card">
                    <div class="resumen-progress-ring-big">
                        <svg viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="8"/>
                            <circle cx="50" cy="50" r="${radius}" fill="none" stroke="${colorAvance}" stroke-width="8" stroke-dasharray="${dash} ${resto}" stroke-dashoffset="0" stroke-linecap="round"/>
                        </svg>
                        <span class="resumen-progress-text-big" style="color:${colorAvance};">${r.avance_usuario_pct}%</span>
                    </div>
                    <div class="resumen-progress-label">Avance del usuario</div>
                </div>
                <div class="resumen-stats-grid" id="resumenFiltros">
                    <div class="resumen-stat-card" onclick="filtrarResumen('completada', this)" style="border-left-color:#027a35;"><span class="resumen-stat-num" style="color:#027a35;">${r.completadas}</span><span class="resumen-stat-lbl">Completadas</span></div>
                    <div class="resumen-stat-card" onclick="filtrarResumen('en_curso', this)" style="border-left-color:#266cb4;"><span class="resumen-stat-num" style="color:#266cb4;">${r.en_curso}</span><span class="resumen-stat-lbl">En curso</span></div>
                    <div class="resumen-stat-card" onclick="filtrarResumen('en_revision', this)" style="border-left-color:#FDC60A;"><span class="resumen-stat-num" style="color:#FDC60A;">${r.en_revision}</span><span class="resumen-stat-lbl">En revisión</span></div>
                    <div class="resumen-stat-card" onclick="filtrarResumen('por_hacer', this)" style="border-left-color:#94a3b8;"><span class="resumen-stat-num" style="color:#94a3b8;">${r.por_hacer}</span><span class="resumen-stat-lbl">Por hacer</span></div>
                    <div class="resumen-stat-card" onclick="filtrarResumen('programado', this)" style="border-left-color:#F08217;"><span class="resumen-stat-num" style="color:#F08217;">${r.programadas}</span><span class="resumen-stat-lbl">Programadas</span></div>
                    <div class="resumen-stat-card" onclick="filtrarResumen('bloqueado', this)" style="border-left-color:#E5074C;"><span class="resumen-stat-num" style="color:#E5074C;">${r.bloqueadas}</span><span class="resumen-stat-lbl">Bloqueadas</span></div>
                </div>
            </div>

            <div class="resumen-alertas-section">
                <div class="resumen-alertas-title">🚨 Atención requerida</div>
                ${alertasHtml}
            </div>

            <div class="resumen-actividades-section">
                <div class="resumen-actividades-header">
                    <h3 id="resumenListaTitulo">📋 Todas las actividades</h3>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="resumen-actividades-count" id="resumenListaCount">${acts.length} en total</span>
                        <button class="btn-ver-todas" id="btnVerTodas" style="display:none;" onclick="filtrarResumen('todas', null)">Ver todas</button>
                    </div>
                </div>
                <div class="resumen-acts-list" id="resumenActsList">
                    ${generarListaActs(acts)}
                </div>
            </div>
        </div>
        <div class="resumen-footer">
            <button class="btn btn-outline" onclick="cerrarResumenUsuario()" style="width:100%; padding:12px; font-weight:700;">← Cerrar resumen</button>
        </div>
    `;
    document.getElementById('resumenUsuarioBody').innerHTML = html;
}

function generarListaActs(listaActs) {
    if (!listaActs || listaActs.length === 0) {
        return `<div class="resumen-empty-acts"><span class="icon">📭</span>No hay actividades en este estado.</div>`;
    }
    return listaActs.map(a => {
        const pct = parseFloat(a.porcentaje_aporte) || 0;
        const col = ESTADO_COLORS[a.estado] || '#94a3b8';
        const lbl = ESTADO_LABELS[a.estado] || a.estado;
        const ic  = ESTADO_ICONS[a.estado] || '📝';
        const fecha = a.fecha_limite ? formatearFecha(a.fecha_limite) : '—';
        return `
            <div class="resumen-act-item" style="border-left-color:${col};">
                <div class="resumen-act-info">
                    <div class="resumen-act-title">${escapeHtml(a.titulo)}</div>
                    <div class="resumen-act-meta">
                        <span style="color:${col}; font-weight:700;">${ic} ${lbl}</span>
                        <span class="dot-sep">•</span>
                        <span>🏁 ${fecha}</span>
                    </div>
                </div>
                <div class="resumen-act-pct">${pct}%</div>
            </div>
        `;
    }).join('');
}

function filtrarResumen(estado, el) {
    const listaContenedor = document.getElementById('resumenActsList');
    const titulo = document.getElementById('resumenListaTitulo');
    const count = document.getElementById('resumenListaCount');
    const btnVerTodas = document.getElementById('btnVerTodas');
    const tarjetas = document.querySelectorAll('.resumen-stat-card');
    const data = window._resumenActsData || [];

    if (estado === 'todas') {
        tarjetas.forEach(t => t.classList.remove('filtro-activo'));
        titulo.innerHTML = '📋 Todas las actividades';
        count.textContent = data.length + ' en total';
        btnVerTodas.style.display = 'none';
        listaContenedor.innerHTML = generarListaActs(data);
        return;
    }

    tarjetas.forEach(t => t.classList.remove('filtro-activo'));
    if (el) el.classList.add('filtro-activo');

    const filtradas = data.filter(a => a.estado === estado);

    const labelStr = ESTADO_LABELS[estado] || estado;
    titulo.innerHTML = `📋 Actividades: ${labelStr}`;
    count.textContent = filtradas.length + ' en total';
    btnVerTodas.style.display = 'inline-flex';

    listaContenedor.innerHTML = generarListaActs(filtradas);
}

function mostrarMensaje(tipo, titulo, texto, callback) {
    CALLBACK_MENSAJE = callback || null;
    document.getElementById('mensajeIcon').textContent = tipo === 'ok' ? '✅' : (tipo === 'error' ? '❌' : 'ℹ️');
    document.getElementById('mensajeTitulo').textContent = titulo;
    document.getElementById('mensajeTexto').textContent = texto;
    document.getElementById('modalMensaje').classList.remove('hidden');
}

function cerrarModalMensaje() { document.getElementById('modalMensaje').classList.add('hidden'); if (CALLBACK_MENSAJE) { const cb = CALLBACK_MENSAJE; CALLBACK_MENSAJE = null; cb(); } }

function toggleCargarMenu(e) { e.stopPropagation(); const menu = document.getElementById('cargarMenu'); const btn = document.getElementById('btnCargar'); if (!menu) return; menu.classList.toggle('open'); btn.classList.toggle('open'); }
document.addEventListener('click', () => { const menu = document.getElementById('cargarMenu'); const btn = document.getElementById('btnCargar'); if (menu) menu.classList.remove('open'); if (btn) btn.classList.remove('open'); });

function cargarArchivo(tipo) {
    const input = document.createElement('input');
    input.type = 'file'; input.accept = tipo === 'imagen' ? 'image/*' : '*/*';
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
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); }
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
    fetch('api/upload_attachment.php', { method:'POST', body: fd }).then(r => r.json()).then(json => {
        if (json.error) mostrarMensaje('error','❌ Error', json.error);
        else { document.getElementById('modalEnlace').classList.add('hidden'); abrirDetalleActividad(ACTIVIDAD_ID_ACTUAL); }
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
    } catch (e) { mostrarMensaje('error','❌ Error', e.message); }
}

function eliminarActividad(id) {
    if (!PROYECTO_OPERATIVO) { mostrarMensaje('error','⏳','No puedes eliminar actividades ahora.'); return; }
    mostrarConfirmacion('🗑️ Eliminar actividad','¿Eliminar esta actividad? Esta acción se puede deshacer desde el administrador.', async () => {
        const res = await fetch('api/delete_activity.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id_actividad: id, accion: 'eliminar' }) });
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
    document.getElementById('btnConfirmarSi').onclick = () => { cerrarModalConfirmar(); callback(); };
}

function cerrarModalConfirmar() { document.getElementById('modalConfirmar').classList.add('hidden'); }

function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModalActividad(); cerrarModalInvitar(); cerrarModalPorcentajes(); cerrarDetalleActividad();
        cerrarModalBloquear(); cerrarModalDesbloquear(); cerrarModalBloquearProyecto(); cerrarModalDesbloquearProyecto();
        cerrarModalModificarApertura(); cerrarModalHistorial(); cerrarModalAgregarResponsable(); cerrarModalFecha();
        cerrarModalFechaActividad(); cerrarModalMensaje(); cerrarModalConfirmar(); cerrarResumenUsuario(); cerrarModalAñadirResponsableTarjeta();
        cerrarModalDragProgramado(); cerrarModalDragBloqueado(); cerrarModalAperturarActividad();
        cerrarModalEquipoActividad();
    }
});

function cerrarAlClicFuera(idModal, fnCerrar) { const el = document.getElementById(idModal); if (!el) return; el.addEventListener('click', function (e) { if (e.target === this) fnCerrar(); }); }
cerrarAlClicFuera('modalActividad', cerrarModalActividad);
cerrarAlClicFuera('modalInvitar', cerrarModalInvitar);
cerrarAlClicFuera('modalPorcentajes', cerrarModalPorcentajes);
cerrarAlClicFuera('modalDetalleActividad', cerrarDetalleActividad);
cerrarAlClicFuera('modalBloquear', cerrarModalBloquear);
cerrarAlClicFuera('modalDesbloquear', cerrarModalDesbloquear);
cerrarAlClicFuera('modalBloquearProyecto', cerrarModalBloquearProyecto);
cerrarAlClicFuera('modalDesbloquearProyecto', cerrarModalDesbloquearProyecto);
cerrarAlClicFuera('modalModificarApertura', cerrarModalModificarApertura);
cerrarAlClicFuera('modalHistorial', cerrarModalHistorial);
cerrarAlClicFuera('modalAgregarResponsable', cerrarModalAgregarResponsable);
cerrarAlClicFuera('modalFecha', cerrarModalFecha);
cerrarAlClicFuera('modalFechaActividad', cerrarModalFechaActividad);
cerrarAlClicFuera('modalResumenUsuario', cerrarResumenUsuario);
cerrarAlClicFuera('modalAñadirResponsableTarjeta', cerrarModalAñadirResponsableTarjeta);
cerrarAlClicFuera('modalDragProgramado', cerrarModalDragProgramado);
cerrarAlClicFuera('modalDragBloqueado', cerrarModalDragBloqueado);
cerrarAlClicFuera('modalAperturarActividad', cerrarModalAperturarActividad);
cerrarAlClicFuera('modalEquipoActividad', cerrarModalEquipoActividad);

function abrirModalDragProgramado(idActividad) {
    DRAG_ACTIVIDAD_PROGRAMAR = idActividad;
    const btn = document.getElementById('btnCountdownProgramar');
    const txt = document.getElementById('countdownTexto');
    const wrap = document.getElementById('dragFechaWrap');
    const btnConfirm = document.getElementById('btnConfirmarProgramar');
    const input = document.getElementById('inputFechaProgramar');
    btn.disabled = true;
    btn.classList.remove('ready');
    txt.textContent = 'Espera 8s...';
    wrap.classList.remove('visible');
    btnConfirm.disabled = true;
    input.value = '';
    if (COUNTDOWN_INTERVAL) { clearInterval(COUNTDOWN_INTERVAL); COUNTDOWN_INTERVAL = null; }
    document.getElementById('modalDragProgramado').classList.remove('hidden');
    iniciarCountdown();
}

function iniciarCountdown() {
    let segundos = 8;
    const btn = document.getElementById('btnCountdownProgramar');
    const txt = document.getElementById('countdownTexto');
    txt.textContent = 'Espera ' + segundos + 's...';
    COUNTDOWN_INTERVAL = setInterval(() => {
        segundos--;
        if (segundos > 0) {
            txt.textContent = 'Espera ' + segundos + 's...';
        } else {
            clearInterval(COUNTDOWN_INTERVAL);
            COUNTDOWN_INTERVAL = null;
            txt.textContent = '✅ Continuar';
            btn.disabled = false;
            btn.classList.add('ready');
        }
    }, 1000);
}

function mostrarFechaProgramar() {
    const wrap = document.getElementById('dragFechaWrap');
    const btn = document.getElementById('btnCountdownProgramar');
    const btnConfirm = document.getElementById('btnConfirmarProgramar');
    const input = document.getElementById('inputFechaProgramar');
    wrap.classList.add('visible');
    btn.style.display = 'none';
    btnConfirm.disabled = false;
    if (!input.value) {
        const manana = new Date();
        manana.setDate(manana.getDate() + 1);
        manana.setHours(9, 0, 0, 0);
        const yyyy = manana.getFullYear();
        const mm = String(manana.getMonth() + 1).padStart(2, '0');
        const dd = String(manana.getDate()).padStart(2, '0');
        const hh = String(manana.getHours()).padStart(2, '0');
        const min = String(manana.getMinutes()).padStart(2, '0');
        input.value = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
    }
    setTimeout(() => input.focus(), 100);
}

function cerrarModalDragProgramado() {
    if (COUNTDOWN_INTERVAL) { clearInterval(COUNTDOWN_INTERVAL); COUNTDOWN_INTERVAL = null; }
    document.getElementById('modalDragProgramado').classList.add('hidden');
    const btn = document.getElementById('btnCountdownProgramar');
    if (btn) btn.style.display = '';
    if (DRAG_ACTIVIDAD_PROGRAMAR !== null) {
        DRAG_ACTIVIDAD_PROGRAMAR = null;
        location.reload();
    }
}

async function guardarProgramacionDrag() {
    const idActividad = DRAG_ACTIVIDAD_PROGRAMAR;
    if (!idActividad) return;
    const valor = document.getElementById('inputFechaProgramar').value;
    if (!valor) {
        mostrarMensaje('error', '⚠️ Falta fecha', 'Selecciona la fecha y hora de apertura.');
        return;
    }
    const fechaDate = valor.split('T')[0];
    const btn = document.getElementById('btnConfirmarProgramar');
    btn.disabled = true;
    btn.textContent = '⏳ Programando...';

    try {
        const res1 = await fetch('api/update_activity_dates.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_actividad: idActividad, campo: 'fecha_inicio', fecha: fechaDate })
        });
        const json1 = await res1.json();
        if (json1.error) {
            mostrarMensaje('error', '❌ No se pudo programar', json1.error);
            btn.disabled = false;
            btn.textContent = '💾 Programar actividad';
            return;
        }

        const res2 = await fetch('api/update_activity_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_actividad: idActividad, estado: 'programado' })
        });
        const json2 = await res2.json();
        if (json2.error) {
            mostrarMensaje('error', '❌ No se pudo cambiar el estado', json2.error);
            btn.disabled = false;
            btn.textContent = '💾 Programar actividad';
            return;
        }

        DRAG_ACTIVIDAD_PROGRAMAR = null;
        document.getElementById('modalDragProgramado').classList.add('hidden');
        mostrarToast('✅ Actividad programada correctamente.', '✅');
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        mostrarMensaje('error', '❌ Error de red', e.message);
        btn.disabled = false;
        btn.textContent = '💾 Programar actividad';
    }
}

function abrirModalDragBloqueado(idActividad) {
    DRAG_ACTIVIDAD_BLOQUEAR = idActividad;
    document.getElementById('motivoDragBloqueo').value = '';
    document.getElementById('dragMotivoWrap').classList.remove('visible');
    document.getElementById('dragMotivoError').classList.remove('visible');
    document.getElementById('btnSiBloquear').style.display = '';
    document.getElementById('btnConfirmarBloqueoDrag').classList.remove('visible');
    document.getElementById('modalDragBloqueado').classList.remove('hidden');
}

function habilitarMotivoDragBloqueo() {
    document.getElementById('dragMotivoWrap').classList.add('visible');
    document.getElementById('btnSiBloquear').style.display = 'none';
    document.getElementById('btnConfirmarBloqueoDrag').classList.add('visible');
    setTimeout(() => document.getElementById('motivoDragBloqueo').focus(), 100);
}

function cerrarModalDragBloqueado() {
    document.getElementById('modalDragBloqueado').classList.add('hidden');
    if (DRAG_ACTIVIDAD_BLOQUEAR !== null) {
        DRAG_ACTIVIDAD_BLOQUEAR = null;
        location.reload();
    }
}

async function guardarBloqueoDrag() {
    const idActividad = DRAG_ACTIVIDAD_BLOQUEAR;
    if (!idActividad) return;
    const motivo = document.getElementById('motivoDragBloqueo').value.trim();
    if (!motivo) {
        document.getElementById('dragMotivoError').classList.add('visible');
        return;
    }
    document.getElementById('dragMotivoError').classList.remove('visible');
    const btn = document.getElementById('btnConfirmarBloqueoDrag');
    btn.disabled = true;
    btn.textContent = '⏳ Bloqueando...';

    try {
        const res = await fetch('api/block_activity.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_actividad: idActividad, motivo: motivo })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error', '❌ No se pudo bloquear', json.error);
            btn.disabled = false;
            btn.textContent = '🔒 Confirmar bloqueo';
            return;
        }
        DRAG_ACTIVIDAD_BLOQUEAR = null;
        document.getElementById('modalDragBloqueado').classList.add('hidden');
        mostrarToast('✅ Actividad bloqueada correctamente.', '✅');
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        mostrarMensaje('error', '❌ Error de red', e.message);
        btn.disabled = false;
        btn.textContent = '🔒 Confirmar bloqueo';
    }
}

function abrirModalAperturarActividad(idActividad, columnaDestino, estadoAnterior) {
    APERTURAR_ACTIVIDAD_ID = idActividad;
    APERTURAR_COLUMNA_DESTINO = columnaDestino;
    APERTURAR_ESTADO_ANTERIOR = estadoAnterior;
    document.getElementById('aperturarDestinoLabel').textContent = ESTADO_LABELS[columnaDestino] || columnaDestino;
    const btn = document.getElementById('btnCountdownAperturar');
    const txt = document.getElementById('countdownAperturarTexto');
    const btnConfirm = document.getElementById('btnConfirmarAperturar');
    btn.disabled = true;
    btn.classList.remove('ready');
    btn.style.display = '';
    btnConfirm.disabled = true;
    txt.textContent = 'Espera 5s...';
    if (COUNTDOWN_APERTURAR_INTERVAL) { clearInterval(COUNTDOWN_APERTURAR_INTERVAL); COUNTDOWN_APERTURAR_INTERVAL = null; }
    document.getElementById('modalAperturarActividad').classList.remove('hidden');
    let segundos = 5;
    COUNTDOWN_APERTURAR_INTERVAL = setInterval(() => {
        segundos--;
        if (segundos > 0) {
            txt.textContent = 'Espera ' + segundos + 's...';
        } else {
            clearInterval(COUNTDOWN_APERTURAR_INTERVAL);
            COUNTDOWN_APERTURAR_INTERVAL = null;
            txt.textContent = '✅ Ya puedes aperturar';
            btn.disabled = false;
            btn.classList.add('ready');
            btnConfirm.disabled = false;
        }
    }, 1000);
}

function cerrarModalAperturarActividad() {
    if (COUNTDOWN_APERTURAR_INTERVAL) { clearInterval(COUNTDOWN_APERTURAR_INTERVAL); COUNTDOWN_APERTURAR_INTERVAL = null; }
    document.getElementById('modalAperturarActividad').classList.add('hidden');
    if (APERTURAR_ACTIVIDAD_ID !== null) {
        APERTURAR_ACTIVIDAD_ID = null;
        APERTURAR_COLUMNA_DESTINO = null;
        APERTURAR_ESTADO_ANTERIOR = null;
        location.reload();
    }
}

async function confirmarAperturarActividad() {
    const idActividad = APERTURAR_ACTIVIDAD_ID;
    const columnaDestino = APERTURAR_COLUMNA_DESTINO;
    if (!idActividad || !columnaDestino) return;
    const btn = document.getElementById('btnConfirmarAperturar');
    btn.disabled = true;
    btn.textContent = '⏳ Aperturando...';

    try {
        if (APERTURAR_ESTADO_ANTERIOR === 'programado') {
            const hoy = new Date();
            const yyyy = hoy.getFullYear();
            const mm = String(hoy.getMonth() + 1).padStart(2, '0');
            const dd = String(hoy.getDate()).padStart(2, '0');
            const fechaHoy = `${yyyy}-${mm}-${dd}`;
            await fetch('api/update_activity_dates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_actividad: idActividad, campo: 'fecha_inicio', fecha: fechaHoy })
            });
        }

        const res = await fetch('api/update_activity_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_actividad: idActividad, estado: columnaDestino })
        });
        const json = await res.json();
        if (json.error) {
            mostrarMensaje('error', '❌ No se pudo mover', json.error);
            btn.disabled = false;
            btn.textContent = '✅ Sí, aperturar ahora mismo';
            return;
        }
        APERTURAR_ACTIVIDAD_ID = null;
        APERTURAR_COLUMNA_DESTINO = null;
        document.getElementById('modalAperturarActividad').classList.add('hidden');
        mostrarToast('✅ Actividad aperturada y movida a ' + (ESTADO_LABELS[columnaDestino] || columnaDestino) + '.', '✅');
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        mostrarMensaje('error', '❌ Error de red', e.message);
        btn.disabled = false;
        btn.textContent = '✅ Sí, aperturar ahora mismo';
    }
}

const COLS_PERMITIDAS_RESP = ['por_hacer', 'en_curso', 'en_revision'];
const COLS_TODAS = ['por_hacer', 'en_curso', 'en_revision', 'completada', 'programado', 'bloqueado'];

function columnasPermitidasPara(rol) {
    if (rol === 'administrador') return COLS_TODAS;
    if (rol === 'responsable') return COLS_PERMITIDAS_RESP;
    return [];
}

function puedeBrillarColumna(rol, col) {
    if (rol === 'administrador') return true;
    if (rol === 'responsable') return COLS_PERMITIDAS_RESP.includes(col);
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    if (!PUEDE_REORDENAR) return;
    let ARRASTRANDO = false, dragCard = null, ghostCard = null, placeholder = null, offsetX = 0, offsetY = 0;
    window.cardEstaSiendoArrastrada = function() { return ARRASTRANDO; };
    const handles = document.querySelectorAll('.drag-handle[data-drag="true"]');
    handles.forEach(handle => {
        let dragIniciado = false; let startX = 0, startY = 0;
        handle.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return;
            dragIniciado = false; startX = e.clientX; startY = e.clientY;
            window.addEventListener('mousemove', onMouseMoveStart);
            window.addEventListener('mouseup', onMouseUpStart, { once: true });
            function onMouseMoveStart(ev) {
                const dx = Math.abs(ev.clientX - startX); const dy = Math.abs(ev.clientY - startY);
                if (dx > 4 || dy > 4) { dragIniciado = true; ev.preventDefault(); window.removeEventListener('mousemove', onMouseMoveStart); window.removeEventListener('mouseup', onMouseUpStart); iniciarDrag(handle, ev); }
            }
            function onMouseUpStart() { window.removeEventListener('mousemove', onMouseMoveStart); }
        });
    });

    function iniciarDrag(handle, e) {
        const card = handle.closest('.project-card');
        if (!card) return;
        if (USER_ROL === 'visualizador') return;
        if (USER_ROL === 'responsable') {
            const estado = card.dataset.estado;
            if (!COLS_PERMITIDAS_RESP.includes(estado)) return;
        }
        ARRASTRANDO = true; dragCard = card;
        const rect = card.getBoundingClientRect();
        offsetX = e.clientX - rect.left; offsetY = e.clientY - rect.top;
        ghostCard = card.cloneNode(true); ghostCard.classList.add('drag-ghost');
        ghostCard.style.width = rect.width + 'px'; ghostCard.style.height = rect.height + 'px';
        ghostCard.style.left = (e.clientX - offsetX) + 'px'; ghostCard.style.top = (e.clientY - offsetY) + 'px';
        document.body.appendChild(ghostCard);
        placeholder = document.createElement('div'); placeholder.className = 'drag-placeholder-task'; placeholder.style.height = rect.height + 'px';
        card.parentNode.insertBefore(placeholder, card);
        card.classList.add('drag-origin');
        document.body.style.cursor = 'grabbing'; document.body.style.userSelect = 'none';
        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp, { once: true });
    }

    function onMouseMove(e) {
        if (!ARRASTRANDO) return; e.preventDefault();
        ghostCard.style.left = (e.clientX - offsetX) + 'px'; ghostCard.style.top = (e.clientY - offsetY) + 'px';
        const elBajo = document.elementFromPoint(e.clientX, e.clientY); if (!elBajo) return;
        const colDest = elBajo.closest('.kanban-col'); if (!colDest) return;

        const col = colDest.dataset.col;
        const puedeSoltarAqui = puedeBrillarColumna(USER_ROL, col);

        document.querySelectorAll('.kanban-col.drag-over-target-task').forEach(c => c.classList.remove('drag-over-target-task'));

        if (!puedeSoltarAqui) {
            return;
        }

        colDest.classList.add('drag-over-target-task');
        const contenedorDest = colDest.querySelector('.board-cards-task'); if (!contenedorDest) return;
        if (placeholder.parentNode !== contenedorDest) contenedorDest.appendChild(placeholder);
        const cards = [...contenedorDest.querySelectorAll('.project-card:not(.drag-origin):not(.drag-ghost)')];
        let refCard = null;
        for (const c of cards) { const rect = c.getBoundingClientRect(); if (e.clientY < rect.top + rect.height / 2) { refCard = c; break; } }
        if (refCard) { if (placeholder.nextElementSibling !== refCard) contenedorDest.insertBefore(placeholder, refCard); }
        else { if (contenedorDest.lastElementChild !== placeholder) contenedorDest.appendChild(placeholder); }
    }

    function onMouseUp(e) {
        if (!ARRASTRANDO) return; window.removeEventListener('mousemove', onMouseMove);
        let colFinal = null, contenedorDest = null;
        if (placeholder && placeholder.parentNode) { contenedorDest = placeholder.closest('.board-cards-task'); if (contenedorDest) colFinal = contenedorDest.dataset.col; }
        if (contenedorDest && placeholder && dragCard && colFinal) contenedorDest.insertBefore(dragCard, placeholder);
        if (ghostCard && ghostCard.parentNode) ghostCard.parentNode.removeChild(ghostCard);
        if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        if (dragCard) dragCard.classList.remove('drag-origin');
        document.querySelectorAll('.kanban-col.drag-over-target-task').forEach(c => c.classList.remove('drag-over-target-task'));
        document.body.style.cursor = ''; document.body.style.userSelect = '';

        if (dragCard && colFinal) {
            const id = parseInt(dragCard.dataset.id, 10);
            const estadoOriginal = dragCard.dataset.estado;

            if (colFinal !== estadoOriginal) {

                if (USER_ROL === 'visualizador') {
                    location.reload();
                    return;
                }
                if (USER_ROL === 'responsable') {
                    if (!COLS_PERMITIDAS_RESP.includes(colFinal)) {
                        alert('Como responsable solo puedes mover actividades entre Por hacer, En curso y En revisión.');
                        location.reload();
                        return;
                    }
                }

                if (USER_ROL === 'administrador' && colFinal === 'programado') {
                    dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
                    abrirModalDragProgramado(id);
                    return;
                }

                if (USER_ROL === 'administrador' && colFinal === 'bloqueado') {
                    dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
                    abrirModalDragBloqueado(id);
                    return;
                }

                if (USER_ROL === 'administrador' && estadoOriginal === 'programado') {
                    dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
                    abrirModalAperturarActividad(id, colFinal, 'programado');
                    return;
                }

                if (USER_ROL === 'administrador' && estadoOriginal === 'bloqueado') {
                    dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
                    abrirModalAperturarActividad(id, colFinal, 'bloqueado');
                    return;
                }

                guardarNuevaColumnaActividad(id, colFinal);
            }
        }
        dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
    }
});

async function guardarNuevaColumnaActividad(id, nuevaColumna) {
    try {
        const res = await fetch('api/update_activity_status.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_actividad: id, estado: nuevaColumna }) });
        const json = await res.json();
        if (json.error) { alert(json.error); location.reload(); }
        else location.reload();
    } catch (e) { console.error(e); location.reload(); }
}

// ============================================================
// NUEVA FUNCIÓN PARA PROGRAMAR PROYECTO (desde menú de estados)
// ============================================================
function abrirModalDragProgramadoProyecto() {
    DRAG_PROYECTO_PROGRAMAR = true; // Solo para indicar que es programación de proyecto
    const btn = document.getElementById('btnCountdownProgramar');
    const txt = document.getElementById('countdownTexto');
    const wrap = document.getElementById('dragFechaWrap');
    const btnConfirm = document.getElementById('btnConfirmarProgramar');
    const input = document.getElementById('inputFechaProgramar');
    
    btn.disabled = true;
    btn.classList.remove('ready');
    txt.textContent = 'Espera 8s...';
    wrap.classList.remove('visible');
    btnConfirm.disabled = true;
    input.value = '';
    
    if (COUNTDOWN_INTERVAL) { clearInterval(COUNTDOWN_INTERVAL); COUNTDOWN_INTERVAL = null; }
    document.getElementById('modalDragProgramado').classList.remove('hidden');
    
    let segundos = 8;
    COUNTDOWN_INTERVAL = setInterval(() => {
        segundos--;
        if (segundos > 0) {
            txt.textContent = 'Espera ' + segundos + 's...';
        } else {
            clearInterval(COUNTDOWN_INTERVAL);
            COUNTDOWN_INTERVAL = null;
            txt.textContent = '✅ Continuar';
            btn.disabled = false;
            btn.classList.add('ready');
        }
    }, 1000);
}

// Modificamos el guardar para detectar si es proyecto o actividad
const _oldGuardarProgramacionDrag = guardarProgramacionDrag;
guardarProgramacionDrag = async function() {
    if (DRAG_PROYECTO_PROGRAMAR === true) {
        // Lógica para programar PROYECTO
        const valor = document.getElementById('inputFechaProgramar').value;
        if (!valor) {
            mostrarMensaje('error', '⚠️ Falta fecha', 'Selecciona la fecha y hora de apertura.');
            return;
        }
        const fechaFormateada = valor.replace('T', ' ') + ':00';
        const btn = document.getElementById('btnConfirmarProgramar');
        btn.disabled = true;
        btn.textContent = '⏳ Programando...';

        try {
            const res = await fetch('api/update_project_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    id_proyecto: PROYECTO_ID, 
                    columna_manual: 'programado', 
                    orden: 0,
                    fecha_apertura: fechaFormateada
                })
            });
            const json = await res.json();
            if (json.error) {
                alert('Error: ' + json.error);
                btn.disabled = false;
                btn.textContent = '💾 Programar';
                return;
            }
            DRAG_PROYECTO_PROGRAMAR = null;
            document.getElementById('modalDragProgramado').classList.add('hidden');
            mostrarToast('✅ Proyecto programado correctamente.', '⏳');
            setTimeout(() => location.reload(), 1000);
        } catch (e) {
            alert('Error de red: ' + e.message);
            btn.disabled = false;
            btn.textContent = '💾 Programar';
        }
        return;
    }
    _oldGuardarProgramacionDrag();
};

// Modificamos el cerrar para limpiar la variable de proyecto
const _oldCerrarModalDragProgramado = cerrarModalDragProgramado;
cerrarModalDragProgramado = function() {
    if (COUNTDOWN_INTERVAL) { clearInterval(COUNTDOWN_INTERVAL); COUNTDOWN_INTERVAL = null; }
    document.getElementById('modalDragProgramado').classList.add('hidden');
    const btn = document.getElementById('btnCountdownProgramar');
    if (btn) btn.style.display = '';
    
    if (DRAG_PROYECTO_PROGRAMAR === true) {
        DRAG_PROYECTO_PROGRAMAR = null;
    } else if (DRAG_ACTIVIDAD_PROGRAMAR !== null) {
        DRAG_ACTIVIDAD_PROGRAMAR = null;
        location.reload();
    }
};

// Modificamos el mostrar fecha para el proyecto
const _oldMostrarFechaProgramar = mostrarFechaProgramar;
mostrarFechaProgramar = function() {
    const wrap = document.getElementById('dragFechaWrap');
    const btn = document.getElementById('btnCountdownProgramar');
    const btnConfirm = document.getElementById('btnConfirmarProgramar');
    const input = document.getElementById('inputFechaProgramar');
    wrap.classList.add('visible');
    btn.style.display = 'none';
    btnConfirm.disabled = false;
    if (!input.value) {
        const manana = new Date();
        manana.setDate(manana.getDate() + 1);
        manana.setHours(9, 0, 0, 0);
        const yyyy = manana.getFullYear();
        const mm = String(manana.getMonth() + 1).padStart(2, '0');
        const dd = String(manana.getDate()).padStart(2, '0');
        const hh = String(manana.getHours()).padStart(2, '0');
        const min = String(manana.getMinutes()).padStart(2, '0');
        input.value = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
    }
    setTimeout(() => input.focus(), 100);
};
</script>
</body>
</html>