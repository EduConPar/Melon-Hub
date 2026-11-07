<?php
/* ──────────────────────────────────────────────────────────────────────
   PRESENCIA (quién está en línea, ausente, en "no molestar" y qué suena)
   ──────────────────────────────────────────────────────────────────────
   Es la misma para todos (no depende de quién la pida), así que además de
   la acción `presence` de assets/profile/api.php se deja escrita en un
   archivo estático del timbre (rt_urlPresencia, assets/php/timbre.php):
   los perfiles (escritorio y móvil) la leen de ahí cada 20 s sin PHP ni
   BD. Antes cada escritorio llamaba a la API cada 20 s; con esto, en
   reposo solo queda el heartbeat (ver .claude/notas/rendimiento.md).
   La reescriben el heartbeat (como mucho cada 10 s: con mucha gente
   habría varias por segundo), y al momento quien cambia su "no molestar"
   o la canción que suena. Lleva `ahora` (cuándo se calculó): si la ve
   vieja, la ventana pregunta a la API como antes.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/php/timbre.php';

function pf_presencia(PDO $pdo): array {
    /* Devuelve los user_keys ONLINE (heartbeat en los últimos 60s),
       un mapa de "última vez visto" para TODOS los que tienen row en
       user_presence, y la lista de user_keys en "no molestar" (DND).
       DND = preferencia `mute_messages` activa → indicador rojo en el
       UI en lugar del verde habitual. La app de chat también la usa
       para silenciar el ping de mensajes entrantes en el receptor. */
    /* Tolerante a BDs sin columna `state` (migración pendiente).
       Devolvemos el flag `isOnlineRecent` (heartbeat < 60s) y el flag
       `isAwayWindow` (heartbeat < 3 min) por separado. Los usuarios en
       state='away' cuentan como visibles si su último ping está dentro
       de la ventana extendida — porque navegadores móviles throttlean
       setInterval cuando la pantalla está bloqueada y un ping puede
       llegar tarde sin que el usuario haya cerrado realmente la app. */
    try {
        $st = $pdo->query("
            SELECT u.user_key,
                   UNIX_TIMESTAMP(p.last_at) AS lastAt,
                   (p.last_at > DATE_SUB(NOW(), INTERVAL 60 SECOND))  AS isOnlineRecent,
                   (p.last_at > DATE_SUB(NOW(), INTERVAL 180 SECOND)) AS isAwayWindow,
                   p.state AS state
              FROM user_presence p
              JOIN usuarios u ON u.id = p.user_id
        ");
    } catch (Throwable $_) {
        $st = $pdo->query("
            SELECT u.user_key,
                   UNIX_TIMESTAMP(p.last_at) AS lastAt,
                   (p.last_at > DATE_SUB(NOW(), INTERVAL 60 SECOND))  AS isOnlineRecent,
                   (p.last_at > DATE_SUB(NOW(), INTERVAL 180 SECOND)) AS isAwayWindow,
                   'online' AS state
              FROM user_presence p
              JOIN usuarios u ON u.id = p.user_id
        ");
    }
    $online   = [];
    $away     = [];
    $lastSeen = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $state    = $r['state'] ?? '';
        $isAway   = ($state === 'away' && (int)$r['isAwayWindow']);
        $isOnline = (int)$r['isOnlineRecent'] || $isAway;
        if ($isOnline) {
            $online[] = $r['user_key'];
            if ($isAway) $away[] = $r['user_key'];
        }
        $lastSeen[$r['user_key']] = (int)$r['lastAt'];
    }
    /* DND set: user_keys con `mute_messages = true` en user_settings.
       El endpoint `notif-settings` (POST) guarda con json_encode(bool)
       → el valor SQL es la string literal `true` SIN comillas (es JSON
       de un boolean, no de un string). Por eso comparamos con `'true'`,
       NO con `'\"true\"'` (que sería el JSON de un STRING).
       try/catch para no romper si la tabla user_settings no existe. */
    $dnd = [];
    try {
        $st2 = $pdo->query("
            SELECT u.user_key
              FROM user_settings s
              JOIN usuarios u ON u.id = s.user_id
             WHERE s.key_name = 'mute_messages' AND s.value = 'true'
        ");
        foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $k) $dnd[] = $k;
    } catch (Throwable $_) {}
    /* Now-playing: mapa user_key → {title, artist} de los que están
       reproduciendo música en estos momentos. La tabla `now_playing`
       la mantiene el reproductor (save-now-playing). Solo incluimos
       updates de los últimos 90s para no mostrar canciones obsoletas
       si el usuario cerró el reproductor sin pausar. */
    $nowPlaying = [];
    try {
        $stN = $pdo->query("
            SELECT u.user_key, np.track_json
              FROM now_playing np
              JOIN usuarios u ON u.id = np.user_id
             WHERE np.is_playing = 1
               AND np.updated_at > DATE_SUB(NOW(), INTERVAL 90 SECOND)
        ");
        foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tr = json_decode((string)$r['track_json'], true);
            if (!is_array($tr)) continue;
            $title  = trim((string)($tr['title']  ?? ''));
            $artist = trim((string)($tr['artist'] ?? ''));
            if ($title === '' && $artist === '') continue;
            $nowPlaying[$r['user_key']] = ['title' => $title, 'artist' => $artist];
        }
    } catch (Throwable $_) { /* tabla puede no existir */ }


    return [
        'ok'         => true,
        'online'     => $online,
        'away'       => $away,
        'lastSeen'   => $lastSeen,
        'dnd'        => $dnd,
        'nowPlaying' => $nowPlaying,
    ];
}

/* Reescribe el archivo de la presencia si tiene más de 10 s (o siempre,
   con $ya). Si otra petición la está escribiendo en ese momento, no
   espera: ya queda escrita por ella. */
function pf_presenciaArchivo(PDO $pdo, bool $ya = false): void {
    $f = dirname(__DIR__, 2) . '/' . rt_urlPresencia();
    if (!$ya && @filemtime($f) > time() - 10) return;
    if (!is_dir(rt_dir()) && !@mkdir(rt_dir(), 0775, true)) return;
    $cerrojo = @fopen(rt_dir() . '/.presencia.cerrojo', 'c');
    if (!$cerrojo) return;
    if (!flock($cerrojo, LOCK_EX | LOCK_NB)) { fclose($cerrojo); return; }
    try {
        $d = pf_presencia($pdo);
        $d['ahora'] = time();
        rt_escribir(rt_urlPresencia(), $d);
    } catch (Throwable $e) {
        error_log('[presencia] ' . $e->getMessage());
    } finally {
        flock($cerrojo, LOCK_UN);
        fclose($cerrojo);
    }
}
