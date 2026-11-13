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
     La primera vez (sin nada guardado) TODAS las versiones que ya hay se
     dan por anunciadas sin publicar (no se repite la 0.1.6).
   - Versión nueva → mensaje con @everyone y la sección (embed; si pasa de
     ~4000 caracteres, en varios mensajes, solo el primero con @everyone).
   - Versión ya anunciada cuyo texto cambia (una errata) → se EDITAN sus
     mensajes, sin volver a avisar.
   - Solo en main (MELON_DEV_BUILD false): dev comparte el changelog y
     publicaría dos veces. CHANGELOG_DISCORD=1 en el .env lo fuerza (pods de
     prueba) y =0 lo apaga.
   - Canal: CHANGELOG_DISCORD_CHANNEL del .env o, si no está, el de los
     eventos (assets/events/api.php EV_DISCORD_CHANNEL). Token:
     DISCORD_BOT_TOKEN. Base de la API: DISCORD_API_BASE (pruebas).
     El bot necesita en ese canal "Enviar mensajes", "Insertar enlaces" y
     "Mencionar @everyone".
   - Si Discord falla, se reintenta a los 10 min (no en cada petición).
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('melon_changelogComprobar')) {

    define('MELON_CL_CANAL_EVENTOS', '1516472123651133450');   /* = EV_DISCORD_CHANNEL */

    function melon_changelogActivo(): bool {
        $f = function_exists('env') ? (string)env('CHANGELOG_DISCORD', '') : '';
        if ($f === '0') return false;
        if ($f === '1') return true;
        require_once dirname(__DIR__) . '/dev-build.php';
        return !(defined('MELON_DEV_BUILD') && MELON_DEV_BUILD);
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
       ids de sus mensajes, o null si Discord ha fallado. */
    function melon_changelogEnviar(string $canal, array $v, array $ids): ?array {
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
            if ($code < 200 || $code >= 300 || empty($j['id'])) return null;
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
            $st = $pdo->prepare("SELECT cache_value FROM app_cache WHERE cache_key = 'changelog_discord'");
            $st->execute();
            $raw = $st->fetchColumn();
            $estado = $raw ? (json_decode((string)$raw, true) ?: []) : null;
            $versiones = melon_changelogVersiones((string)@file_get_contents($md));
            $falla = false;
            if ($estado === null) {
                /* Primera vez: lo que ya hay cuenta como anunciado. */
                $estado = ['versiones' => []];
                foreach ($versiones as $v) $estado['versiones'][$v['titulo']] = ['ids' => [], 'hash' => md5($v['cuerpo'])];
            } else {
                $canal = trim((string)env('CHANGELOG_DISCORD_CHANNEL', '')) ?: MELON_CL_CANAL_EVENTOS;
                foreach ($versiones as $v) {
                    $h = md5($v['cuerpo']);
                    $prev = $estado['versiones'][$v['titulo']] ?? null;
                    if ($prev && $prev['hash'] === $h) continue;
                    /* Ya anunciada antes de esto (sin mensaje): no se publica. */
                    if ($prev && !$prev['ids']) { $estado['versiones'][$v['titulo']]['hash'] = $h; continue; }
                    $ids = melon_changelogEnviar($canal, $v, $prev ? $prev['ids'] : []);
                    if ($ids === null) { $falla = true; error_log('[changelog] Discord no ha aceptado "' . $v['titulo'] . '"'); continue; }
                    $estado['versiones'][$v['titulo']] = ['ids' => $ids, 'hash' => $h];
                }
            }
            $pdo->prepare("REPLACE INTO app_cache (cache_key, cache_value) VALUES ('changelog_discord', ?)")
                ->execute([json_encode($estado, JSON_UNESCAPED_UNICODE)]);
            @file_put_contents($marca, $falla ? $firma . '|reintentar:' . (time() + 600) : $firma);
        } catch (Throwable $e) {
            error_log('[changelog] ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
