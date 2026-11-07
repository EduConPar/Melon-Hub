<?php
/* ──────────────────────────────────────────────────────────────────────
   TIMBRE: avisos en archivos estáticos (rt/)
   ──────────────────────────────────────────────────────────────────────
   Para que lo que se pregunta cada pocos segundos (¿ha movido el rival?,
   ¿me ha llegado un reto?) no gaste PHP ni base de datos. Cuando cambia
   algo, PHP reescribe un JSON pequeño en rt/; las ventanas lo miran cada
   pocos segundos y LiteSpeed lo sirve tal cual, casi siempre con un
   "304, no ha cambiado", sin ocupar un proceso PHP ni una conexión (los
   dos topes que tumbaron la web en el torneo del 6/11; ver
   .claude/notas/rendimiento.md). Solo cuando cambia, la ventana llama a
   su API como siempre.

   Archivos:
     rt/u/<código>.json  uno por usuario, con un contador por tema que
                         sube con cada cambio: {"ajz": 3, "aviso": 7}.
                         ajz = algo del menú del ajedrez (retos, partidas,
                         torneos en los que está); aviso = algo para el
                         aviso del haro (reto nuevo, partida que le
                         espera, torneo cancelado o al que se apunta).
                         El código es un HMAC del id (rt_codigo): sin él
                         no se adivina el de otro. Solo lleva contadores.
     rt/p/<id>.json      una partida de ajedrez: {"v", "n", "e"} (versión,
                         jugadas y estado).
     rt/t/<id>.json      un torneo: {"v"}.
     rt/ajz.json         la lista de torneos: {"v"}.

   Se escriben DESPUÉS de confirmar la transacción (ajz_tx llama a
   rt_vaciar) o al acabar la petición: si la ventana viera el archivo
   nuevo y preguntara antes de que la BD tuviera el cambio, se quedaría
   con lo viejo y no volvería a preguntar. Si la transacción se deshace,
   rt_descartar.
   Escritura atómica (temporal + rename): nunca se sirve medio archivo.
   rt/ está en .gitignore salvo su .htaccess (sin caché, sin PHP).
   ────────────────────────────────────────────────────────────────────── */

function rt_dir(): string { return dirname(__DIR__, 2) . '/rt'; }

/* Código del archivo de un usuario. La clave sale de la contraseña de la
   BD (nunca sale del servidor): no hace falta guardar otro secreto. */
function rt_codigo(int $uid): string {
    return substr(hash_hmac('sha256', 'u' . $uid, 'melon-rt|' . DB_NAME . '|' . DB_PASS), 0, 20);
}
/* Ruta desde la raíz de la web (las páginas le ponen delante lo que
   necesiten: '' en el escritorio, '../' en las apps de apps/). Las de
   partida y torneo las arma también assets/juegos/ajedrez.js. */
function rt_urlUsuario(int $uid): string { return 'rt/u/' . rt_codigo($uid) . '.json'; }
function rt_urlPartida(int $id): string  { return 'rt/p/' . $id . '.json'; }
function rt_urlTorneo(int $id): string   { return 'rt/t/' . $id . '.json'; }
/* Sesión de Escuchar juntos (assets/listen/api.php): lo que suena, para
   los invitados; con código para que no se lean las de otros. */
function rt_urlSesion(int $id): string {
    return 'rt/s/' . $id . '-' . substr(hash_hmac('sha256', 's' . $id, 'melon-rt|' . DB_NAME . '|' . DB_PASS), 0, 12) . '.json';
}
/* La presencia (assets/profile/presencia.php): una para todos, con el
   nombre secreto para que no la lea quien no ha entrado (es lo mismo que
   devuelve la API a cualquiera con sesión). */
function rt_urlPresencia(): string {
    return 'rt/presencia-' . substr(hash_hmac('sha256', 'presencia', 'melon-rt|' . DB_NAME . '|' . DB_PASS), 0, 20) . '.json';
}

/* Lo pendiente de esta petición: se junta (varios cambios del mismo
   usuario o partida = una escritura) y se escribe en rt_vaciar. */
$GLOBALS['_rt'] = ['u' => [], 'p' => [], 't' => [], 'g' => []];
register_shutdown_function('rt_vaciar');

