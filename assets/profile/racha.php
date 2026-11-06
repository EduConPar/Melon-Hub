<?php
/* ──────────────────────────────────────────────────────────────────────
   RACHA DIARIA E INVITACIONES (decidido así)
   ──────────────────────────────────────────────────────────────────────
   Racha: no cuenta solo con entrar. Hay que pulsar el fueguito que sale
   al lado del nombre en tu perfil (perfil-v2.js) una vez al día; el día
   va en hora de Madrid, no la del servidor.
     - Puntos de autismo de cada día: 10, 15, 20… hasta 40 desde el 7.º
       día de racha (RACHA_PUNTOS_DIA).
     - Hitos con la MEJOR racha (no baja al perderla): 100 / 365 / 500 /
       1000 días → +100 / +250 / +500 / +1000, una sola vez cada uno
       (columna hitos = cuántos se han cobrado). Es también la medalla
       "En racha" (bronce / plata / oro / diamante, PF2_MEDALS).
     - Fallar un día la rompe: al pulsar vuelve a 1. Si se tiene un
       "Protector de racha" (tienda, pestaña Perfil, slug protector-racha)
       salva UN día sin pulsar: se gasta al pulsar el día siguiente
       (tienda_compras.consumido_en) y se puede volver a comprar. Si se
       fallan dos o más días seguidos la racha se rompe igual y el
       protector sigue guardado (no ha salvado nada).
     - Mientras no se pulsa, la racha que se ve es la de ayer (o 0 si ya
       está rota): el fueguito sale apagado.

   Invitaciones: enlace.php?invitar=<user_key> deja la cookie
   melon_invita y register-user.php apunta la invitación al crear la
   cuenta (user_invites). Si la persona invitada llega a 7 días de racha
   dentro de sus primeros 14 días, las dos se llevan 500 puntos (se
   comprueba al pulsar su fueguito); si no, caduca. Quien invita se
   entera por la campana del perfil (notifications, tipo invite-reward).
   ────────────────────────────────────────────────────────────────────── */

const RACHA_TZ            = 'Europe/Madrid';
const RACHA_HITOS         = [100, 365, 500, 1000];   /* días de mejor racha */
const RACHA_HITOS_PUNTOS  = [100, 250, 500, 1000];
const RACHA_PROTECTOR     = 'protector-racha';       /* slug en catalogo.json */
const INVITA_PUNTOS       = 500;                     /* a cada una */
const INVITA_RACHA        = 7;                       /* días de racha que tiene que hacer */
const INVITA_PLAZO_DIAS   = 14;                      /* desde que se registra */
const INVITA_COOKIE       = 'melon_invita';

