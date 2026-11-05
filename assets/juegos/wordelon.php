<?php
/* ──────────────────────────────────────────────────────────────────────
   WORDELON (app Juegos; decidido así)
   ──────────────────────────────────────────────────────────────────────
   Palabra del día: la misma para todos, 5 letras en español, 6 intentos.
   Pistas por letra: ok (en su sitio), cerca (está en otra posición), no.
     - La ñ es una letra más; las tildes y la diéresis no cuentan
       ("arbol" vale por "árbol"). Todo se compara normalizado
       (wordelon_norm).
     - El día va en hora de Madrid, como la racha.
     - La palabra NO se calcula a partir de la fecha: el repo es público
       y cualquiera podría sacarla. Se elige al azar la primera vez que
       alguien abre el juego ese día y se guarda en wordelon_dias (sin
       repetir las de los últimos WORDELON_NO_REPETIR días). Tampoco
       llega al navegador hasta que la partida termina.
     - soluciones.txt = palabras que pueden tocar (comunes, sin nombres
       propios ni tacos, con tildes para enseñarla bien al final);
       validas.txt = todo lo que se acepta como intento (incluye plurales
       y formas verbales), ya normalizado.
     - Acertar da puntos de autismo según el intento (WORDELON_PUNTOS),
       una sola vez al día porque solo hay una partida por día.
     - El bot de Discord tiene UN mensaje por día en WORDELON_CANAL; se
       edita (PATCH) con cada partida que acaba. Lleva una imagen con una
       tarjeta por jugador (wordelon-img.php) y un texto corto; solo la
       cuadrícula de colores, sin letras, para no destripar la palabra.
   ────────────────────────────────────────────────────────────────────── */

const WORDELON_NOMBRE      = 'Wordelon';
const WORDELON_TZ          = 'Europe/Madrid';
const WORDELON_INICIO      = '2026-11-05';            /* día del Wordelon nº 1 */
const WORDELON_LARGO       = 5;
const WORDELON_INTENTOS    = 6;
const WORDELON_PUNTOS      = [60, 50, 40, 30, 20, 10]; /* según el intento en que se acierta */
const WORDELON_NO_REPETIR  = 365;                      /* días sin repetir palabra */
const WORDELON_CANAL       = '1567977480667529277';
const WORDELON_WEB         = 'https://melonhub.es';

function wordelon_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wordelon_dias (
            dia                DATE PRIMARY KEY,
            palabra            VARCHAR(16) NOT NULL,
            discord_message_id VARCHAR(32) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wordelon_partidas (
            user_id    INT NOT NULL,
            dia        DATE NOT NULL,
            intentos   TEXT NOT NULL,
            terminado  TINYINT NOT NULL DEFAULT 0,
            acertado   TINYINT NOT NULL DEFAULT 0,
            puntos     INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, dia),
            INDEX idx_dia (dia, terminado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE: fallará al usarlas */ }
}

function wordelon_hoy(): string {
    return (new DateTime('now', new DateTimeZone(WORDELON_TZ)))->format('Y-m-d');
}

/* Número del Wordelon de ese día (el 1 es WORDELON_INICIO). */
function wordelon_numero(string $dia): int {
    $d = (int)round((strtotime($dia . ' 12:00:00 UTC') - strtotime(WORDELON_INICIO . ' 12:00:00 UTC')) / 86400);
    return max(1, $d + 1);
}

/* Minúsculas, sin tildes ni diéresis, con la ñ. */
function wordelon_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
    ]);
}

function wordelon_esPalabra(string $norm): bool {
    return (bool)preg_match('/^[a-zñ]{' . WORDELON_LARGO . '}$/u', $norm);
}

function wordelon_lista(string $archivo): array {
    $ruta = __DIR__ . '/wordelon/' . $archivo;
    $l = @file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($l) ? array_map('trim', $l) : [];
}

function wordelon_esValida(string $norm): bool {
    static $set = null;
    if ($set === null) {
        $set = array_flip(wordelon_lista('validas.txt'));
        /* Las soluciones siempre valen como intento. */
        foreach (wordelon_lista('soluciones.txt') as $w) $set[wordelon_norm($w)] = true;
    }
    return isset($set[$norm]);
}

