<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_login();
$user = current_user();

$ver_archivados = isset($_GET['archivados']) && $_GET['archivados'] == '1';
$ver_eliminados = isset($_GET['eliminados']) && $_GET['eliminados'] == '1' && $user['rol'] === 'administrador';
$ver_activos    = !$ver_archivados && !$ver_eliminados;

// Filtro WHERE
if ($ver_eliminados) {
    $where = "p.eliminado = TRUE";
} elseif ($ver_archivados) {
    $where = "p.archivado = TRUE AND p.eliminado = FALSE";
} else {
    $where = "p.archivado = FALSE AND p.eliminado = FALSE";
}

// Obtener proyectos
if ($user['rol'] === 'administrador') {
    $proyectos = db()->query("
        SELECT p.*, u.nombre_completo AS responsable_nombre
        FROM gestion_proyectos p
        LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
        WHERE $where
        ORDER BY p.porcentaje_avance DESC, p.fecha_fin ASC
    ")->fetchAll();
} else {
    $stmt = db()->prepare("
        SELECT p.*, u.nombre_completo AS responsable_nombre
        FROM gestion_proyectos p
        JOIN gestion_miembros_proyecto m ON m.id_proyecto = p.id_proyecto
        LEFT JOIN gestion_usuarios u ON u.id_usuario = p.id_responsable
        WHERE m.id_usuario = ? AND $where
        ORDER BY p.porcentaje_avance DESC, p.fecha_fin ASC
    ");
    $stmt->execute([$user['id_usuario']]);
    $proyectos = $stmt->fetchAll();
}

// Agrupar
$col_sin_iniciar = [];
$col_en_curso    = [];
$col_en_revision = [];
$col_finalizado  = [];

foreach ($proyectos as $p) {
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

$total_finalizados = count($col_finalizado);
$total_sin_finalizar = count($proyectos) - $total_finalizados;

// Helpers
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Proyectos · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <!-- ============ TOPBAR ============ -->
        <div class="topbar">
            <div class="topbar-left">
                <h1>
                    <?= $ver_eliminados ? 'Proyectos eliminados' : ($ver_archivados ? 'Proyectos archivados' : 'Proyectos') ?>
                </h1>
                <?php if ($ver_activos): ?>
                    <span class="topbar-leyenda">
                        <b><?= $total_finalizados ?></b> proyectos finalizados,
                        <b><?= $total_sin_finalizar ?></b> sin finalizar
                    </span>
                <?php endif; ?>
            </div>
            <div class="topbar-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
        </div>

        <!-- ============ BOTONES ============ -->
        <div class="projects-actions">
            <?php if ($ver_activos && $user['rol'] !== 'visualizador'): ?>
                <a class="btn" href="project_new.php">+ Nuevo proyecto</a>
            <?php endif; ?>

            <!-- Archivados -->
            <a class="btn <?= $ver_archivados ? 'btn-activo' : '' ?>"
               href="projects.php?archivados=1"
               style="background:#F08217;"
               <?= $ver_archivados ? 'onclick="return false;"' : '' ?>>
                📦 Proyectos archivados
            </a>

            <!-- Eliminados -->
            <?php if ($user['rol'] === 'administrador'): ?>
                <a class="btn <?= $ver_eliminados ? 'btn-activo' : '' ?>"
                   href="projects.php?eliminados=1"
                   style="background:#E5074C;"
                   <?= $ver_eliminados ? 'onclick="return false;"' : '' ?>>
                    🗑️ Proyectos eliminados
                </a>
            <?php endif; ?>

            <!-- Volver a activos (solo si estás en archivados o eliminados) -->
            <?php if (!$ver_activos): ?>
                <a class="btn" href="projects.php" style="background:#94a3b8;">← Volver a activos</a>
            <?php endif; ?>
        </div>

        <!-- ============ BUSCADOR ============ -->
        <div class="projects-search">
            <input type="text" id="buscador" placeholder="🔍 Buscar proyecto" oninput="filtrarProyectos()">
        </div>

        <!-- ============ COLUMNAS ============ -->
        <div class="projects-board">

            <div class="board-column" data-col="sin_iniciar">
                <div class="board-header">
                    <span class="board-dot" style="background:#94a3b8;"></span>
                    <span class="board-title">Sin iniciar</span>
                    <span class="board-count"><?= count($col_sin_iniciar) ?></span>
                </div>
                <div class="board-cards">
                    <?php foreach ($col_sin_iniciar as $p): ?>
                        <?php renderCard($p, $user); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="board-column" data-col="en_curso">
                <div class="board-header">
                    <span class="board-dot" style="background:#266cb4;"></span>
                    <span class="board-title">En curso</span>
                    <span class="board-count"><?= count($col_en_curso) ?></span>
                </div>
                <div class="board-cards">
                    <?php foreach ($col_en_curso as $p): ?>
                        <?php renderCard($p, $user); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="board-column" data-col="en_revision">
                <div class="board-header">
                    <span class="board-dot" style="background:#F08217;"></span>
                    <span class="board-title">En revisión</span>
                    <span class="board-count"><?= count($col_en_revision) ?></span>
                </div>
                <div class="board-cards">
                    <?php foreach ($col_en_revision as $p): ?>
                        <?php renderCard($p, $user); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="board-column" data-col="finalizado">
                <div class="board-header">
                    <span class="board-dot" style="background:#027a35;"></span>
                    <span class="board-title">Finalizado</span>
                    <span class="board-count"><?= count($col_finalizado) ?></span>
                </div>
                <div class="board-cards">
                    <?php foreach ($col_finalizado as $p): ?>
                        <?php renderCard($p, $user); ?>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <?php if (empty($proyectos)): ?>
            <p class="empty-msg" style="margin-top:30px;">No hay proyectos para mostrar.</p>
        <?php endif; ?>
    </main>
</div>

<?php
function renderCard($p, $user) {
    $progreso = (float) $p['porcentaje_avance'];
    $color = color_progreso($progreso);
    $iniciales_resp = iniciales($p['responsable_nombre'] ?? '');
    ?>
    <div class="project-card" data-name="<?= h(strtolower($p['nombre'] . ' ' . ($p['descripcion'] ?? ''))) ?>">
        <div class="project-card-title"><?= h($p['nombre']) ?></div>
        <div class="project-card-area"><?= h($p['descripcion'] ? mb_substr($p['descripcion'], 0, 50) : 'Sin área') ?></div>

        <div class="project-progress-bar">
            <div class="project-progress-fill" style="width: <?= $progreso ?>%; background: <?= $color ?>;"></div>
        </div>

        <div class="project-card-footer">
            <span class="project-card-pct"><?= $progreso ?>%</span>
            <span class="project-card-date">
                <?php if ($p['estado'] === 'finalizado'): ?>
                    Cerrado <?= h(fmt_date($p['fecha_fin'])) ?>
                <?php else: ?>
                    Vence <?= h(fmt_date($p['fecha_fin'])) ?>
                <?php endif; ?>
            </span>
            <div class="project-card-avatar" title="<?= h($p['responsable_nombre'] ?? 'Sin responsable') ?>">
                <?= h($iniciales_resp) ?>
            </div>
        </div>

        <a href="project_detail.php?id=<?= h($p['id_proyecto']) ?>" class="project-card-overlay"></a>
    </div>
    <?php
}
?>

<style>
/* ============================================================
   PROYECTOS · Board tipo Kanban
   ============================================================ */
.projects-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.projects-search {
    margin-bottom: 20px;
}
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

/* ============================================================
   BOTONES CON ESTADO ACTIVO
   ============================================================ */
.projects-actions .btn {
    position: relative;
    transition: all .2s ease;
    cursor: pointer;
    opacity: 1;
}

/* Botón activo: atenuado con sombra verde */
.projects-actions .btn.btn-activo {
    opacity: 0.85;
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.35), 0 4px 12px rgba(120, 190, 32, 0.4);
    transform: scale(0.98);
    cursor: default;
    pointer-events: none;
}
.projects-actions .btn.btn-activo::after {
    content: '●';
    position: absolute;
    top: -6px;
    right: -6px;
    color: #78BE20;
    background: white;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    font-size: 9pt;
    line-height: 16px;
    text-align: center;
    box-shadow: 0 0 0 2px white;
    font-weight: 700;
}

/* Hover en botones no activos: sombra verde */
.projects-actions .btn:not(.btn-activo):hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.35), 0 2px 6px rgba(120, 190, 32, 0.2);
    filter: brightness(1.05);
}

