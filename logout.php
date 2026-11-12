<?php
session_start();
/* Este navegador deja de recordar la cuenta (assets/remember-login.php). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assets/remember-login.php';
forgetLogin($pdo);
session_destroy();
/* ?to=manual → flujo manual (escribir usuario y contraseña). Útil desde
   el menú de ajustes del móvil. Cualquier otro valor (o sin param) cae
   en index.php con el picker de avatares por compatibilidad. */
$to = $_GET['to'] ?? '';
if ($to === 'manual') {
    header('Location: login-manual.php');
} else {
    header('Location: index.php?nointro=1');
}
exit;
