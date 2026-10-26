<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_login();

$user = current_user();

// Proyectos del usuario (SIN archivados)
if ($user['rol'] === 'administrador') {
    $proyectos = db()->query("
        SELECT * FROM gestion_proyectos
        WHERE archivado = FALSE AND eliminado = FALSE
        ORDER BY creado_en DESC
    ")->fetchAll();
} else {
    $stmt = db()->prepare("
        SELECT p.* FROM gestion_proyectos p
        JOIN gestion_miembros_proyecto m ON m.id_proyecto = p.id_proyecto
        WHERE m.id_usuario = ? AND p.archivado = FALSE AND p.eliminado = FALSE
        ORDER BY p.creado_en DESC
    ");
    $stmt->execute([$user['id_usuario']]);
    $proyectos = $stmt->fetchAll();
}

$finalizados   = array_values(array_filter($proyectos, fn($p) => $p['estado'] === 'finalizado'));
$sin_finalizar = array_values(array_filter($proyectos, fn($p) => in_array($p['estado'], ['detenido','en_riesgo'])));
$en_curso      = array_values(array_filter($proyectos, fn($p) => $p['estado'] === 'activo'));

$ids = array_column($proyectos, 'id_proyecto');
$actividades = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("
        SELECT a.*, p.nombre AS proyecto_nombre, u.nombre_completo AS responsable_nombre
        FROM gestion_actividades a
        JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
        LEFT JOIN gestion_usuarios u ON u.id_usuario = a.asignado_a
        WHERE a.id_proyecto IN ($in) AND a.archivada = FALSE AND a.eliminada = FALSE
        ORDER BY a.fecha_limite NULLS LAST, a.creado_en DESC
    ");
    $stmt->execute($ids);
    $actividades = $stmt->fetchAll();
}

$act_completadas = array_values(array_filter($actividades, fn($a) => $a['estado'] === 'completada'));
$act_en_curso    = array_values(array_filter($actividades, fn($a) => in_array($a['estado'], ['en_curso','en_revision'])));
$act_no_cumplidas = array_values(array_filter($actividades, function($a) {
    if ($a['estado'] === 'bloqueado') return true;
    if ($a['fecha_limite'] && $a['fecha_limite'] < date('Y-m-d') && $a['estado'] !== 'completada') return true;
    return false;
}));

// Cálculo de contadores para leyendas
$c_semaforo = ['completadas' => 0, 'en_curso' => 0, 'en_revision' => 0, 'por_hacer' => 0, 'bloqueadas' => 0];
$c_carga    = ['verde' => 0, 'naranja' => 0, 'rojo' => 0];

$hoy = strtotime(date('Y-m-d'));
$hoy7 = strtotime(date('Y-m-d', strtotime('+7 days')));

foreach ($actividades as $a) {
    if ($a['estado'] === 'completada') $c_semaforo['completadas']++;
    elseif ($a['estado'] === 'en_curso') $c_semaforo['en_curso']++;
    elseif ($a['estado'] === 'en_revision') $c_semaforo['en_revision']++;
    elseif ($a['estado'] === 'por_hacer') $c_semaforo['por_hacer']++;
    elseif ($a['estado'] === 'bloqueado') $c_semaforo['bloqueadas']++;

    if ($a['estado'] === 'completada') {
        $c_carga['verde']++;
    } elseif ($a['fecha_limite']) {
        $ts = strtotime($a['fecha_limite']);
        if ($ts > $hoy7) $c_carga['verde']++;
        elseif ($ts >= $hoy && $ts <= $hoy7) $c_carga['naranja']++;
        else $c_carga['rojo']++;
    }
}

function slim_proyectos($lista) {
    return array_map(fn($p) => [
        'id'        => $p['id_proyecto'],
        'nombre'    => $p['nombre'],
        'estado'    => $p['estado'],
        'avance'    => $p['porcentaje_avance'],
        'fecha_fin' => $p['fecha_fin'],
        'prioridad' => $p['prioridad'],
    ], $lista);
}

function slim_actividades($lista) {
    return array_map(fn($a) => [
        'id'              => $a['id_actividad'],
        'titulo'          => $a['titulo'],
        'estado'          => $a['estado'],
        'proyecto_id'     => $a['id_proyecto'],
        'proyecto_nombre' => $a['proyecto_nombre'] ?? '—',
        'fecha_limite'    => $a['fecha_limite'],
        'prioridad'       => $a['prioridad'],
        'responsable'     => $a['responsable_nombre'] ?? 'Sin asignar'
    ], $lista);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Dashboard · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<style>
.chart-btn {
    transition: all .2s ease !important;
    position: relative;
}
.chart-btn:hover {
    background: var(--rtp-verde);
    color: white;
    border-color: var(--rtp-verde);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.35), 0 2px 6px rgba(120, 190, 32, 0.2);
    filter: brightness(1.05);
}
.chart-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(120, 190, 32, 0.3), inset 0 1px 2px rgba(0, 0, 0, 0.1);
}
.chart-btn:hover::after {
    content: attr(title);
    position: absolute;
    bottom: -32px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--rtp-negro);
    color: white;
    font-size: 9pt;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 100;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    font-weight: 400;
}

