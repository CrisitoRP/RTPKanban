<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_login();

$user = current_user();
if ($user['rol'] === 'visualizador') {
    header('Location: projects.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nuevo proyecto · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* ============ WIZARD ============ */
.wizard-container { max-width: 850px; margin: 0 auto; }

.wizard-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
    position: relative;
}
.wizard-steps::before {
    content: '';
    position: absolute;
    top: 19px;
    left: 10%;
    right: 10%;
    height: 2px;
    background: #e2e8f0;
    z-index: 0;
}
.wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    z-index: 1;
    background: var(--bg);
    padding: 0 14px;
    flex: 1;
}
.wizard-step .step-num {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #94a3b8;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13pt;
    transition: all .3s;
}
.wizard-step.active .step-num {
    background: var(--rtp-verde);
    color: white;
    box-shadow: 0 4px 12px rgba(120, 190, 32, 0.4);
    transform: scale(1.1);
}
.wizard-step.done .step-num {
    background: var(--estado-verde);
    color: white;
}
.wizard-step .step-label {
    font-size: 10pt;
    color: var(--rtp-gris);
    font-weight: 500;
    text-align: center;
    line-height: 1.3;
}
.wizard-step.active .step-label {
    color: var(--rtp-guinda);
    font-weight: 700;
}

.wizard-card {
    background: white;
    border-radius: 14px;
    padding: 32px;
    box-shadow: 0 4px 16px rgba(0,0,0,.06);
    border-top: 5px solid var(--rtp-verde);
}
.wizard-card h2 {
    color: var(--rtp-guinda);
    font-size: 15pt;
    margin-bottom: 6px;
}
.wizard-card .sub {
    color: var(--rtp-gris);
    font-size: 10.5pt;
    margin-bottom: 24px;
    line-height: 1.5;
}

