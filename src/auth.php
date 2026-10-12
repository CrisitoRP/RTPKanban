<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Devuelve el usuario logueado o null
 */
function current_user() {
    if (empty($_SESSION['id_usuario'])) return null;

    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare("SELECT * FROM gestion_usuarios WHERE id_usuario = ? AND activo = TRUE");
        $stmt->execute([$_SESSION['id_usuario']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/**
 * Exige que haya sesión iniciada
 */
function require_login() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Exige uno de los roles indicados
 */
function require_role(array $roles) {
    require_login();
    $u = current_user();
    if (!in_array($u['rol'], $roles)) {
        http_response_code(403);
        exit('Acceso denegado');
    }
}

/**
 * Intenta iniciar sesión con correo y contraseña
 */
function login($correo, $contrasena) {
    $stmt = db()->prepare("SELECT * FROM gestion_usuarios WHERE correo = ? AND activo = TRUE");
    $stmt->execute([$correo]);
    $u = $stmt->fetch();

    if ($u && password_verify($contrasena, $u['hash_contrasena'])) {
        $_SESSION['id_usuario'] = $u['id_usuario'];

        // Registrar último acceso
        db()->prepare("UPDATE gestion_usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id_usuario = ?")
            ->execute([$u['id_usuario']]);

        return true;
    }
    return false;
}

/**
 * Cierra la sesión
 */
function logout() {
    session_destroy();
}