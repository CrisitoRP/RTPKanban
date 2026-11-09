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

/* ============ FECHA DE INICIO (TARJETAS) ============ */
.apertura-opciones {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 22px;
}
.apertura-opcion {
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 18px;
    cursor: pointer;
    transition: all .2s ease;
    text-align: center;
    background: white;
    position: relative;
}
.apertura-opcion:hover {
    border-color: var(--rtp-verde);
    background: #f8fef0;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.15);
}
.apertura-opcion.seleccionada {
    border-color: var(--rtp-verde);
    background: #ecf9d9;
    box-shadow: 0 6px 16px rgba(120, 190, 32, 0.2);
}
.apertura-opcion.seleccionada::after {
    content: '✓';
    position: absolute;
    top: 10px;
    right: 12px;
    width: 24px;
    height: 24px;
    background: var(--rtp-verde);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12pt;
    box-shadow: 0 2px 6px rgba(120, 190, 32, 0.4);
}
.apertura-opcion .op-icon {
    font-size: 28pt;
    display: block;
    margin-bottom: 10px;
}
.apertura-opcion .op-titulo {
    font-weight: 700;
    font-size: 11.5pt;
    color: var(--rtp-guinda);
    margin-bottom: 4px;
}
.apertura-opcion .op-desc {
    font-size: 9.5pt;
    color: var(--rtp-gris);
    line-height: 1.4;
}

/* Panel de programación */
.programacion-panel {
    display: none;
    background: #f8fafc;
    border-radius: 12px;
    padding: 22px 24px;
    border-left: 4px solid var(--rtp-guinda);
    margin-top: 6px;
    margin-bottom: 22px;
    animation: slideDown .25s ease;
}
.programacion-panel.visible { display: block; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.programacion-panel .prog-label {
    display: block;
    font-size: 10.5pt;
    color: var(--rtp-guinda);
    font-weight: 700;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.programacion-panel input[type="date"],
.programacion-panel input[type="time"] {
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 12pt;
    font-family: inherit;
    color: var(--rtp-guinda);
    font-weight: 700;
    background: white;
    transition: all .15s;
    cursor: pointer;
}
.programacion-panel input[type="date"]:focus,
.programacion-panel input[type="time"]:focus {
    outline: none;
    border-color: var(--rtp-verde);
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.15);
}
.programacion-panel .prog-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
}

