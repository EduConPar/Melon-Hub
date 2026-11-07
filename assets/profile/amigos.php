<?php
/* ──────────────────────────────────────────────────────────────────────
   AMIGOS ESTILO STEAM (decidido así)
   ──────────────────────────────────────────────────────────────────────
   Antes había "seguir" y un amigo era quien te seguía y le seguías tú.
   Ahora hay SOLICITUDES de amistad: se envía, la otra persona la acepta
   o la rechaza, y quien la envió puede cancelarla.
     - La amistad se sigue guardando como DOS filas en `follows` (una en
       cada sentido): así todo lo que ya pregunta por "amigos mutuos"
       (isMutualFollow / mutualFollowerIds: regalos, colaborar, chat,
       cumpleaños, OCs…) sigue funcionando sin tocarlo.
     - Las solicitudes pendientes van en `friend_requests` (se borran al
       aceptar, rechazar o cancelar).
     - Migración única (decidido así): los "seguir" de un solo lado que
       había se BORRAN; solo quedan como amigos los mutuos. Se hace una
       vez (marca `amigos_migracion_v1` en app_cache) y no tiene vuelta.
     - Notificaciones: friend-request (con Aceptar / Rechazar) y
       friend-accept.
     - Avisos (decidido así): además de la campana, el haro del
       escritorio (desktop-base.php, pollAmigos) y el aviso emergente del
       móvil (mobile.php, mNotifSystem ← assets/notifications/pending.php)
       sacan "X quiere ser tu amigo" con Rechazar / Aceptar mientras la
       solicitud siga pendiente, y "X ha aceptado tu solicitud" una sola
       vez (tabla amigos_avisos, `entregado`). Suenan por el timbre
       (tema 'amigos' en el archivo de cada usuario).
   La página está en el Perfil (sección Social → Amigos, apps/perfil.php).
   ────────────────────────────────────────────────────────────────────── */

/* Tablas y migración única: una vez por versión de este archivo
   (melon_esquema, assets/php/esquema.php), no en cada petición. */