function racha_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_streaks (
            user_id   INT PRIMARY KEY,
            current   INT NOT NULL DEFAULT 0,
            best      INT NOT NULL DEFAULT 0,
            last_day  DATE NULL,
            hitos     TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_invites (
            invitee_id  INT PRIMARY KEY,
            inviter_id  INT NOT NULL,
            estado      VARCHAR(12) NOT NULL DEFAULT 'pendiente',
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resuelto_en TIMESTAMP NULL,
            INDEX idx_inviter (inviter_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE: el resto degrada */ }
    require_once dirname(__DIR__) . '/tienda/catalogo.php';
    tiendaEnsureConsumibles($pdo);
}

/* Hoy (Y-m-d) en hora de Madrid. */
function racha_hoy(): string {
    return (new DateTime('now', new DateTimeZone(RACHA_TZ)))->format('Y-m-d');
}
/* Días de diferencia entre dos fechas Y-m-d (b - a). */
function racha_dias(string $a, string $b): int {
    return (int)round((strtotime($b . ' 12:00:00 UTC') - strtotime($a . ' 12:00:00 UTC')) / 86400);
}
function racha_puntosDia(int $dia): int {
    return 10 + 5 * (min(max($dia, 1), 7) - 1);
}

/* id de la compra del protector que tiene guardado (sin gastar), o 0. */
function racha_protectorId(PDO $pdo, int $uid): int {
    try {
        $st = $pdo->prepare("SELECT c.id FROM tienda_compras c JOIN tienda_items i ON i.id = c.item_id
                              WHERE c.user_id = ? AND i.slug = ? AND c.consumido_en IS NULL ORDER BY c.id LIMIT 1");
        $st->execute([$uid, RACHA_PROTECTOR]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function racha_fila(PDO $pdo, int $uid, bool $lock = false): array {
    $st = $pdo->prepare("SELECT current, best, last_day, hitos FROM user_streaks WHERE user_id = ?" . ($lock ? " FOR UPDATE" : ""));
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: ['current' => 0, 'best' => 0, 'last_day' => null, 'hitos' => 0];
}

/* Lo que se ve: racha viva (la de ayer si aún no se ha pulsado hoy, 0 si
   está rota), mejor racha y si hoy ya se pulsó. $propia añade lo que
   solo ve su dueño (protector, puntos de pulsar hoy). */
function racha_estado(PDO $pdo, int $uid, bool $propia): array {
    racha_ensure($pdo);
    try { $r = racha_fila($pdo, $uid); }
    catch (Throwable $e) { $r = ['current' => 0, 'best' => 0, 'last_day' => null, 'hitos' => 0]; }
    $hoy  = racha_hoy();
    /* El protector cuenta también para quien mira (la racha salvada se ve
       igual), pero solo su dueño sabe que lo tiene. */
    $prot = racha_protectorId($pdo, $uid);
    $gap  = $r['last_day'] ? racha_dias((string)$r['last_day'], $hoy) : null;
    $viva = $gap !== null && ($gap <= 1 || ($gap === 2 && $prot));
    $out = [
        'current' => $viva ? (int)$r['current'] : 0,
        'best'    => (int)$r['best'],
        'today'   => $gap === 0,
    ];
    if ($propia) {
        $out['protector'] = $prot > 0;
        $out['enPeligro'] = $gap === 2 && $prot > 0;   /* ayer no se pulsó: el protector la salvará */
        $out['puntosHoy'] = racha_puntosDia($viva ? (int)$r['current'] + 1 : 1);
    }
    return $out;
}

/* Pulsar el fueguito. Devuelve el estado nuevo y lo que se ha ganado. */
function racha_pulsar(PDO $pdo, int $uid): array {
    racha_ensure($pdo);
    $hoy = racha_hoy();
    $ganado = ['dia' => 0, 'hitos' => [], 'invitacion' => null, 'protector' => false];
    $avisarA = null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO user_streaks (user_id) VALUES (?)")->execute([$uid]);
        $r = racha_fila($pdo, $uid, true);
        $gap = $r['last_day'] ? racha_dias((string)$r['last_day'], $hoy) : null;
        if ($gap === 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Hoy ya has encendido la racha.']; }
        $cur = (int)$r['current'];
        if ($gap === 1) {
            $cur++;
        } elseif ($gap === 2 && ($prot = racha_protectorId($pdo, $uid))) {
            $cur++;
            $pdo->prepare("UPDATE tienda_compras SET consumido_en = NOW() WHERE id = ?")->execute([$prot]);
            $ganado['protector'] = true;
        } else {
            $cur = 1;
        }
        $best  = max((int)$r['best'], $cur);
        $hitos = (int)$r['hitos'];
        $puntos = racha_puntosDia($cur);
        $ganado['dia'] = $puntos;
        while ($hitos < count(RACHA_HITOS) && $best >= RACHA_HITOS[$hitos]) {
            $ganado['hitos'][] = ['dias' => RACHA_HITOS[$hitos], 'puntos' => RACHA_HITOS_PUNTOS[$hitos]];
            $puntos += RACHA_HITOS_PUNTOS[$hitos];
            $hitos++;
        }
        $pdo->prepare("UPDATE user_streaks SET current = ?, best = ?, last_day = ?, hitos = ? WHERE user_id = ?")
            ->execute([$cur, $best, $hoy, $hitos, $uid]);

        /* Invitación pendiente de esta persona: 7 días de racha dentro de
           sus primeros 14 días. */
        $st = $pdo->prepare("SELECT inviter_id, created_at, created_at < DATE_SUB(NOW(), INTERVAL " . INVITA_PLAZO_DIAS . " DAY) AS caducada
                               FROM user_invites WHERE invitee_id = ? AND estado = 'pendiente' FOR UPDATE");
        $st->execute([$uid]);
        if ($inv = $st->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$inv['caducada']) {
                $pdo->prepare("UPDATE user_invites SET estado = 'caducada', resuelto_en = NOW() WHERE invitee_id = ?")->execute([$uid]);
            } elseif ($cur >= INVITA_RACHA) {
                $pdo->prepare("UPDATE user_invites SET estado = 'cobrada', resuelto_en = NOW() WHERE invitee_id = ?")->execute([$uid]);
                $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([INVITA_PUNTOS, (int)$inv['inviter_id']]);
                $puntos += INVITA_PUNTOS;
                $ganado['invitacion'] = INVITA_PUNTOS;
                $avisarA = (int)$inv['inviter_id'];
            }
        }
        $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$puntos, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'No se pudo encender la racha.'];
    }
    if ($avisarA) {
        try {
            $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, payload, is_read) VALUES (?, 'invite-reward', ?, ?, 0)")
                ->execute([$avisarA, $uid, json_encode(['points' => INVITA_PUNTOS])]);
            /* Timbre 'notif' (assets/php/timbre.php): su escritorio lo ve. */
            require_once dirname(__DIR__) . '/php/timbre.php';
            rt_usuario((int)$avisarA, 'notif');
        } catch (Throwable $e) { /* sin tabla de avisos: los puntos ya están dados */ }
    }
    $st = $pdo->prepare("SELECT autismo FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    return ['ok' => true, 'racha' => racha_estado($pdo, $uid, true), 'ganado' => $ganado, 'total' => array_sum(array_column($ganado['hitos'], 'puntos')) + $ganado['dia'] + (int)$ganado['invitacion'], 'autismo' => (int)$st->fetchColumn()];
}

/* ── Invitaciones ─────────────────────────────────────────────────── */

/* La llama register-user.php con la cuenta recién creada: si llegó por
   un enlace de invitación (cookie), se apunta. */
function invita_registrar(PDO $pdo, int $nuevoId): void {
    $key = (string)($_COOKIE[INVITA_COOKIE] ?? '');
    if ($key === '') return;
    setcookie(INVITA_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $key)) return;
    try {
        racha_ensure($pdo);
        $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
        $st->execute([$key]);
        $inviter = (int)$st->fetchColumn();
        if (!$inviter || $inviter === $nuevoId) return;
        $pdo->prepare("INSERT IGNORE INTO user_invites (invitee_id, inviter_id) VALUES (?, ?)")->execute([$nuevoId, $inviter]);
    } catch (Throwable $e) { /* sin invitación: la cuenta ya está creada */ }
}

/* Las invitaciones de alguien, para la ventana "Invitar" del perfil. */
function invita_lista(PDO $pdo, int $uid): array {
    racha_ensure($pdo);
    try {
        $pdo->prepare("UPDATE user_invites SET estado = 'caducada', resuelto_en = NOW()
                        WHERE inviter_id = ? AND estado = 'pendiente' AND created_at < DATE_SUB(NOW(), INTERVAL " . INVITA_PLAZO_DIAS . " DAY)")
            ->execute([$uid]);
        $st = $pdo->prepare("SELECT i.invitee_id, i.estado, UNIX_TIMESTAMP(i.created_at) AS ts,
                                    UNIX_TIMESTAMP(DATE_ADD(i.created_at, INTERVAL " . INVITA_PLAZO_DIAS . " DAY)) AS fin,
                                    u.user_key, u.username, u.label
                               FROM user_invites i JOIN usuarios u ON u.id = i.invitee_id
                              WHERE i.inviter_id = ? ORDER BY i.created_at DESC");
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $r) {
        $label = (string)($r['label'] ?: $r['username'] ?: $r['user_key']);
        $img = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        $out[] = [
            'key'    => $r['user_key'],
            'label'  => $label,
            'image'  => $img,
            'estado' => $r['estado'],
            'racha'  => $r['estado'] === 'pendiente' ? min(INVITA_RACHA, racha_estado($pdo, (int)$r['invitee_id'], false)['current']) : null,
            'quedan' => max(0, (int)ceil(((int)$r['fin'] - time()) / 86400)),
        ];
    }
    return ['invites' => $out, 'puntos' => INVITA_PUNTOS, 'racha' => INVITA_RACHA, 'plazo' => INVITA_PLAZO_DIAS];
}