/* Palabra del día (con tildes, tal cual en soluciones.txt). La primera
   petición del día la elige; INSERT IGNORE por si dos llegan a la vez:
   gana la que entra primero y las dos leen la misma. */
function wordelon_palabra(PDO $pdo, string $dia): string {
    $st = $pdo->prepare("SELECT palabra FROM wordelon_dias WHERE dia = ?");
    $st->execute([$dia]);
    $p = $st->fetchColumn();
    if ($p !== false) return (string)$p;

    $todas = wordelon_lista('soluciones.txt');
    if (!$todas) throw new RuntimeException('Sin lista de palabras');
    $st = $pdo->prepare("SELECT palabra FROM wordelon_dias WHERE dia > DATE_SUB(?, INTERVAL " . WORDELON_NO_REPETIR . " DAY)");
    $st->execute([$dia]);
    $usadas = array_flip(array_map('wordelon_norm', $st->fetchAll(PDO::FETCH_COLUMN)));
    $libres = array_values(array_filter($todas, fn($w) => !isset($usadas[wordelon_norm($w)])));
    if (!$libres) $libres = $todas;  /* se acabó la lista: se puede repetir */
    $elegida = $libres[random_int(0, count($libres) - 1)];

    $pdo->prepare("INSERT IGNORE INTO wordelon_dias (dia, palabra) VALUES (?, ?)")->execute([$dia, $elegida]);
    $st = $pdo->prepare("SELECT palabra FROM wordelon_dias WHERE dia = ?");
    $st->execute([$dia]);
    return (string)$st->fetchColumn();
}

/* Pistas de un intento. Con letras repetidas manda lo que hay en la
   solución: primero se marcan las que están en su sitio y luego "cerca"
   solo mientras queden letras de esas sin usar (si la palabra tiene una
   sola A y pones dos, la segunda sale gris). */
function wordelon_evaluar(string $intento, string $solucion): array {
    $a = mb_str_split($intento, 1, 'UTF-8');
    $b = mb_str_split($solucion, 1, 'UTF-8');
    $res = array_fill(0, count($a), 'no');
    $quedan = [];
    foreach ($a as $i => $c) {
        if (($b[$i] ?? null) === $c) $res[$i] = 'ok';
        else $quedan[$b[$i] ?? ''] = ($quedan[$b[$i] ?? ''] ?? 0) + 1;
    }
    foreach ($a as $i => $c) {
        if ($res[$i] === 'ok') continue;
        if (!empty($quedan[$c])) { $res[$i] = 'cerca'; $quedan[$c]--; }
    }
    return $res;
}

