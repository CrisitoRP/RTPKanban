<?php
// ============================================================
// DEFENSA: Garantizar que auth.php esté cargado antes de usar current_user()
// ============================================================
if (!function_exists('current_user')) {
    $rutas_posibles = [
        __DIR__ . '/../../src/auth.php',   // src/ al mismo nivel que public/
        __DIR__ . '/../src/auth.php',       // src/ dentro de public/
    ];
    foreach ($rutas_posibles as $r) {
        if (file_exists($r)) {
            require_once $r;
            break;
        }
    }
}

if (!function_exists('current_user')) {
    die('ERROR CRÍTICO: No se pudo cargar auth.php desde sidebar.php. Ruta buscada desde: ' . __DIR__);
}

$u = current_user();
?>
<aside class="sidebar">
    <div class="logo-header">
        <img src="assets/img/logo-rtp.png" alt="RTP">
    </div>

    <nav>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="projects.php">📁 Proyectos</a>
        <a href="notifications.php">🔔 Notificaciones</a>
        <a href="calendar.php">📅 Calendario</a>

        <?php if ($u && $u['rol'] === 'administrador'): ?>
            <a href="admin_users.php">👥 Usuarios</a>
        <?php endif; ?>
    </nav>

    <div class="user-box">
        <span><?= h($u['nombre_completo'] ?? 'Usuario') ?></span>
        <small><?= h($u['rol'] ?? '') ?></small>
        <a href="logout.php">Cerrar sesión</a>
    </div>
</aside>