/* ============================================================
   BOARD
   ============================================================ */
.projects-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    align-items: start;
}
.board-column {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    min-height: 400px;
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
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.board-title {
    font-weight: 700;
    font-size: 12pt;
    color: var(--rtp-guinda);
    flex: 1;
}
.board-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 22px;
    padding: 0 8px;
    background: #e2e8f0;
    color: #475569;
    border-radius: 999px;
    font-size: 9.5pt;
    font-weight: 700;
    pointer-events: none;
    user-select: none;
    font-family: 'Roboto', sans-serif;
    border: none;
}

/* ============================================================
   CARD
   ============================================================ */
.board-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.project-card {
    background: white;
    border-radius: 10px;
    padding: 14px;
    border: 1px solid #e2e8f0;
    position: relative;
    transition: all .2s ease;
    cursor: pointer;
}
.project-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(120, 190, 32, 0.25), 0 2px 6px rgba(120, 190, 32, 0.15);
    border-color: var(--rtp-verde);
}
.project-card-title {
    font-weight: 700;
    font-size: 11.5pt;
    color: var(--rtp-negro);
    margin-bottom: 6px;
    line-height: 1.3;
}
.project-card-area {
    font-size: 10pt;
    color: var(--rtp-gris);
    margin-bottom: 10px;
    min-height: 14px;
}
.project-progress-bar {
    width: 100%;
    height: 6px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 10px;
}
.project-progress-fill {
    height: 100%;
    border-radius: 999px;
    transition: width .3s ease;
}
.project-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 9.5pt;
    color: var(--rtp-gris);
}
.project-card-pct {
    font-weight: 700;
    color: var(--rtp-negro);
}
.project-card-date {
    flex: 1;
    text-align: center;
}
.project-card-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--rtp-verde);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 10pt;
    flex-shrink: 0;
    border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,.1);
}
.project-card-overlay {
    position: absolute;
    inset: 0;
    z-index: 1;
}
.project-card.hidden {
    display: none;
}

/* ============ RESPONSIVE ============ */
@media (max-width: 1200px) {
    .projects-board { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 700px) {
    .projects-board { grid-template-columns: 1fr; }
}
</style>

<script>
function filtrarProyectos() {
    const query = document.getElementById('buscador').value.toLowerCase().trim();
    document.querySelectorAll('.project-card').forEach(card => {
        const nombre = card.dataset.name || '';
        if (!query || nombre.includes(query)) {
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
        }
    });

    document.querySelectorAll('.board-column').forEach(col => {
        const visibles = col.querySelectorAll('.project-card:not(.hidden)').length;
        col.querySelector('.board-count').textContent = visibles;
    });
}
</script>
</body>
</html>