.wizard-actions {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}
.wizard-actions .btn {
    min-width: 150px;
    text-align: center;
}
.btn-outline {
    background: white;
    color: var(--rtp-gris);
    border: 2px solid #e2e8f0;
}
.btn-outline:hover {
    border-color: var(--rtp-verde);
    color: var(--rtp-verde-dark);
    background: #f8fef0;
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.2);
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ============ ACTIVIDADES ============ */
.activity-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}
.activity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: #f8fafc;
    border-radius: 10px;
    border-left: 4px solid var(--rtp-verde);
    transition: all .15s;
}
.activity-item:hover {
    background: #ecf9d9;
    box-shadow: 0 4px 12px rgba(120, 190, 32, 0.15);
}
.activity-item-info { flex: 1; }
.activity-item-title {
    font-weight: 700;
    color: var(--rtp-negro);
    font-size: 11.5pt;
    margin-bottom: 2px;
}
.activity-item-meta { font-size: 10pt; color: var(--rtp-gris); }
.activity-item-remove {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: none;
    background: #fee2e2;
    color: #991b1b;
    cursor: pointer;
    font-size: 14pt;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.activity-item-remove:hover {
    background: var(--estado-rojo);
    color: white;
    transform: scale(1.1);
}

/* ============ INVITACIONES ============ */
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

.wizard-panel { display: none; }
.wizard-panel.active { display: block; }
</style>
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <div class="wizard-container">

            <!-- ============ PASOS ============ -->
            <div class="wizard-steps">
                <div class="wizard-step active" data-step="1">
                    <div class="step-num">1</div>
                    <div class="step-label">Nombre<br>del proyecto</div>
                </div>
                <div class="wizard-step" data-step="2">
                    <div class="step-num">2</div>
                    <div class="step-label">Actividades<br>del proyecto</div>
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-num">3</div>
                    <div class="step-label">Invitar<br>usuarios</div>
                </div>
            </div>

            <!-- ============ PASO 1 ============ -->
            <div class="wizard-panel active" id="panel1">
                <div class="wizard-card">
                    <h2>Nombre del proyecto</h2>
                    <p class="sub">Escribe el nombre con el que identificarás este proyecto.</p>

                    <input type="text" class="input" id="proyectoNombre"
                           placeholder="Ej. Plataforma interna RTP"
                           style="font-size: 13pt; padding: 14px 16px;"
                           maxlength="200" autofocus>

                    <div class="wizard-actions">
                        <a href="projects.php" class="btn btn-outline">← Regresar</a>
                        <button class="btn" id="btnNext1" disabled onclick="irPaso2()">Aceptar →</button>
                    </div>
                </div>
            </div>

            <!-- ============ PASO 2 ============ -->
            <div class="wizard-panel" id="panel2">
                <div class="wizard-card">
                    <h2>Actividades del proyecto</h2>
                    <p class="sub">Agrega todas las actividades que necesites. Puedes crear más después.</p>

                    <div id="activityList" class="activity-list"></div>

                    <button class="btn btn-outline" onclick="abrirModalActividad()" style="width: 100%;">
                        + Crear actividad
                    </button>

                    <div class="wizard-actions">
                        <button class="btn btn-outline" onclick="irPaso1()">← Regresar</button>
                        <button class="btn" onclick="irPaso3()">Invitar usuarios →</button>
                    </div>
                </div>
            </div>

            <!-- ============ PASO 3 ============ -->
            <div class="wizard-panel" id="panel3">
                <div class="wizard-card">
                    <h2>Invitar usuarios al proyecto</h2>
                    <p class="sub">Envía invitaciones por correo o comparte el link generado.</p>

                    <input type="email" class="input" id="inviteEmail"
                           placeholder="Correo electrónico"
                           oninput="validarFormInvite()">

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

                    <button class="btn" id="btnEnviarInvitacion" disabled onclick="agregarInvitacion()" style="width: 100%;">
                        ✉️ Agregar invitación
                    </button>

                    <div class="invite-list" id="inviteList"></div>

                    <div class="link-generado">
                        <div style="flex: 1; min-width: 200px;">
                            <div style="font-size: 9.5pt; color: var(--rtp-gris); margin-bottom: 6px;">
                                <b>Link de invitación (se generará al crear el proyecto):</b>
                            </div>
                            <input type="text" id="inviteLink" readonly value="Se generará al crear el proyecto...">
                        </div>
                        <button class="btn-copiar" onclick="copiarLink()">📋 Copiar</button>
                    </div>

                    <div class="wizard-actions">
                        <button class="btn btn-outline" onclick="irPaso2()">← Regresar</button>
                        <button class="btn" id="btnFinalizar" onclick="finalizar()">✓ Crear proyecto</button>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ============ MODAL ACTIVIDAD ============ -->
<div id="modalActividad" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalActividad()">×</span>
        <h2>Nueva actividad</h2>

        <input type="text" class="input" id="actTitle" placeholder="Nombre de la actividad" maxlength="200">

        <label style="display: block; margin-bottom: 6px; font-size: 10.5pt; color: var(--rtp-gris);">
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

        <div style="display: flex; gap: 10px; margin-top: 16px;">
            <button class="btn btn-outline" onclick="cerrarModalActividad()" style="flex: 1;">← Regresar</button>
            <button class="btn" onclick="agregarActividad()" style="flex: 1;">✓ Aceptar</button>
        </div>
    </div>
</div>

<script>
/* ============================================================
   ESTADO
   ============================================================ */
const state = {
    proyecto: { nombre: '' },
    actividades: [],
    invitaciones: [],
};

/* ============================================================
   NAVEGACIÓN
   ============================================================ */
function mostrarPaso(n) {
    document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.wizard-step').forEach(s => {
        s.classList.remove('active', 'done');
        if (+s.dataset.step < n) s.classList.add('done');
        if (+s.dataset.step == n) s.classList.add('active');
    });
    document.getElementById('panel' + n).classList.add('active');
}

function irPaso1() { mostrarPaso(1); }

function irPaso2() {
    const nombre = document.getElementById('proyectoNombre').value.trim();
    if (!nombre) { alert('Escribe un nombre para el proyecto.'); return; }
    state.proyecto.nombre = nombre;
    mostrarPaso(2);
}

function irPaso3() { mostrarPaso(3); }

/* ============================================================
   PASO 1
   ============================================================ */
document.getElementById('proyectoNombre').addEventListener('input', function () {
    document.getElementById('btnNext1').disabled = this.value.trim().length < 3;
});

/* ============================================================
   PASO 2
   ============================================================ */
function abrirModalActividad() {
    document.getElementById('actTitle').value = '';
    document.getElementById('actFecha').value = '';
    document.getElementById('actPrioridad').value = 'media';
    document.getElementById('modalActividad').classList.remove('hidden');
    setTimeout(() => document.getElementById('actTitle').focus(), 100);
}

function cerrarModalActividad() {
    document.getElementById('modalActividad').classList.add('hidden');
}

