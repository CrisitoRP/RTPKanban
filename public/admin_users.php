<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_role(['administrador']);
$user = current_user();

// Solo mostramos usuarios no eliminados (por si acaso quedan registros viejos)
$usuarios = db()->query("
    SELECT * FROM gestion_usuarios
    WHERE eliminado = FALSE
    ORDER BY creado_en DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Usuarios · RTP</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <main class="content">
        <div class="topbar">
            <h1>Gestión de usuarios</h1>
            <div class="topbar-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
        </div>

        <div style="margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn" onclick="nuevoUsuario()">+ Nuevo usuario</button>
        </div>

        <table class="table">
            <tr>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Último acceso</th>
                <th>Acciones</th>
            </tr>
            <?php if (empty($usuarios)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">
                        No hay usuarios registrados.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><b><?= h($u['nombre_completo']) ?></b></td>
                    <td><?= h($u['correo']) ?></td>
                    <td><?= h($u['rol']) ?></td>
                    <td>
                        <?php if ($u['actividades_finalizadas']): ?>
                            <span style="color:#027a35;">✅ Finalizado</span>
                        <?php elseif ($u['activo']): ?>
                            <span style="color:#027a35;">🟢 Activo</span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">⚪ Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h(fmt_date($u['ultimo_acceso'], true)) ?></td>
                    <td>
                        <?php if ($u['id_usuario'] != $user['id_usuario']): ?>
                            <?php if ($u['actividades_finalizadas']): ?>
                                <button class="btn-mini" style="background:#027a35;"
                                        onclick="reactivarUsuario(<?= $u['id_usuario'] ?>)">
                                    ♻️ Reactivar
                                </button>
                            <?php else: ?>
                                <button class="btn-mini" style="background:#F08217;"
                                        onclick="finalizarUsuario(<?= $u['id_usuario'] ?>)">
                                    ✅ Finalizar actividades
                                </button>
                            <?php endif; ?>

                            <button class="btn-mini" style="background:#E5074C;"
                                    onclick="eliminarUsuario(<?= $u['id_usuario'] ?>)">
                                🗑️ Eliminar
                            </button>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-size:10pt;">(Tú)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </main>
</div>

<!-- Modal nuevo usuario -->
<div id="modalNuevoUsuario" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="cerrarModal('modalNuevoUsuario')">×</span>
        <h2>Nuevo usuario</h2>
        <form onsubmit="crearUsuario(event)">
            <input class="input" name="nombre_completo" placeholder="Nombre completo" required>
            <input class="input" name="nombre_usuario" placeholder="Usuario" required>
            <input class="input" type="email" name="correo" placeholder="Correo" required>
            <input class="input" type="password" name="contrasena" placeholder="Contraseña" required>
            <select class="input" name="rol" required>
                <option value="responsable">Responsable</option>
                <option value="visualizador">Visualizador</option>
                <option value="administrador">Administrador</option>
            </select>
            <button class="btn">Crear usuario</button>
        </form>
    </div>
</div>

<!-- Modal reasignación -->
<div id="modalReasignacion" class="modal hidden">
    <div class="modal-content large">
        <span class="close" onclick="cerrarModal('modalReasignacion')">×</span>
        <h2>Reasignar antes de eliminar</h2>
        <p style="margin-bottom:16px;color:#55585A;">
            Este usuario tiene actividades y/o proyectos asignados. Antes de eliminarlo permanentemente, debes reasignarlos:
        </p>
        <div id="reasignacionBody"></div>
    </div>
</div>

<script>
/* ==================== NUEVO USUARIO ==================== */
function nuevoUsuario() {
    document.getElementById('modalNuevoUsuario').classList.remove('hidden');
}

async function crearUsuario(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await fetch('api/create_user.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

/* ==================== FINALIZAR / REACTIVAR ==================== */
async function finalizarUsuario(id) {
    if (!confirm('¿Marcar al usuario como "actividades finalizadas"?\n\nDejará de recibir notificaciones y no aparecerá en reportes.')) return;

    const res = await fetch('api/finalize_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id, accion: 'finalizar' })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

async function reactivarUsuario(id) {
    if (!confirm('¿Reactivar las actividades de este usuario?')) return;

    const res = await fetch('api/finalize_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id, accion: 'reactivar' })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

/* ==================== ELIMINAR USUARIO ==================== */
async function eliminarUsuario(id) {
    // 1. Consultar qué implica la eliminación
    const check = await fetch('api/delete_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id, accion: 'check' })
    });
    const info = await check.json();

    if (info.error) {
        alert(info.error);
        return;
    }

    // 2. Sin pendientes: eliminar directo con advertencia fuerte
    if (!info.requiere_reasignacion && !info.requiere_nuevo_responsable) {
        const confirmMsg =
            '⚠️ ELIMINACIÓN PERMANENTE ⚠️\n\n' +
            '¿Estás seguro de eliminar a este usuario?\n\n' +
            'Esta acción NO se puede deshacer.\n\n' +
            'Se borrarán:\n' +
            '• Su cuenta de usuario\n' +
            '• Sus comentarios\n' +
            '• Sus notificaciones\n' +
            '• Sus eventos de calendario\n\n' +
            'Sus actividades y proyectos se reasignarán a ti.';

        if (!confirm(confirmMsg)) return;

        const res = await fetch('api/delete_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_usuario: id, accion: 'eliminar' })
        });
        const json = await res.json();
        if (json.error) alert(json.error);
        else {
            alert('✅ Usuario eliminado permanentemente');
            location.reload();
        }
        return;
    }

    // 3. Con pendientes: mostrar modal de reasignación
    mostrarReasignacion(id, info);
}

async function mostrarReasignacion(id, info) {
    const body = document.getElementById('reasignacionBody');
    let html = '';

    if (info.proyectos_como_responsable.length) {
        html += '<h3 style="color:#9F2241;margin:10px 0;">📁 Proyectos que quedarán sin responsable</h3>';
        info.proyectos_como_responsable.forEach(p => {
            html += `
                <div style="background:#fff3e0;padding:12px;border-radius:8px;margin-bottom:8px;">
                    <b>${escapeHtml(p.nombre)}</b>
                    <div style="margin-top:8px;">
                        Nuevo responsable:
                        <select class="input" id="nuevo_resp_${p.id_proyecto}" style="margin-top:6px;">
                            <option value="">— Seleccionar —</option>
                        </select>
                    </div>
                </div>
            `;
        });
    }

    if (info.actividades_asignadas.length) {
        html += '<h3 style="color:#9F2241;margin:20px 0 10px;">📌 Actividades que deben reasignarse</h3>';
        info.actividades_asignadas.forEach(a => {
            html += `
                <div style="background:#fde8ec;padding:12px;border-radius:8px;margin-bottom:8px;">
                    <b>${escapeHtml(a.titulo)}</b>
                    <div style="font-size:12px;color:#55585A;">Proyecto: ${escapeHtml(a.proyecto)} · Estado: ${escapeHtml(a.estado)}</div>
                    <div style="margin-top:8px;">
                        Nuevo responsable:
                        <select class="input" id="nuevo_act_${a.id_actividad}" style="margin-top:6px;">
                            <option value="">— Seleccionar —</option>
                        </select>
                    </div>
                </div>
            `;
        });
    }

    html += `
        <div style="background:#fde8ec;padding:14px;border-radius:8px;margin-top:16px;border-left:4px solid #E5074C;">
            <b style="color:#991b1b;">⚠️ Advertencia</b>
            <p style="color:#991b1b;font-size:10.5pt;margin-top:6px;">
                Al confirmar, este usuario será eliminado permanentemente de la base de datos.
                Esta acción no se puede deshacer.
            </p>
        </div>
        <div style="margin-top:20px;text-align:right;">
            <button class="btn" onclick="cerrarModal('modalReasignacion')" style="background:#94a3b8;">← Cancelar</button>
            <button class="btn" onclick="confirmarReasignacion(${id})" style="background:#E5074C;">
                Confirmar reasignación y eliminar permanentemente
            </button>
        </div>
    `;

    body.innerHTML = html;
    document.getElementById('modalReasignacion').classList.remove('hidden');

    // Cargar usuarios disponibles para reasignar
    const resUsers = await fetch('api/list_users.php');
    const users = await resUsers.json();

    document.querySelectorAll('#reasignacionBody select').forEach(sel => {
        users.forEach(u => {
            if (u.id_usuario === id) return;
            const opt = document.createElement('option');
            opt.value = u.id_usuario;
            opt.textContent = u.nombre_completo + ' (' + u.rol + ')';
            sel.appendChild(opt);
        });
    });
}

async function confirmarReasignacion(id) {
    const reasignaciones = [];
    const nuevos_responsables = [];
    let faltantes = 0;

    document.querySelectorAll('#reasignacionBody select').forEach(sel => {
        if (!sel.value) { faltantes++; return; }

        if (sel.id.startsWith('nuevo_act_')) {
            reasignaciones.push({
                id_actividad: sel.id.replace('nuevo_act_', ''),
                id_usuario_nuevo: sel.value
            });
        }
        if (sel.id.startsWith('nuevo_resp_')) {
            nuevos_responsables.push({
                id_proyecto: sel.id.replace('nuevo_resp_', ''),
                id_usuario_nuevo: sel.value
            });
        }
    });

    if (faltantes > 0) {
        alert('Debes asignar un nuevo responsable a todos los elementos antes de continuar.');
        return;
    }

    const res = await fetch('api/delete_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id_usuario: id,
            accion: 'reasignar_eliminar',
            reasignaciones,
            nuevos_responsables
        })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else {
        alert('✅ Usuario eliminado permanentemente');
        location.reload();
    }
}

/* ==================== HELPERS ==================== */
function cerrarModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
</script>
</body>
</html>