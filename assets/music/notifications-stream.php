<?php
/* SSE de notificaciones musicales (invites + collab-*) de antes del timbre:
   solo para páginas viejas; contesta una vez y cierra (ver abajo). */
session_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

if (!isset($_SESSION['user'])) { http_response_code(401); exit; }
$userKey = $_SESSION['user'];
session_write_close();

/* Resolver mi user_id una sola vez */
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
$stmt->execute([$userKey]);
$uid = (int)$stmt->fetchColumn();
if (!$uid) { http_response_code(401); exit; }

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$query = $pdo->prepare(
    "SELECT pi.id, pi.type, pi.playlist_id AS playlistId,
            COALESCE(p.name, '') AS playlistName,
            fu.user_key AS fromUser,
            fu.label    AS fromLabel,
            UNIX_TIMESTAMP(pi.created_at) AS sentAt
     FROM playlist_invites pi
     JOIN usuarios fu ON pi.from_user_id = fu.id
     LEFT JOIN playlists p ON pi.playlist_id = p.id
     WHERE pi.to_user_id = ?
     ORDER BY pi.created_at ASC"
);

/* Ya no lo abre el escritorio: pide playlist-invites (assets/music/api.php)
   cuando suena su timbre (assets/php/timbre.php). Queda solo para las
   páginas abiertas antes de ese cambio: contesta una vez y cierra, en vez
   de quedarse 25 s en bucle ocupando un proceso PHP y una conexión a la
   BD por escritorio (lo que llenaba el hosting, ver
   .claude/notas/rendimiento.md). Al recargar, la página ya usa el timbre;
   cuando no quede ninguna vieja se puede borrar este archivo. */
$query->execute([$uid]);
$invites = $query->fetchAll(PDO::FETCH_ASSOC);
foreach ($invites as &$row) {
    $row['id']         = (int)$row['id'];
    $row['playlistId'] = (int)$row['playlistId'];
    $row['sentAt']     = (int)$row['sentAt'];
}
unset($row);
echo "retry: 60000\n";
echo 'data: ' . json_encode($invites) . "\n\n";
if (ob_get_level()) ob_flush();
flush();