/* ============================================================
   🆕 RESALTADO GLOBAL: cualquier tarjeta opaca a TODAS las demás
   ============================================================ */
.chart-card,
.ring-card {
    transition: all .2s ease;
}
.chart-card:hover,
.ring-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(120, 190, 32, 0.2), 0 2px 6px rgba(120, 190, 32, 0.12);
}

/* Cuando hay hover en CUALQUIER tarjeta del dashboard,
   TODAS las demás (sin importar el grid) se opacan */
body:has(.chart-card:hover) .chart-card:not(:hover),
body:has(.chart-card:hover) .ring-card,
body:has(.ring-card:hover) .chart-card,
body:has(.ring-card:hover) .ring-card:not(:hover) {
    opacity: 0.65;
    filter: grayscale(20%);
}

/* La tarjeta con hover siempre se ve a full */
body:has(.chart-card:hover) .chart-card:hover,
body:has(.ring-card:hover) .ring-card:hover {
    opacity: 1;
    filter: none;
}

/* ============================================================
   🆕 RESPIRACIÓN EN LOS CANVAS DE LOS ANILLOS
   ============================================================ */
.ring-card canvas {
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-origin: center center;
}
.ring-card:hover canvas {
    transform: scale(1.04);
}

/* LEYENDAS */
.ring-legend span,
.chart-legend span {
    transition: transform .25s cubic-bezier(0.34, 1.56, 0.64, 1), 
                background-color .2s ease, 
                color .2s ease, 
                box-shadow .2s ease;
    cursor: pointer;
    display: inline-block;
    padding: 2px 6px;
    border-radius: 6px;
    margin: 2px;
}
.ring-legend span:hover,
.chart-legend span:hover {
    background: #ecf9d9;
    color: var(--rtp-verde-dark);
    transform: scale(1.04);
    box-shadow: 0 2px 6px rgba(120, 190, 32, 0.15);
}
</style>
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <div class="topbar">
            <h1>Dashboard</h1>
            <div class="topbar-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
        </div>

        <div class="rings">
            <div class="ring-card" data-chart="anillo-proyectos">
                <div class="chart-header">
                    <h3>Proyectos</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('anillo-proyectos')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('anillo-proyectos')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="ringProjects"></canvas>
                <div class="ring-legend">
                    <span data-cat="finalizados">🟢 Finalizados (<?= count($finalizados) ?>)</span>
                    <span data-cat="sin_finalizar">🔴 Sin finalizar (<?= count($sin_finalizar) ?>)</span>
                    <span data-cat="en_curso">🔵 En curso (<?= count($en_curso) ?>)</span>
                </div>
            </div>

            <div class="ring-card" data-chart="anillo-actividades">
                <div class="chart-header">
                    <h3>Actividades</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('anillo-actividades')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('anillo-actividades')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="ringActivities"></canvas>
                <div class="ring-legend">
                    <span data-cat="act_completadas">✅ Completadas (<?= count($act_completadas) ?>)</span>
                    <span data-cat="act_en_curso">🔄 En curso (<?= count($act_en_curso) ?>)</span>
                    <span data-cat="act_no_cumplidas">⚠️ No cumplidas (<?= count($act_no_cumplidas) ?>)</span>
                </div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card" data-chart="semaforo-proyecto">
                <div class="chart-header">
                    <h3>Semáforo por proyecto</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('semaforo-proyecto')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('semaforo-proyecto')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="chartSemaforo"></canvas>
                <div class="chart-legend" id="legend-semaforo">
                    <span data-legend="completadas"><i style="background:#027a35"></i> Completadas (<?= $c_semaforo['completadas'] ?>)</span>
                    <span data-legend="en_curso"><i style="background:#266cb4"></i> En curso (<?= $c_semaforo['en_curso'] ?>)</span>
                    <span data-legend="en_revision"><i style="background:#FDC60A"></i> En revisión (<?= $c_semaforo['en_revision'] ?>)</span>
                    <span data-legend="por_hacer"><i style="background:#B1B1B1"></i> Por hacer (<?= $c_semaforo['por_hacer'] ?>)</span>
                    <span data-legend="bloqueadas"><i style="background:#E5074C"></i> Bloqueadas (<?= $c_semaforo['bloqueadas'] ?>)</span>
                </div>
            </div>

            <div class="chart-card" data-chart="carga-proyecto">
                <div class="chart-header">
                    <h3>Carga por proyecto</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('carga-proyecto')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('carga-proyecto')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="chartCarga"></canvas>
                <div class="chart-legend" id="legend-carga">
                    <span data-legend="carga_verde"><i style="background:#027a35"></i> Entregadas o +1 semana (<?= $c_carga['verde'] ?>)</span>
                    <span data-legend="carga_naranja"><i style="background:#F08217"></i> Queda ≤ 1 semana (<?= $c_carga['naranja'] ?>)</span>
                    <span data-legend="carga_rojo"><i style="background:#E5074C"></i> Fecha límite vencida (<?= $c_carga['rojo'] ?>)</span>
                </div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card" data-chart="avance-tiempo">
                <div class="chart-header">
                    <h3>Avance contra tiempo</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('avance-tiempo')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('avance-tiempo')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="chartAvanceTiempo"></canvas>
                <div class="chart-legend">
                    <span><i style="background:#78BE20"></i> Avance real</span>
                    <span><i style="background:#F08217"></i> Tiempo transcurrido</span>
                </div>
            </div>

            <div class="chart-card" data-chart="ultimo-acceso">
                <div class="chart-header">
                    <h3>Último acceso de responsables</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="downloadChart('ultimo-acceso')" title="Descargar PDF">⬇</button>
                        <button class="chart-btn" onclick="showInfo('ultimo-acceso')" title="Información">i</button>
                    </div>
                </div>
                <canvas id="chartUltimoAcceso"></canvas>
                <div class="chart-legend" id="legend-acceso">
                    <span data-legend="acceso_verde"><i style="background:#027a35"></i> ≤ 7 días</span>
                    <span data-legend="acceso_naranja"><i style="background:#F08217"></i> 8-14 días</span>
                    <span data-legend="acceso_rojo"><i style="background:#E5074C"></i> > 14 días</span>
                </div>
            </div>
        </div>

        <div class="chart-card full" data-chart="proyectos-detenidos">
            <div class="chart-header">
                <h3>Top 5 · Proyectos detenidos</h3>
                <div class="chart-actions">
                    <button class="chart-btn" onclick="downloadChart('proyectos-detenidos')" title="Descargar PDF">⬇</button>
                    <button class="chart-btn" onclick="showInfo('proyectos-detenidos')" title="Información">i</button>
                </div>
            </div>
            <div id="listaDetenidos" class="stalled-list">
                <p class="empty-msg">Cargando...</p>
            </div>
        </div>
    </main>
