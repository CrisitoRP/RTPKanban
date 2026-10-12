<?php
require_once __DIR__ . '/../src/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;