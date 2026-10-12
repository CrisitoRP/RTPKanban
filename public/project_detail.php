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

// Proyecto
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

// Actividades
$stmt = db()->prepare("
    SELECT a.*, u.nombre_completo AS asignado_nombre
    FROM gestion_actividades a
    LEFT JOIN gestion_usuarios u ON u.id_usuario = a.asignado_a
    WHERE a.id_proyecto = ? AND a.archivada = FALSE AND a.eliminada = FALSE
    ORDER BY a.posicion, a.creado_en
");
$stmt->execute([$id_proyecto]);
$actividades = $stmt->fetchAll();

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

// ============================================================
// MIEMBROS (solo activos y no eliminados)
// ============================================================
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

// Helpers
function iniciales($nombre) {
    if (!$nombre) return '?';
    $partes = preg_split('/\s+/', trim($nombre));
    if (count($partes) === 1) return strtoupper(substr($partes[0], 0, 2));
    return strtoupper(substr($partes[0], 0, 1) . substr($partes[count($partes) - 1], 0, 1));
}

function renderTaskCard($a) {
    $clase = $a['estado'] === 'bloqueado' ? 'blocked' : '';
    $ini = iniciales($a['asignado_nombre'] ?? '');
    ?>
    <div class="task-card <?= $clase ?>">
        <div class="task-card-title"><?= h($a['titulo']) ?></div>
        <div class="task-card-meta">
            <span>📅 <?= h(fmt_date($a['fecha_limite'])) ?></span>
            <?php if ($a['asignado_nombre']): ?>
                <div class="task-card-avatar" title="<?= h($a['asignado_nombre']) ?>">
                    <?= h($ini) ?>
                </div>
            <?php endif; ?>
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
.project-header {
    background: white;
    border-radius: 12px;
    padding: 24px 28px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    border-left: 5px solid var(--rtp-verde);
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 20px;
    flex-wrap: wrap;
}
.project-header-info h1 {
    color: var(--rtp-guinda);
    font-size: 18pt;
    margin-bottom: 8px;
}
.project-header-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 10.5pt;
    color: var(--rtp-gris);
    margin-top: 12px;
}
.project-header-meta span b { color: var(--rtp-negro); }
.project-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.project-progress-mini { display: flex; align-items: center; gap: 12px; }
.progress-circle {
    width: 70px; height: 70px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13pt;
    color: var(--rtp-guinda);
    position: relative;
}
.progress-circle::before {
    content: '';
    position: absolute; inset: 0;
    border-radius: 50%;
    padding: 5px;
    background: conic-gradient(var(--rtp-verde) var(--pct), #e2e8f0 0);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
}

.info-bar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    align-items: center;
}
.info-bar .spacer { flex: 1; }
.btn-invitar { background: var(--rtp-guinda) !important; }
.btn-invitar:hover {
    background: #7a1a33 !important;
    box-shadow: 0 6px 16px rgba(159, 34, 65, 0.3) !important;
}

.kanban-board {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
.kanban-col {
    background: #f8fafc;
    border-radius: 12px;
    padding: 14px;
    min-height: 300px;
}
.kanban-col-header {
    display: flex; align-items: center; gap: 8px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 12px;
}
.kanban-col-header .dot { width: 10px; height: 10px; border-radius: 50%; }
.kanban-col-header .title { font-weight: 700; font-size: 11pt; color: var(--rtp-guinda); flex: 1; }
.kanban-col-header .count {
    background: #e2e8f0; color: #475569;
    padding: 2px 10px; border-radius: 999px;
    font-size: 9.5pt; font-weight: 700;
}

.task-card {
    background: white;
    border-radius: 10px;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
    border-left: 4px solid var(--rtp-verde);
    transition: all .15s;
    cursor: pointer;
    position: relative;
}
.task-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.2);
    border-color: var(--rtp-verde);
}
.task-card.blocked {
    border-left-color: var(--estado-rojo);
    background: #fde8ec;
}
.task-card-title {
    font-weight: 600;
    color: var(--rtp-negro);
    font-size: 10.5pt;
    margin-bottom: 6px;
    line-height: 1.3;
}
.task-card-meta {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 9pt; color: var(--rtp-gris);
}
.task-card-avatar {
    width: 24px; height: 24px;
    border-radius: 50%;
    background: var(--rtp-verde);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 8pt;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
}
.task-card-actions {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity .15s;
}
.task-card:hover .task-card-actions { opacity: 1; }
.task-card-actions button {
    width: 22px; height: 22px;
    border-radius: 50%;
    border: none;
    background: rgba(0,0,0,.05);
    cursor: pointer;
    font-size: 11pt;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.task-card-actions button:hover { background: var(--rtp-verde); color: white; }
.task-card-actions button.danger:hover { background: var(--estado-rojo); }

.members-card {
    background: white;
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    margin-top: 20px;
}
.members-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 12px;
}
.member-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #f8fafc;
    border-radius: 8px;
    border-left: 4px solid var(--rtp-verde);
}
.member-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--rtp-verde);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 10pt;
    border: 2px solid white;
}
.member-info { flex: 1; }
.member-name { font-weight: 700; color: var(--rtp-negro); font-size: 11pt; }
.member-email { font-size: 9.5pt; color: var(--rtp-gris); }
.member-rol {
    background: var(--rtp-guinda);
    color: white;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 9pt;
    font-weight: 700;
    text-transform: capitalize;
}
.member-rol.administrador { background: var(--rtp-guinda); }
.member-rol.responsable { background: var(--rtp-verde); }
.member-rol.visualizador { background: var(--estado-azul); }

