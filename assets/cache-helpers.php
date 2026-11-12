<?php
/* ──────────────────────────────────────────────
   CACHÉ CLAVE-VALOR EN SQL (tabla app_cache)
   ──────────────────────────────────────────────
   Sustituye los antiguos ficheros .json de caché (tokens de Spotify,
   respuestas de Innertube de YouTube). Todo se guarda en SQL, no en disco.

   API:
     cacheGet(string $key): ?string        → valor vigente o null si no existe/expiró
     cacheSet(string $key, string $value, ?int $ttlSeconds = null): void

   Es best-effort: si la BD no está disponible, get devuelve null y set no
   lanza (la app sigue funcionando, simplemente sin caché). */

require_once __DIR__ . '/config.php';

/* PDO compartido. db.php deja $pdo en scope local del require, así que lo
   movemos a $GLOBALS para reutilizarlo (mismo patrón que themesPdo()). */
function cachePdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    require_once dirname(__DIR__) . '/db.php';
    $GLOBALS['pdo'] = $pdo;
    return $pdo;
}

/* Crea la tabla si no existe (portabilidad: hosting sin migraciones). */
function cacheEnsureTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    /* Una vez por versión de este archivo (melon_esquema), no en cada
       petición: ver .claude/notas/rendimiento.md. */
    require_once __DIR__ . '/php/esquema.php';
    melon_esquema($pdo, 'cache', __FILE__, function (PDO $pdo) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_cache (
                cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,
                cache_value LONGTEXT     NOT NULL,
                expires_at  INT          NULL,
                updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }, true);
    $done = true;
}

function cacheGet(string $key): ?string {
    try {
        $pdo = cachePdo();
        cacheEnsureTable($pdo);
        $st = $pdo->prepare(
            "SELECT cache_value FROM app_cache
             WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > ?)"
        );
        $st->execute([$key, time()]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function cacheSet(string $key, string $value, ?int $ttlSeconds = null): void {
    try {
        $pdo = cachePdo();
        cacheEnsureTable($pdo);
        $exp = ($ttlSeconds !== null) ? time() + max(0, $ttlSeconds) : null;
        $st = $pdo->prepare(
            "INSERT INTO app_cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)"
        );
        $st->execute([$key, $value, $exp]);
    } catch (Throwable $e) {
        /* caché best-effort: ignorar fallos */
    }
}
