<?php
/* ──────────────────────────────────────────────────────────────────────
   TV: archivos del timbre y emparejamiento (tv.php, tv-api.php, mobile.php)
   ──────────────────────────────────────────────────────────────────────
   La TV (tv.php, en su navegador) suena y lleva la cola; el móvil es el
   mando. Se hablan con archivos estáticos de rt/tv/ (assets/php/timbre.php):
   leerlos no gasta PHP ni base de datos (LiteSpeed los sirve tal cual), y
   solo se llama a PHP para escribir (tv-api.php): una orden del móvil, o
   la TV contando cómo va.

     rt/tv/<código>-c.html  órdenes del móvil para la TV: las últimas 20,
                            numeradas ({"cmds":[{"n":7,"c":"next"},…]}).
     rt/tv/<código>-q.html  la cola que el móvil le ha mandado ("load").
     rt/tv/<código>-s.html  cómo va la TV (canción, posición, si suena…),
                            para el móvil. "t" = hora del servidor (ms).
     rt/tv/p-<hash>.html    emparejamiento de una TV aún sin sesión.

   Son HTML con el JSON dentro de <pre id="d">: los navegadores de TVs
   viejas solo los leen bien con un <iframe> (XHR deja de ir tras la
   primera petición en algunas), y un .json en un iframe lo descargan.
   El código de usuario es un HMAC del id, como los del timbre.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/php/timbre.php';
require_once dirname(__DIR__) . '/php/esquema.php';

function tv_codigo(int $uid): string {
    return substr(hash_hmac('sha256', 'tv' . $uid, 'melon-rt|' . DB_NAME . '|' . DB_PASS), 0, 20);
}
function tv_urlOrdenes(int $uid): string { return 'rt/tv/' . tv_codigo($uid) . '-c.html'; }
function tv_urlCola(int $uid): string    { return 'rt/tv/' . tv_codigo($uid) . '-q.html'; }
function tv_urlEstado(int $uid): string  { return 'rt/tv/' . tv_codigo($uid) . '-s.html'; }
function tv_urlPar(string $token): string {
    return 'rt/tv/p-' . substr(hash_hmac('sha256', 'par' . $token, 'melon-rt|' . DB_NAME . '|' . DB_PASS), 0, 20) . '.html';
}

/* Escritura atómica (temporal + rename, como rt_escribir). */
function tv_escribir(string $rel, array $datos): void {
    $f = dirname(__DIR__, 2) . '/' . $rel;
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $json = json_encode((object)$datos, JSON_UNESCAPED_UNICODE);
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><pre id="d">'
          . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
    $tmp = $dir . '/.' . basename($f) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $html) === false) return;
    if (!@rename($tmp, $f)) @unlink($tmp);
}

function tv_leer(string $rel): array {
    $html = (string)@file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    if (!preg_match('~<pre id="d">(.*)</pre>~s', $html, $m)) return [];
    $d = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    return is_array($d) ? $d : [];
}

/* Hora del servidor en ms: la de los estados ("t") y la que reciben las
   páginas para calcular por dónde va la canción. */
function tv_ahora(): int { return (int)round(microtime(true) * 1000); }

function tv_esquema(PDO $pdo): void {
    melon_esquema($pdo, 'tv-emparejar', __FILE__, function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tv_pairs (
                token      CHAR(24) PRIMARY KEY,
                code       CHAR(6) NOT NULL,
                user_key   VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_code (code),
                INDEX idx_created (created_at)
            ) DEFAULT CHARSET=utf8mb4
        ");
    }, true);
}

/* Cuánto vale un QR / código: la página de la TV se recarga antes para
   sacar uno nuevo. */
const TV_PAR_MINUTOS = 10;
