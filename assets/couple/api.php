<?php
/* ──────────────────────────────────────────────────────────
   COUPLE API — router único para Calendario / Pareja
   ──────────────────────────────────────────────────────────
   Acciones (vía ?action= o $_POST['action']):

   GET   ?action=get-momentos&pareja_id=N
   GET   ?action=get-recordatorios&pareja_id=N
   GET   ?action=get-users
   GET   ?action=get-partner-invites
   GET   ?action=widget-calendar       (widgets de la app: momentos + recordatorios sin pareja_id)

   POST  ?action=save-momento          { pareja_id, titulo, fecha, descripcion }
   POST  ?action=save-recordatorio     { pareja_id, titulo, fecha, descripcion, periodicidad }
   POST  ?action=delete-momento        { id }
   POST  ?action=delete-recordatorio   { id }
   POST  ?action=purge-recordatorios   (borra no-periódicos cuya fecha < hoy)
   POST  ?action=invite-partner        { toUser }
   POST  ?action=respond-partner-invite{ inviteId, action: 'accept'|'reject', fecha? }
   POST  ?action=upload-foto           multipart: momento_id + foto
   ────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/../../db.php';

$u        = requireAuth();
$userKey  = $u['key'];
$action   = $_GET['action'] ?? $_POST['action'] ?? '';

/* Helper: obtiene el id (PK) en la tabla `usuarios` del usuario actual. */
function getCurrentUserId(PDO $pdo, string $userKey): ?int {
    static $cache = [];
    if (array_key_exists($userKey, $cache)) return $cache[$userKey];
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $cache[$userKey] = $id ? (int)$id : null;
}

/* Cumpleaños de los amigos (seguimiento mutuo) que lo tienen puesto en el
   perfil, con la forma de un recordatorio anual. No se guardan en
   `recordatorios`: se calculan en cada petición, así salen en cuanto os
   seguís los dos, desaparecen si uno deja de seguir y siguen al día si
   alguien cambia la fecha. `cumple: true` hace que los calendarios no
   pongan la ✕ (borrarlo no serviría: volvería a salir). `amigo_id` lo usa
   upcoming-reminders para no repetir avisos. El propio cumpleaños no sale:
   es para acordarse del de los demás. */
function cumplesDeAmigos(PDO $pdo, int $uid): array {
    require_once dirname(__DIR__) . '/social-helpers.php';
    $ids = mutualFollowerIds($pdo, $uid);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT u.id, u.user_key, u.label, u.username, p.birthday
                               FROM usuarios u JOIN profile p ON p.user_id = u.id
                              WHERE u.id IN ($ph) AND p.birthday IS NOT NULL");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return []; /* la columna la crea el API del perfil; aún no ha pasado */
    }
    $out = [];
    foreach ($rows as $r) {
        $fecha = (string)$r['birthday'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < '1900-01-01') continue;
        $nombre = $r['label'] ?: $r['username'];
        $out[] = [
            'id'           => 'cumple-' . $r['user_key'],
            'titulo'       => '🎂 Cumpleaños de ' . $nombre,
            'fecha'        => $fecha,
            'descripcion'  => '',
            'periodicidad' => 'anual',
            'autor'        => '',
            'cumple'       => true,
            'amigo_id'     => (int)$r['id'],
        ];
    }
    return $out;
}

/* Auto-migración: tabla que registra qué recordatorios YA se notificaron
   para cada threshold (7/2/1 días) y fecha de ocurrencia, evitando
   reenviar la misma notificación. */