function rt_usuario(int $uid, string ...$temas): void {
    if ($uid <= 0) return;
    foreach ($temas as $t) $GLOBALS['_rt']['u'][$uid][$t] = true;
    if (!$temas) $GLOBALS['_rt']['u'][$uid] = $GLOBALS['_rt']['u'][$uid] ?? [];
}
function rt_partida(int $id, int $n, string $estado): void { $GLOBALS['_rt']['p'][$id] = ['n' => $n, 'e' => $estado]; }
function rt_torneo(int $id): void {
    $GLOBALS['_rt']['t'][$id] = true;
    $GLOBALS['_rt']['g']['ajz'] = true;   /* cambia también la lista de torneos */
}
function rt_listaTorneos(): void { $GLOBALS['_rt']['g']['ajz'] = true; }
/* Ha cambiado su saldo de puntos: la tienda abierta lo vuelve a pedir. */
function rt_saldo(int $uid): void { rt_usuario($uid, 'saldo'); }

function rt_existe(string $rel): bool { return is_file(dirname(__DIR__, 2) . '/' . $rel); }

/* Que el archivo del usuario exista ya: la ventana lo mira desde el
   principio y, si no está (404), vuelve a preguntar a PHP cada poco. */
function rt_asegurarUsuario(int $uid): void {
    if ($uid > 0 && !is_file(rt_dir() . '/' . rt_urlUsuario($uid))) rt_usuario($uid);
}

function rt_descartar(): void { $GLOBALS['_rt'] = ['u' => [], 'p' => [], 't' => [], 'g' => []]; }

function rt_vaciar(): void {
    $q = $GLOBALS['_rt'] ?? null;
    if (!$q || (!$q['u'] && !$q['p'] && !$q['t'] && !$q['g'])) return;
    rt_descartar();
    try {
        foreach ($q['p'] as $id => $x) rt_escribir(rt_urlPartida((int)$id), ['v' => rt_version(), 'n' => $x['n'], 'e' => $x['e']]);
        foreach ($q['t'] as $id => $_) rt_escribir(rt_urlTorneo((int)$id), ['v' => rt_version()]);
        foreach ($q['g'] as $nombre => $_) rt_escribir('rt/' . $nombre . '.json', ['v' => rt_version()]);
        if ($q['u']) rt_sumarUsuarios($q['u']);
    } catch (Throwable $e) {
        error_log('[timbre] ' . $e->getMessage());
    }
}

/* Distinta en cada escritura (las ventanas solo miran si ha cambiado). */
function rt_version(): string {
    return base_convert((string)(int)floor(microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(2));
}

/* $rel desde la raíz de la web ('rt/…'). */
function rt_escribir(string $rel, array $datos): void {
    $f = dirname(__DIR__, 2) . '/' . $rel;
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $tmp = $dir . '/.' . basename($f) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode((object)$datos)) === false) return;
    if (!@rename($tmp, $f)) @unlink($tmp);
}

/* Los archivos de usuario se leen y se reescriben (varios temas en el
   mismo archivo): con un cerrojo para que dos peticiones a la vez no se
   pisen y una pierda su cambio. */
function rt_sumarUsuarios(array $porUsuario): void {
    $dir = rt_dir() . '/u';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $cerrojo = @fopen($dir . '/.cerrojo', 'c');
    if ($cerrojo) flock($cerrojo, LOCK_EX);
    try {
        foreach ($porUsuario as $uid => $temas) {
            $rel = rt_urlUsuario((int)$uid);
            $d = json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/' . $rel), true);
            if (!is_array($d)) $d = [];
            if (!$temas && $d) continue;   /* solo había que crearlo y ya existe */
            foreach ($temas as $t => $_) $d[$t] = (int)($d[$t] ?? 0) + 1;
            rt_escribir($rel, $d);
        }
    } finally {
        if ($cerrojo) { flock($cerrojo, LOCK_UN); fclose($cerrojo); }
    }
}

/* Partidas acabadas hace días: sus archivos ya no los mira nadie. Se
   llama de vez en cuando (ajz_tick). */
function rt_limpiarPartidas(int $dias = 3): void {
    $limite = time() - $dias * 86400;
    foreach (glob(rt_dir() . '/p/*.json') ?: [] as $f) {
        if (@filemtime($f) < $limite) @unlink($f);
    }
}