</div>

<div id="listModal" class="modal hidden">
    <div class="modal-content large">
        <span class="close" onclick="closeListModal()">×</span>
        <h2 id="listTitle">Proyectos</h2>
        <div id="listBody" class="list-body"></div>
    </div>
</div>

<div id="infoModal" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="closeInfoModal()">×</span>
        <h2 id="infoTitle">Información</h2>
        <div id="infoBody" class="info-body"></div>
    </div>
</div>

<script>
window.DASH_DATA = {
    proyectos: {
        finalizados:   <?= count($finalizados) ?>,
        sin_finalizar: <?= count($sin_finalizar) ?>,
        en_curso:      <?= count($en_curso) ?>,
        finalizadosList:   <?= json_encode(slim_proyectos($finalizados), JSON_UNESCAPED_UNICODE) ?>,
        sinFinalizarList:  <?= json_encode(slim_proyectos($sin_finalizar), JSON_UNESCAPED_UNICODE) ?>,
        enCursoList:       <?= json_encode(slim_proyectos($en_curso), JSON_UNESCAPED_UNICODE) ?>
    },
    actividades: {
        completadas:  <?= count($act_completadas) ?>,
        en_curso:     <?= count($act_en_curso) ?>,
        no_cumplidas: <?= count($act_no_cumplidas) ?>,
        completadasList:  <?= json_encode(slim_actividades($act_completadas), JSON_UNESCAPED_UNICODE) ?>,
        enCursoList:      <?= json_encode(slim_actividades($act_en_curso), JSON_UNESCAPED_UNICODE) ?>,
        noCumplidasList:  <?= json_encode(slim_actividades($act_no_cumplidas), JSON_UNESCAPED_UNICODE) ?>
    },
    todasList: <?= json_encode(slim_actividades($actividades), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/dashboard.js"></script>
</body>
</html>