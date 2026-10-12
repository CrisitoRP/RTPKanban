<?php
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

        <?php if ($u['rol'] === 'administrador'): ?>
            <a href="admin_users.php">👥 Usuarios</a>
        <?php endif; ?>
    </nav>

    <div class="user-box">
        <span><?= h($u['nombre_completo']) ?></span>
        <small><?= h($u['rol']) ?></small>
        <a href="logout.php">Cerrar sesión</a>
    </div>
</aside>