function agregarActividad() {
    const titulo = document.getElementById('actTitle').value.trim();
    const fecha = document.getElementById('actFecha').value;
    const prioridad = document.getElementById('actPrioridad').value;

    if (!titulo) { alert('Escribe un nombre para la actividad.'); return; }
    if (!fecha) { alert('Selecciona una fecha de entrega.'); return; }

    state.actividades.push({ titulo, fecha, prioridad });
    renderActividades();
    cerrarModalActividad();
}

function eliminarActividad(idx) {
    if (!confirm('¿Eliminar esta actividad?')) return;
    state.actividades.splice(idx, 1);
    renderActividades();
}

function renderActividades() {
    const cont = document.getElementById('activityList');
    if (!state.actividades.length) {
        cont.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:20px;font-style:italic;font-size:10.5pt;">Aún no has agregado actividades.</p>';
        return;
    }
    cont.innerHTML = state.actividades.map((a, i) => `
        <div class="activity-item">
            <div class="activity-item-info">
                <div class="activity-item-title">📌 ${escapeHtml(a.titulo)}</div>
                <div class="activity-item-meta">📅 Entrega: <b>${a.fecha}</b> · Prioridad: <b>${a.prioridad}</b></div>
            </div>
            <button class="activity-item-remove" onclick="eliminarActividad(${i})" title="Eliminar">×</button>
        </div>
    `).join('');
}

/* ============================================================
   PASO 3
   ============================================================ */
function validarFormInvite() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const valido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && rol;
    document.getElementById('btnEnviarInvitacion').disabled = !valido;
}

function agregarInvitacion() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;

    // Verificar duplicado
    if (state.invitaciones.some(i => i.email === email)) {
        alert('Ya agregaste este correo.');
        return;
    }

    state.invitaciones.push({ email, rol });
    renderInvitaciones();

    document.getElementById('inviteEmail').value = '';
    document.getElementById('inviteRol').value = '';
    document.getElementById('btnEnviarInvitacion').disabled = true;
}

function renderInvitaciones() {
    const cont = document.getElementById('inviteList');
    if (!state.invitaciones.length) { cont.innerHTML = ''; return; }
    cont.innerHTML = state.invitaciones.map((i, idx) => `
        <div class="invite-item">
            ✉️ <b>${escapeHtml(i.email)}</b>
            <span class="invite-item-rol">${i.rol}</span>
            <button onclick="eliminarInvitacion(${idx})"
                    style="margin-left:auto;background:none;border:none;color:#E5074C;font-size:16pt;cursor:pointer;"
                    title="Quitar">×</button>
        </div>
    `).join('');
}

function eliminarInvitacion(idx) {
    state.invitaciones.splice(idx, 1);
    renderInvitaciones();
}

function copiarLink() {
    const input = document.getElementById('inviteLink');
    input.select();
    document.execCommand('copy');
    alert('✅ Link copiado al portapapeles');
}

/* ============================================================
   FINALIZAR
   ============================================================ */
async function finalizar() {
    if (!state.proyecto.nombre) { alert('Falta el nombre del proyecto.'); return; }

    const btn = document.getElementById('btnFinalizar');
    btn.disabled = true;
    btn.textContent = '⏳ Creando...';

    try {
        const res = await fetch('api/create_project_full.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre: state.proyecto.nombre,
                actividades: state.actividades,
                invitaciones: state.invitaciones,
            })
        });

        const json = await res.json();

        if (json.error) {
            alert('Error: ' + json.error);
            btn.disabled = false;
            btn.textContent = '✓ Crear proyecto';
            return;
        }

        // Mostrar resultado
        let mensaje = '✅ Proyecto creado\n\n' +
                      '📋 Actividades: ' + state.actividades.length + '\n';

        if (json.invitaciones_resultados && json.invitaciones_resultados.length) {
            let ok = 0, fail = 0;
            json.invitaciones_resultados.forEach(r => r.ok ? ok++ : fail++);
            mensaje += '✉️ Invitaciones enviadas: ' + ok;
            if (fail) mensaje += '\n⚠️ Fallidas: ' + fail;
        }

        alert(mensaje);
        window.location.href = 'project_detail.php?id=' + json.id_proyecto;

    } catch (e) {
        alert('Error al crear el proyecto: ' + e.message);
        btn.disabled = false;
        btn.textContent = '✓ Crear proyecto';
    }
}

/* ============================================================
   HELPERS
   ============================================================ */
function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModalActividad();
});

document.getElementById('modalActividad').addEventListener('click', function (e) {
    if (e.target === this) cerrarModalActividad();
});
</script>
</body>
</html>