function amigos_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'amigos', __FILE__, function (PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS friend_requests (
            from_id    INT NOT NULL,
            to_id      INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (from_id, to_id),
            INDEX idx_to (to_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS amigos_avisos (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            user_id   INT NOT NULL,
            de_id     INT NOT NULL,
            creado    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            entregado TINYINT NOT NULL DEFAULT 0,
            INDEX idx_user (user_id, entregado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_cache (
            cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,
            cache_value LONGTEXT     NOT NULL,
            expires_at  INT          NULL,
            updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* Migración única: se queda con la marca quien consiga insertarla
           (si dos peticiones llegan a la vez, solo una borra). */
        $st = $pdo->prepare("INSERT IGNORE INTO app_cache (cache_key, cache_value) VALUES ('amigos_migracion_v1', ?)");
        $st->execute([date('c')]);
        if ($st->rowCount() === 1) {
            $pdo->exec("DELETE f FROM follows f
                          LEFT JOIN follows r ON r.follower_id = f.followee_id AND r.followee_id = f.follower_id
                         WHERE r.follower_id IS NULL");
        }
    });
}

function amigos_uid(PDO $pdo, string $key): int {
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $st->execute([$key]);
    return (int)$st->fetchColumn();
}

/* Relación de $uid con $tid: amigos | enviada (yo le pedí) | recibida
   (me pidió) | ninguna. */
function amigos_estado(PDO $pdo, int $uid, int $tid): string {
    if ($uid === $tid) return 'yo';
    $st = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE (follower_id = ? AND followee_id = ?) OR (follower_id = ? AND followee_id = ?)");
    $st->execute([$uid, $tid, $tid, $uid]);
    if ((int)$st->fetchColumn() >= 2) return 'amigos';
    $st = $pdo->prepare("SELECT from_id FROM friend_requests WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)");
    $st->execute([$uid, $tid, $tid, $uid]);
    $from = $st->fetchColumn();
    if ($from === false) return 'ninguna';
    return (int)$from === $uid ? 'enviada' : 'recibida';
}

function amigos_notif(PDO $pdo, int $to, string $tipo, int $from): void {
    if (function_exists('pf_addNotif')) pf_addNotif($pdo, $to, $tipo, $from);
}
/* Que su haro / aviso del móvil vuelva a mirar (timbre). */
function amigos_timbre(int $uid): void {
    if (function_exists('rt_usuario')) rt_usuario($uid, 'amigos');
}
function amigos_borrarNotif(PDO $pdo, int $to, string $tipo, int $from): void {
    if (function_exists('pf_deleteNotifsMatching')) pf_deleteNotifsMatching($pdo, $to, $tipo, $from);
}

/* Enviar solicitud. Si la otra persona ya me la había mandado, se acepta. */
function amigos_enviar(PDO $pdo, int $uid, int $tid): string {
    $e = amigos_estado($pdo, $uid, $tid);
    if ($e === 'yo') throw new InvalidArgumentException('No puedes agregarte a ti');
    if ($e === 'amigos') return 'amigos';
    if ($e === 'recibida') return amigos_aceptar($pdo, $uid, $tid);
    if ($e === 'ninguna') {
        $pdo->prepare("INSERT IGNORE INTO friend_requests (from_id, to_id) VALUES (?, ?)")->execute([$uid, $tid]);
        amigos_notif($pdo, $tid, 'friend-request', $uid);
        amigos_timbre($tid);
    }
    return 'enviada';
}

/* Aceptar la solicitud que me mandó $fromId. */
function amigos_aceptar(PDO $pdo, int $uid, int $fromId): string {
    if (amigos_estado($pdo, $uid, $fromId) !== 'recibida') throw new InvalidArgumentException('No tienes ninguna solicitud de esa persona');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM friend_requests WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)")
            ->execute([$fromId, $uid, $uid, $fromId]);
        $ins = $pdo->prepare("INSERT IGNORE INTO follows (follower_id, followee_id) VALUES (?, ?)");
        $ins->execute([$uid, $fromId]);
        $ins->execute([$fromId, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    amigos_borrarNotif($pdo, $uid, 'friend-request', $fromId);
    amigos_notif($pdo, $fromId, 'friend-accept', $uid);
    $pdo->prepare("INSERT INTO amigos_avisos (user_id, de_id) VALUES (?, ?)")->execute([$fromId, $uid]);
    amigos_timbre($fromId);
    amigos_timbre($uid);   /* sus otras ventanas (PC / móvil) quitan el aviso */
    return 'amigos';
}

function amigos_rechazar(PDO $pdo, int $uid, int $fromId): string {
    $pdo->prepare("DELETE FROM friend_requests WHERE from_id = ? AND to_id = ?")->execute([$fromId, $uid]);
    amigos_borrarNotif($pdo, $uid, 'friend-request', $fromId);
    amigos_timbre($uid);
    return amigos_estado($pdo, $uid, $fromId);
}

function amigos_cancelar(PDO $pdo, int $uid, int $toId): string {
    $pdo->prepare("DELETE FROM friend_requests WHERE from_id = ? AND to_id = ?")->execute([$uid, $toId]);
    amigos_borrarNotif($pdo, $toId, 'friend-request', $uid);
    amigos_timbre($toId);
    return amigos_estado($pdo, $uid, $toId);
}

function amigos_eliminar(PDO $pdo, int $uid, int $tid): string {
    $pdo->prepare("DELETE FROM follows WHERE (follower_id = ? AND followee_id = ?) OR (follower_id = ? AND followee_id = ?)")
        ->execute([$uid, $tid, $tid, $uid]);
    return amigos_estado($pdo, $uid, $tid);
}

/* Lo que necesita la página: amigos y solicitudes (user_keys). Nombre,
   foto y presencia ya los tiene el Perfil (PROFILE_USERS, presencia). */
function amigos_lista(PDO $pdo, int $uid): array {
    $col = function (string $sql) use ($pdo, $uid) {
        $st = $pdo->prepare($sql);
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    return [
        'amigos'    => $col("SELECT u.user_key FROM follows f1
                               JOIN follows f2 ON f2.follower_id = f1.followee_id AND f2.followee_id = f1.follower_id
                               JOIN usuarios u ON u.id = f1.followee_id
                              WHERE f1.follower_id = ?"),
        'recibidas' => $col("SELECT u.user_key FROM friend_requests r JOIN usuarios u ON u.id = r.from_id
                              WHERE r.to_id = ? ORDER BY r.created_at DESC"),
        'enviadas'  => $col("SELECT u.user_key FROM friend_requests r JOIN usuarios u ON u.id = r.to_id
                              WHERE r.from_id = ? ORDER BY r.created_at DESC"),
    ];
}

/* Buscar por nombre de perfil (label; también vale el usuario). */
function amigos_buscar(PDO $pdo, int $uid, string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 1) return [];
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT id, user_key, label FROM usuarios
                          WHERE id <> ? AND (label LIKE ? OR username LIKE ?)
                          ORDER BY (label LIKE ?) DESC, label ASC LIMIT 20");
    $st->execute([$uid, $like, $like, $q . '%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['key' => $r['user_key'], 'nombre' => (string)($r['label'] ?: $r['user_key']), 'estado' => amigos_estado($pdo, $uid, (int)$r['id'])];
    }
    return $out;
}

/* Para el haro del escritorio y el aviso del móvil: las solicitudes que
   me han mandado (salen mientras sigan pendientes) y las que me han
   aceptado (una sola vez: se marcan como entregadas al pedirlas).
   Cada uno: key, nombre, foto (ruta desde la raíz de la web), ts (s). */
function amigos_avisos(PDO $pdo, int $uid): array {
    $foto = function (string $label): ?string {
        $f = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        return $f !== '' ? preg_replace('#^(\.\./)+#', '', $f) : null;
    };
    $fila = function (array $r) use ($foto): array {
        $nombre = (string)($r['label'] ?: $r['user_key']);
        return ['key' => (string)$r['user_key'], 'nombre' => $nombre, 'foto' => $foto($nombre), 'ts' => (int)$r['ts']];
    };
    $st = $pdo->prepare("SELECT u.user_key, u.label, UNIX_TIMESTAMP(r.created_at) AS ts
                           FROM friend_requests r JOIN usuarios u ON u.id = r.from_id
                          WHERE r.to_id = ? ORDER BY r.created_at");
    $st->execute([$uid]);
    $solicitudes = array_map($fila, $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT a.id, u.user_key, u.label, UNIX_TIMESTAMP(a.creado) AS ts
                           FROM amigos_avisos a JOIN usuarios u ON u.id = a.de_id
                          WHERE a.user_id = ? AND a.entregado = 0 ORDER BY a.id");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $in = implode(',', array_map('intval', array_column($rows, 'id')));
        $pdo->exec("UPDATE amigos_avisos SET entregado = 1 WHERE id IN ($in)");
    }
    $aceptadas = [];
    foreach ($rows as $r) $aceptadas[] = ['id' => (int)$r['id']] + $fila($r);
    return ['solicitudes' => $solicitudes, 'aceptadas' => $aceptadas];
}
