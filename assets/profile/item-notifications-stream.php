<?php
/* SSE de invites + status notifs de items (perfil) de antes del timbre:
   solo para páginas viejas; contesta una vez y cierra (ver abajo). */
session_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

if (!isset($_SESSION['user'])) { http_response_code(401); exit; }
$userKey = $_SESSION['user'];
session_write_close();

$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
$stmt->execute([$userKey]);
$uid = (int)$stmt->fetchColumn();
if (!$uid) { http_response_code(401); exit; }

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$query = $pdo->prepare(
    "SELECT i.id, i.type, i.category, i.item_id AS itemId,
            i.item_title AS itemTitle, i.item_image AS itemImage,
            i.item_music_type AS itemMusicType, i.item_artist AS itemArtist,
            fu.user_key AS fromUser, fu.label AS fromLabel,
            UNIX_TIMESTAMP(i.created_at) AS sentAt
     FROM item_invites i
     JOIN usuarios fu ON i.from_user_id = fu.id
     WHERE i.to_user_id = ?
     ORDER BY i.created_at ASC"
);

/* Ya no lo abre el escritorio: pide item-invites (assets/profile/api.php)
   cuando suena su timbre (assets/php/timbre.php). Queda solo para las
   páginas abiertas antes de ese cambio: contesta una vez y cierra, en vez
   de quedarse 25 s en bucle ocupando un proceso PHP y una conexión a la
   BD por escritorio (lo que llenaba el hosting, ver
   .claude/notas/rendimiento.md). Al recargar, la página ya usa el timbre;
   cuando no quede ninguna vieja se puede borrar este archivo. */
$query->execute([$uid]);
$invites = $query->fetchAll(PDO::FETCH_ASSOC);
foreach ($invites as &$row) {
    $row['id']     = (int)$row['id'];
    $row['itemId'] = $row['itemId'] !== null ? (int)$row['itemId'] : null;
    $row['sentAt'] = (int)$row['sentAt'];
}
unset($row);
echo "retry: 60000\n";
echo 'data: ' . json_encode($invites) . "\n\n";
if (ob_get_level()) ob_flush();
flush();