function wordelon_partida(PDO $pdo, int $uid, string $dia): ?array {
    $st = $pdo->prepare("SELECT * FROM wordelon_partidas WHERE user_id = ? AND dia = ?");
    $st->execute([$uid, $dia]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/* Lo que ve el navegador. La palabra solo va cuando la partida acaba. */
function wordelon_estado(PDO $pdo, int $uid): array {
    $dia      = wordelon_hoy();
    $palabra  = wordelon_palabra($pdo, $dia);
    $sol      = wordelon_norm($palabra);
    $p        = wordelon_partida($pdo, $uid, $dia);
    $intentos = $p ? (json_decode((string)$p['intentos'], true) ?: []) : [];
    $filas = [];
    foreach ($intentos as $w) $filas[] = ['palabra' => $w, 'pistas' => wordelon_evaluar($w, $sol)];
    $terminado = $p && (int)$p['terminado'] === 1;
    return [
        'nombre'    => WORDELON_NOMBRE,
        'dia'       => $dia,
        'numero'    => wordelon_numero($dia),
        'largo'     => WORDELON_LARGO,
        'maximo'    => WORDELON_INTENTOS,
        'puntosPor' => WORDELON_PUNTOS,
        'filas'     => $filas,
        'terminado' => $terminado,
        'acertado'  => $p && (int)$p['acertado'] === 1,
        'puntos'    => $p ? (int)$p['puntos'] : 0,
        'palabra'   => $terminado ? mb_strtoupper($palabra, 'UTF-8') : null,
    ];
}

/* Un intento. Devuelve [estado, puntos ganados ahora] o lanza
   InvalidArgumentException con el mensaje para el usuario. */
function wordelon_intentar(PDO $pdo, int $uid, string $texto): array {
    $dia = wordelon_hoy();
    $sol = wordelon_norm(wordelon_palabra($pdo, $dia));
    $w   = wordelon_norm($texto);
    if (!wordelon_esPalabra($w))  throw new InvalidArgumentException('Tienen que ser ' . WORDELON_LARGO . ' letras');
    if (!wordelon_esValida($w))   throw new InvalidArgumentException('No está en la lista de palabras');

    $ganados = 0;
    $acaba   = false;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO wordelon_partidas (user_id, dia, intentos) VALUES (?, ?, '[]')")
            ->execute([$uid, $dia]);
        $st = $pdo->prepare("SELECT * FROM wordelon_partidas WHERE user_id = ? AND dia = ? FOR UPDATE");
        $st->execute([$uid, $dia]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$p['terminado'] === 1) throw new InvalidArgumentException('Ya has jugado el ' . WORDELON_NOMBRE . ' de hoy');
        $intentos = json_decode((string)$p['intentos'], true) ?: [];
        if (count($intentos) >= WORDELON_INTENTOS) throw new InvalidArgumentException('No te quedan intentos');

        $intentos[] = $w;
        $acierto = ($w === $sol);
        $acaba   = $acierto || count($intentos) >= WORDELON_INTENTOS;
        if ($acierto) $ganados = WORDELON_PUNTOS[count($intentos) - 1] ?? 0;

        $pdo->prepare("UPDATE wordelon_partidas SET intentos = ?, terminado = ?, acertado = ?, puntos = ? WHERE user_id = ? AND dia = ?")
            ->execute([json_encode($intentos, JSON_UNESCAPED_UNICODE), $acaba ? 1 : 0, $acierto ? 1 : 0, $ganados, $uid, $dia]);
        if ($ganados > 0) {
            $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$ganados, $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    /* Discord después de guardar: si el bot falla, la partida ya cuenta. */
    if ($acaba) {
        try { wordelon_discordResumen($pdo, $dia); }
        catch (Throwable $e) { error_log('[wordelon] discord: ' . $e->getMessage()); }
    }
    return [wordelon_estado($pdo, $uid), $ganados];
}

/* ── Resumen del día en Discord ─────────────────────────────────────── */

function wordelon_cuadricula(array $intentos, string $sol): string {
    $em = ['ok' => '🟩', 'cerca' => '🟨', 'no' => '⬛'];
    $l = [];
    foreach ($intentos as $w) {
        $l[] = implode('', array_map(fn($x) => $em[$x], wordelon_evaluar($w, $sol)));
    }
    return implode("\n", $l);
}

function wordelon_fechaLarga(string $dia): string {
    static $m = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($dia . ' 12:00:00 UTC');
    return (int)gmdate('j', $t) . ' de ' . $m[(int)gmdate('n', $t) - 1] . ' de ' . gmdate('Y', $t);
}

/* Mensaje del día: [embed, png]. Con imagen (wordelon-img.php: una
   tarjeta por jugador con su foto, su interfaz y su tema, por orden de
   llegada) el texto es corto: título, fecha y cuántos han jugado. Si el
   servidor no puede dibujarla (sin GD), va la clasificación en texto. */
/* Los que han terminado ese día, por orden de llegada, listos para
   wimg_render (también los usa la pantalla de Estadísticas de la app).
   Devuelve [jugadores, cuántos han acertado]. */
function wordelon_jugadores(PDO $pdo, string $dia): array {
    $sol = wordelon_norm(wordelon_palabra($pdo, $dia));
    $st = $pdo->prepare("SELECT p.user_id, p.intentos, p.acertado, u.label, u.user_key
                           FROM wordelon_partidas p JOIN usuarios u ON u.id = p.user_id
                          WHERE p.dia = ? AND p.terminado = 1
                          ORDER BY p.updated_at ASC, p.user_id ASC");
    $st->execute([$dia]);
    $jug = [];
    $aciertos = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $int = json_decode((string)$r['intentos'], true) ?: [];
        if ((int)$r['acertado']) $aciertos++;
        $label = (string)($r['label'] ?: $r['user_key']);
        $foto = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        $jug[] = [
            'uid'       => (int)$r['user_id'],
            'label'     => $label,
            'ruta'      => $foto !== '' ? dirname(__DIR__, 2) . '/' . $foto : null,
            'resultado' => ((int)$r['acertado'] ? count($int) : 'X') . '/' . WORDELON_INTENTOS,
            'acertado'  => (int)$r['acertado'],
            'intentos'  => $int,
            'filas'     => array_map(fn($w) => wordelon_evaluar($w, $sol), $int),
        ];
    }
    return [$jug, $aciertos];
}

/* Texto "N personas han jugado · M la han acertado". */
function wordelon_resumenTexto(int $n, int $aciertos): string {
    return $n . ($n === 1 ? ' persona ha jugado' : ' personas han jugado')
         . ' · ' . $aciertos . ($aciertos === 1 ? ' la ha acertado' : ' la han acertado');
}

/* Mensaje del día: [embed, png, n]. Con imagen (wordelon-img.php: una
   tarjeta por jugador con su foto, su interfaz y su tema, por orden de
   llegada) el texto es corto: título, fecha y cuántos han jugado. Si el
   servidor no puede dibujarla (sin GD), va la clasificación en texto. */
function wordelon_embed(PDO $pdo, string $dia): array {
    require_once __DIR__ . '/wordelon-img.php';
    $sol = wordelon_norm(wordelon_palabra($pdo, $dia));
    [$jug, $aciertos] = wordelon_jugadores($pdo, $dia);

    /* Si la imagen falla, el mensaje sale en texto y el motivo queda en
       $GLOBALS['wordelon_img_error'] (lo enseña ?action=wordelon-discord). */
    $png = null;
    $GLOBALS['wordelon_img_error'] = null;
    if (!wimg_disponible()) $GLOBALS['wordelon_img_error'] = 'El PHP del servidor no tiene GD con FreeType';
    try { $png = wimg_render($pdo, $jug, wimg_cfgWordelon(WORDELON_INTENTOS)); }
    catch (Throwable $e) {
        $GLOBALS['wordelon_img_error'] = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        error_log('[wordelon] imagen: ' . $GLOBALS['wordelon_img_error']);
    }

    $n = count($jug);
    $desc = wordelon_fechaLarga($dia) . "\n" . wordelon_resumenTexto($n, $aciertos)
          . "\n**[→ Jugar en Melon Hub](" . WORDELON_WEB . '/)**';
    $embed = [
        'title'       => WORDELON_NOMBRE . ' #' . wordelon_numero($dia),
        'url'         => WORDELON_WEB . '/',
        'description' => $desc,
        'color'       => 0x6AAA64,
        'footer'      => ['text' => 'Se actualiza con cada partida · Melon Hub'],
    ];
    if ($png !== null) {
        $embed['image'] = ['url' => 'attachment://wordelon.png'];
        return [$embed, $png, $n];
    }

    /* Sin imagen: clasificación en texto (aciertos primero, menos
       intentos antes). Discord admite 25 campos: 24 + "y N más". */
    usort($jug, fn($x, $y) => [$y['acertado'], count($x['intentos'])] <=> [$x['acertado'], count($y['intentos'])]);
    $fields = [];
    foreach ($jug as $i => $j) {
        if ($i === 24 && $n > 25) {
            $fields[] = ['name' => '…', 'value' => 'y ' . ($n - 24) . ' más', 'inline' => true];
            break;
        }
        $fields[] = [
            'name'   => mb_substr(($i + 1) . '. ' . $j['label'], 0, 200) . ' · ' . $j['resultado'],
            'value'  => wordelon_cuadricula($j['intentos'], $sol) ?: '—',
            'inline' => true,
        ];
    }
    $embed['fields'] = $fields;
    return [$embed, null, $n];
}

/* Petición a la API de Discord. Con $png va en multipart como adjunto
   "wordelon.png" (el embed lo enseña con attachment://). Al editar,
   `attachments` dice qué adjuntos quedan: solo el nuevo, así el viejo
   se borra. */
function wordelon_discord(string $metodo, string $ruta, array $payload, ?string $png = null): array {
    $token = trim((string)env('DISCORD_BOT_TOKEN', ''));
    if ($token === '') return [0, null, 'sin token'];
    /* DISCORD_API_BASE solo para las pruebas (un Discord falso). */
    $base = rtrim((string)env('DISCORD_API_BASE', 'https://discord.com/api/v10'), '/');
    $headers = ['Authorization: Bot ' . $token, 'User-Agent: MelonHub-Wordelon (https://melonhub.es, 1.0)'];
    $tmp = null;
    if ($png !== null) {
        $payload['attachments'] = [['id' => 0, 'filename' => 'wordelon.png']];
        if (class_exists('CURLStringFile')) {
            $file = new CURLStringFile($png, 'wordelon.png', 'image/png');
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'wdl');
            file_put_contents($tmp, $png);
            $file = new CURLFile($tmp, 'image/png', 'wordelon.png');
        }
        $body = ['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'files[0]' => $file];
    } else {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    if ($tmp) @unlink($tmp);
    return [$code, json_decode((string)$resp, true), $err];
}

/* Publica o edita el mensaje del día. Con un candado de MariaDB para que
   dos partidas que acaban a la vez no publiquen dos mensajes. Si alguien
   borró el mensaje en Discord (PATCH → 404) se publica otro.
   Devuelve lo que pasó (lo enseña la acción wordelon-discord de la API,
   que sirve para ver por qué no sale y para reintentarlo). */
function wordelon_discordResumen(PDO $pdo, string $dia): array {
    if (trim((string)env('DISCORD_BOT_TOKEN', '')) === '') {
        return ['ok' => false, 'error' => 'Falta DISCORD_BOT_TOKEN en el .env del servidor'];
    }
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP sin la extensión curl'];
    $lock = 'wordelon_discord_' . $dia;
    $st = $pdo->prepare("SELECT GET_LOCK(?, 10)");
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) return ['ok' => false, 'error' => 'Otra petición está publicando ahora mismo'];
    try {
        [$embed, $png, $n] = wordelon_embed($pdo, $dia);
        if ($n === 0) return ['ok' => false, 'error' => 'Nadie ha terminado todavía la partida de hoy'];
        $st = $pdo->prepare("SELECT discord_message_id FROM wordelon_dias WHERE dia = ?");
        $st->execute([$dia]);
        $msgId = (string)$st->fetchColumn();
        $canal = '/channels/' . rawurlencode(WORDELON_CANAL) . '/messages';
        if ($msgId !== '') {
            [$code, $j, $err] = wordelon_discord('PATCH', $canal . '/' . rawurlencode($msgId), ['embeds' => [$embed]], $png);
            if ($code !== 404) {
                $r = wordelon_discordResultado('PATCH', $code, $j, $err);
                $r['mensaje'] = $msgId;
                $r['imagen'] = $png !== null;
                if ($png === null) $r['imagenError'] = $GLOBALS['wordelon_img_error'] ?? null;
                return $r;
            }
        }
        [$code, $j, $err] = wordelon_discord('POST', $canal, ['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]], $png);
        $r = wordelon_discordResultado('POST', $code, $j, $err);
        if ($r['ok'] && isset($j['id'])) {
            $pdo->prepare("UPDATE wordelon_dias SET discord_message_id = ? WHERE dia = ?")->execute([(string)$j['id'], $dia]);
            $r['mensaje'] = (string)$j['id'];
        }
        $r['imagen'] = $png !== null;
        if ($png === null) $r['imagenError'] = $GLOBALS['wordelon_img_error'] ?? null;
        return $r;
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
    }
}

/* Traduce la respuesta de Discord a algo que se entienda (y la apunta en
   el log si ha ido mal). */
function wordelon_discordResultado(string $metodo, int $code, $j, string $err): array {
    if ($code >= 200 && $code < 300) return ['ok' => true, 'accion' => $metodo, 'http' => $code];
    $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
    $porque = [
        0   => 'No se pudo conectar con Discord' . ($err !== '' ? ' (' . $err . ')' : ''),
        401 => 'El token del bot no es válido (DISCORD_BOT_TOKEN)',
        403 => 'El bot no tiene permiso en el canal (Ver canal, Enviar mensajes, Insertar enlaces)',
        404 => 'Discord no encuentra el canal ' . WORDELON_CANAL . ' (o el bot no está en ese servidor)',
    ][$code] ?? 'Discord respondió ' . $code;
    error_log('[wordelon] discord ' . $metodo . ' ' . $code . ' ' . $msg . ' ' . $err);
    return ['ok' => false, 'accion' => $metodo, 'http' => $code, 'error' => $porque, 'discord' => $msg];
}