.empty-msg {
    text-align: center;
    color: var(--rtp-gris-light);
    padding: 20px;
    font-style: italic;
    font-size: 10pt;
}

.info-roles {
    background: #f8fafc;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 16px;
    font-size: 10pt;
    color: var(--rtp-gris);
    line-height: 1.6;
    border-left: 4px solid var(--rtp-dorado);
}
.info-roles b { color: var(--rtp-guinda); }

.link-generado {
    background: #f8fafc;
    border: 1px dashed var(--rtp-verde);
    border-radius: 10px;
    padding: 14px 16px;
    margin-top: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.link-generado input {
    flex: 1;
    min-width: 200px;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 9.5pt;
    font-family: monospace;
    background: white;
    color: var(--rtp-gris);
}
.btn-copiar {
    padding: 8px 14px;
    background: var(--rtp-verde);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 10pt;
    transition: all .15s;
}
.btn-copiar:hover {
    background: var(--rtp-verde-dark);
    box-shadow: 0 4px 12px rgba(120, 190, 32, 0.3);
}

.invite-list {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.invite-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #ecf9d9;
    border-radius: 8px;
    border-left: 4px solid var(--rtp-verde);
    font-size: 10.5pt;
}
.invite-item-rol {
    background: var(--rtp-verde);
    color: white;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 9pt;
    font-weight: 700;
    text-transform: capitalize;
}

.send-status {
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 10.5pt;
    margin-top: 12px;
    display: none;
}
.send-status.ok {
    display: block;
    background: #ecf9d9;
    color: var(--estado-verde);
    border-left: 4px solid var(--estado-verde);
}
.send-status.error {
    display: block;
    background: #fde8ec;
    color: #991b1b;
    border-left: 4px solid var(--estado-rojo);
}
</style>
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <div class="project-header">
            <div class="project-header-info" style="flex: 1;">
                <h1><?= h($proyecto['nombre']) ?></h1>
                <?php if ($proyecto['descripcion']): ?>
                    <p style="color: var(--rtp-gris); font-size: 10.5pt;"><?= h($proyecto['descripcion']) ?></p>
                <?php endif; ?>

                <div class="project-header-meta">
                    <span>📅 Inicio: <b><?= h(fmt_date($proyecto['fecha_inicio'])) ?></b></span>
                    <span>🏁 Fin: <b><?= h(fmt_date($proyecto['fecha_fin'])) ?></b></span>
                    <span>👤 Responsable: <b><?= h($proyecto['responsable_nombre'] ?? 'Sin asignar') ?></b></span>
                    <span>🎯 Estado: <b><?= h(project_status_label($proyecto['estado'])) ?></b></span>
                </div>
            </div>

            <div class="project-progress-mini">
                <div class="progress-circle" style="--pct: <?= (float)$proyecto['porcentaje_avance'] ?>%;">
                    <?= (float)$proyecto['porcentaje_avance'] ?>%
                </div>
            </div>

            <div class="project-header-actions">
                <a href="projects.php" class="btn btn-outline">← Volver</a>
            </div>
        </div>

        <!-- ============ INFO BAR ============ -->
        <div class="info-bar">
            <?php if ($user['rol'] !== 'visualizador'): ?>
                <button class="btn" onclick="abrirModalNuevaActividad()">+ Nueva actividad</button>
            <?php endif; ?>

            <?php if ($user['rol'] === 'administrador' || $user['rol'] === 'responsable'): ?>
                <button class="btn" style="background:#F08217;" onclick="archivarProyecto()">
                    📦 Archivar proyecto
                </button>
            <?php endif; ?>

            <?php if ($user['rol'] === 'administrador'): ?>
                <button class="btn" style="background:#E5074C;" onclick="eliminarProyecto()">
                    🗑️ Eliminar proyecto
                </button>
            <?php endif; ?>

            <div class="spacer"></div>

            <?php if ($user['rol'] === 'administrador'): ?>
                <button class="btn btn-invitar" onclick="abrirModalInvitar()">
                    ✉️ Invitar usuarios
                </button>
            <?php endif; ?>
        </div>

        <h2 style="color: var(--rtp-guinda); margin-bottom: 16px;">Tablero Kanban</h2>

        <div class="kanban-board">

            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#94a3b8;"></span>
                    <span class="title">Por hacer</span>
                    <span class="count"><?= count($columnas['por_hacer']) ?></span>
                </div>
                <?php foreach ($columnas['por_hacer'] as $a): ?>
                    <?php renderTaskCard($a); ?>
                <?php endforeach; ?>
            </div>

            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#266cb4;"></span>
                    <span class="title">En curso</span>
                    <span class="count"><?= count($columnas['en_curso']) ?></span>
                </div>
                <?php foreach ($columnas['en_curso'] as $a): ?>
                    <?php renderTaskCard($a); ?>
                <?php endforeach; ?>
            </div>

            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#FDC60A;"></span>
                    <span class="title">En revisión</span>
                    <span class="count"><?= count($columnas['en_revision']) ?></span>
                </div>
                <?php foreach ($columnas['en_revision'] as $a): ?>
                    <?php renderTaskCard($a); ?>
                <?php endforeach; ?>
            </div>

            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#027a35;"></span>
                    <span class="title">Completada</span>
                    <span class="count"><?= count($columnas['completada']) ?></span>
                </div>
                <?php foreach ($columnas['completada'] as $a): ?>
                    <?php renderTaskCard($a); ?>
                <?php endforeach; ?>
            </div>

            <div class="kanban-col">
                <div class="kanban-col-header">
                    <span class="dot" style="background:#E5074C;"></span>
                    <span class="title">Bloqueado</span>
                    <span class="count"><?= count($columnas['bloqueado']) ?></span>
                </div>
                <?php foreach ($columnas['bloqueado'] as $a): ?>
                    <?php renderTaskCard($a); ?>
                <?php endforeach; ?>
            </div>

        </div>

        <div class="members-card">
            <h2 style="color: var(--rtp-guinda); font-size: 13pt; margin-bottom: 4px;">
                👥 Miembros del proyecto
            </h2>
            <p style="color: var(--rtp-gris); font-size: 10pt;">
                <?= count($miembros) ?> personas tienen acceso a este proyecto.
            </p>

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
                            <span class="member-rol <?= h($m['rol_miembro']) ?>">
                                <?= h($m['rol_miembro']) ?>
                            </span>
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

        <label style="display: block; margin: 10px 0 6px; font-size: 10.5pt; color: var(--rtp-gris);">
            Descripción (opcional):
        </label>
        <textarea class="input" id="actDesc" placeholder="Describe la actividad..." style="min-height: 70px;"></textarea>

        <label style="display: block; margin: 8px 0 6px; font-size: 10.5pt; color: var(--rtp-gris);">
            Fecha de entrega:
        </label>
        <input type="date" class="input" id="actFecha">

        <label style="display: block; margin: 8px 0 6px; font-size: 10.5pt; color: var(--rtp-gris);">
            Prioridad:
        </label>
        <select class="input" id="actPrioridad">
            <option value="baja">Baja</option>
            <option value="media" selected>Media</option>
            <option value="alta">Alta</option>
            <option value="critica">Crítica</option>
        </select>

        <label style="display: block; margin: 8px 0 6px; font-size: 10.5pt; color: var(--rtp-gris);">
            Asignar a (opcional):
        </label>
        <select class="input" id="actAsignado">
            <option value="">— Sin asignar —</option>
            <?php foreach ($miembros as $m): ?>
                <option value="<?= $m['id_usuario'] ?>"><?= h($m['nombre_completo']) ?></option>
            <?php endforeach; ?>
        </select>

        <div style="display: flex; gap: 10px; margin-top: 16px;">
            <button class="btn btn-outline" onclick="cerrarModalActividad()" style="flex: 1;">← Regresar</button>
            <button class="btn" onclick="crearActividad()" style="flex: 1;">✓ Crear actividad</button>
        </div>
    </div>
</div>

<!-- ============ MODAL: INVITAR ============ -->
<div id="modalInvitar" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalInvitar()">×</span>
        <h2>Invitar usuarios al proyecto</h2>
        <p style="color: var(--rtp-gris); font-size: 10.5pt; margin-bottom: 20px;">
            Envía invitaciones por correo o comparte el link generado.
        </p>

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

        <button class="btn" id="btnEnviarInvitacion" disabled onclick="enviarInvitacion()" style="width: 100%;">
            ✉️ Enviar invitación
        </button>

        <div class="send-status" id="sendStatus"></div>

        <div class="link-generado">
            <div style="flex: 1; min-width: 200px;">
                <div style="font-size: 9.5pt; color: var(--rtp-gris); margin-bottom: 6px;">
                    <b>Link de invitación del proyecto:</b>
                </div>
                <input type="text" id="inviteLink" readonly
                       value="<?= h(env('APP_URL', 'http://localhost:8000')) ?>/invite.php?proyecto=<?= h($id_proyecto) ?>">
            </div>
            <button class="btn-copiar" onclick="copiarLink()">📋 Copiar</button>
        </div>

        <div class="invite-list" id="inviteList"></div>

        <div style="display: flex; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
            <button class="btn btn-outline" onclick="cerrarModalInvitar()" style="flex: 1;">← Cerrar</button>
        </div>
    </div>
</div>

<script>
const PROYECTO_ID = <?= (int)$id_proyecto ?>;
const USER_ROL = '<?= h($user['rol']) ?>';
const invitaciones = [];

/* ============================================================
   NUEVA ACTIVIDAD
   ============================================================ */
function abrirModalNuevaActividad() {
    document.getElementById('actTitle').value = '';
    document.getElementById('actDesc').value = '';
    document.getElementById('actFecha').value = '';
    document.getElementById('actPrioridad').value = 'media';
    document.getElementById('actAsignado').value = '';
    document.getElementById('modalActividad').classList.remove('hidden');
    setTimeout(() => document.getElementById('actTitle').focus(), 100);
}

function cerrarModalActividad() {
    document.getElementById('modalActividad').classList.add('hidden');
}

async function crearActividad() {
    const titulo = document.getElementById('actTitle').value.trim();
    const descripcion = document.getElementById('actDesc').value.trim();
    const fecha = document.getElementById('actFecha').value;
    const prioridad = document.getElementById('actPrioridad').value;
    const asignado = document.getElementById('actAsignado').value;

    if (!titulo) { alert('Escribe un nombre para la actividad.'); return; }

    const res = await fetch('api/create_activity.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id_proyecto: PROYECTO_ID,
            titulo,
            descripcion,
            fecha,
            prioridad,
            asignado_a: asignado || null,
        })
    });

    const json = await res.json();

    if (json.error) {
        alert('Error: ' + json.error);
    } else {
        location.reload();
    }
}

