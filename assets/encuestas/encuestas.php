<?php
/* ──────────────────────────────────────────────────────────────────────
   Encuestas de satisfacción (15/11/2026, decidido así)
   ──────────────────────────────────────────────────────────────────────
   - De vez en cuando a un usuario le toca una encuesta sobre una app al
     azar (cualquiera de ENC_APPS, sin repetir la última). En el PC avisa
     el haro (Rechazar / Responder); en el móvil, un aviso (mToast).
   - Siete preguntas del 1 al 5 (ENC_NOTAS: intuitiva, útil, cuánto la
     usa, bonita, rápida, si es una razón para recomendar Melon Hub y
     satisfacción general) y una abierta opcional: responder da ENC_PUNTOS
     (los mismos con más preguntas, decidido así) y, si la abierta tiene
     al menos ENC_MIN_COMENTARIO letras, ENC_PUNTOS_EXTRA más.
   - Frecuencia: como mucho una cada ENC_DIAS (7) días; pasado ese tiempo,
     cada vez que se abre Melon Hub hay 1 entre ENC_AZAR de que toque
     (~1 por semana). Shadow ban: ENC_DIAS_SHADOW (28) días → ~4 veces
     menos. Bloqueado: ninguna (y las que tuviera sin responder se anulan).
   - Sin responder ni rechazar (se cerró la ventana) sigue pendiente y
     vuelve a salir la próxima vez que se abra Melon Hub.
   - Se ven y se modera (Normal / Shadow ban / Bloquear) en
     apps/devstats.php, pestaña Encuestas.
   Tablas:
     encuestas          una fila por encuesta enviada (estado pendiente /
                        respondida / rechazada / anulada).
     encuestas_usuarios modo de cada usuario (sin fila = normal).
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('enc_esquema')) {

    define('ENC_PUNTOS', 50);
    define('ENC_PUNTOS_EXTRA', 100);
    define('ENC_MIN_COMENTARIO', 15);
    define('ENC_MAX_COMENTARIO', 1000);
    define('ENC_DIAS', 7);
    define('ENC_DIAS_SHADOW', 28);
    define('ENC_AZAR', 4);
    /* Columnas de las notas, en el orden de las preguntas (los textos
       están en assets/js/encuesta.js; los títulos cortos, en devstats). */
    define('ENC_NOTAS', ['intuitiva', 'util', 'uso', 'bonita', 'rapida', 'recomendar', 'general']);

    /* Apps por las que se pregunta: clave (la de uso-apps.js) → nombre e
       icono (assets/img/appIcons/, icon-pack.js lo cambia según el pack). */
    function enc_apps(): array {
        return [
            'musica'       => ['Música',        'musicaIcon.png'],
            'perfil'       => ['Perfil',        'profileIcon.png'],
            'chat'         => ['Chat',          'chatIcon.png'],
            'juegos'       => ['Juegos',        'juegosIcon.png'],
            'tienda'       => ['Tienda',        'tiendaIcon.png'],
            'temas'        => ['Temas',         'temasIcon.png'],
            'galeria'      => ['Galería',       'galeriaIcon.png'],
            'dibujo'       => ['Dibujo',        'drawingIcon.png'],
            'calendario'   => ['Calendario',    'calendarioIcon.png'],
            'melonarchive' => ['MelonArchive',  'melonArchiveIcon.png'],
            'dnd'          => ['Fichas D&D',    'dndIcon.png'],
            'mascota'      => ['Mascota',       'mascotaIcon.png'],
            'companion'    => ['Companion',     'companionIcon.png'],
        ];
    }
    function enc_nombreApp(string $k): string { return enc_apps()[$k][0] ?? $k; }

    function enc_esquema(PDO $pdo): void {
        require_once dirname(__DIR__) . '/php/esquema.php';
        melon_esquema($pdo, 'encuestas', __FILE__, function (PDO $pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS encuestas (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                app         VARCHAR(32) NOT NULL,
                estado      ENUM('pendiente','respondida','rechazada','anulada') NOT NULL DEFAULT 'pendiente',
                creada      DATETIME NOT NULL,
                respondida  DATETIME NULL,
                intuitiva   TINYINT NULL,
                util        TINYINT NULL,
                uso         TINYINT NULL,
                comentario  TEXT NULL,
                puntos      INT NOT NULL DEFAULT 0,
                plataforma  VARCHAR(12) NULL,
                INDEX idx_user (user_id, creada),
                INDEX idx_creada (creada)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            /* Preguntas añadidas el 15/11 (las de antes quedan a NULL). */
            foreach (['bonita', 'rapida', 'recomendar', 'general'] as $c) {
                $pdo->exec("ALTER TABLE encuestas ADD COLUMN IF NOT EXISTS $c TINYINT NULL");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS encuestas_usuarios (
                user_id     INT PRIMARY KEY,
                modo        ENUM('normal','shadow','bloqueado') NOT NULL DEFAULT 'normal',
                cambiado_por INT NULL,
                cambiado    DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }, true);
    }

    function enc_modo(PDO $pdo, int $uid): string {
        $st = $pdo->prepare("SELECT modo FROM encuestas_usuarios WHERE user_id = ?");
        $st->execute([$uid]);
        return (string)($st->fetchColumn() ?: 'normal');
    }

    /* Lo que ve el cliente de una encuesta. */
    function enc_publica(array $e): array {
        $a = enc_apps()[$e['app']] ?? [$e['app'], 'haroIcon.png'];
        return [
            'id' => (int)$e['id'], 'app' => $e['app'], 'nombre' => $a[0],
            'icono' => 'assets/img/appIcons/' . $a[1],
            'puntos' => ENC_PUNTOS, 'extra' => ENC_PUNTOS_EXTRA, 'minComentario' => ENC_MIN_COMENTARIO,
            'maxComentario' => ENC_MAX_COMENTARIO,
        ];
    }

    /* ¿Le toca una encuesta? La pendiente si la hay; si no, quizá una
       nueva (ver la cabecera). Se llama una vez al abrir el escritorio o
       el móvil, no en bucle. */
    function enc_toca(PDO $pdo, int $uid): ?array {
        $modo = enc_modo($pdo, $uid);
        if ($modo === 'bloqueado') return null;

        $st = $pdo->prepare("SELECT * FROM encuestas WHERE user_id = ? AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
        $st->execute([$uid]);
        if ($e = $st->fetch(PDO::FETCH_ASSOC)) return enc_publica($e);

        $dias = $modo === 'shadow' ? ENC_DIAS_SHADOW : ENC_DIAS;
        $st = $pdo->prepare("SELECT app, creada FROM encuestas WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$uid]);
        $ultima = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($ultima && strtotime($ultima['creada']) > time() - $dias * 86400) return null;
        if (random_int(1, ENC_AZAR) !== 1) return null;

        $claves = array_keys(enc_apps());
        if ($ultima && count($claves) > 1) $claves = array_values(array_diff($claves, [$ultima['app']]));
        $app = $claves[random_int(0, count($claves) - 1)];

        /* Dos pestañas a la vez no deben crear dos: se bloquea la fila del
           usuario en encuestas_usuarios y se vuelve a mirar dentro. */
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT IGNORE INTO encuestas_usuarios (user_id) VALUES (?)")->execute([$uid]);
            $pdo->prepare("SELECT modo FROM encuestas_usuarios WHERE user_id = ? FOR UPDATE")->execute([$uid]);
            $st = $pdo->prepare("SELECT COUNT(*) FROM encuestas WHERE user_id = ? AND (estado = 'pendiente' OR creada > NOW() - INTERVAL ? DAY)");
            $st->execute([$uid, $dias]);
            if ((int)$st->fetchColumn() > 0) { $pdo->commit(); return null; }
            $pdo->prepare("INSERT INTO encuestas (user_id, app, creada) VALUES (?, ?, NOW())")->execute([$uid, $app]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return enc_publica(['id' => $id, 'app' => $app]);
    }

    /* Respuesta: $notas = [columna de ENC_NOTAS => 1..5] (ya validadas).
       Devuelve los puntos ganados (null si ya no estaba pendiente:
       respondida en otra pestaña, rechazada o anulada). */
    function enc_responder(PDO $pdo, int $uid, int $id, array $notas, string $comentario, string $plataforma): ?int {
        $comentario = trim($comentario);
        if (mb_strlen($comentario) > ENC_MAX_COMENTARIO) $comentario = mb_substr($comentario, 0, ENC_MAX_COMENTARIO);
        $puntos = ENC_PUNTOS + (mb_strlen($comentario) >= ENC_MIN_COMENTARIO ? ENC_PUNTOS_EXTRA : 0);
        $pdo->beginTransaction();
        try {
            $set = ''; $vals = [];
            foreach (ENC_NOTAS as $c) { $set .= "$c = ?, "; $vals[] = (int)$notas[$c]; }
            $st = $pdo->prepare("UPDATE encuestas SET estado = 'respondida', respondida = NOW(), $set
                                        comentario = ?, puntos = ?, plataforma = ?
                                 WHERE id = ? AND user_id = ? AND estado = 'pendiente'");
            $st->execute(array_merge($vals, [$comentario !== '' ? $comentario : null, $puntos, $plataforma, $id, $uid]));
            if ($st->rowCount() !== 1) { $pdo->rollBack(); return null; }
            $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$puntos, $uid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        require_once dirname(__DIR__) . '/php/timbre.php';
        rt_saldo($uid);
        return $puntos;
    }

    function enc_rechazar(PDO $pdo, int $uid, int $id, string $plataforma): void {
        $pdo->prepare("UPDATE encuestas SET estado = 'rechazada', respondida = NOW(), plataforma = ?
                       WHERE id = ? AND user_id = ? AND estado = 'pendiente'")->execute([$plataforma, $id, $uid]);
    }

    /* Desde devstats. Bloquear anula lo pendiente (no le sale ni la que
       tenía a medias). */
    function enc_ponerModo(PDO $pdo, int $uid, string $modo, int $porUid): void {
        if (!in_array($modo, ['normal', 'shadow', 'bloqueado'], true)) return;
        $pdo->prepare("INSERT INTO encuestas_usuarios (user_id, modo, cambiado_por, cambiado) VALUES (?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE modo = VALUES(modo), cambiado_por = VALUES(cambiado_por), cambiado = NOW()")
            ->execute([$uid, $modo, $porUid ?: null]);
        if ($modo === 'bloqueado') {
            $pdo->prepare("UPDATE encuestas SET estado = 'anulada' WHERE user_id = ? AND estado = 'pendiente'")->execute([$uid]);
        }
    }
}
