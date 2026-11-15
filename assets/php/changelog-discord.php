<?php
/* ──────────────────────────────────────────────────────────────────────
   Changelog → Discord (13/11)
   ──────────────────────────────────────────────────────────────────────
   Al subir a main un changelog.md con una versión nueva (`# Alfa 0.1.7`),
   el bot la publica en Discord con @everyone. Sin pasos a mano.

   - Lo dispara config.php (cualquier petición): solo compara la fecha y
     el tamaño de changelog.md con una marca en uploads/esquema/ (un stat).
     Si cambió, el trabajo va DESPUÉS de mandar la respuesta (como los
     avisos push), con un candado para que dos peticiones a la vez no
     publiquen dos veces.
   - Lo anunciado se guarda en app_cache 'changelog_discord':
     { versiones: { "Alfa 0.1.7": { ids: [id de cada mensaje], hash } } }.
     Sin nada guardado (una web que empieza de cero) TODO lo que ya hay en
     el archivo cuenta como anunciado y no se publica nada.
   - NUNCA dos veces (14/11: la 0.2.1 salió repetida): se publica solo desde
     el dominio de main (no desde melonhubdev aunque su MELON_DEV_BUILD esté
     mal), bajo un candado de la BD (GET_LOCK, vale para los dos sitios si
     comparten BD), y la versión se APUNTA como anunciada ANTES de llamar a
     Discord. Si Discord dice que no (error HTTP), se quita el apunte y se
     reintenta a los 10 min; si no contesta (no se sabe si salió) o el
     proceso se corta, se queda apuntada: mejor un aviso de menos que uno
     repetido.
   - Versión nueva → mensaje con @everyone y la sección (embed; si pasa de
     ~4000 caracteres, en varios mensajes, solo el primero con @everyone).
   - Versión ya anunciada cuyo texto cambia (una errata) → se EDITAN sus
     mensajes, sin volver a avisar.
   - Solo en main (MELON_DEV_BUILD false Y la petición llega al dominio de
     melon_urlMain(), melonhub.es): dev comparte el changelog y publicaría
     dos veces. CHANGELOG_DISCORD=1 en el .env lo fuerza (pods de prueba) y
     =0 lo apaga.
   - Canal: el del changelog (MELON_CL_CANAL, 1508966953723625592);
     CHANGELOG_DISCORD_CHANNEL en el .env lo cambia. Token:
     DISCORD_BOT_TOKEN. Base de la API: DISCORD_API_BASE (pruebas).
     El bot necesita en ese canal "Enviar mensajes", "Insertar enlaces" y
     "Mencionar @everyone".
   - Si Discord falla, se reintenta a los 10 min (no en cada petición).
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('melon_changelogComprobar')) {

    /* Canal del changelog en Discord (el de los anuncios de versiones). */
    define('MELON_CL_CANAL', '1508966953723625592');

    function melon_changelogActivo(): bool {
        $f = function_exists('env') ? (string)env('CHANGELOG_DISCORD', '') : '';
        if ($f === '0') return false;
        if ($f === '1') return true;
        require_once dirname(__DIR__) . '/dev-build.php';
        if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD) return false;
        /* Y solo en el dominio de main: el flag ya se ha quedado mal dos
           veces tras un merge y melonhubdev publicaba también. */
        $sin = fn($h) => preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', (string)$h)));
        $host = $sin($_SERVER['HTTP_HOST'] ?? '');
        $main = $sin(parse_url(function_exists('melon_urlMain') ? melon_urlMain() : 'https://melonhub.es', PHP_URL_HOST) ?: 'melonhub.es');
        return $host !== '' && $host === $main;
    }

    function melon_changelogRutas(): array {
        $raiz = dirname(__DIR__, 2);
        return [$raiz . '/changelog.md', $raiz . '/uploads/esquema/changelog-discord.txt'];
    }

    /* Lo barato, en cada petición: ¿ha cambiado changelog.md? */
    function melon_changelogComprobar(): void {
        static $hecho = false;
        if ($hecho) return;
        $hecho = true;
        if (!melon_changelogActivo()) return;
        [$md, $marca] = melon_changelogRutas();
        $firma = @filemtime($md) . '|' . @filesize($md);
        $antes = (string)@file_get_contents($marca);
        if ($antes === $firma) return;
        /* "firma|reintentar:<hora>": falló Discord; se espera a esa hora. */
        if (strpos($antes, $firma . '|reintentar:') === 0 && time() < (int)substr($antes, strlen($firma . '|reintentar:'))) return;
        register_shutdown_function('melon_changelogPublicar');
    }

    /* Secciones en el orden del archivo: [['titulo' => 'Alfa 0.1.6', 'cuerpo' => '…'], …]. */
    function melon_changelogVersiones(string $md): array {
        $out = [];
        foreach (preg_split('/^# +/m', str_replace("\r", '', $md)) as $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') continue;
            $nl = strpos($trozo, "\n");
            $titulo = trim($nl === false ? $trozo : substr($trozo, 0, $nl));
            $cuerpo = $nl === false ? '' : substr($trozo, $nl + 1);
            $cuerpo = trim(preg_replace('/^-{3,}\s*$/m', '', $cuerpo));
            if ($titulo !== '') $out[] = ['titulo' => $titulo, 'cuerpo' => $cuerpo];
        }
        return $out;
    }

    /* Markdown del changelog → el de Discord (los "##" no salen como
       títulos dentro de un embed: van en negrita). Trozos de ≤ 4000. */
    function melon_changelogTrozos(string $cuerpo): array {
        $lineas = [];
        foreach (explode("\n", $cuerpo) as $l) {
            if (preg_match('/^#{2,}\s*(.+)$/', $l, $m)) $l = '**' . trim($m[1]) . '**';
            $lineas[] = rtrim($l);
        }
        $txt = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lineas)));
        $trozos = [];
        $actual = '';
        foreach (explode("\n", $txt) as $l) {
            if (mb_strlen($l) > 3900) $l = mb_substr($l, 0, 3900) . '…';
            if ($actual !== '' && mb_strlen($actual) + 1 + mb_strlen($l) > 4000) { $trozos[] = $actual; $actual = ''; }
            $actual .= ($actual === '' ? '' : "\n") . $l;
        }
        if ($actual !== '' || !$trozos) $trozos[] = $actual === '' ? '—' : $actual;
        return $trozos;
    }

    function melon_changelogDiscord(string $metodo, string $ruta, ?array $payload): array {
        $token = trim((string)env('DISCORD_BOT_TOKEN', ''));
        if ($token === '') return [0, null];
        $base = rtrim((string)env('DISCORD_API_BASE', 'https://discord.com/api/v10'), '/');
        $ch = curl_init($base . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => ['Authorization: Bot ' . $token, 'Content-Type: application/json',
                                       'User-Agent: MelonHub-Changelog (https://melonhub.es, 1.0)'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode((string)$resp, true)];
    }

    function melon_changelogEmbed(string $titulo, string $texto, int $i, int $n): array {
        return [
            'title'       => 'Melon Hub — ' . $titulo . ($n > 1 ? ' (' . ($i + 1) . '/' . $n . ')' : ''),
            'url'         => 'https://melonhub.es',
            'description' => $texto,
            'color'       => 0x4A8B3E,
            'footer'      => ['text' => 'Changelog de Melon Hub'],
        ];
    }

    /* Publica (ids = []) o edita (ids de antes) una versión. Devuelve los
       ids de sus mensajes; null si Discord ha dicho que no (se puede
       reintentar); false si no ha contestado (no se sabe si salió). Si ya
       había salido algún trozo, devuelve esos ids (no se repite). */
    function melon_changelogEnviar(string $canal, array $v, array $ids): array|false|null {
        $trozos = melon_changelogTrozos($v['cuerpo']);
        $n = count($trozos);
        $nuevos = [];
        $ruta = '/channels/' . rawurlencode($canal) . '/messages';
        foreach ($trozos as $i => $t) {
            $embed = melon_changelogEmbed($v['titulo'], $t, $i, $n);
            if (isset($ids[$i])) {
                /* Editar: sin tocar `content` (no vuelve a avisar a todos). */
                [$code] = melon_changelogDiscord('PATCH', $ruta . '/' . rawurlencode($ids[$i]), ['embeds' => [$embed]]);
                if ($code < 200 || $code >= 300) return null;
                $nuevos[] = $ids[$i];
                continue;
            }
            $p = ['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
            if (!$ids && $i === 0) {
                $p['content'] = '@everyone ¡Nueva actualización de Melon Hub: **' . $v['titulo'] . '**!';
                $p['allowed_mentions'] = ['parse' => ['everyone']];
            }
            [$code, $j] = melon_changelogDiscord('POST', $ruta, $p);
            /* Sin respuesta (0): no se sabe si ha salido → false, que el
               que llama trata como enviado (nunca dos veces). */
            if ($code === 0) return $nuevos ?: false;
            if ($code < 200 || $code >= 300 || empty($j['id'])) return $nuevos ?: null;
            $nuevos[] = (string)$j['id'];
        }
        /* Sobran mensajes (el texto se ha acortado): fuera. */
        foreach (array_slice($ids, $n) as $id) melon_changelogDiscord('DELETE', $ruta . '/' . rawurlencode($id), null);
        return $nuevos;
    }

    /* Lo caro, ya con la respuesta mandada. */
    function melon_changelogPublicar(): void {
        if (PHP_SAPI !== 'cli') {
            ignore_user_abort(true);
            if (function_exists('litespeed_finish_request')) litespeed_finish_request();
            elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        }
        [$md, $marca] = melon_changelogRutas();
        if (!is_dir(dirname($marca))) @mkdir(dirname($marca), 0775, true);
        $lock = @fopen($marca . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;   /* otra petición ya está en ello */
        try {
            clearstatcache();
            $firma = @filemtime($md) . '|' . @filesize($md);
            if ((string)@file_get_contents($marca) === $firma) return;
            $pdo = function_exists('melon_pdo') ? melon_pdo() : null;
            if (!$pdo) return;
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_cache (
                cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,
                cache_value LONGTEXT     NOT NULL,
                expires_at  INT          NULL,
                updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            /* Candado de la BD: si main y dev comparten BD, también vale
               entre los dos sitios (el flock solo dentro de uno). */
            if (!(int)$pdo->query("SELECT GET_LOCK('melon_changelog_discord', 0)")->fetchColumn()) return;
            $guardar = function () use ($pdo, &$estado) {
                $pdo->prepare("REPLACE INTO app_cache (cache_key, cache_value) VALUES ('changelog_discord', ?)")
                    ->execute([json_encode($estado, JSON_UNESCAPED_UNICODE)]);
            };
            try {
                $st = $pdo->prepare("SELECT cache_value FROM app_cache WHERE cache_key = 'changelog_discord'");
                $st->execute();
                $raw = $st->fetchColumn();
                $estado = $raw ? (json_decode((string)$raw, true) ?: ['versiones' => []]) : null;
                $versiones = melon_changelogVersiones((string)@file_get_contents($md));
                $falla = false;
                if ($estado === null) {
                    /* Nada guardado: todo lo que hay cuenta como anunciado. */
                    $estado = ['versiones' => []];
                    foreach ($versiones as $v) $estado['versiones'][$v['titulo']] = ['ids' => [], 'hash' => md5($v['cuerpo'])];
                    $guardar();
                    $versiones = [];
                }
                $canal = trim((string)env('CHANGELOG_DISCORD_CHANNEL', '')) ?: MELON_CL_CANAL;
                foreach ($versiones as $v) {
                    $t = $v['titulo'];
                    $h = md5($v['cuerpo']);
                    $prev = $estado['versiones'][$t] ?? null;
                    if ($prev && $prev['hash'] === $h) continue;
                    /* Anunciada sin mensaje guardado (las de antes del bot, o
                       una que se cortó a mitad): no se vuelve a publicar. */
                    if ($prev && !$prev['ids']) { $estado['versiones'][$t]['hash'] = $h; $guardar(); continue; }
                    if (!$prev) {
                        /* Nueva: se apunta ANTES de avisar. */
                        $estado['versiones'][$t] = ['ids' => [], 'hash' => $h, 'enviando' => time()];
                        $guardar();
                        $ids = melon_changelogEnviar($canal, $v, []);
                        if ($ids === null) {
                            /* Discord ha dicho que no: fuera el apunte, se reintenta. */
                            unset($estado['versiones'][$t]);
                            $guardar();
                            $falla = true;
                            error_log('[changelog] Discord no ha aceptado "' . $t . '"');
                            continue;
                        }
                        if ($ids === false) { error_log('[changelog] Discord no contesta: "' . $t . '" queda como anunciada'); continue; }
                        $estado['versiones'][$t] = ['ids' => $ids, 'hash' => $h];
                        $guardar();
                        continue;
                    }
                    /* Errata en una ya publicada: editar (sin @everyone). */
                    $ids = melon_changelogEnviar($canal, $v, $prev['ids']);
                    if (!$ids) { $falla = true; error_log('[changelog] no se ha podido editar "' . $t . '"'); continue; }
                    $estado['versiones'][$t] = ['ids' => $ids, 'hash' => $h];
                    $guardar();
                }
                @file_put_contents($marca, $falla ? $firma . '|reintentar:' . (time() + 600) : $firma);
            } finally {
                try { $pdo->query("SELECT RELEASE_LOCK('melon_changelog_discord')"); } catch (Throwable $_) {}
            }
        } catch (Throwable $e) {
            error_log('[changelog] ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
