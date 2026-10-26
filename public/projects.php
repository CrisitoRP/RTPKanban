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

// ✅ ORDENAR BLOQUEADOS: más recientes primero (bloqueado_en DESC)
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
                            <?php renderCard($p, $user, $puede_reordenar); ?>
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
                            <?php renderCard($p, $user, $puede_reordenar); ?>
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
                            <?php renderCard($p, $user, $puede_reordenar); ?>
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
                            <?php renderCard($p, $user, $puede_reordenar); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="board-column" data-col="programado">
                    <div class="board-header">
                        <span class="board-dot" style="background:#F08217;"></span>
                        <span class="board-title">Programados</span>
                        <span class="board-count"><?= count($col_programado) ?></span>
                    </div>
                    <div class="board-cards" data-col="programado">
                        <?php foreach ($col_programado as $p): ?>
                            <?php renderCard($p, $user, $puede_reordenar); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ===== BLOQUEADOS: al final, columna NO droppable ===== -->
                <div class="board-column board-column-locked" data-col="bloqueado">
                    <div class="board-header">
                        <span class="board-dot" style="background:#E5074C;"></span>
                        <span class="board-title">Bloqueado</span>
                        <span class="board-count"><?= count($col_bloqueado) ?></span>
                    </div>
                    <div class="board-cards board-cards-locked" data-col="bloqueado">
                        <?php foreach ($col_bloqueado as $p): ?>
                            <?php renderCard($p, $user, false); ?>
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
function renderCard($p, $user, $puede_reordenar = false) {
    $progreso = (float) $p['porcentaje_avance'];
    $pctVisible = min(100, max(0, $progreso));
    $resto = 100 - $pctVisible;
    $color = color_progreso($progreso);
    $iniciales_resp = iniciales($p['responsable_nombre'] ?? '');
    $bloqueado = !empty($p['bloqueado']);

    $fecha_ap = !empty($p['fecha_apertura']) ? strtotime($p['fecha_apertura']) : null;
    $programado = ($fecha_ap && $fecha_ap > time());

    // ✅ Los bloqueados NUNCA se pueden arrastrar
    $puede_arrastrar = $puede_reordenar && !$bloqueado;
    ?>
    <div class="project-card <?= $bloqueado ? 'card-bloqueado' : '' ?> <?= $programado ? 'card-programado' : '' ?>"
         id="proyecto-<?= (int)$p['id_proyecto'] ?>"
         data-id="<?= (int)$p['id_proyecto'] ?>"
         data-name="<?= h(strtolower($p['nombre'] . ' ' . ($p['descripcion'] ?? ''))) ?>"
         <?= $puede_arrastrar ? 'draggable="false"' : '' ?>
         onclick="abrirProyecto(event, <?= (int)$p['id_proyecto'] ?>)">

        <?php if ($puede_arrastrar): ?>
            <div class="drag-handle" data-drag="true" title="Arrastra o clic para mover">
                <span class="bar bar-1"></span>
                <span class="bar bar-2"></span>
                <span class="bar bar-3"></span>
            </div>

            <div class="mover-menu" onclick="event.stopPropagation();">
                <div class="mover-menu-title">Mover proyecto</div>
                <button type="button" onclick="moverArriba(<?= (int)$p['id_proyecto'] ?>, event)">
                    <span class="menu-icon-wrap">⬆️</span> Mover arriba
                </button>
                <button type="button" onclick="moverAbajo(<?= (int)$p['id_proyecto'] ?>, event)">
                    <span class="menu-icon-wrap">⬇️</span> Mover abajo
                </button>
                <button type="button" onclick="moverIzquierda(<?= (int)$p['id_proyecto'] ?>, event)">
                    <span class="menu-icon-wrap">⬅️</span> Columna anterior
                </button>
                <button type="button" onclick="moverDerecha(<?= (int)$p['id_proyecto'] ?>, event)">
                    <span class="menu-icon-wrap">➡️</span> Columna siguiente
                </button>
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

        <div class="project-card-footer">
            <div class="project-card-date-wrap">
                <?php if ($bloqueado): ?>
                    <span class="priority-dot" style="background:#E5074C;"></span>
                    <span style="color:#E5074C; font-weight:700;">
                        🔒 Bloqueado el <?= h(fmt_date($p['bloqueado_en'] ?? '')) ?>
                    </span>
                <?php elseif ($programado): ?>
                    <span class="priority-dot" style="background:#F08217;"></span>
                    <span style="color:#F08217; font-weight:700;">⏳ Se abre: <?= h(date('d/m/Y', $fecha_ap)) ?> · <?= h(date('H:i', $fecha_ap)) ?></span>
                <?php elseif ($p['estado'] === 'finalizado'): ?>
                    <span class="priority-dot" style="background:#027a35;"></span>
                    <span style="color:#027a35; font-weight:700;">🏁 Cerrado: <?= h(fmt_date($p['fecha_fin'])) ?></span>
                <?php else: ?>
                    <span class="priority-dot" style="background:<?= $color ?>;"></span>
                    <span style="color:<?= $color ?>; font-weight:600;">Vence: <?= h(fmt_date($p['fecha_fin'])) ?></span>
                <?php endif; ?>
            </div>

            <div class="project-card-responsables">
                <?php if ($p['responsable_nombre']): ?>
                    <div class="project-card-avatar" title="<?= h($p['responsable_nombre']) ?>">
                        <?= h($iniciales_resp) ?>
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
.projects-board {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    align-items: start;
}
.board-column {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    min-height: 400px;
    transition: box-shadow .3s cubic-bezier(0.22, 1, 0.36, 1), background .3s ease;
}
.board-column.drag-over-target {
    box-shadow: 0 0 0 2px rgba(120, 190, 32, 0.25), 0 0 32px rgba(120, 190, 32, 0.35), 0 0 60px rgba(120, 190, 32, 0.15);
    background: #fbfef5;
}

/* ✅ Columna BLOQUEADO: NO se ilumina y NO acepta drops */
.board-column.board-column-locked {
    background: #fef2f4;
    border: 1px dashed #fbc5d2;
}
.board-column.board-column-locked.drag-over-target {
    box-shadow: none;
    background: #fef2f4;
}
.board-cards-locked { pointer-events: auto; }

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
.project-card.drag-ghost .mover-menu { display: none !important; }
.project-card.drag-origin { display: none; }

.project-card.drop-success { animation: dropSuccess .9s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes dropSuccess {
    0% { background: #ecf9d9; box-shadow: 0 0 0 8px rgba(120, 190, 32, 0.7), 0 0 48px rgba(120, 190, 32, 0.9); transform: scale(1.06); }
    35% { background: #f4ffdf; box-shadow: 0 0 0 4px rgba(120, 190, 32, 0.4), 0 0 24px rgba(120, 190, 32, 0.5); transform: scale(0.98); }
    55% { transform: scale(1.02); }
    75% { transform: scale(0.995); }
    100% { background: white; box-shadow: 0 0 0 0 rgba(120, 190, 32, 0), 0 0 0 rgba(120, 190, 32, 0); transform: scale(1); }
}

.mover-menu {
    display: none; position: absolute; left: 26px; top: 8px;
    background: white; border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
    padding: 8px; min-width: 200px; z-index: 50;
    border-top: 3px solid var(--rtp-verde);
    animation: slideDownMover .15s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes slideDownMover {
    from { opacity: 0; transform: translateY(-6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.mover-menu.open { display: block; }
.mover-menu-title {
    font-size: 9pt; font-weight: 700; color: var(--rtp-guinda);
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 4px 10px 8px 10px; border-bottom: 1px solid #e2e8f0; margin-bottom: 4px;
}
.mover-menu button {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 8px 10px; background: none; border: none; text-align: left;
    font-family: inherit; font-size: 10.5pt; font-weight: 600;
    color: var(--rtp-gris); border-radius: 6px; cursor: pointer; transition: all .15s ease;
}
.mover-menu button:hover { background: #ecf9d9; color: var(--rtp-verde-dark); transform: translateX(2px); }
.mover-menu button .menu-icon-wrap { display: inline-block; transition: transform .25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.mover-menu button:hover .menu-icon-wrap { transform: scale(1.3); }

/* ===== CARD BLOQUEADO: sin cursor de arrastre ===== */
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
.project-card.card-bloqueado .project-card-body {
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
.project-card-footer {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    padding: 8px 14px 10px 26px; border-top: 1px dashed #e2e8f0; background: #fafbfc;
}
.project-card.card-bloqueado .project-card-footer {
    padding-left: 14px;
}
.project-card-date-wrap {
    display: flex; align-items: center; gap: 6px;
    font-size: 9pt; white-space: nowrap; flex-shrink: 0;
}
.priority-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0;
}
.project-card-responsables {
    display: flex; align-items: center; overflow-x: auto; overflow-y: hidden;
    padding: 2px 2px; max-width: 55%; scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent; flex-shrink: 0;
}
.project-card-responsables::-webkit-scrollbar { height: 4px; }
.project-card-responsables::-webkit-scrollbar-track { background: transparent; }
.project-card-responsables::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.project-card-avatar {
    width: 28px; height: 28px; border-radius: 50%; background: var(--rtp-verde);
    color: white; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 8pt; border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,.12); flex-shrink: 0;
    transition: transform .2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.project-card-avatar:hover { transform: scale(1.15); }
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
    .projects-board { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 1200px) {
    .projects-board { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
    .projects-board { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
    .projects-board { grid-template-columns: 1fr; }
    .archived-row { padding: 12px 14px; gap: 10px; }
    .archived-row-icon { width: 38px; height: 38px; font-size: 18pt; }
    .archived-row-title { font-size: 11pt; }
}
</style>

<script>
// ============================================================
// ✅ TOAST: mostrar mensaje pendiente tras cambio de estado
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

// ============================================================
// ✅ FALLBACK GLOBAL
// ============================================================
window.cardEstaSiendoArrastrada = function() { return false; };

window.abrirProyecto = function(event, idProyecto) {
    if (event.target.closest('.drag-handle')) return;
    if (event.target.closest('.mover-menu')) return;
    if (window.cardEstaSiendoArrastrada && window.cardEstaSiendoArrastrada()) return;
    window.location.href = 'project_detail.php?id=' + idProyecto;
};

window.moverArriba = window.moverArriba || function() { console.warn('moverArriba no implementado'); };
window.moverAbajo = window.moverAbajo || function() { console.warn('moverAbajo no implementado'); };
window.moverIzquierda = window.moverIzquierda || function() { console.warn('moverIzquierda no implementado'); };
window.moverDerecha = window.moverDerecha || function() { console.warn('moverDerecha no implementado'); };

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

document.addEventListener('DOMContentLoaded', function() {
    console.log("🔍 DOM Cargado. Iniciando sistema Kanban...");

    const PUEDE_REORDENAR = <?= $puede_reordenar ? 'true' : 'false' ?>;
    if (!PUEDE_REORDENAR) {
        console.log("⏸️ Reordenamiento desactivado para este usuario/vista.");
        return;
    }

    let ARRASTRANDO = false;
    let dragCard = null;
    let ghostCard = null;
    let placeholder = null;
    let offsetX = 0;
    let offsetY = 0;
    let contenedorDestGlobal = null;

    window.cardEstaSiendoArrastrada = function() {
        return ARRASTRANDO;
    };

    const handles = document.querySelectorAll('.drag-handle[data-drag="true"]');
    console.log(`✅ Se encontraron ${handles.length} barras de agarre.`);

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
                if (!dragIniciado) {
                    toggleMoverMenu(handle, e);
                }
            }
        });
    });

    function iniciarDrag(handle, e) {
        const card = handle.closest('.project-card');
        if (!card) return;

        // 🚫 Seguridad extra: los bloqueados NO se pueden arrastrar
        if (card.classList.contains('card-bloqueado')) return;

        ARRASTRANDO = true;
        dragCard = card;
        const rect = card.getBoundingClientRect();
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;

        ghostCard = card.cloneNode(true);
        const menu = ghostCard.querySelector('.mover-menu');
        if (menu) menu.remove();
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
        if (colInicial && !colInicial.classList.contains('board-column-locked')) {
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

        // 🚫 No permitir drop en la columna bloqueada
        if (colDest.classList.contains('board-column-locked')) {
            document.querySelectorAll('.board-column.drag-over-target').forEach(c => c.classList.remove('drag-over-target'));
            return;
        }

        document.querySelectorAll('.board-column.drag-over-target').forEach(c => {
            if (c !== colDest) c.classList.remove('drag-over-target');
        });
        colDest.classList.add('drag-over-target');

        const contenedorDest = colDest.querySelector('.board-cards');
        if (!contenedorDest) return;

        contenedorDestGlobal = contenedorDest;

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

    function onMouseUp(e) {
        if (!ARRASTRANDO) return;
        window.removeEventListener('mousemove', onMouseMove);

        let colFinal = null;
        let contenedorDest = null;

        if (placeholder && placeholder.parentNode) {
            contenedorDest = placeholder.closest('.board-cards');
            if (contenedorDest && !contenedorDest.classList.contains('board-cards-locked')) {
                colFinal = contenedorDest.dataset.col;
            }
        }

        if (dragCard && colFinal && contenedorDest) {
            const contenedorOrigen = dragCard.closest('.board-cards');
            const colOrigen = contenedorOrigen ? contenedorOrigen.dataset.col : null;

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
        }

        if (contenedorDest && placeholder && dragCard && colFinal) {
            contenedorDest.insertBefore(dragCard, placeholder);
        }

        if (ghostCard && ghostCard.parentNode) ghostCard.parentNode.removeChild(ghostCard);
        if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        if (dragCard) dragCard.classList.remove('drag-origin');
        document.querySelectorAll('.board-column.drag-over-target').forEach(c => c.classList.remove('drag-over-target'));
        document.body.style.cursor = '';
        document.body.style.userSelect = '';

        if (dragCard && colFinal && contenedorDest) {
            dragCard.classList.remove('drop-success');
            void dragCard.offsetWidth;
            dragCard.classList.add('drop-success');
            setTimeout(() => dragCard.classList.remove('drop-success'), 950);

            const id = parseInt(dragCard.dataset.id, 10);
            const idx = [...contenedorDest.querySelectorAll('.project-card:not(.hidden)')].indexOf(dragCard);

            fetch('api/update_project_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_proyecto: id,
                    columna_manual: colFinal,
                    orden: idx >= 0 ? idx : 0
                })
            })
            .then(res => res.json())
            .then(data => console.log("✅ Guardado exitoso:", data))
            .catch(err => console.error("❌ Error al guardar:", err));
        }

        dragCard = null;
        ghostCard = null;
        placeholder = null;
        contenedorDestGlobal = null;
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

    window.toggleMoverMenu = function(handle, e) {
        const card = handle.closest('.project-card');
        if (!card || card.classList.contains('card-bloqueado')) return;
        const menu = card.querySelector('.mover-menu');
        if (!menu) return;
        document.querySelectorAll('.mover-menu.open').forEach(m => { if (m !== menu) m.classList.remove('open'); });
        menu.classList.toggle('open');
    };

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.mover-menu') && !e.target.closest('.drag-handle')) {
            document.querySelectorAll('.mover-menu.open').forEach(m => m.classList.remove('open'));
        }
    });
});
</script>
</body>
</html>