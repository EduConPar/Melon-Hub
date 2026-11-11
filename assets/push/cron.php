<?php
/* ──────────────────────────────────────────────────────────────────────
   Avisos push diarios (tarea programada de Hostinger, decidido así)
   ──────────────────────────────────────────────────────────────────────
   En hPanel → Avanzado → Tareas Cron, cada 15 minutos, una por sitio
   (el dominio va de argumento: con él se arman las URLs de los avisos):
     php /home/<usuario>/domains/melonhub.es/public_html/assets/push/cron.php melonhub.es
     php /home/<usuario>/domains/melonhubdev.melonhub.es/public_html/assets/push/cron.php melonhubdev.melonhub.es
   Solo por línea de comandos (por web, assets/push/.htaccess lo niega y
   aquí se vuelve a comprobar).

   Hora de Madrid:
     · desde las 10:00 → Wordelon: "ya hay palabra nueva" a quien terminó
       la de ayer y aún no ha empezado la de hoy.
     · desde las 22:00 → racha: a quien tiene la racha viva y hoy aún no
       ha pulsado el fueguito (se apaga a medianoche).
   Cada uno sale una vez al día: la tabla push_diarios guarda qué día se
   ha mandado cada cosa (INSERT IGNORE: si dos ejecuciones se pisan, solo
   una lo manda). Si el cron se para unas horas, al volver manda lo que
   falte del día (el Wordelon solo hasta las 22:00).
   ────────────────────────────────────────────────────────────────────── */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$host = strtolower(trim((string)($argv[1] ?? 'melonhub.es')));
if (!preg_match('/^[a-z0-9.-]+$/', $host)) { fwrite(STDERR, "Dominio no válido\n"); exit(1); }
/* send-push.php arma las URLs con estos dos (pushNotifBaseUrl,
   melonPushUrlAbs) como si fuera una petición a assets/. */
$_SERVER['HTTP_HOST']   = $host;
$_SERVER['SCRIPT_NAME'] = '/assets/push/cron.php';

require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/php/esquema.php';
require_once __DIR__ . '/send-push.php';
require_once dirname(__DIR__) . '/profile/racha.php';

melon_esquema($pdo, 'push-diarios', __FILE__, function (PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_diarios (
        dia  DATE NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        PRIMARY KEY (dia, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

$madrid = new DateTimeZone('Europe/Madrid');
$ahora  = new DateTime('now', $madrid);
$hoy    = $ahora->format('Y-m-d');
$ayer   = (clone $ahora)->modify('-1 day')->format('Y-m-d');
$hora   = (int)$ahora->format('G');

/* true si este es el primero en mandarlo hoy. */
function cron_toca(PDO $pdo, string $dia, string $tipo): bool {
    $st = $pdo->prepare("INSERT IGNORE INTO push_diarios (dia, tipo) VALUES (?, ?)");
    $st->execute([$dia, $tipo]);
    return $st->rowCount() > 0;
}

$base = pushNotifBaseUrl() . 'mobile.php?pwa=1#abrir=';
$enviados = 0;

/* ── Wordelon (10:00–22:00) ── */
if ($hora >= 10 && $hora < 22 && cron_toca($pdo, $hoy, 'wordelon')) {
    $num = max(1, (int)round((strtotime($hoy . ' 12:00:00 UTC') - strtotime('2026-11-05 12:00:00 UTC')) / 86400) + 1);
    try {
        $st = $pdo->prepare("SELECT a.user_id, a.acertado, a.intentos FROM wordelon_partidas a
                              LEFT JOIN wordelon_partidas h ON h.user_id = a.user_id AND h.dia = ?
                              WHERE a.dia = ? AND a.terminado = 1 AND h.user_id IS NULL");
        $st->execute([$hoy, $ayer]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = count(json_decode((string)$r['intentos'], true) ?: []);
            $ayerTxt = (int)$r['acertado'] ? 'Ayer la sacaste en ' . $n . ($n === 1 ? ' intento' : ' intentos') . '. ¿Hoy en menos?'
                                           : 'Ayer se te resistió. ¿Hoy sí?';
            sendPushToUser($pdo, (int)$r['user_id'], [
                'type'  => 'wordelon',
                'title' => '🟩 Wordelon nº ' . $num,
                'body'  => 'Ya tienes la palabra de hoy. ' . $ayerTxt,
                'tag'   => 'wordelon',
                'url'   => $base . 'wordelon',
            ]);
            $enviados++;
        }
    } catch (Throwable $e) { error_log('[cron push] wordelon: ' . $e->getMessage()); }
}

/* ── Racha (22:00–24:00) ── */
if ($hora >= 22 && cron_toca($pdo, $hoy, 'racha')) {
    try {
        racha_ensure($pdo);
        $st = $pdo->prepare("SELECT u.id, u.user_key, s.current, s.last_day FROM user_streaks s
                              JOIN usuarios u ON u.id = s.user_id
                              WHERE s.current > 0 AND s.last_day < ? AND s.last_day >= ?");
        $st->execute([$hoy, (clone $ahora)->modify('-2 day')->format('Y-m-d')]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid  = (int)$r['id'];
            $gap  = racha_dias((string)$r['last_day'], $hoy);
            $prot = racha_protectorId($pdo, $uid) > 0;
            /* Viva: ayer la pulsó (gap 1), o anteayer y el protector salva
               ayer (gap 2). Con gap 2 sin protector ya está rota. */
            if ($gap === 2 && !$prot) continue;
            $dias = (int)$r['current'];
            if ($gap === 2) {
                $body = 'Ayer no la encendiste y tu protector la está salvando: si hoy tampoco, se apaga a medianoche.';
            } elseif ($prot) {
                $body = 'Enciende el fueguito de tu perfil antes de medianoche o mañana se gastará tu protector de racha.';
            } else {
                $body = 'Enciende el fueguito de tu perfil antes de medianoche o se apagará.';
            }
            sendPushToUser($pdo, $uid, [
                'type'  => 'racha',
                'title' => '🔥 Tu racha de ' . $dias . ($dias === 1 ? ' día' : ' días') . ' está en peligro',
                'body'  => $body,
                'tag'   => 'racha',
                'url'   => $base . 'perfil',
            ]);
            $enviados++;
        }
    } catch (Throwable $e) { error_log('[cron push] racha: ' . $e->getMessage()); }
}

echo date('c') . " $host: $enviados avisos\n";
