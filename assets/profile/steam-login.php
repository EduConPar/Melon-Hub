<?php
/* Vincular Steam (perfil nuevo, steam.php): manda al inicio de sesión
   oficial de Steam (OpenID 2.0). Steam vuelve a steam-callback.php con la
   identidad firmada; el `state` de la sesión evita que otro la cuele. */
session_start();
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/steam.php';
$userKey = $_SESSION['user'] ?? null;
if (!$userKey || !isset($loginUsers[$userKey])) { header('Location: ../../index.php'); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['steam_openid_state'] = $state;
$_SESSION['steam_openid_user']  = $userKey;

header('Location: ' . steam_loginUrl('state', $state));
