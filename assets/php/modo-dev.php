<?php
/* ──────────────────────────────────────────────────────────────────────
   Modo dev (15/11/2026, decidido así)
   ──────────────────────────────────────────────────────────────────────
   - Quien tiene la tag dev (usuarios.es_dev) ve en el menú Inicio de la
     Melon Hub normal una casilla "Modo dev". Marcada, ve todo lo marcado
     como solo-dev (lo mismo que en la build de dev), solo esa persona;
     se guarda en usuarios.modo_dev y vale también en el móvil.
   - En la build de dev (MELON_DEV_BUILD) no sale: ya está todo.
   - Las funciones de dev se comprueban con melon_funcionesDev(), NO con
     MELON_DEV_BUILD a pelo. MELON_DEV_BUILD se queda para lo que es de
     la build y no de la persona: puerta de melonhubdev, nombre en
     devstats, etiqueta de las descargas, avisos de changelog a Discord.
   - Quitar la tag no apaga modo_dev, pero sin la tag no cuenta.
   API: assets/dev/modo-api.php.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/dev-build.php';
require_once __DIR__ . '/dev-acceso.php';

if (!function_exists('melon_funcionesDev')) {

    function melon_modoDevEsquema(PDO $pdo): void {
        melon_devEsquema($pdo);
        require_once __DIR__ . '/esquema.php';
        melon_esquema($pdo, 'modo-dev', __FILE__, function (PDO $pdo) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS modo_dev TINYINT(1) NOT NULL DEFAULT 0");
        });
    }

    /* [es_dev, modo_dev] del usuario (sin $userKey, el de la sesión). */
    function melon_estadoDev(?PDO $pdo = null, ?string $userKey = null): array {
        static $cache = [];
        $pdo = $pdo ?: (function_exists('melon_pdo') ? melon_pdo() : null);
        $userKey = $userKey ?? (string)($_SESSION['user'] ?? '');
        if (!$pdo || $userKey === '') return [false, false];
        if (isset($cache[$userKey])) return $cache[$userKey];
        try {
            melon_modoDevEsquema($pdo);
            $st = $pdo->prepare("SELECT es_dev, modo_dev FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
            $st->execute([$userKey, $userKey]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['es_dev' => 0, 'modo_dev' => 0];
            return $cache[$userKey] = [(int)$r['es_dev'] === 1, (int)$r['modo_dev'] === 1];
        } catch (Throwable $e) {
            error_log('[modo-dev] ' . $e->getMessage());
            return [false, false];
        }
    }

    /* ¿Se ven las funciones solo-dev? En la build de dev, siempre; en la
       normal, si es dev y tiene el modo dev puesto. */
    function melon_funcionesDev(?PDO $pdo = null, ?string $userKey = null): bool {
        if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD) return true;
        [$esDev, $modo] = melon_estadoDev($pdo, $userKey);
        return $esDev && $modo;
    }
}