/* ============ FECHA DE FIN ============ */
.date-row-fin {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
    background: #f8fafc;
    padding: 16px 20px;
    border-radius: 12px;
    border-left: 4px solid var(--rtp-verde);
}
.date-row-fin input[type="date"] {
    flex: 1;
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 11pt;
    font-family: inherit;
    color: var(--rtp-guinda);
    font-weight: 700;
}
.date-row-fin input[type="date"]:focus {
    outline: none;
    border-color: var(--rtp-verde);
    box-shadow: 0 0 0 3px rgba(120, 190, 32, 0.15);
}
.date-row-fin input[type="date"]:disabled {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
}
.date-row-fin .date-check {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10pt;
    color: var(--rtp-gris);
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.date-row-fin .date-check input {
    cursor: pointer;
    width: 18px;
    height: 18px;
    accent-color: var(--rtp-verde);
}
.date-hint {
    font-size: 9.5pt;
    color: #94a3b8;
    font-style: italic;
    margin-bottom: 16px;
    line-height: 1.4;
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
                    <div class="step-label">Fechas<br>del proyecto</div>
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-num">3</div>
                    <div class="step-label">Invitar<br>usuarios</div>
                </div>
            </div>

            <!-- ============ PASO 1: NOMBRE ============ -->
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

            <!-- ============ PASO 2: FECHAS ============ -->
            <div class="wizard-panel" id="panel2">
                <div class="wizard-card">
                    <h2>Fechas del proyecto</h2>
                    <p class="sub">Define cuándo inicia y cuándo termina este proyecto.</p>

                    <!-- FECHA DE INICIO -->
                    <label class="prog-label" style="margin-top:0;">📅 Fecha de inicio (Apertura)</label>
                    <div class="apertura-opciones">
                        <div class="apertura-opcion" id="opAhora" onclick="seleccionarApertura('ahora')">
                            <span class="op-icon">⚡</span>
                            <div class="op-titulo">Disponible ahora mismo</div>
                            <div class="op-desc">El proyecto iniciará hoy mismo y todos podrán acceder.</div>
                        </div>

                        <div class="apertura-opcion" id="opProgramar" onclick="seleccionarApertura('programar')">
                            <span class="op-icon">📅</span>
                            <div class="op-titulo">Programar apertura</div>
                            <div class="op-desc">Elige fecha y hora. El proyecto se abrirá automáticamente.</div>
                        </div>
                    </div>

                    <!-- Panel de programación -->
                    <div class="programacion-panel" id="panelProgramacion">
                        <div class="prog-grid">
                            <div>
                                <label class="prog-label">📅 Fecha de apertura</label>
                                <input type="date" id="fechaApertura" onchange="actualizarPreview()">
                            </div>
                            <div>
                                <label class="prog-label">🕐 Hora</label>
                                <input type="time" id="horaApertura" value="09:00" onchange="actualizarPreview()">
                            </div>
                        </div>
                        <p style="font-size:9.5pt; color:#94a3b8; margin-top:10px; font-style:italic;">
                            💡 La fecha debe ser posterior a hoy.
                        </p>
                    </div>

                    <!-- FECHA DE FIN -->
                    <label class="prog-label" style="margin-top:22px;">🏁 Fecha de finalización</label>
                    <div class="date-row-fin">
                        <input type="date" id="fechaFin">
                        <label class="date-check">
                            <input type="checkbox" id="checkFechaFin" onchange="toggleFechaFin()"> Aún sin asignar
                        </label>
                    </div>
                    <p class="date-hint">
                        💡 Si no conoces la fecha de término exacta, puedes dejarla en blanco. Podrás agregarla o modificarla después desde el detalle del proyecto.
                    </p>

                    <div class="wizard-actions">
                        <button class="btn btn-outline" onclick="irPaso1()">← Regresar</button>
                        <button class="btn" id="btnNext2" onclick="irPaso3()">Siguiente →</button>
                    </div>
                </div>
            </div>

            <!-- ============ PASO 3: INVITAR ============ -->
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

<script>
/* ============================================================ */
/* ESTADO */
/* ============================================================ */
const state = {
    proyecto: { nombre: '' },
    apertura: {
        tipo: null,       // 'ahora' | 'programar'
        fecha: null,      // 'YYYY-MM-DD'
        hora: null        // 'HH:MM'
    },
    fecha_fin: null,
    invitaciones: []
};

/* ============================================================ */
/* NAVEGACIÓN */
/* ============================================================ */
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

/* ============================================================ */
/* PASO 1 */
/* ============================================================ */
document.getElementById('proyectoNombre').addEventListener('input', function () {
    document.getElementById('btnNext1').disabled = this.value.trim().length < 3;
});

/* ============================================================ */
/* PASO 2: APERTURA Y FECHA FIN */
/* ============================================================ */
function seleccionarApertura(tipo) {
    state.apertura.tipo = tipo;

    document.getElementById('opAhora').classList.toggle('seleccionada', tipo === 'ahora');
    document.getElementById('opProgramar').classList.toggle('seleccionada', tipo === 'programar');

    const panel = document.getElementById('panelProgramacion');
    panel.classList.toggle('visible', tipo === 'programar');

    if (tipo === 'ahora') {
        state.apertura.fecha = null;
        state.apertura.hora = null;
        document.getElementById('fechaApertura').value = '';
        document.getElementById('horaApertura').value = '09:00';
        document.getElementById('btnNext2').disabled = false;
    } else {
        const hoy = new Date();
        hoy.setDate(hoy.getDate() + 1);
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        document.getElementById('fechaApertura').setAttribute('min', `${yyyy}-${mm}-${dd}`);

        validarFechaProgramada();
    }
}

function validarFechaProgramada() {
    const fecha = document.getElementById('fechaApertura').value;
    const hora = document.getElementById('horaApertura').value;
    const btn = document.getElementById('btnNext2');

    state.apertura.fecha = fecha || null;
    state.apertura.hora = hora || null;

    if (!fecha || !hora) {
        btn.disabled = true;
        return;
    }

    const fechaHora = new Date(`${fecha}T${hora}:00`);
    const ahora = new Date();

    if (fechaHora <= ahora) {
        btn.disabled = true;
    } else {
        btn.disabled = false;
    }
}

function actualizarPreview() {
    if (state.apertura.tipo === 'programar') {
        validarFechaProgramada();
    }
}

function toggleFechaFin() {
    const check = document.getElementById('checkFechaFin');
    const input = document.getElementById('fechaFin');
    
    if (check.checked) {
        input.value = '';
        input.disabled = true;
        state.fecha_fin = null;
    } else {
        input.disabled = false;
    }
}

/* ============================================================ */
/* PASO 3: INVITACIONES */
/* ============================================================ */
function validarFormInvite() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;
    const valido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && rol;
    document.getElementById('btnEnviarInvitacion').disabled = !valido;
}

function agregarInvitacion() {
    const email = document.getElementById('inviteEmail').value.trim();
    const rol = document.getElementById('inviteRol').value;

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

/* ============================================================ */
/* FINALIZAR */
/* ============================================================ */
async function finalizar() {
    if (!state.proyecto.nombre) { alert('Falta el nombre del proyecto.'); return; }

    let fecha_apertura = null;
    let fecha_inicio = null;

    if (state.apertura.tipo === 'programar') {
        if (!state.apertura.fecha || !state.apertura.hora) {
            alert('Debes seleccionar fecha y hora de apertura.');
            return;
        }
        fecha_apertura = `${state.apertura.fecha} ${state.apertura.hora}:00`;
        fecha_inicio = state.apertura.fecha; // La fecha de inicio es el día de apertura
    } else {
        // Si es "Ahora", la fecha de inicio es HOY
        fecha_inicio = new Date().toISOString().slice(0, 10);
    }

    const fecha_fin = document.getElementById('checkFechaFin').checked ? null : document.getElementById('fechaFin').value;

    const btn = document.getElementById('btnFinalizar');
    btn.disabled = true;
    btn.textContent = '⏳ Creando...';

    try {
        const res = await fetch('api/create_project_full.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre: state.proyecto.nombre,
                fecha_inicio: fecha_inicio,
                fecha_fin: fecha_fin || null,
                fecha_apertura: fecha_apertura,
                invitaciones: state.invitaciones
            })
        });

        const json = await res.json();

        if (json.error) {
            alert('Error: ' + json.error);
            btn.disabled = false;
            btn.textContent = '✓ Crear proyecto';
            return;
        }

        let mensaje = '✅ Proyecto creado exitosamente.';
        if (json.invitaciones_resultados && json.invitaciones_resultados.length) {
            let ok = 0, fail = 0;
            json.invitaciones_resultados.forEach(r => r.ok ? ok++ : fail++);
            mensaje += '\n✉️ Invitaciones enviadas: ' + ok;
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

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
</script>
</body>
</html>