require_once dirname(__DIR__) . '/php/esquema.php';   /* melon_esquema: tablas una vez por versión */
try {
    melon_esquema($pdo, 'recordatorios-avisados', __FILE__, function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reminder_notifs_sent (
                recordatorio_id INT NOT NULL,
                user_id         INT NOT NULL,
                threshold       INT NOT NULL,
                occurrence_date DATE NOT NULL,
                sent_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (recordatorio_id, user_id, threshold, occurrence_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }, true);
} catch (Throwable $e) { /* silencio */ }

switch ($action) {

case 'get-momentos': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonResponse([]);
    $parejaId = (int)($_GET['pareja_id'] ?? 0);
    if ($parejaId) {
        $stmt = $pdo->prepare("SELECT m.id, m.titulo, m.descripcion, m.fecha, m.foto, u.username AS autor
                               FROM momentos m JOIN usuarios u ON m.usuario_id = u.id
                               WHERE m.pareja_id = ? ORDER BY m.fecha ASC");
        $stmt->execute([$parejaId]);
    } else {
        $stmt = $pdo->prepare("SELECT m.id, m.titulo, m.descripcion, m.fecha, m.foto, u.username AS autor
                               FROM momentos m JOIN usuarios u ON m.usuario_id = u.id
                               WHERE m.usuario_id = ? AND m.pareja_id IS NULL
                               ORDER BY m.fecha ASC");
        $stmt->execute([$userId]);
    }
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

case 'save-momento': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonError('Usuario no encontrado');
    $b      = jsonBody();
    $titulo = trim($b['titulo'] ?? '');
    $fecha  = $b['fecha'] ?? '';
    if (!$titulo || !$fecha) jsonError('Datos incompletos');
    $foto = trim($b['foto'] ?? '');
    $stmt = $pdo->prepare("INSERT INTO momentos (pareja_id, usuario_id, titulo, descripcion, fecha, foto)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $pid = (int)($b['pareja_id'] ?? 0);
    $stmt->execute([
        $pid > 0 ? $pid : null, $userId, $titulo,
        trim($b['descripcion'] ?? ''), $fecha,
        $foto ?: null,
    ]);
    jsonResponse(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

case 'delete-momento': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonError('Usuario no encontrado');
    $id = (int)(jsonBody()['id'] ?? 0);
    if (!$id) jsonError('ID inválido');
    $stmt = $pdo->prepare("SELECT foto FROM momentos WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('No autorizado', 403);
    if ($row['foto']) {
        $ruta = __DIR__ . '/../../uploads/momentos/' . $row['foto'];
        if (file_exists($ruta)) @unlink($ruta);
    }
    $pdo->prepare("DELETE FROM momentos WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

/* Borra TODOS los momentos del usuario que tengan exactamente este
   título. Pensado para auto-cleanup cuando se elimina un item completado
   del perfil → el momento que se creó via crearMomentoDesdeItem desaparece
   también. Si el momento ya fue borrado manualmente, no hace nada (idempotente).
   Tiene en cuenta que un mismo título pudo crearse varias veces por
   ciclos completar→pendiente→completar; borra todos. */
case 'delete-momento-by-title': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonError('Usuario no encontrado');
    $title = trim((string)(jsonBody()['title'] ?? ''));
    if ($title === '') jsonError('Título vacío');
    /* Limpia fotos en disco antes del DELETE. */
    $stmt = $pdo->prepare("SELECT id, foto FROM momentos WHERE usuario_id = ? AND titulo = ?");
    $stmt->execute([$userId, $title]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!empty($r['foto'])) {
            $ruta = __DIR__ . '/../../uploads/momentos/' . $r['foto'];
            if (file_exists($ruta)) @unlink($ruta);
        }
    }
    $pdo->prepare("DELETE FROM momentos WHERE usuario_id = ? AND titulo = ?")
        ->execute([$userId, $title]);
    jsonResponse(['ok' => true, 'deleted' => count($rows)]);
}

case 'upload-foto': {
    $momentoId = (int)($_POST['momento_id'] ?? 0);
    if (!$momentoId) jsonError('ID de momento inválido');
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) jsonError('Error al subir la foto');
    $file = $_FILES['foto'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) jsonError('Formato no permitido');
    if ($file['size'] > 5 * 1024 * 1024) jsonError('La foto no puede superar 5MB');
    $filename = 'momento_' . $momentoId . '_' . time() . '.' . $ext;
    $destino  = __DIR__ . '/../../uploads/momentos/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destino)) jsonError('Error al guardar la foto', 500);
    $pdo->prepare("UPDATE momentos SET foto = ? WHERE id = ?")->execute([$filename, $momentoId]);
    jsonResponse(['ok' => true, 'foto' => $filename]);
}

/* ─── Recordatorios ─── */
case 'get-recordatorios': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonResponse([]);
    $parejaId = (int)($_GET['pareja_id'] ?? 0);
    /* COALESCE para que filas legacy con periodicidad NULL no exploten
       en el frontend (que esperaba 'ninguna' por default).
       Con pareja: devuelve los compartidos (pareja_id = N) Y los
       personales del usuario actual (pareja_id = 0). Antes solo se
       devolvían los compartidos, así los recordatorios personales
       (p.ej. los que genera la app de Eventos al unirse a un evento)
       no aparecían a usuarios con pareja. */
    if ($parejaId) {
        $stmt = $pdo->prepare("SELECT r.id, r.titulo, r.fecha, r.descripcion,
                               COALESCE(r.periodicidad, 'ninguna') AS periodicidad,
                               u.username AS autor
                               FROM recordatorios r JOIN usuarios u ON r.usuario_id = u.id
                               WHERE r.pareja_id = ?
                                  OR (r.usuario_id = ? AND (r.pareja_id = 0 OR r.pareja_id IS NULL))
                               ORDER BY r.fecha ASC");
        $stmt->execute([$parejaId, $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT r.id, r.titulo, r.fecha, r.descripcion,
                               COALESCE(r.periodicidad, 'ninguna') AS periodicidad,
                               u.username AS autor
                               FROM recordatorios r JOIN usuarios u ON r.usuario_id = u.id
                               WHERE r.usuario_id = ? AND (r.pareja_id = 0 OR r.pareja_id IS NULL)
                               ORDER BY r.fecha ASC");
        $stmt->execute([$userId]);
    }
    /* + cumpleaños de MIS amigos (no los de los amigos de la pareja). */
    jsonResponse(array_merge($stmt->fetchAll(PDO::FETCH_ASSOC), cumplesDeAmigos($pdo, $userId)));
}

case 'save-recordatorio': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonError('Usuario no encontrado');
    $b      = jsonBody();
    $titulo = trim($b['titulo'] ?? '');
    $fecha  = $b['fecha'] ?? '';
    if (!$titulo || !$fecha) jsonError('Datos incompletos');
    $periodicidad = in_array($b['periodicidad'] ?? '', ['anual','mensual','semanal'])
                    ? $b['periodicidad'] : 'ninguna';
    /* `recordatorios.pareja_id` es `NOT NULL DEFAULT 0`. Si pasamos NULL,
       MySQL con sql_mode=STRICT_TRANS_TABLES (default en Hostinger y la
       mayoría de hosts gestionados) rechaza el INSERT y devuelve 500.
       En local sin strict mode el NULL se coercía a 0 → bug invisible.
       Las queries SELECT (get-recordatorios, purge) ya cubren ambos casos
       con `pareja_id = 0 OR pareja_id IS NULL`, así que pasar 0 es safe. */
    $pid = (int)($b['pareja_id'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO recordatorios (usuario_id, pareja_id, titulo, fecha, descripcion, periodicidad)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId, $pid > 0 ? $pid : 0, $titulo, $fecha,
        trim($b['descripcion'] ?? ''), $periodicidad,
    ]);
    jsonResponse(['ok' => true]);
}

case 'delete-recordatorio': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonError('Usuario no encontrado');
    $id = (int)(jsonBody()['id'] ?? 0);
    if (!$id) jsonError('ID inválido');
    $stmt = $pdo->prepare("SELECT id FROM recordatorios WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) jsonError('No autorizado', 403);
    $pdo->prepare("DELETE FROM recordatorios WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

case 'purge-recordatorios': {
    /* Elimina recordatorios no periódicos cuya fecha ya pasó hace
       MÁS DE 1 DÍA. A5: margen de seguridad para no borrar el
       recordatorio del día actual cerca de medianoche, ni si el
       reloj del usuario está desfasado vs el del servidor. */
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonResponse(['ok' => true]);
    $parejaId = (int)($_GET['pareja_id'] ?? 0);
    /* fecha < hoy - 1 día → solo borramos lo que pasó hace 2+ días. */
    $hoy = date('Y-m-d', strtotime('-1 day'));
    if ($parejaId) {
        /* Compartidos de la pareja: cualquiera de los dos puede limpiar. */
        $stmt = $pdo->prepare("DELETE FROM recordatorios
                               WHERE pareja_id = ?
                                 AND (periodicidad = 'ninguna' OR periodicidad IS NULL)
                                 AND fecha < ?");
        $stmt->execute([$parejaId, $hoy]);
        /* Personales del usuario actual (p.ej. recordatorios de eventos):
           cada usuario limpia los SUYOS — no los de su pareja. */
        $stmt = $pdo->prepare("DELETE FROM recordatorios
                               WHERE usuario_id = ? AND (pareja_id = 0 OR pareja_id IS NULL)
                                 AND (periodicidad = 'ninguna' OR periodicidad IS NULL)
                                 AND fecha < ?");
        $stmt->execute([$userId, $hoy]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM recordatorios
                               WHERE usuario_id = ? AND (pareja_id = 0 OR pareja_id IS NULL)
                                 AND (periodicidad = 'ninguna' OR periodicidad IS NULL)
                                 AND fecha < ?");
        $stmt->execute([$userId, $hoy]);
    }
    jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

/* ─── Usuarios ─── */
case 'get-users': {
    require_once dirname(__DIR__) . '/social-helpers.php';
    $uid = getCurrentUserId($pdo, $userKey);
    $mutualIds = $uid ? mutualFollowerIds($pdo, $uid) : [];
    $mutualKeys = [];
    if ($mutualIds) {
        $ph = implode(',', array_fill(0, count($mutualIds), '?'));
        $st = $pdo->prepare("SELECT user_key FROM usuarios WHERE id IN ($ph)");
        $st->execute($mutualIds);
        $mutualKeys = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    }
    $users = [];
    foreach ($GLOBALS['loginUsers'] as $key => $u2) {
        if ($key === $userKey) continue;
        if (!isset($mutualKeys[$key])) continue;
        $users[] = ['key' => $key, 'label' => $u2['label']];
    }
    jsonResponse($users);
}

/* ─── Invitaciones de pareja ─── */
case 'get-partner-invites': {
    $uid = getCurrentUserId($pdo, $userKey);
    if (!$uid) jsonResponse([]);
    $stmt = $pdo->prepare("SELECT pi.id, fu.user_key AS fromUser, fu.label AS fromLabel
                           FROM partner_invites pi
                           JOIN usuarios fu ON pi.from_user_id = fu.id
                           WHERE pi.to_user_id = ?
                           ORDER BY pi.created_at ASC");
    $stmt->execute([$uid]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* Recordatorios próximos al usuario actual (y a su pareja si tiene),
   filtrados a las próximas ocurrencias que caen en 7, 2 o 1 días.
   Cada match registra un row en reminder_notifs_sent → solo se devuelve
   UNA vez. Si la tabla tiene PK compuesta y INSERT IGNORE, ejecuciones
   concurrentes no duplican notificaciones. Manda push best-effort. */
/* Widgets de calendario de la app de Android (CalendarWidget): lo mismo que
   carga el calendario del móvil (momentos y recordatorios de la pareja,
   más los recordatorios personales, como los de los eventos, y los
   cumpleaños de los amigos), sin tener que saber el pareja_id. Sin pareja,
   como el calendario: los momentos y recordatorios personales y los
   cumpleaños. Las repeticiones las expande el widget. */
case 'widget-calendar': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonResponse(['ok' => true, 'pareja' => false, 'momentos' => [], 'recordatorios' => []]);
    $stPair = $pdo->prepare("SELECT id FROM parejas WHERE usuario1_id = ? OR usuario2_id = ? LIMIT 1");
    $stPair->execute([$userId, $userId]);
    $parejaId = (int)($stPair->fetchColumn() ?: 0);

    if ($parejaId) {
        $stM = $pdo->prepare("SELECT fecha, foto FROM momentos WHERE pareja_id = ? ORDER BY fecha ASC");
        $stM->execute([$parejaId]);
        $stR = $pdo->prepare("SELECT r.titulo, r.fecha, COALESCE(r.periodicidad, 'ninguna') AS periodicidad
                              FROM recordatorios r
                              WHERE r.pareja_id = ?
                                 OR (r.usuario_id = ? AND (r.pareja_id = 0 OR r.pareja_id IS NULL))
                              ORDER BY r.fecha ASC");
        $stR->execute([$parejaId, $userId]);
    } else {
        $stM = $pdo->prepare("SELECT fecha, foto FROM momentos
                              WHERE usuario_id = ? AND pareja_id IS NULL ORDER BY fecha ASC");
        $stM->execute([$userId]);
        $stR = $pdo->prepare("SELECT r.titulo, r.fecha, COALESCE(r.periodicidad, 'ninguna') AS periodicidad
                              FROM recordatorios r
                              WHERE r.usuario_id = ? AND (r.pareja_id = 0 OR r.pareja_id IS NULL)
                              ORDER BY r.fecha ASC");
        $stR->execute([$userId]);
    }
    $recs = $stR->fetchAll(PDO::FETCH_ASSOC);
    foreach (cumplesDeAmigos($pdo, $userId) as $c) {
        $recs[] = ['titulo' => $c['titulo'], 'fecha' => $c['fecha'], 'periodicidad' => 'anual'];
    }
    jsonResponse([
        'ok'            => true,
        'pareja'        => (bool)$parejaId,
        'momentos'      => $stM->fetchAll(PDO::FETCH_ASSOC),
        'recordatorios' => $recs,
    ]);
}

case 'upcoming-reminders': {
    $userId = getCurrentUserId($pdo, $userKey);
    if (!$userId) jsonResponse(['ok' => true, 'reminders' => []]);

    /* Encuentra pareja_id del usuario (si tiene). */
    $stPair = $pdo->prepare("
        SELECT id FROM parejas
         WHERE usuario1_id = ? OR usuario2_id = ?
         LIMIT 1
    ");
    $stPair->execute([$userId, $userId]);
    $parejaId = (int)($stPair->fetchColumn() ?: 0);

    /* Carga TODOS los recordatorios del usuario + de su pareja. */
    if ($parejaId) {
        $stR = $pdo->prepare("
            SELECT id, titulo, fecha, descripcion, periodicidad
              FROM recordatorios
             WHERE usuario_id = ? OR pareja_id = ?
        ");
        $stR->execute([$userId, $parejaId]);
    } else {
        $stR = $pdo->prepare("
            SELECT id, titulo, fecha, descripcion, periodicidad
              FROM recordatorios
             WHERE usuario_id = ?
        ");
        $stR->execute([$userId]);
    }
    $rows = array_merge($stR->fetchAll(PDO::FETCH_ASSOC), cumplesDeAmigos($pdo, (int)$userId));

    $today = new DateTimeImmutable('today');
    $matches = [];

    /* Calcula la próxima ocurrencia de un recordatorio en función de su
       periodicidad (ninguna/semanal/mensual/anual). Devuelve DateTime
       o null si ya no aplica. */
    $nextOccurrence = function(string $fecha, ?string $periodicidad) use ($today): ?DateTimeImmutable {
        try {
            $d = new DateTimeImmutable($fecha);
        } catch (Throwable $e) { return null; }
        $per = $periodicidad ?: 'ninguna';
        if ($per === 'ninguna') {
            return ($d >= $today) ? $d : null;
        }
        if ($per === 'semanal') {
            $diffDays = (int)$today->diff($d)->format('%r%a');
            /* Avanzar de 7 en 7 hasta caer en o después de hoy. */
            while ($diffDays < 0) {
                $d = $d->modify('+7 days');
                $diffDays = (int)$today->diff($d)->format('%r%a');
            }
            return $d;
        }
        if ($per === 'mensual') {
            /* Misma día-del-mes. Si pasó este mes, próximo mes. */
            $tryDate = $today->setDate(
                (int)$today->format('Y'),
                (int)$today->format('m'),
                min((int)$d->format('d'), (int)$today->format('t'))
            );
            if ($tryDate < $today) {
                $next = $today->modify('first day of next month');
                $tryDate = $next->setDate(
                    (int)$next->format('Y'),
                    (int)$next->format('m'),
                    min((int)$d->format('d'), (int)$next->format('t'))
                );
            }
            return $tryDate;
        }
        if ($per === 'anual') {
            $tryDate = $today->setDate(
                (int)$today->format('Y'),
                (int)$d->format('m'),
                (int)$d->format('d')
            );
            if ($tryDate < $today) {
                $tryDate = $tryDate->modify('+1 year');
            }
            return $tryDate;
        }
        return null;
    };

    $thresholds = [7, 2, 1];
    foreach ($rows as $r) {
        $next = $nextOccurrence($r['fecha'], $r['periodicidad'] ?? null);
        if (!$next) continue;
        $diffDays = (int)$today->diff($next)->format('%r%a');
        if (!in_array($diffDays, $thresholds, true)) continue;

        /* INSERT IGNORE: si ya enviamos esta notif (recordatorio + user
           + threshold + occurrence_date) la query no devuelve nada
           nuevo. rowCount = 0 → ya estaba notificado. Los cumpleaños no
           tienen fila en `recordatorios`: van con el id del amigo en
           negativo (los de recordatorios son AUTO_INCREMENT, positivos). */
        $notifId = isset($r['amigo_id']) ? -(int)$r['amigo_id'] : (int)$r['id'];
        $stIns = $pdo->prepare("
            INSERT IGNORE INTO reminder_notifs_sent
                (recordatorio_id, user_id, threshold, occurrence_date)
            VALUES (?, ?, ?, ?)
        ");
        $stIns->execute([$notifId, (int)$userId, $diffDays, $next->format('Y-m-d')]);
        if ($stIns->rowCount() === 0) continue;

        $matches[] = [
            'id'          => isset($r['amigo_id']) ? $r['id'] : (int)$r['id'],
            'titulo'      => $r['titulo'],
            'descripcion' => $r['descripcion'] ?? '',
            'fecha'       => $next->format('Y-m-d'),
            'threshold'   => $diffDays,
        ];
    }

    /* Push notification best-effort por cada match nuevo. */
    if (!empty($matches)) {
        require_once dirname(__DIR__) . '/push/send-push.php';
        foreach ($matches as $m) {
            $whenStr = $m['threshold'] === 1 ? 'mañana'
                     : ($m['threshold'] === 2 ? 'en 2 días' : 'en 1 semana');
            sendPushToUser($pdo, (int)$userId, [
                'type'  => 'reminder',
                'title' => '🔔 Recordatorio',
                'body'  => $m['titulo'] . ' ' . $whenStr,
                'tag'   => 'reminder-' . $m['id'] . '-' . $m['threshold'],
                'url'   => pushNotifBaseUrl() . 'mobile.php?pwa=1#notif=reminder',
            ]);
        }
    }

    jsonResponse(['ok' => true, 'reminders' => $matches]);
}

case 'invite-partner': {
    $b      = jsonBody();
    $toUser = isset($b['toUser']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $b['toUser']) : '';
    if (!$toUser) jsonError('Datos incompletos');
    if (!array_key_exists($toUser, $GLOBALS['loginUsers'])) jsonError('Usuario inválido');
    if ($toUser === $userKey) jsonError('No puedes invitarte a ti mismo');

    $fromId = getCurrentUserId($pdo, $userKey);
    $toId   = getCurrentUserId($pdo, $toUser);
    if (!$fromId || !$toId) jsonError('Usuario no encontrado en BD', 500);

    require_once dirname(__DIR__) . '/social-helpers.php';
    if (!isMutualFollow($pdo, (int)$fromId, (int)$toId)) {
        jsonError('Solo puedes invitar a usuarios con seguimiento mutuo');
    }

    try {
        $pdo->prepare("INSERT INTO partner_invites (to_user_id, from_user_id) VALUES (?, ?)")
            ->execute([$toId, $fromId]);
        /* Timbre 'pareja' (assets/php/timbre.php): el móvil pide sus avisos
           al verlo (antes, cada 15 s). */
        require_once dirname(__DIR__) . '/php/timbre.php';
        rt_usuario((int)$toId, 'pareja');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') jsonError('Ya tienes una invitación pendiente');
        throw $e;
    }
    require_once dirname(__DIR__) . '/push/send-push.php';
    $fromLabel = $GLOBALS['loginUsers'][$userKey]['label'] ?? $userKey;
    sendPushToUser($pdo, (int)$toId, buildInvitePushPayload(
        'partner',
        $fromLabel . ' te ha invitado a compartir calendario',
        'Acepta para vincular vuestros calendarios',
        melonPushFoto($fromLabel),
        ['from' => $userKey, 'fromName' => $fromLabel],
    ));
    jsonResponse(['ok' => true]);
}

case 'respond-partner-invite': {
    $b        = jsonBody();
    $inviteId = (int)($b['inviteId'] ?? 0);
    $act      = $b['action']   ?? '';
    $fecha    = $b['fecha']    ?? '';
    if (!$inviteId || !in_array($act, ['accept','reject'])) jsonError('Datos incompletos');

    $uid = getCurrentUserId($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);

    $stmt = $pdo->prepare("SELECT from_user_id FROM partner_invites WHERE id = ? AND to_user_id = ?");
    $stmt->execute([$inviteId, $uid]);
    $fromId = $stmt->fetchColumn();
    if (!$fromId) jsonError('Invitación no encontrada', 404);

    $pdo->prepare("DELETE FROM partner_invites WHERE id = ?")->execute([$inviteId]);

    /* B12: nombre del usuario que respondió (para mostrar en el push). */
    $respLabel = '';
    $st = $pdo->prepare("SELECT username FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    $respLabel = (string)($st->fetchColumn() ?: 'Alguien');

    if ($act === 'reject') {
        /* B12: notifica al inviter que su invitación fue rechazada. */
        require_once dirname(__DIR__) . '/push/send-push.php';
        sendPushToUser($pdo, (int)$fromId, [
            /* type/tag: la app de Android le pone el icono del calendario. */
            'type'  => 'partner',
            'tag'   => 'partner-resp',
            'title' => 'Invitación rechazada',
            'body'  => ucfirst($respLabel) . ' ha rechazado tu invitación al calendario.',
            /* URL portable construida con pushNotifBaseUrl() — usa la
               base correcta del proyecto (localhost: `/scrapbookOnline/`,
               producción: `/`). Sin esto la PWA navegaba a una ruta
               inexistente y se salía del contenedor standalone. */
            'url'   => pushNotifBaseUrl() . 'mobile.php?pwa=1#notif=calendario',
        ]);
        jsonResponse(['ok' => true]);
    }
    if (!$fecha) jsonError('Falta la fecha de inicio');

    $stmt = $pdo->prepare("SELECT id FROM parejas
                           WHERE (usuario1_id = ? AND usuario2_id = ?)
                              OR (usuario1_id = ? AND usuario2_id = ?)");
    $stmt->execute([$fromId, $uid, $uid, $fromId]);
    if ($stmt->fetch()) jsonError('Ya sois pareja');

    $pdo->prepare("INSERT INTO parejas (usuario1_id, usuario2_id, fecha_inicio) VALUES (?, ?, ?)")
        ->execute([(int)$fromId, $uid, $fecha]);

    /* B12: notifica al inviter que la invitación fue ACEPTADA. */
    require_once dirname(__DIR__) . '/push/send-push.php';
    sendPushToUser($pdo, (int)$fromId, [
        'type'  => 'partner',
        'tag'   => 'partner-resp',
        'title' => '💕 ¡Sois pareja!',
        'body'  => ucfirst($respLabel) . ' ha aceptado tu invitación. Abrid el calendario juntos.',
        /* Misma corrección que el reject — URL portable con
           pushNotifBaseUrl() para que funcione en local y producción. */
        'url'   => pushNotifBaseUrl() . 'mobile.php?pwa=1#notif=calendario',
    ]);

    jsonResponse(['ok' => true]);
}

case 'save-momento-foto-url': {
    $b         = jsonBody();
    $momentoId = (int)($b['momento_id'] ?? 0);
    $fotoUrl   = trim($b['foto_url']    ?? '');
    if (!$momentoId || !$fotoUrl) jsonError('Datos incompletos');
    $pdo->prepare("UPDATE momentos SET foto = ? WHERE id = ?")->execute([$fotoUrl, $momentoId]);
    jsonResponse(['ok' => true]);
}

default:
    jsonError('Acción no válida: ' . $action, 400);
}