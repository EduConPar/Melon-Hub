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

$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$origin = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$return = $origin . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/steam-callback.php?state=' . $state;
header('Location: ' . steam_openid_url() . '?' . http_build_query([
    'openid.ns'         => 'http://specs.openid.net/auth/2.0',
    'openid.mode'       => 'checkid_setup',
    'openid.return_to'  => $return,
    'openid.realm'      => $origin . '/',
    'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
    'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
]));
