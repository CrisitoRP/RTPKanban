<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_login();
$user = current_user();

$ver_archivados = isset($_GET['archivados']) && $_GET['archivados'] == '1';
$ver_activos    = !$ver_archivados;

if ($ver_archivados) {
    $where = "p.archivado = TRUE AND p.eliminado = FALSE";
} else {
    $where = "p.archivado = FALSE AND p.eliminado = FALSE";
}

if ($user['rol'] === 'administrador') {
    if ($ver_archivados) {
        $proyectos = db()->query("
            SELECT p.*, u.nombre_completo AS responsable_nombre
            FROM gestion_proyectos p
            LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
            WHERE $where
            ORDER BY LOWER(p.nombre) ASC
        ")->fetchAll();
    } else {
        $proyectos = db()->query("
            SELECT p.*, u.nombre_completo AS responsable_nombre
            FROM gestion_proyectos p
            LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
            WHERE $where
            ORDER BY p.columna_manual NULLS LAST, p.orden_manual ASC, p.porcentaje_avance DESC, p.fecha_fin ASC
        ")->fetchAll();
    }
} else {
    if ($ver_archivados) {
        $stmt = db()->prepare("
            SELECT p.*, u.nombre_completo AS responsable_nombre
            FROM gestion_proyectos p
            JOIN gestion_miembros_proyecto m ON m.id_proyecto = p.id_proyecto
            LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
            WHERE m.id_usuario = ? AND $where
            ORDER BY LOWER(p.nombre) ASC
        ");
    } else {
        $stmt = db()->prepare("
            SELECT p.*, u.nombre_completo AS responsable_nombre
            FROM gestion_proyectos p
            JOIN gestion_miembros_proyecto m ON m.id_proyecto = p.id_proyecto
            LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
            WHERE m.id_usuario = ? AND $where
            ORDER BY p.columna_manual NULLS LAST, p.orden_manual ASC, p.porcentaje_avance DESC, p.fecha_fin ASC
        ");
    }
    $stmt->execute([$user['id_usuario']]);
    $proyectos = $stmt->fetchAll();
}

// Cargar miembros de todos los proyectos y calcular su % de avance
$miembros_por_proyecto = [];
if (!empty($proyectos)) {
    $ids = array_column($proyectos, 'id_proyecto');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    
    // 1. Obtener miembros
    $stmtM = db()->prepare("
        SELECT m.id_proyecto, u.id_usuario, u.nombre_completo, u.rol
        FROM gestion_miembros_proyecto m
        JOIN gestion_usuarios u ON u.id_usuario = m.id_usuario
        WHERE m.id_proyecto IN ($in) AND u.eliminado = FALSE AND u.activo = TRUE
        ORDER BY m.id_proyecto, u.nombre_completo
    ");
    $stmtM->execute($ids);
    foreach ($stmtM->fetchAll() as $row) {
        $miembros_por_proyecto[(int)$row['id_proyecto']][] = $row;
    }

    // 2. Obtener actividades para calcular avance real por usuario
    $stmtA = db()->prepare("
        SELECT a.id_proyecto, a.id_actividad, a.porcentaje_aporte, a.estado, a.asignado_a
        FROM gestion_actividades a
        WHERE a.id_proyecto IN ($in) AND a.archivada = FALSE AND a.eliminada = FALSE
    ");
    $stmtA->execute($ids);
    $acts_db = $stmtA->fetchAll();

    // 3. Obtener responsables por actividad
    $stmtResp = db()->prepare("
        SELECT ar.id_actividad, ar.id_usuario
        FROM gestion_actividades_responsables ar
        JOIN gestion_actividades a ON a.id_actividad = ar.id_actividad
        WHERE a.id_proyecto IN ($in) AND a.archivada = FALSE AND a.eliminada = FALSE
    ");
    $stmtResp->execute($ids);
    $acts_resp = [];
    foreach ($stmtResp->fetchAll() as $r) {
        $acts_resp[(int)$r['id_actividad']][] = (int)$r['id_usuario'];
    }

    // 4. Calcular progreso
    foreach ($acts_db as $act) {
        $id_p = (int)$act['id_proyecto'];
        $id_a = (int)$act['id_actividad'];
        $pct_aporte = (float)$act['porcentaje_aporte'];
        $completada = ($act['estado'] === 'completada');
        
        $usuarios_involucrados = $acts_resp[$id_a] ?? [];
        if (empty($usuarios_involucrados) && $act['asignado_a']) {
            $usuarios_involucrados = [(int)$act['asignado_a']];
        }

        foreach ($usuarios_involucrados as $id_u) {
            if (!isset($miembros_por_proyecto[$id_p])) continue;
            foreach ($miembros_por_proyecto[$id_p] as &$m) {
                if ($m['id_usuario'] == $id_u) {
                    $m['pct_asignado'] = ($m['pct_asignado'] ?? 0) + $pct_aporte;
                    if ($completada) {
                        $m['pct_completado'] = ($m['pct_completado'] ?? 0) + $pct_aporte;
                    }
                }
            }
            unset($m);
        }
    }

    // 5. Formatear %
    foreach ($miembros_por_proyecto as $id_p => &$lista) {
        foreach ($lista as &$m) {
            $asignado = $m['pct_asignado'] ?? 0;
            $completado = $m['pct_completado'] ?? 0;
            $m['avance'] = ($asignado > 0) ? round(($completado / $asignado) * 100) : 0;
        }
        unset($m);
    }
    unset($lista);
}

$col_programado  = [];
$col_sin_iniciar = [];
$col_en_curso    = [];
$col_en_revision = [];
$col_finalizado  = [];
$col_bloqueado   = [];

$ahora = time();

foreach ($proyectos as $p) {
    if (!empty($p['columna_manual'])) {
        switch ($p['columna_manual']) {
            case 'bloqueado':   $col_bloqueado[]   = $p; continue 2;
            case 'programado':  $col_programado[]  = $p; continue 2;
            case 'finalizado':  $col_finalizado[]  = $p; continue 2;
            case 'en_revision': $col_en_revision[] = $p; continue 2;
            case 'en_curso':    $col_en_curso[]    = $p; continue 2;
            case 'sin_iniciar':
            default:            $col_sin_iniciar[] = $p; continue 2;
        }
    }

    if (!empty($p['bloqueado'])) {
        $col_bloqueado[] = $p;
        continue;
    }

    $fecha_ap = !empty($p['fecha_apertura']) ? strtotime($p['fecha_apertura']) : null;
    if ($fecha_ap && $fecha_ap > $ahora) {
        $col_programado[] = $p;
        continue;
    }

    if ($p['estado'] === 'finalizado') {
        $col_finalizado[] = $p;
    } elseif ($p['porcentaje_avance'] == 0) {
        $col_sin_iniciar[] = $p;
    } elseif ($p['estado'] === 'en_riesgo' || $p['estado'] === 'detenido') {
        $col_en_revision[] = $p;
    } else {
        if ($p['porcentaje_avance'] >= 100) {
            $col_finalizado[] = $p;
        } else {
            $col_en_curso[] = $p;
        }
    }
}

usort($col_bloqueado, function($a, $b) {
    $ta = !empty($a['bloqueado_en']) ? strtotime($a['bloqueado_en']) : 0;
    $tb = !empty($b['bloqueado_en']) ? strtotime($b['bloqueado_en']) : 0;
    return $tb <=> $ta;
});

$total_finalizados   = count($col_finalizado);
$total_sin_finalizar = count($proyectos) - $total_finalizados;
$total_archivados    = $ver_archivados ? count($proyectos) : 0;

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

$color_barrita = '#78BE20';
if ($ver_archivados) $color_barrita = '#F08217';

$placeholder_busqueda = 'Buscar proyecto';
if ($ver_archivados) $placeholder_busqueda = 'Buscar proyectos archivados';

$puede_reordenar = $ver_activos && $user['rol'] === 'administrador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Proyectos · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.topbar,
.topbar-left,
.content > .topbar {
    border-left-color: <?= $color_barrita ?> !important;
    transition: border-left-color .25s ease;
}
.topbar::before,
.topbar-left::before {
    background: <?= $color_barrita ?> !important;
    transition: background .25s ease;
}
<?php if ($ver_archivados): ?>
.topbar-leyenda b { color: #F08217 !important; }
<?php endif; ?>

/* ============ TOAST CENTRADO ============ */
.toast-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    pointer-events: none;
}
.toast-box {
    background: #fff;
    border-radius: 12px;
    padding: 18px 26px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 11pt;
    color: var(--rtp-gris);
    border-left: 6px solid var(--rtp-verde);
    min-width: 300px;
    max-width: 520px;
    opacity: 0;
    transform: scale(0.8) translateY(20px);
    transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    pointer-events: auto;
}
.toast-box.show {
    opacity: 1;
    transform: scale(1) translateY(0);
}
.toast-icon { font-size: 22pt; flex-shrink: 0; }
.toast-text b { color: var(--rtp-guinda); font-weight: 700; }
</style>
</head>
<body>
<div class="app">
    <div class="toast-overlay" id="toastOverlay"></div>

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <div class="topbar">
            <div class="topbar-left">
                <h1><?= $ver_archivados ? 'Proyectos archivados' : 'Proyectos' ?></h1>

                <?php if ($ver_activos): ?>
                    <span class="topbar-leyenda" id="leyenda-top">
                        <b><?= $total_finalizados ?></b> proyectos finalizados,
                        <b><?= $total_sin_finalizar ?></b> sin finalizar
                    </span>
                <?php else: ?>
                    <span class="topbar-leyenda">
                        <b><?= $total_archivados ?></b> proyecto<?= $total_archivados == 1 ? '' : 's' ?> archivado<?= $total_archivados == 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="topbar-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
        </div>

        <div class="projects-actions">
            <?php if ($ver_activos): ?>
                <?php if ($user['rol'] !== 'visualizador'): ?>
                    <a class="btn btn-nuevo-proyecto" href="project_new.php">+ Nuevo proyecto</a>
                <?php endif; ?>

                <a class="btn btn-archivados" href="projects.php?archivados=1">
                    📦 Proyectos archivados
                </a>
            <?php else: ?>
                <a class="btn btn-volver" href="projects.php">← Volver a activos</a>
            <?php endif; ?>
        </div>

        <div class="projects-search">
            <input type="text"
                   id="buscador"
                   placeholder="🔍 <?= h($placeholder_busqueda) ?>"
                   oninput="filtrarProyectos()"
                   onkeydown="if(event.key === 'Enter') { event.preventDefault(); buscarProyecto(); }">
        </div>

        <?php if ($ver_activos): ?>
            <!-- ============ VISTA KANBAN (ACTIVOS) ============ -->
            <div class="projects-board<?= $puede_reordenar ? ' board-reordenable' : '' ?>">

                <div class="board-column" data-col="sin_iniciar">
                    <div class="board-header">
                        <span class="board-dot" style="background:#94a3b8;"></span>
                        <span class="board-title">Sin iniciar</span>
                        <span class="board-count"><?= count($col_sin_iniciar) ?></span>
                    </div>
                    <div class="board-cards" data-col="sin_iniciar">
                        <?php foreach ($col_sin_iniciar as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="board-column" data-col="en_curso">
                    <div class="board-header">
                        <span class="board-dot" style="background:#266cb4;"></span>
                        <span class="board-title">En curso</span>
                        <span class="board-count"><?= count($col_en_curso) ?></span>
                    </div>
                    <div class="board-cards" data-col="en_curso">
                        <?php foreach ($col_en_curso as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="board-column" data-col="en_revision">
                    <div class="board-header">
                        <span class="board-dot" style="background:#F08217;"></span>
                        <span class="board-title">En revisión</span>
                        <span class="board-count"><?= count($col_en_revision) ?></span>
                    </div>
                    <div class="board-cards" data-col="en_revision">
                        <?php foreach ($col_en_revision as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="board-column" data-col="finalizado">
                    <div class="board-header">
                        <span class="board-dot" style="background:#027a35;"></span>
                        <span class="board-title">Finalizado</span>
                        <span class="board-count"><?= count($col_finalizado) ?></span>
                    </div>
                    <div class="board-cards" data-col="finalizado">
                        <?php foreach ($col_finalizado as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ===== PROGRAMADOS ===== -->
                <div class="board-column board-column-programado" data-col="programado">
                    <div class="board-header">
                        <span class="board-dot" style="background:#F08217;"></span>
                        <span class="board-title">Programados</span>
                        <span class="board-count"><?= count($col_programado) ?></span>
                    </div>
                    <div class="board-cards" data-col="programado">
                        <?php foreach ($col_programado as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ===== BLOQUEADOS ===== -->
                <div class="board-column board-column-bloqueado" data-col="bloqueado">
                    <div class="board-header">
                        <span class="board-dot" style="background:#E5074C;"></span>
                        <span class="board-title">Bloqueado</span>
                        <span class="board-count"><?= count($col_bloqueado) ?></span>
                    </div>
                    <div class="board-cards" data-col="bloqueado">
                        <?php foreach ($col_bloqueado as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar, $miembros_por_proyecto); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

        <?php else: ?>
            <!-- ============ VISTA LISTA A–Z (ARCHIVADOS) ============ -->
            <?php if (empty($proyectos)): ?>
                <div class="archived-empty">
                    <div style="font-size:40pt; margin-bottom:12px;">📦</div>
                    <p>No hay proyectos archivados.</p>
                </div>
            <?php else: ?>
                <div class="archived-list">
                    <?php foreach ($proyectos as $p): ?>
                        <?php renderArchivedRow($p); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($proyectos)): ?>
            <p class="empty-msg" style="margin-top:30px;">No hay proyectos para mostrar.</p>
        <?php endif; ?>
    </main>
</div>

<?php
function renderCard($p, $user, $puede_reordenar = false, $miembros_por_proyecto = []) {
    $progreso = (float) $p['porcentaje_avance'];
    $pctVisible = min(100, max(0, $progreso));
    $resto = 100 - $pctVisible;
    $color = color_progreso($progreso);
    $bloqueado = !empty($p['bloqueado']) || (!empty($p['columna_manual']) && $p['columna_manual'] === 'bloqueado');

    $fecha_ap = !empty($p['fecha_apertura']) ? strtotime($p['fecha_apertura']) : null;
    $programado = ($fecha_ap && $fecha_ap > time()) || (!empty($p['columna_manual']) && $p['columna_manual'] === 'programado');

    $puede_arrastrar = $puede_reordenar;

    $miembros = $miembros_por_proyecto[(int)$p['id_proyecto']] ?? [];
    $total_miembros = count($miembros);
    ?>
    <div class="project-card <?= $bloqueado ? 'card-bloqueado' : '' ?> <?= $programado ? 'card-programado' : '' ?>"
         id="proyecto-<?= (int)$p['id_proyecto'] ?>"
         data-id="<?= (int)$p['id_proyecto'] ?>"
         data-estado="<?= h($p['columna_manual'] ?? ($bloqueado ? 'bloqueado' : ($programado ? 'programado' : ''))) ?>"
         data-name="<?= h(strtolower($p['nombre'] . ' ' . ($p['descripcion'] ?? ''))) ?>"
         <?= $puede_arrastrar ? 'draggable="false"' : '' ?>
         onclick="abrirProyecto(event, <?= (int)$p['id_proyecto'] ?>)">

        <?php if ($puede_arrastrar): ?>
            <div class="drag-handle" data-drag="true" title="Arrastra para mover">
                <span class="bar bar-1"></span>
                <span class="bar bar-2"></span>
                <span class="bar bar-3"></span>
            </div>
        <?php endif; ?>

        <?php if ($bloqueado): ?>
            <span class="bloqueo-badge" title="Proyecto bloqueado">🔒</span>
        <?php elseif ($programado): ?>
            <span class="bloqueo-badge badge-programado" title="Proyecto programado">⏳</span>
        <?php endif; ?>

        <div class="project-card-body">
            <div class="project-card-pct-ring" title="<?= $progreso ?>% de avance">
                <svg viewBox="0 0 36 36" width="42" height="42">
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="<?= $programado ? '#F08217' : $color ?>" stroke-width="3"
                            stroke-dasharray="<?= $pctVisible ?> <?= $resto ?>"
                            stroke-dashoffset="0"
                            stroke-linecap="round"/>
                </svg>
                <span class="project-card-pct-text" style="<?= $programado ? 'color:#F08217;' : '' ?>"><?= $progreso ?>%</span>
            </div>

            <div class="project-card-title"><?= h($p['nombre']) ?></div>
        </div>

        <div class="project-card-dates">
            <?php if ($bloqueado): ?>
                <span class="priority-dot" style="background:#E5074C;"></span>
                <span style="color:#E5074C; font-weight:700;">
                    🔒 Bloqueado el <?= h(fmt_date($p['bloqueado_en'] ?? '')) ?>
                </span>
            <?php elseif ($programado): ?>
                <span class="priority-dot" style="background:#F08217;"></span>
                <span style="color:#F08217; font-weight:700;">⏳ Se abre: <?= h(date('d/m/Y', $fecha_ap ?: time())) ?> · <?= h(date('H:i', $fecha_ap ?: time())) ?></span>
            <?php else: ?>
                <?php if (!empty($p['fecha_inicio'])): ?>
                    <span class="date-inicio"><span class="priority-dot" style="background:#94a3b8;"></span> Inicio: <?= h(fmt_date($p['fecha_inicio'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($p['fecha_fin'])): ?>
                    <span class="date-fin"><span class="priority-dot" style="background:<?= $p['estado'] === 'finalizado' ? '#027a35' : $color ?>;"></span>
                        <?= $p['estado'] === 'finalizado' ? '🏁 Cerrado' : 'Vence' ?>: <?= h(fmt_date($p['fecha_fin'])) ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="project-card-footer">
            <div class="project-card-responsables">
                <?php if ($total_miembros > 0): ?>
                    <div class="project-card-avatar group-avatar" 
                         title="Ver equipo del proyecto"
                         onclick="event.stopPropagation(); abrirModalEquipo(<?= (int)$p['id_proyecto'] ?>, '<?= h(addslashes($p['nombre'])) ?>')">
                        👥
                    </div>
                    <span class="group-count"><?= $total_miembros ?></span>
                    <script>
                        if (!window.EQUIPOS_PROYECTOS) window.EQUIPOS_PROYECTOS = {};
                        window.EQUIPOS_PROYECTOS[<?= (int)$p['id_proyecto'] ?>] = <?= json_encode($miembros) ?>;
                    </script>
                <?php elseif ($p['responsable_nombre']): ?>
                    <div class="project-card-avatar" title="<?= h($p['responsable_nombre']) ?>">
                        <?= h(iniciales($p['responsable_nombre'])) ?>
                    </div>
                <?php else: ?>
                    <div class="project-card-avatar" style="background:#94a3b8;" title="Sin asignar">?</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function renderArchivedRow($p) {
    $progreso = (float) $p['porcentaje_avance'];
    $pctVisible = min(100, max(0, $progreso));
    $resto = 100 - $pctVisible;
    $color = color_progreso($progreso);
    $iniciales_resp = iniciales($p['responsable_nombre'] ?? '');
    ?>
    <div class="archived-row"
         id="proyecto-<?= (int)$p['id_proyecto'] ?>"
         data-id="<?= (int)$p['id_proyecto'] ?>"
         data-name="<?= h(strtolower($p['nombre'] . ' ' . ($p['descripcion'] ?? ''))) ?>"
         onclick="abrirProyecto(event, <?= (int)$p['id_proyecto'] ?>)">

        <div class="archived-row-icon">📦</div>

        <div class="archived-row-main">
            <div class="archived-row-title"><?= h($p['nombre']) ?></div>
            <div class="archived-row-meta">
                <?php if (!empty($p['archivado_en'])): ?>
                    <span>📦 Archivado: <?= h(fmt_date($p['archivado_en'])) ?></span>
                <?php endif; ?>
                <?php if ($p['fecha_fin']): ?>
                    <span>· 🏁 Fin: <?= h(fmt_date($p['fecha_fin'])) ?></span>
                <?php endif; ?>
                <?php if ($p['responsable_nombre']): ?>
                    <span>· 👤 <?= h($p['responsable_nombre']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="archived-row-pct" title="<?= $progreso ?>% de avance">
            <svg viewBox="0 0 36 36" width="38" height="38">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="<?= $color ?>" stroke-width="3"
                        stroke-dasharray="<?= $pctVisible ?> <?= $resto ?>"
                        stroke-dashoffset="0" stroke-linecap="round"/>
            </svg>
            <span class="archived-row-pct-text"><?= $progreso ?>%</span>
        </div>

        <div class="archived-row-resp">
            <?php if ($p['responsable_nombre']): ?>
                <div class="project-card-avatar" title="<?= h($p['responsable_nombre']) ?>">
                    <?= h($iniciales_resp) ?>
                </div>
            <?php else: ?>
                <div class="project-card-avatar" style="background:#94a3b8;" title="Sin asignar">?</div>
            <?php endif; ?>
        </div>

        <div class="archived-row-arrow">→</div>
    </div>
    <?php
}
?>

<style>
/* ============ FIX: content y board para scroll horizontal visible ============ */
.content {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
/* ===== BOARD: scroll idéntico a project_detail.php (nativo del navegador) ===== */
.projects-board {
    display: grid;
    grid-template-columns: repeat(6, 280px);
    gap: 16px;
    align-items: stretch;
    overflow-x: auto;
    padding-bottom: 10px;
    margin-bottom: 24px;
    min-height: calc(100vh - 320px);
}

.projects-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.projects-search { margin-bottom: 20px; }
.projects-search input {
    width: 100%;
    max-width: 400px;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 12pt;
    font-family: inherit;
    color: var(--rtp-gris);
    background: white;
}
.projects-search input:focus {
    outline: none;
    border-color: var(--rtp-verde);
    box-shadow: 0 0 0 3px rgba(120,190,32,.15);
}

@keyframes destelloBusqueda {
    0%   { box-shadow: 0 0 0 0 rgba(120, 190, 32, 0.8); transform: scale(1); }
    50%  { box-shadow: 0 0 0 20px rgba(120, 190, 32, 0), 0 0 20px 5px rgba(120, 190, 32, 0.5); transform: scale(1.03); }
    100% { box-shadow: 0 0 0 0 rgba(120, 190, 32, 0), 0 0 0 0 rgba(120, 190, 32, 0); transform: scale(1); }
}
.card-destello {
    animation: destelloBusqueda 1s cubic-bezier(0.22, 1, 0.36, 1) 3;
    border-color: var(--rtp-verde) !important;
    z-index: 10;
}

.topbar-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.topbar-leyenda {
    font-size: 10pt;
    color: var(--rtp-gris);
    font-weight: 400;
}
.topbar-leyenda b {
    color: var(--rtp-guinda);
    font-weight: 700;
}

/* ============ BOTONES ============ */
.projects-actions .btn {
    position: relative;
    transition: all .2s ease;
    cursor: pointer;
    opacity: 1;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 10.5pt;
    font-weight: 600;
    font-family: inherit;
    color: white;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-nuevo-proyecto { background: #78BE20 !important; }
.btn-nuevo-proyecto:hover {
    background: #5fa015 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.45), 0 2px 6px rgba(120, 190, 32, 0.3);
    filter: brightness(1.05);
}
.btn-archivados { background: #F08217 !important; }
.btn-archivados:hover {
    background: #d97412 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(240, 130, 23, 0.45), 0 2px 6px rgba(240, 130, 23, 0.3);
    filter: brightness(1.05);
}
.btn-volver { background: #94a3b8 !important; }
.btn-volver:hover {
    background: #7a8699 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(148, 163, 184, 0.45), 0 2px 6px rgba(148, 163, 184, 0.3);
    filter: brightness(1.05);
}

/* ============ BOARD (ACTIVOS) ============ */
.board-column {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    min-height: calc(100vh - 360px);
    width: 280px;
    box-sizing: border-box;
    transition: box-shadow .3s cubic-bezier(0.22, 1, 0.36, 1), background .3s ease;
}
.board-column.drag-over-target {
    box-shadow: 0 0 0 2px rgba(120, 190, 32, 0.25), 0 0 32px rgba(120, 190, 32, 0.35), 0 0 60px rgba(120, 190, 32, 0.15);
    background: #fbfef5;
}
/* Columnas programado / bloqueado: diseño diferenciado pero DESTINO VÁLIDO */
.board-column.board-column-programado {
    background: #fff7ed;
    border: 1px dashed #fed7aa;
}
.board-column.board-column-bloqueado {
    background: #fef2f4;
    border: 1px dashed #fbc5d2;
}
.board-column.board-column-programado.drag-over-target {
    background: #fff7ed;
    box-shadow: 0 0 0 2px rgba(240, 130, 23, 0.35), 0 0 32px rgba(240, 130, 23, 0.45), 0 0 60px rgba(240, 130, 23, 0.2);
    border-color: rgba(240, 130, 23, 0.55);
}
.board-column.board-column-bloqueado.drag-over-target {
    background: #fef2f4;
    box-shadow: 0 0 0 2px rgba(229, 7, 76, 0.35), 0 0 32px rgba(229, 7, 76, 0.45), 0 0 60px rgba(229, 7, 76, 0.2);
    border-color: rgba(229, 7, 76, 0.55);
}

.board-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 12px;
}
.board-dot {
    width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0;
}
.board-title {
    font-weight: 700; font-size: 12pt; color: var(--rtp-guinda); flex: 1;
}
.board-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 26px; height: 22px; padding: 0 8px;
    background: #e2e8f0; color: #475569; border-radius: 999px;
    font-size: 9.5pt; font-weight: 700; pointer-events: none; user-select: none;
    font-family: 'Roboto', sans-serif; border: none;
    transition: transform .2s ease, background .2s ease, color .2s ease;
}
.board-count.pop { animation: countPop .45s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes countPop {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.4); background: #78BE20; color: white; }
    70%  { transform: scale(1.15); }
    100% { transform: scale(1); background: #e2e8f0; color: #475569; }
}
.board-cards { display: flex; flex-direction: column; gap: 10px; min-height: 40px; }

/* ============ CARD (ACTIVOS) ============ */
.project-card {
    background: white;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid var(--rtp-verde);
    margin-bottom: 0;
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
.project-card.drag-origin .drag-handle .bar,
.project-card.drag-ghost .drag-handle .bar { background: var(--rtp-verde) !important; width: 12px !important; }

.drag-placeholder {
    border-radius: 10px; border: none;
    background: linear-gradient(135deg, #ffffff 0%, #f4ffdf 35%, #e3f7c2 70%, #cdefa0 100%);
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.18), 0 4px 18px rgba(120, 190, 32, 0.25), inset 0 0 24px rgba(120, 190, 32, 0.12);
    position: relative; flex-shrink: 0; overflow: hidden;
    animation: placeholderPulse 1.8s ease-in-out infinite;
    transition: height .25s cubic-bezier(0.22, 1, 0.36, 1);
}
.drag-placeholder::before {
    content: ''; position: absolute; inset: 0; border-radius: 10px;
    background: radial-gradient(circle at 30% 40%, rgba(120, 190, 32, 0.22) 0%, rgba(120, 190, 32, 0.08) 40%, transparent 75%);
    pointer-events: none; animation: placeholderGlow 2.4s ease-in-out infinite;
}
.drag-placeholder::after {
    content: ''; position: absolute; top: 0; left: -60%; width: 60%; height: 100%;
    background: linear-gradient(100deg, transparent 0%, rgba(255, 255, 255, 0.7) 45%, rgba(255, 255, 255, 0.95) 50%, rgba(255, 255, 255, 0.7) 55%, transparent 100%);
    transform: skewX(-20deg); animation: placeholderShimmer 2.8s ease-in-out infinite; pointer-events: none;
}
@keyframes placeholderPulse {
    0%, 100% { box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.18), 0 4px 18px rgba(120, 190, 32, 0.25), inset 0 0 24px rgba(120, 190, 32, 0.12); }
    50% { box-shadow: 0 0 0 6px rgba(120, 190, 32, 0.1), 0 6px 26px rgba(120, 190, 32, 0.4), inset 0 0 34px rgba(120, 190, 32, 0.22); }
}
@keyframes placeholderGlow { 0%, 100% { opacity: 0.8; } 50% { opacity: 1; } }
@keyframes placeholderShimmer { 0% { left: -60%; } 55% { left: 120%; } 100% { left: 120%; } }

.project-card.drag-ghost {
    position: fixed !important; pointer-events: none !important; z-index: 9999 !important;
    margin: 0 !important; opacity: 1; transform: scale(1.05) !important;
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.5), 0 24px 52px rgba(120, 190, 32, 0.5), 0 10px 24px rgba(0, 0, 0, 0.22) !important;
    border: 2px solid #78BE20 !important;
    background: #fbfff5 !important;
    cursor: grabbing;
    transition: transform .18s cubic-bezier(0.22, 1, 0.36, 1), box-shadow .18s ease, left 0s, top 0s;
    animation: ghostFloat 1.4s ease-in-out infinite;
}
.project-card.drag-ghost * { opacity: 1 !important; visibility: visible !important; }
@keyframes ghostFloat { 0%, 100% { transform: scale(1.05); } 50% { transform: scale(1.07); } }
.project-card.drag-origin { display: none; }

.project-card.drop-success { animation: dropSuccess .9s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes dropSuccess {
    0% { background: #ecf9d9; box-shadow: 0 0 0 8px rgba(120, 190, 32, 0.7), 0 0 48px rgba(120, 190, 32, 0.9); transform: scale(1.06); }
    35% { background: #f4ffdf; box-shadow: 0 0 0 4px rgba(120, 190, 32, 0.4), 0 0 24px rgba(120, 190, 32, 0.5); transform: scale(0.98); }
    55% { transform: scale(1.02); }
    75% { transform: scale(0.995); }
    100% { background: white; box-shadow: 0 0 0 0 rgba(120, 190, 32, 0), 0 0 0 rgba(120, 190, 32, 0); transform: scale(1); }
}

/* ===== CARD BLOQUEADO ===== */
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

/* ============ FECHAS EN LA TARJETA ============ */
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
    display: flex; justify-content: flex-end; align-items: center; gap: 10px;
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
}

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
.project-card.hidden { display: none; }

/* ============ LISTA A-Z (ARCHIVADOS) ============ */
.archived-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 24px;
}
.archived-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    border-left: 5px solid #FDC60A;
    box-shadow: 0 2px 6px rgba(0,0,0,.04);
    cursor: pointer;
    transition: transform .2s cubic-bezier(0.22, 1, 0.36, 1),
                box-shadow .2s ease,
                border-color .2s ease,
                background .2s ease;
}
.archived-row:hover {
    transform: translateY(-2px);
    background: #fffdf2;
    border-color: #FDC60A;
    box-shadow: 0 8px 20px rgba(253, 198, 10, 0.25), 0 0 0 1px rgba(253, 198, 10, 0.35);
}
.archived-row-icon {
    font-size: 22pt;
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #fffbe6 0%, #fff3bf 100%);
    border-radius: 12px;
    border: 1px solid rgba(253, 198, 10, 0.35);
}
.archived-row-main {
    flex: 1;
    min-width: 0;
}
.archived-row-title {
    font-weight: 700;
    font-size: 12pt;
    color: var(--rtp-guinda);
    margin-bottom: 4px;
    line-height: 1.3;
}
.archived-row-meta {
    font-size: 9.5pt;
    color: var(--rtp-gris);
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.archived-row-pct {
    position: relative;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.archived-row-pct svg {
    position: absolute;
    top: 0; left: 0;
    transform: rotate(-90deg);
}
.archived-row-pct-text {
    position: relative;
    font-size: 7.5pt;
    font-weight: 700;
    color: var(--rtp-verde-dark);
    z-index: 1;
}
.archived-row-resp {
    display: flex;
    align-items: center;
    flex-shrink: 0;
}
.archived-row-arrow {
    font-size: 18pt;
    color: #FDC60A;
    flex-shrink: 0;
    transition: transform .2s ease, color .2s ease;
}
.archived-row:hover .archived-row-arrow {
    transform: translateX(4px);
    color: #b58900;
}
.archived-row.hidden { display: none; }

.archived-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--rtp-gris);
    background: #f8fafc;
    border-radius: 12px;
    margin-top: 10px;
}
.archived-empty p {
    font-size: 11pt;
    font-style: italic;
}

/* ============ RESPONSIVE ============ */
@media (max-width: 1600px) {
    .projects-board { grid-template-columns: repeat(6, 280px); }
}
@media (max-width: 700px) {
    .archived-row { padding: 12px 14px; gap: 10px; }
    .archived-row-icon { width: 38px; height: 38px; font-size: 18pt; }
    .archived-row-title { font-size: 11pt; }
}

/* ============================================================
   MODAL RESUMEN DE USUARIO
   ============================================================ */
#modalResumenUsuario {
    padding: 20px;
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
    top: 12px;
    right: 16px;
    opacity: 0.8;
}
#modalResumenUsuario .close:hover { opacity: 1; }
.resumen-hero {
    background: linear-gradient(135deg, var(--rtp-guinda) 0%, #5a0f24 100%);
    padding: 40px 48px 50px;
    color: #fff;
    position: relative;
}
.resumen-hero::after {
    content: '';
    position: absolute;
    bottom: -1px; left: 0; right: 0; height: 40px;
    background: #fff;
    border-radius: 40px 40px 0 0;
}
.resumen-hero-inner { display: flex; align-items: center; gap: 32px; position: relative; z-index: 2; }
.resumen-user-avatar-big {
    width: 100px; height: 100px; border-radius: 50%;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 30pt; color: var(--rtp-guinda);
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    border: 4px solid rgba(255,255,255,0.5);
    flex-shrink: 0;
}
.resumen-user-meta { flex: 1; min-width: 0; }
.resumen-user-name-big {
    font-size: 22pt; font-weight: 800; margin-bottom: 8px; line-height: 1.2;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.resumen-user-email-big { font-size: 12pt; color: rgba(255,255,255,0.85); word-break: break-all; margin-bottom: 10px; }
.resumen-rol-badge {
    background: rgba(255,255,255,0.2);
    color: #fff;
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 10pt;
    font-weight: 700;
    text-transform: capitalize;
}
.resumen-body { padding: 0 48px 36px; }

.resumen-dashboard {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 40px;
    align-items: center;
    margin-top: -20px;
    margin-bottom: 32px;
}
.resumen-progress-card {
    background: #f8fafc;
    border-radius: 16px;
    padding: 30px;
    display: flex; flex-direction: column; align-items: center;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}
.resumen-progress-ring-big { position: relative; width: 150px; height: 150px; display: flex; align-items: center; justify-content: center; }
.resumen-progress-ring-big svg { position: absolute; top: 0; left: 0; width: 150px; height: 150px; transform: rotate(-90deg); }
.resumen-progress-text-big { position: relative; font-size: 26pt; font-weight: 800; z-index: 1; }
.resumen-progress-label { font-size: 10pt; color: #64748b; text-transform: uppercase; font-weight: 700; margin-top: 12px; text-align: center; }
.resumen-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.resumen-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px 16px;
    text-align: center;
    border: 1px solid #e2e8f0;
    border-left-width: 4px;
    transition: all .2s ease;
    cursor: pointer;
    user-select: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}
.resumen-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    border-color: #cbd5e1;
}
.resumen-stat-card.filtro-activo {
    background: #f8fafc;
    box-shadow: inset 0 0 0 2px var(--rtp-guinda);
    border-color: var(--rtp-guinda);
}
.resumen-stat-num { display: block; font-size: 22pt; font-weight: 800; line-height: 1; margin-bottom: 8px; }
.resumen-stat-lbl { display: block; font-size: 9pt; color: #64748b; text-transform: uppercase; font-weight: 700; }
.resumen-alertas-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 28px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.resumen-alertas-title {
    font-size: 12pt; font-weight: 800; color: var(--rtp-guinda);
    text-transform: uppercase; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.resumen-alerta-item {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 16px;
    background: #fff7ed;
    border-radius: 10px;
    border-left: 4px solid #F08217;
    margin-bottom: 10px;
    font-size: 11pt;
}
.resumen-alerta-item.bloqueo { background: #fde8ec; border-left-color: #E5074C; }
.resumen-alerta-item.vencida { background: #fef2f2; border-left-color: #dc2626; }
.resumen-alerta-txt { flex: 1; color: var(--rtp-negro); font-weight: 600; line-height: 1.4; }
.resumen-alerta-txt span { display: block; font-size: 9.5pt; color: #64748b; font-weight: 400; margin-top: 4px; }
.resumen-alertas-vacio { text-align: center; color: #027a35; font-weight: 600; padding: 16px; font-size: 11pt; background: #f0faf1; border-radius: 10px; border: 1px dashed #a7f3d0; }
.resumen-actividades-section { margin-top: 8px; }
.resumen-actividades-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
}
.resumen-actividades-header h3 {
    margin: 0; font-size: 12pt; font-weight: 800; color: var(--rtp-guinda);
    text-transform: uppercase;
}
.resumen-actividades-count {
    background: #ecf9d9; color: var(--rtp-verde-dark);
    padding: 5px 14px; border-radius: 999px; font-size: 9.5pt; font-weight: 700;
}
.btn-ver-todas {
    background: #f1f5f9; color: #475569; border: none; padding: 6px 14px; border-radius: 999px; font-size: 9.5pt; font-weight: 700; cursor: pointer; transition: all .15s;
}
.btn-ver-todas:hover { background: #e2e8f0; color: var(--rtp-guinda); }
.resumen-acts-list {
    display: flex; flex-direction: column; gap: 12px;
    padding-right: 6px;
}
.resumen-act-item {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 18px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-left-width: 4px;
    border-radius: 12px;
    transition: transform .15s ease, box-shadow .15s ease;
    cursor: pointer;
}
.resumen-act-item:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); border-color: var(--rtp-verde); }
.resumen-act-info { flex: 1; min-width: 0; }
.resumen-act-title { font-size: 11pt; font-weight: 700; color: var(--rtp-negro); margin-bottom: 4px; }
.resumen-act-meta { font-size: 9pt; color: #64748b; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.resumen-act-pct {
    background: #ecf9d9; color: var(--rtp-verde-dark);
    padding: 6px 12px; border-radius: 999px;
    font-size: 10pt; font-weight: 800; white-space: nowrap;
}
.resumen-empty-acts {
    background: #f8fafc;
    padding: 40px 20px;
    border-radius: 12px;
    text-align: center;
    color: #94a3b8;
    font-style: italic;
    font-size: 11pt;
    border: 2px dashed #e2e8f0;
}
.resumen-empty-acts .icon { font-size: 32pt; display: block; margin-bottom: 12px; }
.resumen-footer { padding: 16px 48px; border-top: 1px solid #e2e8f0; background: #fff; }

@media (max-width: 800px) {
    .resumen-dashboard { grid-template-columns: 1fr; }
    .resumen-progress-card { flex-direction: row; justify-content: center; gap: 20px; }
    .resumen-hero { padding: 30px 24px 40px; }
    .resumen-body, .resumen-footer { padding-left: 24px; padding-right: 24px; }
    .resumen-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* MODAL GENÉRICO */
.modal { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: flex; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
.modal.hidden { display: none; }
.modal-content { background: #fff; border-radius: 14px; width: 100%; max-width: 700px; max-height: 92vh; overflow-y: auto; padding: 24px 28px; position: relative; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.modal-content .close { position: absolute; top: 10px; right: 16px; font-size: 22pt; line-height: 1; cursor: pointer; color: var(--rtp-gris); background: none; border: none; font-weight: bold; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all .15s; }
.modal-content .close:hover { background: #fee2e2; color: #E5074C; }

/* ============================================================
   MODAL: EQUIPO DEL PROYECTO (MINI POPUP)
   ============================================================ */
#modalEquipo .modal-content {
    max-width: 420px;
    border-top: 5px solid var(--rtp-verde);
    padding: 20px 24px;
    border-radius: 16px;
}
#modalEquipo h2 {
    color: var(--rtp-guinda);
    font-size: 15pt;
    font-weight: 800;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
#modalEquipo .subtitulo-equipo {
    color: #64748b;
    font-size: 10.5pt;
    margin-bottom: 16px;
    line-height: 1.4;
}
.equipo-lista {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 350px;
    overflow-y: auto;
    padding-right: 4px;
}
.equipo-lista::-webkit-scrollbar { width: 5px; }
.equipo-lista::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.equipo-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all .15s ease;
}
.equipo-item:hover {
    background: #fff;
    border-color: var(--rtp-verde);
    box-shadow: 0 4px 12px rgba(120,190,32,.15);
    transform: translateY(-1px);
}
.equipo-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--rtp-verde);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 11pt;
    flex-shrink: 0;
}
.equipo-info { flex: 1; min-width: 0; }
.equipo-nombre {
    font-weight: 700; color: var(--rtp-negro);
    font-size: 11pt; line-height: 1.3;
    margin-bottom: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.equipo-rol {
    font-size: 9pt; color: #64748b;
    text-transform: capitalize;
}
.equipo-progreso {
    display: flex; flex-direction: column; align-items: flex-end;
    flex-shrink: 0;
}
.equipo-progreso-texto {
    font-size: 12pt; font-weight: 800;
    color: var(--rtp-verde-dark);
    line-height: 1;
    margin-bottom: 2px;
}
.equipo-progreso-mini-bar {
    width: 50px; height: 4px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}
.equipo-progreso-mini-bar-fill {
    height: 100%; background: var(--rtp-verde); border-radius: 999px;
}

/* ============================================================
   MODAL DETALLE ACTIVIDAD
   ============================================================ */
.modal-actividad { position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; z-index: 5000; padding:20px; }
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
.adjunto-item.enlace { background:#fef3c7; color:#78350f; border-left-color:#F08217; }
.adjunto-item .adjunto-icon { font-size:14pt; }
.adjunto-item .adjunto-name { max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.comentarios-list { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.comentario-item { background:#f8fafc; border-radius:10px; padding:12px 16px; border-left:4px solid var(--rtp-verde); }
.comentario-meta { font-size:9pt; color:var(--rtp-gris); margin-bottom:6px; display:flex; align-items:center; gap:8px; }
.comentario-meta b { color:var(--rtp-guinda); font-size:10pt; }
.comentario-texto { font-size:10.5pt; color:var(--rtp-negro); line-height:1.5; }
.actividad-footer { padding:16px 26px; border-top:1px solid #e2e8f0; background:#fafbfc; display:flex; justify-content:flex-end; align-items:center; gap:12px; flex-wrap:wrap; }
.btn-modal { padding:10px 18px; border-radius:8px; font-size:10.5pt; font-weight:600; cursor:pointer; font-family:inherit; border:none; transition:all .15s; }
.btn-modal.secondary { background:#fff; color:var(--rtp-gris); border:2px solid #e2e8f0; }
.btn-modal.secondary:hover { border-color:var(--rtp-guinda); color:var(--rtp-guinda); }

/* ============================================================
   MODALES DRAG: PROGRAMADO / BLOQUEADO / APERTURAR
   ============================================================ */
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

/* DRAG → BLOQUEADO */
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

/* MODAL: DESBLOQUEAR PROYECTO (drag desde bloqueado) */
#modalAperturarProyecto .modal-content {
    max-width: 520px;
    border-top: 5px solid #E5074C;
    padding: 28px 30px 24px;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.28);
}
#modalAperturarProyecto h2 {
    color: #E5074C;
    font-size: 16pt;
    margin: 0 0 8px 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}
#modalAperturarProyecto .apertura-sub {
    color: #991b1b;
    font-size: 10.5pt;
    line-height: 1.6;
    margin-bottom: 18px;
    background: #fde8ec;
    border-left: 4px solid #E5074C;
    border-radius: 10px;
    padding: 14px 16px;
}
#modalAperturarProyecto .apertura-sub b { color: #E5074C; }
#modalAperturarProyecto .apertura-countdown-btn {
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
#modalAperturarProyecto .apertura-countdown-btn.ready {
    background: #E5074C;
    color: #fff;
    cursor: pointer;
    box-shadow: 0 6px 16px rgba(229,7,76,.4);
}
#modalAperturarProyecto .apertura-countdown-btn.ready:hover {
    background: #b8063c;
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(229,7,76,.55);
}
#modalAperturarProyecto .apertura-acciones {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
}
#modalAperturarProyecto .apertura-acciones .btn { flex: 1; padding: 12px 18px; font-size: 11pt; font-weight: 700; border-radius: 10px; }
#modalAperturarProyecto .btn-confirmar-aperturar {
    background: #E5074C !important;
    color: #fff !important;
    border: none !important;
}
#modalAperturarProyecto .btn-confirmar-aperturar:hover {
    background: #b8063c !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(229,7,76,.45);
}
#modalAperturarProyecto .btn-confirmar-aperturar:disabled {
    background: #f1f5f9 !important;
    color: #94a3b8 !important;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
</style>

<!-- ============ MODAL: EQUIPO DEL PROYECTO ============ -->
<div id="modalEquipo" class="modal hidden" style="z-index:3100;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalEquipo()">×</span>
        <h2>👥 Equipo del proyecto</h2>
        <p class="subtitulo-equipo" id="equipoSubtitulo">Proyecto</p>
        <div class="equipo-lista" id="equipoLista"></div>
        <div style="margin-top:16px;">
            <button class="btn btn-outline" onclick="cerrarModalEquipo()" style="width:100%; border-radius:8px; padding:10px; font-weight:600; background:#f8fafc; border:1px solid #e2e8f0; cursor:pointer; color:#475569; font-family:inherit;">← Cerrar equipo</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: RESUMEN DE USUARIO ============ -->
<div id="modalResumenUsuario" class="modal hidden" style="z-index:3400;">
    <div class="modal-content">
        <span class="close" onclick="cerrarResumenUsuario()">×</span>
        <div id="resumenUsuarioBody" style="display:flex; flex-direction:column; height:100%; width:100%;"></div>
    </div>
</div>

<!-- ============ MODAL: DETALLE DE ACTIVIDAD ============ -->
<div id="modalDetalleActividad" class="modal-actividad hidden" style="z-index:5500;">
    <div class="actividad-content" id="actividadContent"></div>
</div>

<!-- ============ MODAL: DRAG → PROGRAMADO ============ -->
<div id="modalDragProgramado" class="modal hidden" style="z-index:4500;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalDragProgramado()">×</span>
        <h2>⏳ Programar proyecto</h2>
        <div class="drag-sub">
            Este proyecto estaba <b>en proceso de realización</b>. Si se coloca como <b>Programado</b>, se pospondrá su apertura y la edición del proyecto hasta la nueva fecha que asignes.
        </div>
        <div class="drag-aviso">
            💡 <b>Nota:</b> El proyecto seguirá visible en el tablero, pero no se podrá trabajar en él hasta la fecha de apertura.
        </div>
        <button type="button" id="btnCountdownProgramar" class="drag-countdown-btn" disabled onclick="mostrarFechaProgramar()">
            <span id="countdownTexto">Espera 8s...</span>
        </button>
        <div class="drag-fecha-wrap" id="dragFechaWrap">
            <label for="inputFechaProgramar">📅 Nueva fecha y hora de apertura</label>
            <input type="datetime-local" id="inputFechaProgramar">
            <div class="drag-fecha-hint">
                Al guardar, el proyecto se moverá a la columna <b>Programado</b> y quedará bloqueado para edición hasta esa fecha.
            </div>
        </div>
        <div class="drag-acciones">
            <button class="btn btn-outline" onclick="cerrarModalDragProgramado()">← Cancelar</button>
            <button class="btn btn-confirmar-programar" id="btnConfirmarProgramar" disabled onclick="guardarProgramacionDrag()">💾 Programar proyecto</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DRAG → BLOQUEADO ============ -->
<div id="modalDragBloqueado" class="modal hidden" style="z-index:4500;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalDragBloqueado()">×</span>
        <h2>🔒 Bloquear proyecto</h2>
        <div class="drag-sub">
            ¿Seguro que quieres <b>bloquear este proyecto</b>?<br>
            El proyecto se moverá a la columna <b>Bloqueado</b> y no se podrá trabajar en él hasta que lo desbloquees.
        </div>
        <div class="drag-motivo-wrap" id="dragMotivoWrap">
            <label for="motivoDragBloqueo">Motivo del bloqueo:</label>
            <textarea id="motivoDragBloqueo" placeholder="Explica por qué se bloquea este proyecto..."></textarea>
            <div class="drag-motivo-error" id="dragMotivoError">⚠️ El motivo es obligatorio</div>
        </div>
        <div class="drag-acciones">
            <button class="btn btn-outline" onclick="cerrarModalDragBloqueado()">← Cancelar</button>
            <button class="btn btn-si-bloquear" id="btnSiBloquear" onclick="habilitarMotivoDragBloqueo()">Sí, bloquear</button>
            <button class="btn btn-confirmar-bloqueo" id="btnConfirmarBloqueoDrag" onclick="guardarBloqueoDrag()">🔒 Confirmar bloqueo</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: DESBLOQUEAR PROYECTO (drag desde bloqueado) ============ -->
<div id="modalAperturarProyecto" class="modal hidden" style="z-index:4600;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalAperturarProyecto()">×</span>
        <h2 id="modalAperturarTitulo">⚡ Aperturar proyecto</h2>
        <div class="apertura-sub" id="modalAperturarSub">
            <!-- El texto se inyecta con JS -->
        </div>
        <button type="button" id="btnCountdownAperturar" class="apertura-countdown-btn" disabled onclick="confirmarAperturarProyecto()">
            <span id="countdownAperturarTexto">Espera 5s...</span>
        </button>
        <div class="apertura-acciones">
            <button class="btn btn-outline" onclick="cerrarModalAperturarProyecto()">← Cancelar</button>
            <button class="btn btn-confirmar-aperturar" id="btnConfirmarAperturar" disabled onclick="confirmarAperturarProyecto()">✅ Sí, continuar</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// ✅ TOAST
// ============================================================
(function() {
    try {
        const pending = sessionStorage.getItem('rtp_toast_pending');
        if (!pending) return;
        sessionStorage.removeItem('rtp_toast_pending');

        const data = JSON.parse(pending);
        const overlay = document.getElementById('toastOverlay');
        if (!overlay) return;

        const esc = (s) => (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        const toast = document.createElement('div');
        toast.className = 'toast-box';
        toast.innerHTML = `
            <div class="toast-icon">${data.icono || '✅'}</div>
            <div class="toast-text">${esc(data.mensaje || '')}</div>
        `;
        overlay.appendChild(toast);
        void toast.offsetWidth;
        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
        }, 3000);
    } catch (e) { /* silencioso */ }
})();

window.cardEstaSiendoArrastrada = function() { return false; };

window.abrirProyecto = function(event, idProyecto) {
    if (event.target.closest('.drag-handle')) return;
    if (event.target.closest('.project-card-avatar')) return; 
    if (window.cardEstaSiendoArrastrada && window.cardEstaSiendoArrastrada()) return;
    window.location.href = 'project_detail.php?id=' + idProyecto;
};

// ============================================================
// 🔍 BÚSQUEDA
// ============================================================
window.filtrarProyectos = function() {
    const query = document.getElementById('buscador').value.toLowerCase().trim();
    const items = document.querySelectorAll('.project-card, .archived-row');
    items.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        if (name.includes(query)) {
            item.classList.remove('hidden');
        } else {
            item.classList.add('hidden');
        }
    });
};

window.buscarProyecto = function() {
    const input = document.getElementById('buscador');
    const query = input.value.toLowerCase().trim();
    if (!query) return;

    const items = document.querySelectorAll('.project-card, .archived-row');
    let encontrado = null;

    for (let item of items) {
        const name = item.getAttribute('data-name') || '';
        if (name.includes(query)) { encontrado = item; break; }
    }

    if (encontrado) {
        input.value = '';
        window.filtrarProyectos();
        encontrado.scrollIntoView({ behavior: 'smooth', block: 'center' });
        encontrado.classList.remove('card-destello');
        void encontrado.offsetWidth;
        encontrado.classList.add('card-destello');
        setTimeout(() => encontrado.classList.remove('card-destello'), 3000);
    } else {
        input.value = '';
        window.filtrarProyectos();
    }
};

// ============================================================
// 👥 MODAL DE EQUIPO
// ============================================================
let EQUIPO_PROYECTO_ACTUAL = null;

function abrirModalEquipo(idProyecto, nombreProyecto) {
    EQUIPO_PROYECTO_ACTUAL = idProyecto;
    document.getElementById('equipoSubtitulo').textContent = nombreProyecto;
    
    const miembros = (window.EQUIPOS_PROYECTOS && window.EQUIPOS_PROYECTOS[idProyecto]) ? window.EQUIPOS_PROYECTOS[idProyecto] : [];
    const lista = document.getElementById('equipoLista');
    
    if (miembros.length === 0) {
        lista.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8; font-style:italic;">No hay miembros asignados.</div>';
    } else {
        lista.innerHTML = miembros.map(m => {
            const ini = inicialesJS(m.nombre_completo);
            const avance = m.avance || 0;
            const colorBarra = avance >= 100 ? '#027a35' : avance >= 60 ? '#266cb4' : avance >= 30 ? '#F08217' : '#94a3b8';
            return `
                <div class="equipo-item" onclick="abrirResumenDesdeEquipo(${m.id_usuario}, ${idProyecto})">
                    <div class="equipo-avatar">${ini}</div>
                    <div class="equipo-info">
                        <div class="equipo-nombre">${escapeHtml(m.nombre_completo)}</div>
                        <div class="equipo-rol">${escapeHtml(m.rol || 'Miembro')}</div>
                    </div>
                    <div class="equipo-progreso">
                        <div class="equipo-progreso-texto" style="color:${colorBarra};">${avance}%</div>
                        <div class="equipo-progreso-mini-bar">
                            <div class="equipo-progreso-mini-bar-fill" style="width:${avance}%; background:${colorBarra};"></div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    document.getElementById('modalEquipo').classList.remove('hidden');
}

function cerrarModalEquipo() {
    document.getElementById('modalEquipo').classList.add('hidden');
    EQUIPO_PROYECTO_ACTUAL = null;
}

function abrirResumenDesdeEquipo(idUsuario, idProyecto) {
    cerrarModalEquipo();
    setTimeout(() => abrirResumenUsuario(idUsuario, idProyecto), 150);
}

// ============================================================
// 👤 MODAL DE RESUMEN DE USUARIO
// ============================================================
const ESTADO_COLORS = { por_hacer:'#94a3b8', en_curso:'#266cb4', en_revision:'#FDC60A', programado:'#F08217', completada:'#027a35', bloqueado:'#E5074C' };
const ESTADO_LABELS = { por_hacer:'Por hacer', en_curso:'En curso', en_revision:'En revisión', programado:'Programado', completada:'Completada', bloqueado:'Bloqueado' };
const ESTADO_ICONS  = { por_hacer:'📝', en_curso:'🔄', en_revision:'⏳', programado:'⏳', completada:'✅', bloqueado:'🔒' };
let RESUMEN_USUARIO_CACHE = null;
let PROYECTO_ACTUAL_RESUMEN = 0;
let ULTIMO_RESUMEN_USUARIO_ID = null;
let ULTIMO_RESUMEN_PROYECTO_ID = null;

async function abrirResumenUsuario(idUsuario, idProyecto) {
    RESUMEN_USUARIO_CACHE = { idUsuario, idProyecto };
    PROYECTO_ACTUAL_RESUMEN = idProyecto;
    ULTIMO_RESUMEN_USUARIO_ID = idUsuario;
    ULTIMO_RESUMEN_PROYECTO_ID = idProyecto;

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

function cerrarResumenUsuario() { 
    document.getElementById('modalResumenUsuario').classList.add('hidden'); 
    RESUMEN_USUARIO_CACHE = null; 
}

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
            <button class="btn btn-outline" onclick="cerrarResumenUsuario()" style="width:100%; padding:12px; font-weight:700; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; color:#475569; font-family:inherit;">← Cerrar resumen</button>
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
            <div class="resumen-act-item" style="border-left-color:${col};" onclick="abrirDetalleDesdeResumen(${a.id_actividad}, ${PROYECTO_ACTUAL_RESUMEN})">
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

function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

// ============================================================
// 🚀 ABRIR DETALLE DE ACTIVIDAD DESDE EL RESUMEN
// ============================================================
async function abrirDetalleDesdeResumen(idActividad, idProyecto) {
    const ctxUsuario = ULTIMO_RESUMEN_USUARIO_ID;
    const ctxProyecto = ULTIMO_RESUMEN_PROYECTO_ID || idProyecto;

    document.getElementById('modalResumenUsuario').classList.add('hidden');
    
    const modal = document.getElementById('modalDetalleActividad');
    const content = document.getElementById('actividadContent');
    
    content.innerHTML = '<div style="padding:60px;text-align:center;color:#94a3b8;">Cargando actividad...</div>';
    modal.classList.remove('hidden');

    try {
        const res = await fetch('api/activity_detail.php?id=' + idActividad);
        const data = await res.json();
        if (data.error) { 
            content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">' + data.error + '</div>'; 
            return; 
        }
        
        const a = data.actividad;
        const adjuntos = data.adjuntos || [];
        const comentarios = data.comentarios || [];
        const pct = parseFloat(a.porcentaje_aporte) || 0;
        const prioColor = { baja:'#027a35', media:'#FDC60A', alta:'#F08217', critica:'#E5074C' }[a.prioridad] || '#FDC60A';
        const prioLabel = { baja:'Baja', media:'Media', alta:'Alta', critica:'Crítica' }[a.prioridad] || 'Media';
        const estadoLabel = ESTADO_LABELS[a.estado] || a.estado;
        const estadoIcon  = ESTADO_ICONS[a.estado] || '📝';
        const estadoColor = ESTADO_COLORS[a.estado] || '#94a3b8';
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
        } else {
            responsablesHtml = '<span style="color:#94a3b8;">Sin asignar</span>';
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
            comentarios.forEach(c => { 
                comentariosHtml += `<div class="comentario-item"><div class="comentario-meta"><b>${escapeHtml(c.nombre_completo)}</b><span>·</span><span>${new Date(c.creado_en).toLocaleString('es-MX')}</span></div><div class="comentario-texto">${escapeHtml(c.contenido)}</div></div>`; 
            });
        } else { comentariosHtml = '<span style="color:#94a3b8;font-size:10pt;font-style:italic;">Sin comentarios</span>'; }

        let html = `
            <div class="actividad-header">
                <div class="actividad-pct-circle">
                    <svg viewBox="0 0 80 80"><circle cx="40" cy="40" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="6"/><circle cx="40" cy="40" r="${radius}" fill="none" stroke="#78BE20" stroke-width="6" stroke-dasharray="${dashValue} ${dashResto}" stroke-dashoffset="0" stroke-linecap="round"/></svg>
                    <span class="pct-value">${pct}%</span>
                    <span class="pct-label">del proyecto</span>
                </div>
                <div class="actividad-header-info">
                    <div class="actividad-header-title">${a.estado === 'bloqueado' ? '🔒 ' : ''}${escapeHtml(a.titulo)}</div>
                    <div class="actividad-header-meta">
                        <span>📅 ${formatearFecha(a.fecha_inicio)}</span><span>·</span>
                        <span>🏁 ${formatearFecha(a.fecha_limite)}</span><span>·</span>
                        <span style="color:${prioColor}; font-weight:600;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${prioColor};margin-right:4px;"></span>${prioLabel}</span><span>·</span>
                        <span style="color:${estadoColor}; font-weight:600;">${estadoIcon} ${estadoLabel}</span>
                    </div>
                    <div class="actividad-header-responsables">
                        <span style="font-weight:600;color:var(--rtp-guinda);">Responsables:</span> ${responsablesHtml}
                    </div>
                </div>
                <button class="actividad-close" onclick="cerrarDetalleYVolverResumen()">×</button>
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
                </div>
            </div>
            <div class="actividad-footer">
                <button class="btn-modal secondary" onclick="cerrarDetalleYVolverResumen()">← Cerrar y volver al resumen</button>
            </div>
        `;
        content.innerHTML = html;
        window._VOLVER_A_RESUMEN = { idUsuario: ctxUsuario, idProyecto: ctxProyecto };

    } catch (e) {
        content.innerHTML = '<div style="padding:40px;text-align:center;color:#E5074C;">Error: ' + e.message + '</div>';
    }
}

function cerrarDetalleYVolverResumen() {
    document.getElementById('modalDetalleActividad').classList.add('hidden');
    if (window._VOLVER_A_RESUMEN && window._VOLVER_A_RESUMEN.idUsuario) {
        const { idUsuario, idProyecto } = window._VOLVER_A_RESUMEN;
        setTimeout(() => abrirResumenUsuario(idUsuario, idProyecto), 100);
    }
    window._VOLVER_A_RESUMEN = null;
}

function cerrarDetalleActividad() {
    document.getElementById('modalDetalleActividad').classList.add('hidden');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const detalleAbierto = !document.getElementById('modalDetalleActividad').classList.contains('hidden');
        if (detalleAbierto) {
            cerrarDetalleYVolverResumen();
        } else {
            cerrarResumenUsuario();
            cerrarModalEquipo();
            cerrarModalDragProgramado();
            cerrarModalDragBloqueado();
            cerrarModalAperturarProyecto();
        }
    }
});

// Cerrar modales al clic fuera
(function() {
    const m1 = document.getElementById('modalResumenUsuario');
    if (m1) m1.addEventListener('click', function(e) { if (e.target === this) cerrarResumenUsuario(); });
    const m2 = document.getElementById('modalEquipo');
    if (m2) m2.addEventListener('click', function(e) { if (e.target === this) cerrarModalEquipo(); });
    const m3 = document.getElementById('modalDetalleActividad');
    if (m3) m3.addEventListener('click', function(e) { if (e.target === this) cerrarDetalleYVolverResumen(); });
    const m4 = document.getElementById('modalDragProgramado');
    if (m4) m4.addEventListener('click', function(e) { if (e.target === this) cerrarModalDragProgramado(); });
    const m5 = document.getElementById('modalDragBloqueado');
    if (m5) m5.addEventListener('click', function(e) { if (e.target === this) cerrarModalDragBloqueado(); });
    const m6 = document.getElementById('modalAperturarProyecto');
    if (m6) m6.addEventListener('click', function(e) { if (e.target === this) cerrarModalAperturarProyecto(); });
})();

// ============================================================
// 🎯 MODALES DRAG (PROGRAMADO / BLOQUEADO / APERTURAR)
// ============================================================
let DRAG_PROYECTO_PROGRAMAR = null;
let DRAG_PROYECTO_BLOQUEAR = null;
let COUNTDOWN_INTERVAL = null;

let APERTURAR_PROYECTO_ID = null;
let APERTURAR_COLUMNA_DESTINO = null;
let APERTURAR_ESTADO_ANTERIOR = null;
let COUNTDOWN_APERTURAR_INTERVAL = null;

// ---- PROGRAMAR ----
function abrirModalDragProgramado(idProyecto) {
    DRAG_PROYECTO_PROGRAMAR = idProyecto;
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
    if (DRAG_PROYECTO_PROGRAMAR !== null) {
        DRAG_PROYECTO_PROGRAMAR = null;
        location.reload();
    }
}

async function guardarProgramacionDrag() {
    const idProyecto = DRAG_PROYECTO_PROGRAMAR;
    if (!idProyecto) return;
    const valor = document.getElementById('inputFechaProgramar').value;
    if (!valor) {
        alert('Selecciona la fecha y hora de apertura.');
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
                id_proyecto: idProyecto, 
                columna_manual: 'programado', 
                orden: 0,
                fecha_apertura: fechaFormateada
            })
        });
        const json = await res.json();
        if (json.error) {
            alert('Error: ' + json.error);
            btn.disabled = false;
            btn.textContent = '💾 Programar proyecto';
            return;
        }

        DRAG_PROYECTO_PROGRAMAR = null;
        document.getElementById('modalDragProgramado').classList.add('hidden');
        try {
            sessionStorage.setItem('rtp_toast_pending', JSON.stringify({
                mensaje: '✅ Proyecto programado correctamente.',
                icono: '⏳'
            }));
        } catch (e) {}
        setTimeout(() => location.reload(), 700);
    } catch (e) {
        alert('Error de red: ' + e.message);
        btn.disabled = false;
        btn.textContent = '💾 Programar proyecto';
    }
}

// ---- BLOQUEAR ----
function abrirModalDragBloqueado(idProyecto) {
    DRAG_PROYECTO_BLOQUEAR = idProyecto;
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
    if (DRAG_PROYECTO_BLOQUEAR !== null) {
        DRAG_PROYECTO_BLOQUEAR = null;
        location.reload();
    }
}

async function guardarBloqueoDrag() {
    const idProyecto = DRAG_PROYECTO_BLOQUEAR;
    if (!idProyecto) return;
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
        const res = await fetch('api/update_project_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                id_proyecto: idProyecto, 
                columna_manual: 'bloqueado', 
                orden: 0,
                motivo: motivo 
            })
        });
        const json = await res.json();
        if (json.error) {
            alert('Error: ' + json.error);
            btn.disabled = false;
            btn.textContent = '🔒 Confirmar bloqueo';
            return;
        }
        DRAG_PROYECTO_BLOQUEAR = null;
        document.getElementById('modalDragBloqueado').classList.add('hidden');
        try {
            sessionStorage.setItem('rtp_toast_pending', JSON.stringify({
                mensaje: '✅ Proyecto bloqueado correctamente.',
                icono: '🔒'
            }));
        } catch (e) {}
        setTimeout(() => location.reload(), 700);
    } catch (e) {
        alert('Error de red: ' + e.message);
        btn.disabled = false;
        btn.textContent = '🔒 Confirmar bloqueo';
    }
}

// ---- DESBLOQUEAR (arrastrar desde bloqueado) ----
function abrirModalAperturarProyecto(idProyecto, columnaDestino, estadoAnterior) {
    APERTURAR_PROYECTO_ID = idProyecto;
    APERTURAR_COLUMNA_DESTINO = columnaDestino;
    APERTURAR_ESTADO_ANTERIOR = estadoAnterior;

    const titulo = document.getElementById('modalAperturarTitulo');
    const subtitulo = document.getElementById('modalAperturarSub');
    const modalContent = document.querySelector('#modalAperturarProyecto .modal-content');
    const btnConfirmar = document.getElementById('btnConfirmarAperturar');
    const countdownBtn = document.getElementById('btnCountdownAperturar');
    const nombreColumna = (ESTADO_LABELS[columnaDestino] || columnaDestino);

    if (estadoAnterior === 'programado') {
        // ESTILOS PARA APERTURAR (AZUL)
        modalContent.style.borderTop = '5px solid #266cb4';
        titulo.textContent = '⚡ Aperturar proyecto';
        titulo.style.color = '#266cb4';
        subtitulo.style.background = '#eef6fd';
        subtitulo.style.borderLeftColor = '#266cb4';
        subtitulo.style.color = '#1e3a5f';
        subtitulo.innerHTML = `¿Deseas que <b>este proyecto se aperture ahora mismo</b>?<br><br>Se moverá a la columna <b>${nombreColumna}</b> y quedará disponible para trabajar en él inmediatamente.`;
        
        countdownBtn.style.background = '#266cb4';
        btnConfirmar.style.background = '#266cb4';
        btnConfirmar.innerHTML = '✅ Sí, aperturar ahora mismo';
        
    } else {
        // ESTILOS PARA DESBLOQUEAR (ROJO)
        modalContent.style.borderTop = '5px solid #E5074C';
        titulo.textContent = '🔓 Desbloquear proyecto';
        titulo.style.color = '#E5074C';
        subtitulo.style.background = '#fde8ec';
        subtitulo.style.borderLeftColor = '#E5074C';
        subtitulo.style.color = '#991b1b';
        subtitulo.innerHTML = `Este proyecto estaba <b>bloqueado</b>. ¿Seguro que deseas <b>desbloquearlo</b> ahora mismo?<br><br>Se moverá a la columna <b>${nombreColumna}</b> y quedará disponible para trabajar en él inmediatamente.`;
        
        countdownBtn.style.background = '#E5074C';
        btnConfirmar.style.background = '#E5074C';
        btnConfirmar.innerHTML = '🔓 Sí, desbloquear ahora';
    }

    countdownBtn.disabled = true;
    countdownBtn.classList.remove('ready');
    countdownBtn.style.display = '';
    btnConfirmar.disabled = true;
    document.getElementById('countdownAperturarTexto').textContent = 'Espera 5s...';
    
    if (COUNTDOWN_APERTURAR_INTERVAL) { 
        clearInterval(COUNTDOWN_APERTURAR_INTERVAL); 
        COUNTDOWN_APERTURAR_INTERVAL = null; 
    }
    
    document.getElementById('modalAperturarProyecto').classList.remove('hidden');
    
    let segundos = 5;
    COUNTDOWN_APERTURAR_INTERVAL = setInterval(() => {
        segundos--;
        if (segundos > 0) {
            document.getElementById('countdownAperturarTexto').textContent = 'Espera ' + segundos + 's...';
        } else {
            clearInterval(COUNTDOWN_APERTURAR_INTERVAL);
            COUNTDOWN_APERTURAR_INTERVAL = null;
            document.getElementById('countdownAperturarTexto').textContent = '✅ Ya puedes continuar';
            countdownBtn.disabled = false;
            countdownBtn.classList.add('ready');
            btnConfirmar.disabled = false;
        }
    }, 1000);
}

function cerrarModalAperturarProyecto() {
    if (COUNTDOWN_APERTURAR_INTERVAL) { clearInterval(COUNTDOWN_APERTURAR_INTERVAL); COUNTDOWN_APERTURAR_INTERVAL = null; }
    document.getElementById('modalAperturarProyecto').classList.add('hidden');
    if (APERTURAR_PROYECTO_ID !== null) {
        APERTURAR_PROYECTO_ID = null;
        APERTURAR_COLUMNA_DESTINO = null;
        APERTURAR_ESTADO_ANTERIOR = null;
        location.reload();
    }
}

async function confirmarAperturarProyecto() {
    const idProyecto = APERTURAR_PROYECTO_ID;
    const columnaDestino = APERTURAR_COLUMNA_DESTINO;
    if (!idProyecto || !columnaDestino) return;
    const btn = document.getElementById('btnConfirmarAperturar');
    btn.disabled = true;
    btn.textContent = '⏳ Procesando...';

    try {
        const res = await fetch('api/update_project_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                id_proyecto: idProyecto, 
                columna_manual: columnaDestino, 
                orden: 0
            })
        });
        const json = await res.json();
        if (json.error) {
            alert('Error: ' + json.error);
            btn.disabled = false;
            btn.textContent = '✅ Sí, continuar';
            return;
        }

        APERTURAR_PROYECTO_ID = null;
        APERTURAR_COLUMNA_DESTINO = null;
        document.getElementById('modalAperturarProyecto').classList.add('hidden');
        try {
            sessionStorage.setItem('rtp_toast_pending', JSON.stringify({
                mensaje: '✅ Proyecto movido a ' + (ESTADO_LABELS[columnaDestino] || columnaDestino) + ' correctamente.',
                icono: '✅'
            }));
        } catch (e) {}
        setTimeout(() => location.reload(), 700);
    } catch (e) {
        alert('Error de red: ' + e.message);
        btn.disabled = false;
        btn.textContent = '✅ Sí, continuar';
    }
}

// ============================================================
// KANBAN DRAG & DROP
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const PUEDE_REORDENAR = <?= $puede_reordenar ? 'true' : 'false' ?>;
    if (!PUEDE_REORDENAR) return;

    let ARRASTRANDO = false;
    let dragCard = null;
    let ghostCard = null;
    let placeholder = null;
    let offsetX = 0;
    let offsetY = 0;

    window.cardEstaSiendoArrastrada = function() { return ARRASTRANDO; };

    const handles = document.querySelectorAll('.drag-handle[data-drag="true"]');

    handles.forEach(handle => {
        let dragIniciado = false;
        let startX = 0, startY = 0;

        handle.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return;
            dragIniciado = false;
            startX = e.clientX;
            startY = e.clientY;

            window.addEventListener('mousemove', onMouseMoveStart);
            window.addEventListener('mouseup', onMouseUpStart, { once: true });

            function onMouseMoveStart(ev) {
                const dx = Math.abs(ev.clientX - startX);
                const dy = Math.abs(ev.clientY - startY);
                if (dx > 4 || dy > 4) {
                    dragIniciado = true;
                    ev.preventDefault();
                    window.removeEventListener('mousemove', onMouseMoveStart);
                    window.removeEventListener('mouseup', onMouseUpStart);
                    iniciarDrag(handle, ev);
                }
            }

            function onMouseUpStart() {
                window.removeEventListener('mousemove', onMouseMoveStart);
            }
        });
    });

    function iniciarDrag(handle, e) {
        const card = handle.closest('.project-card');
        if (!card) return;

        ARRASTRANDO = true;
        dragCard = card;
        const rect = card.getBoundingClientRect();
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;

        ghostCard = card.cloneNode(true);
        ghostCard.classList.add('drag-ghost');
        ghostCard.style.width = rect.width + 'px';
        ghostCard.style.height = rect.height + 'px';
        ghostCard.style.left = (e.clientX - offsetX) + 'px';
        ghostCard.style.top = (e.clientY - offsetY) + 'px';
        document.body.appendChild(ghostCard);

        placeholder = document.createElement('div');
        placeholder.className = 'drag-placeholder';
        placeholder.style.height = rect.height + 'px';
        card.parentNode.insertBefore(placeholder, card);

        card.classList.add('drag-origin');

        const colInicial = card.closest('.board-column');
        if (colInicial) {
            colInicial.classList.add('drag-over-target');
        }

        document.body.style.cursor = 'grabbing';
        document.body.style.userSelect = 'none';

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp, { once: true });
    }

    function onMouseMove(e) {
        if (!ARRASTRANDO) return;
        e.preventDefault();

        ghostCard.style.left = (e.clientX - offsetX) + 'px';
        ghostCard.style.top = (e.clientY - offsetY) + 'px';

        const elBajo = document.elementFromPoint(e.clientX, e.clientY);
        if (!elBajo) return;

        const colDest = elBajo.closest('.board-column');
        if (!colDest) return;

        document.querySelectorAll('.board-column.drag-over-target').forEach(c => {
            if (c !== colDest) c.classList.remove('drag-over-target');
        });
        colDest.classList.add('drag-over-target');

        const contenedorDest = colDest.querySelector('.board-cards');
        if (!contenedorDest) return;

        if (placeholder.parentNode !== contenedorDest) {
            contenedorDest.appendChild(placeholder);
        }

        const cards = [...contenedorDest.querySelectorAll('.project-card:not(.drag-origin):not(.drag-ghost):not(.hidden)')];
        let refCard = null;
        for (const c of cards) {
            const rect = c.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) {
                refCard = c;
                break;
            }
        }

        if (refCard) {
            if (placeholder.nextElementSibling !== refCard) {
                contenedorDest.insertBefore(placeholder, refCard);
            }
        } else {
            if (contenedorDest.lastElementChild !== placeholder) {
                contenedorDest.appendChild(placeholder);
            }
        }
    }

    // ============================================================
    // ✅ FIX CRÍTICO: onMouseUp corregido
    // Captura contenedorDest y refCard ANTES de eliminar el placeholder
    // ============================================================
    function onMouseUp(e) {
        if (!ARRASTRANDO) return;
        window.removeEventListener('mousemove', onMouseMove);

        let colFinal = null;
        let contenedorDest = null;
        let refCard = null; // Nodo antes del cual insertar

        // Capturamos TODO antes de tocar el DOM
        if (placeholder && placeholder.parentNode) {
            contenedorDest = placeholder.closest('.board-cards');
            if (contenedorDest) {
                colFinal = contenedorDest.dataset.col;
            }
            refCard = placeholder.nextElementSibling; // puede ser null (append al final)
        }

        const colOrigen = dragCard ? (dragCard.closest('.board-cards') ? dragCard.closest('.board-cards').dataset.col : null) : null;
        const idCard = dragCard ? parseInt(dragCard.dataset.id, 10) : null;

        // Limpieza visual
        if (ghostCard && ghostCard.parentNode) ghostCard.parentNode.removeChild(ghostCard);
        if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        if (dragCard) dragCard.classList.remove('drag-origin');
        document.querySelectorAll('.board-column.drag-over-target').forEach(c => c.classList.remove('drag-over-target'));
        document.body.style.cursor = '';
        document.body.style.userSelect = '';

        if (!dragCard || !colFinal || !contenedorDest || !idCard) {
            dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
            return;
        }

        // ===== REGLAS DE NEGOCIO =====
        if (colFinal === 'programado' && colOrigen !== 'programado') {
            dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
            setTimeout(() => abrirModalDragProgramado(idCard), 100);
            return;
        }
        if (colFinal === 'bloqueado' && colOrigen !== 'bloqueado') {
            dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
            setTimeout(() => abrirModalDragBloqueado(idCard), 100);
            return;
        }
        if (colOrigen === 'bloqueado' && colFinal !== 'bloqueado') {
            dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
            setTimeout(() => abrirModalAperturarProyecto(idCard, colFinal, 'bloqueado'), 100);
            return;
        }
        if (colOrigen === 'programado' && colFinal !== 'programado') {
            dragCard = null; ghostCard = null; placeholder = null; ARRASTRANDO = false;
            setTimeout(() => abrirModalAperturarProyecto(idCard, colFinal, 'programado'), 100);
            return;
        }

        // ===== Movimiento normal =====
        if (colOrigen && colOrigen !== colFinal) {
            const headerOrigen = document.querySelector(`.board-column[data-col="${colOrigen}"] .board-count`);
            if (headerOrigen) {
                const countActual = parseInt(headerOrigen.textContent) || 0;
                headerOrigen.textContent = Math.max(0, countActual - 1);
                animarContador(headerOrigen);
            }
            const headerDest = document.querySelector(`.board-column[data-col="${colFinal}"] .board-count`);
            if (headerDest) {
                const countActual = parseInt(headerDest.textContent) || 0;
                headerDest.textContent = countActual + 1;
                animarContador(headerDest);
            }
            actualizarLeyendaSuperior();
        }

        // Insertar usando refCard capturado ANTES de eliminar el placeholder
        if (refCard && refCard.parentNode === contenedorDest) {
            contenedorDest.insertBefore(dragCard, refCard);
        } else {
            contenedorDest.appendChild(dragCard);
        }

        dragCard.classList.remove('drop-success');
        void dragCard.offsetWidth;
        dragCard.classList.add('drop-success');
        setTimeout(() => dragCard.classList.remove('drop-success'), 950);

        const idx = [...contenedorDest.querySelectorAll('.project-card:not(.hidden)')].indexOf(dragCard);

        fetch('api/update_project_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_proyecto: idCard,
                columna_manual: colFinal,
                orden: idx >= 0 ? idx : 0
            })
        })
        .then(res => res.json())
        .then(data => console.log("✅ Guardado exitoso:", data))
        .catch(err => console.error("❌ Error al guardar:", err));

        dragCard = null;
        ghostCard = null;
        placeholder = null;
        ARRASTRANDO = false;
    }

    function animarContador(elemento) {
        elemento.classList.remove('pop');
        void elemento.offsetWidth;
        elemento.classList.add('pop');
        setTimeout(() => elemento.classList.remove('pop'), 450);
    }

    function actualizarLeyendaSuperior() {
        const leyenda = document.getElementById('leyenda-top');
        if (!leyenda) return;

        const cardsFinalizados = document.querySelectorAll('.board-column[data-col="finalizado"] .project-card');
        const totalFinalizados = cardsFinalizados.length;

        const cardsTotales = document.querySelectorAll('.project-card:not(.hidden)');
        const totalProyectos = cardsTotales.length;

        const totalSinFinalizar = totalProyectos - totalFinalizados;

        leyenda.innerHTML = `<b>${totalFinalizados}</b> proyectos finalizados, <b>${totalSinFinalizar}</b> sin finalizar`;
    }

    document.addEventListener('click', (e) => {
        // Sin menú mover, no hay nada que cerrar aquí
    });
});
</script>
</body>
</html>