<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] === 'visualizador') {
    json_response(['error' => 'Sin permisos'], 403);
}

$d = json_input();
$nombre = trim($d['nombre'] ?? '');
$actividades = $d['actividades'] ?? [];
$invitaciones = $d['invitaciones'] ?? [];

if (!$nombre) json_response(['error' => 'Falta el nombre del proyecto'], 400);

// ============================================================
// 1. Crear el proyecto
// ============================================================
db()->beginTransaction();

try {
    $stmt = db()->prepare("
        INSERT INTO gestion_proyectos (nombre, id_responsable, creado_por, estado)
        VALUES (?, ?, ?, 'activo')
        RETURNING id_proyecto
    ");
    $stmt->execute([$nombre, $user['id_usuario'], $user['id_usuario']]);
    $id_proyecto = $stmt->fetch()['id_proyecto'];

    // Agregar al creador como administrador del proyecto
    db()->prepare("
        INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
        VALUES (?, ?, 'administrador')
        ON CONFLICT (id_proyecto, id_usuario) DO NOTHING
    ")->execute([$id_proyecto, $user['id_usuario']]);

    // 2. Crear actividades
    if (!empty($actividades)) {
        $stmtAct = db()->prepare("
            INSERT INTO gestion_actividades
                (id_proyecto, titulo, estado, prioridad, fecha_limite, creado_por)
            VALUES (?, ?, 'por_hacer', ?, ?, ?)
        ");
        foreach ($actividades as $a) {
            $stmtAct->execute([
                $id_proyecto,
                $a['titulo'],
                $a['prioridad'] ?? 'media',
                !empty($a['fecha']) ? $a['fecha'] : null,
                $user['id_usuario']
            ]);
        }
    }

    db()->commit();
} catch (Exception $e) {
    db()->rollBack();
    json_response(['error' => 'Error creando proyecto: ' . $e->getMessage()], 500);
}

// ============================================================
// 3. Enviar invitaciones
// ============================================================
$resultados = [];

foreach ($invitaciones as $inv) {
    $email = $inv['email'];
    $rol = $inv['rol'];

    $token = generar_token(64);
    $expira = date('Y-m-d H:i:s', strtotime('+7 days'));

    try {
        db()->prepare("
            INSERT INTO gestion_invitaciones
                (id_proyecto, correo, rol_miembro, token, expira_en, creado_por)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$id_proyecto, $email, $rol, $token, $expira, $user['id_usuario']]);
    } catch (Exception $e) {
        $resultados[] = ['email' => $email, 'ok' => false];
        continue;
    }

    $link = env('APP_URL', 'http://localhost:8000') . '/invite.php?token=' . $token;
    $rolLabel = ucfirst($rol);

    $cuerpo = "
        <div style='font-family: Roboto, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f7fa; padding: 20px;'>
            <div style='background: white; border-radius: 12px; padding: 30px; border-top: 5px solid #78BE20;'>
                <h1 style='color: #9F2241; margin-bottom: 10px;'>Invitación a RTP Kanban</h1>
                <p style='color: #55585A; font-size: 14px; line-height: 1.6;'>
                    <b>" . h($user['nombre_completo']) . "</b> te ha invitado a colaborar en el proyecto
                    <b>" . h($nombre) . "</b> con el rol de <b>" . $rolLabel . "</b>.
                </p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . $link . "' style='background: #78BE20; color: white; padding: 14px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; display: inline-block;'>
                        Aceptar invitación
                    </a>
                </div>
                <p style='color: #94a3b8; font-size: 12px; line-height: 1.5;'>
                    O copia este enlace:<br>
                    <a href='" . $link . "' style='color: #78BE20; word-break: break-all;'>" . $link . "</a>
                </p>
                <p style='color: #94a3b8; font-size: 12px; margin-top: 20px;'>
                    Expira el " . date('d/m/Y', strtotime($expira)) . ".
                </p>
            </div>
        </div>
    ";

    $ok = enviar_correo($email, $email, 'Invitación a RTP Kanban', $cuerpo);
    $resultados[] = ['email' => $email, 'ok' => $ok];
}

json_response([
    'ok' => true,
    'id_proyecto' => $id_proyecto,
    'invitaciones_resultados' => $resultados,
]);