<?php
/* ════════════════════════════════════════════════════════════════
   PENDING ACTION NOTIFICATIONS — endpoint unificado.

   Devuelve TODOS los invites pendientes del usuario actual desde las
   fuentes (listen-together, partner, playlist colab, item colab y
   amigos: solicitud / aceptada) en
   un solo JSON. Usado por la shell móvil para mostrar los pendientes
   al abrir la app y vía polling cada 15s.

   Formato:
   {
     ok: true,
     invites: [
       { source: 'listen',   id, fromKey, fromLabel, ... },
       { source: 'partner',  id, fromKey, fromLabel },
       { source: 'playlist', id, fromKey, fromLabel, playlistId, playlistName },
       { source: 'item',     id, fromKey, fromLabel, category, itemId, itemTitle, itemImage }
     ]
   }

   Cada entry contiene los datos mínimos para que el frontend pueda
   renderizar el toast/sheet sin necesidad de fetchear más data.
═════════════════════════════════════════════════════════════════════ */
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/themes/theme-helpers.php';

session_start();
$userKey = $_SESSION['user'] ?? '';
if (!$userKey) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No session']);
    exit;
}
$userId = userIdByKey($userKey);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unknown user']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$invites = [];

/* ── LISTEN-TOGETHER ───────────────────────────────────────────── */
try {
    $st = $pdo->prepare("
        SELECT i.id,
               u.user_key  AS from_key,
               u.label     AS from_label,
               s.track_title  AS track_title,
               s.track_artist AS track_artist
          FROM listening_invites i
          JOIN usuarios            u ON u.id = i.from_user_id
          JOIN listening_sessions  s ON s.id = i.session_id
         WHERE i.to_user_id = ?
           AND i.status = 'pending'
           AND s.closed_at IS NULL
           AND i.created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY i.id DESC
    ");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $invites[] = [
            'source'    => 'listen',
            'id'        => (int)$r['id'],
            'fromKey'   => $r['from_key'],
            'fromLabel' => $r['from_label'],
            'trackTitle'  => $r['track_title']  ?? '',
            'trackArtist' => $r['track_artist'] ?? '',
        ];
    }
} catch (Throwable $e) { /* tabla puede no existir → ignora */ }

/* ── PARTNER ──────────────────────────────────────────────────── */
try {
    $st = $pdo->prepare("
        SELECT pi.id, fu.user_key AS from_key, fu.label AS from_label
          FROM partner_invites pi
          JOIN usuarios fu ON pi.from_user_id = fu.id
         WHERE pi.to_user_id = ?
         ORDER BY pi.created_at ASC
    ");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $invites[] = [
            'source'    => 'partner',
            'id'        => (int)$r['id'],
            'fromKey'   => $r['from_key'],
            'fromLabel' => $r['from_label'],
        ];
    }
} catch (Throwable $e) { }

/* ── PLAYLIST COLAB ───────────────────────────────────────────── */
try {
    $st = $pdo->prepare("
        SELECT pi.id, fu.user_key AS from_key, fu.label AS from_label,
               pi.playlist_id, p.name AS playlist_name
          FROM playlist_invites pi
          JOIN usuarios  fu ON pi.from_user_id = fu.id
          JOIN playlists p  ON p.id  = pi.playlist_id
         WHERE pi.to_user_id = ?
           AND pi.type = 'invite'
         ORDER BY pi.id DESC
    ");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $invites[] = [
            'source'       => 'playlist',
            'id'           => (int)$r['id'],
            'fromKey'      => $r['from_key'],
            'fromLabel'    => $r['from_label'],
            'playlistId'   => (int)$r['playlist_id'],
            'playlistName' => $r['playlist_name'],
        ];
    }
} catch (Throwable $e) { }

/* ── ITEM COLAB ───────────────────────────────────────────────── */
try {
    $st = $pdo->prepare("
        SELECT ii.id, fu.user_key AS from_key, fu.label AS from_label,
               ii.category, ii.item_id, ii.item_title, ii.item_image
          FROM item_invites ii
          JOIN usuarios fu ON ii.from_user_id = fu.id
         WHERE ii.to_user_id = ?
           AND ii.type = 'invite'
         ORDER BY ii.id DESC
    ");
    $st->execute([(int)$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $invites[] = [
            'source'    => 'item',
            'id'        => (int)$r['id'],
            'fromKey'   => $r['from_key'],
            'fromLabel' => $r['from_label'],
            'category'  => $r['category'],
            'itemId'    => (int)$r['item_id'],
            'itemTitle' => $r['item_title'],
            'itemImage' => $r['item_image'],
        ];
    }
} catch (Throwable $e) { }

/* ── AMIGOS (assets/profile/amigos.php) ──────────────────────────
   'amigo'    = solicitud pendiente (Rechazar / Aceptar);
   'amigo-ok' = te han aceptado (sale una vez: amigos_avisos la marca). */
try {
    require_once dirname(__DIR__) . '/php/timbre.php';
    require_once dirname(__DIR__) . '/profile/amigos.php';
    amigos_ensure($pdo);
    $av = amigos_avisos($pdo, (int)$userId);
    foreach ($av['solicitudes'] as $x) {
        $invites[] = ['source' => 'amigo', 'id' => $x['key'] . '-' . $x['ts'], 'fromKey' => $x['key'], 'fromLabel' => $x['nombre']];
    }
    foreach ($av['aceptadas'] as $x) {
        $invites[] = ['source' => 'amigo-ok', 'id' => $x['id'], 'fromKey' => $x['key'], 'fromLabel' => $x['nombre']];
    }
} catch (Throwable $e) { error_log('[pending] amigos: ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'invites' => $invites], JSON_UNESCAPED_UNICODE);
