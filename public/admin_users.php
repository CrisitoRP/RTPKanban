<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/helpers.php';
require_role(['administrador']);
$user = current_user();

$ver_eliminados = isset($_GET['eliminados']) && $_GET['eliminados'] == '1';

$where = $ver_eliminados ? "eliminado = TRUE" : "eliminado = FALSE";
$usuarios = db()->query("
    SELECT * FROM gestion_usuarios
    WHERE $where
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
            <h1><?= $ver_eliminados ? 'Usuarios eliminados' : 'Gestión de usuarios' ?></h1>
            <div class="topbar-logo">
                <img src="assets/img/logo-rtp.png" alt="RTP">
            </div>
        </div>

        <div style="margin-bottom:16px; display:flex; gap:10px;">
            <button class="btn" onclick="nuevoUsuario()">+ Nuevo usuario</button>
            <?php if ($ver_eliminados): ?>
                <a class="btn" href="admin_users.php" style="background:#94a3b8;">← Volver a activos</a>
            <?php else: ?>
                <a class="btn" href="admin_users.php?eliminados=1" style="background:#E5074C;">🗑️ Ver eliminados</a>
            <?php endif; ?>
        </div>

        <table class="table">
            <tr>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= h($u['nombre_completo']) ?></td>
                <td><?= h($u['correo']) ?></td>
                <td><?= h($u['rol']) ?></td>
                <td>
                    <?php if ($u['eliminado']): ?>
                        🗑️ Eliminado <?= h(fmt_date($u['eliminado_en'], true)) ?>
                    <?php elseif ($u['actividades_finalizadas']): ?>
                        ✅ Actividades finalizadas
                    <?php elseif ($u['activo']): ?>
                        🟢 Activo
                    <?php else: ?>
                        ⚪ Inactivo
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$u['eliminado']): ?>
                        <?php if ($u['actividades_finalizadas']): ?>
                            <button class="btn-mini" onclick="reactivarUsuario(<?= $u['id_usuario'] ?>)">♻️ Reactivar</button>
                        <?php else: ?>
                            <button class="btn-mini" onclick="finalizarUsuario(<?= $u['id_usuario'] ?>)" style="background:#F08217;">✅ Finalizar actividades</button>
                        <?php endif; ?>
                        <button class="btn-mini" onclick="eliminarUsuario(<?= $u['id_usuario'] ?>)" style="background:#E5074C;">🗑️ Eliminar</button>
                    <?php else: ?>
                        <button class="btn-mini" onclick="reactivarCuenta(<?= $u['id_usuario'] ?>)">♻️ Reactivar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </main>
</div>

<!-- Modal nuevo usuario -->
<div id="modalNuevoUsuario" class="modal hidden">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')">×</span>
        <h2>Nuevo usuario</h2>
        <form id="formNuevoUsuario" onsubmit="crearUsuario(event)">
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
        <span class="close" onclick="cerrarReasignacion()">×</span>
        <h2>Reasignar actividades</h2>
        <p style="margin-bottom:16px;color:#55585A;">
            Este usuario tiene actividades y proyectos asignados. Antes de eliminarlo, debes reasignar:
        </p>
        <div id="reasignacionBody"></div>
    </div>
</div>

<script>
async function crearUsuario(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await fetch('api/create_user.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

function nuevoUsuario() {
    document.getElementById('modalNuevoUsuario').classList.remove('hidden');
}

async function finalizarUsuario(id) {
    if (!confirm('¿Marcar al usuario como "actividades finalizadas"? Dejará de recibir notificaciones y no aparecerá en reportes.')) return;
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
    const res = await fetch('api/finalize_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id, accion: 'reactivar' })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

async function eliminarUsuario(id) {
    // 1. Ver qué implica
    const check = await fetch('api/delete_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id, accion: 'check' })
    });
    const info = await check.json();

    // 2. Si no hay pendientes, eliminar directo
    if (!info.requiere_reasignacion && !info.requiere_nuevo_responsable) {
        if (!confirm('¿Eliminar este usuario? No tiene actividades ni proyectos asignados.')) return;
        const res = await fetch('api/delete_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_usuario: id, accion: 'eliminar' })
        });
        const json = await res.json();
        if (json.error) alert(json.error);
        else location.reload();
        return;
    }

    // 3. Hay pendientes: mostrar modal
    mostrarReasignacion(id, info);
}

function mostrarReasignacion(id, info) {
    const body = document.getElementById('reasignacionBody');
    let html = '';

    if (info.proyectos_como_responsable.length) {
        html += '<h3 style="color:#9F2241;margin-bottom:10px;">Proyectos que quedarán sin responsable</h3>';
        info.proyectos_como_responsable.forEach(p => {
            html += `
                <div style="background:#fff3e0;padding:12px;border-radius:8px;margin-bottom:8px;">
                    <b>📁 ${escapeHtml(p.nombre)}</b>
                    <input type="hidden" name="proyecto_${p.id_proyecto}">
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
        html += '<h3 style="color:#9F2241;margin:20px 0 10px;">Actividades que deben reasignarse</h3>';
        info.actividades_asignadas.forEach(a => {
            html += `
                <div style="background:#fde8ec;padding:12px;border-radius:8px;margin-bottom:8px;">
                    <b>📌 ${escapeHtml(a.titulo)}</b>
                    <div style="font-size:12px;color:#55585A;">Proyecto: ${escapeHtml(a.proyecto)}</div>
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
        <div style="margin-top:20px;text-align:right;">
            <button class="btn" onclick="confirmarReasignacion(${id})" style="background:#E5074C;">
                Confirmar reasignación y eliminar
            </button>
        </div>
    `;

    body.innerHTML = html;
    document.getElementById('modalReasignacion').classList.remove('hidden');

    // Cargar lista de usuarios disponibles
    fetch('api/list_users.php')
        .then(r => r.json())
        .then(users => {
            document.querySelectorAll('#reasignacionBody select').forEach(sel => {
                users.forEach(u => {
                    if (u.id_usuario === id) return;
                    const opt = document.createElement('option');
                    opt.value = u.id_usuario;
                    opt.textContent = u.nombre_completo + ' (' + u.rol + ')';
                    sel.appendChild(opt);
                });
            });
        });
}

function cerrarReasignacion() {
    document.getElementById('modalReasignacion').classList.add('hidden');
}

async function confirmarReasignacion(id) {
    const reasignaciones = [];
    const nuevos_responsables = [];

    document.querySelectorAll('#reasignacionBody select').forEach(sel => {
        if (!sel.value) return;
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
    else location.reload();
}

async function reactivarCuenta(id) {
    if (!confirm('¿Reactivar este usuario?')) return;
    const res = await fetch('api/reactivate_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id_usuario: id })
    });
    const json = await res.json();
    if (json.error) alert(json.error);
    else location.reload();
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
</script>
</body>
</html>