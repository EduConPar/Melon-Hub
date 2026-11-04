<?php
/* Vuelta del inicio de sesión de Steam (steam-login.php). Se comprueba
   con el propio Steam (check_authentication) que la identidad es buena y
   se guarda el SteamID64; el icono de Steam del perfil pasa a enlazar a
   esa cuenta. Avisa a la ventana del perfil (postMessage 'steam-linked'),
   como el vincular de Discord, y se cierra. */
session_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/steam.php';

function finish(string $status, string $detail = ''): void {
    $s = json_encode($status); $d = json_encode($detail);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Steam</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#171a21;color:#c7d5e0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;padding:20px;box-sizing:border-box}h2{margin:0 0 6px;font-size:18px}p{margin:0;font-size:13px;color:#8f98a0}</style></head>'
       . '<body><div><h2 id="t"></h2><p id="d"></p></div><script>'
       . "var status = $s, detail = $d;"
       . "document.getElementById('t').textContent = status === 'ok' ? '✓ Steam vinculado' : '✗ No se pudo vincular Steam';"
       . "document.getElementById('d').textContent = detail;"
       . "try { if (window.opener && !window.opener.closed) window.opener.postMessage({ type: 'steam-linked', status: status, detail: detail }, '*'); } catch (e) {}"
       . "setTimeout(function () { try { window.close(); } catch (e) {} }, status === 'ok' ? 800 : 2500);"
       . '</script></body></html>';
    exit;
}

$userKey       = $_SESSION['user']               ?? null;
$expectedState = $_SESSION['steam_openid_state'] ?? null;
$expectedUser  = $_SESSION['steam_openid_user']  ?? null;
unset($_SESSION['steam_openid_state'], $_SESSION['steam_openid_user']);

if (!$userKey || !isset($loginUsers[$userKey]))  finish('error', 'Sesión caducada');
if ($expectedUser !== $userKey)                   finish('error', 'La sesión cambió por el camino');
$state = (string)($_GET['state'] ?? '');
if (!$expectedState || !hash_equals($expectedState, $state)) finish('error', 'Petición no válida');
if (($_GET['openid_mode'] ?? '') !== 'id_res')    finish('error', 'Cancelado');

/* PHP cambia los "." de los parámetros por "_": se rehacen los openid.*. */
$params = [];
foreach ($_GET as $k => $v) {
    if (strpos($k, 'openid_') === 0) $params['openid.' . substr($k, 7)] = (string)$v;
}
$params['openid.mode'] = 'check_authentication';
/* La vuelta tiene que ser a este mismo callback (con nuestro state). */
if (strpos($params['openid.return_to'] ?? '', '/steam-callback.php?state=' . $state) === false) finish('error', 'Vuelta no válida');
if (!preg_match('#^https?://steamcommunity\.com/openid/id/(\d{17})$#', $params['openid.claimed_id'] ?? '', $m)) finish('error', 'Identidad no válida');
$sid = $m[1];

$ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: MelonHub\r\n",
    'content' => http_build_query($params),
]]);
$resp = @file_get_contents(steam_openid_url(), false, $ctx);
if (!$resp || strpos($resp, 'is_valid:true') === false) finish('error', 'Steam no confirmó la identidad');

try {
    steam_ensure($pdo);
    $pdo->prepare("UPDATE usuarios SET steam_id = ? WHERE user_key = ?")->execute([$sid, $userKey]);
    $uid = (int)$pdo->query("SELECT id FROM usuarios WHERE user_key = " . $pdo->quote($userKey))->fetchColumn();
    $pdo->prepare("INSERT INTO profile (user_id, steam) VALUES (?, ?) ON DUPLICATE KEY UPDATE steam = VALUES(steam)")
        ->execute([$uid, 'https://steamcommunity.com/profiles/' . $sid]);
} catch (Throwable $e) {
    finish('error', 'No se pudo guardar');
}
$sum = steam_summary($sid);
finish('ok', $sum && $sum['persona'] !== '' ? $sum['persona'] : $sid);