/* ============================================================
   ARCHIVAR PROYECTO
   ============================================================ */
async function archivarProyecto() {
    if (!confirm('¿Archivar este proyecto? Podrás restaurarlo después desde "Proyectos archivados".')) return;

    const res = await fetch('api/archive_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'archivar' })
    });

    const json = await res.json();

    if (json.error) {
        alert('Error: ' + json.error);
    } else {
        alert('✅ Proyecto archivado');
        location.href = 'projects.php';
    }
}

/* ============================================================
   ELIMINAR PROYECTO
   ============================================================ */
async function eliminarProyecto() {
    if (!confirm('⚠️ ¿Eliminar este proyecto?\n\nSe ocultará de todas las listas pero podrás restaurarlo desde "Proyectos eliminados".')) return;

    const res = await fetch('api/delete_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_proyecto: PROYECTO_ID, accion: 'eliminar' })
    });

    const json = await res.json();

    if (json.error) {
        alert('Error: ' + json.error);
    } else {
        alert('✅ Proyecto eliminado');
        location.href = 'projects.php';
    }
}

/* ============================================================
   INVITAR USUARIOS
   ============================================================ */
function abrirModalInvitar() {
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
    alert('✅ Link copiado al portapapeles');
}

async function enviarInvitacion() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const btn = document.getElementById('btnEnviarInvitacion');
    const status = document.getElementById('sendStatus');

    if (!email || !rol) return;

    btn.disabled = true;
    btn.textContent = '⏳ Enviando...';
    status.className = 'send-status';
    status.textContent = '';

    try {
        const res = await fetch('api/invite_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_proyecto: PROYECTO_ID,
                correo: email,
                rol: rol,
            })
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

    btn.disabled = false;
    btn.textContent = '✉️ Enviar invitación';
}

function renderInvitaciones() {
    const cont = document.getElementById('inviteList');
    if (!invitaciones.length) { cont.innerHTML = ''; return; }
    cont.innerHTML = invitaciones.map(i => `
        <div class="invite-item">
            ✉️ <b>${escapeHtml(i.email)}</b>
            <span class="invite-item-rol">${i.rol}</span>
        </div>
    `).join('');
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModalActividad();
        cerrarModalInvitar();
    }
});

document.getElementById('modalActividad').addEventListener('click', function (e) {
    if (e.target === this) cerrarModalActividad();
});
document.getElementById('modalInvitar').addEventListener('click', function (e) {
    if (e.target === this) cerrarModalInvitar();
});
</script>
</body>
</html>