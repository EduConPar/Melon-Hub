<?php
/* ──────────────────────────────────────────────────────────────────────
   Programa beta (13/11, decidido así)
   ──────────────────────────────────────────────────────────────────────
   - Cualquiera se suscribe y se da de baja: menú Inicio "Beta tester..."
     (casilla que abre una ventana que lo explica) y, en el móvil, Ajustes.
     `usuarios.beta` (0/1). API: assets/beta/api.php.
   - Suscribirse da la medalla Tester (fila 'tester' en medallas_fechas, con
     la fecha); darse de baja NO la quita.
   - Los devs (es_dev) no ven lo beta si no se suscriben (decidido así).

   Marcar algo como beta:
     PHP:  if (melon_esBeta()) { … }            (mejor: ni se manda)
     HTML: <html class="<?= melon_betaClase() ?>"> en la página y
           class="solo-beta" en lo beta (base.css / mobile-theme.css lo
           esconden si el <html> no lleva mh-beta)
     JS:   window.MELON_BETA (escritorio y móvil)
     Etiqueta visible: <span class="beta-tag">BETA</span> (color de acento).
   Para sacarlo al público: quitar la condición, la clase y la etiqueta.

   medallas_fechas: fecha de obtención de cada medalla (la del primer nivel;
   subir de nivel no la cambia). La rellena pf2_stats (perfil2.php).
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('melon_betaEsquema')) {

    function melon_betaEsquema(PDO $pdo): void {
        require_once __DIR__ . '/esquema.php';
        melon_esquema($pdo, 'beta', __FILE__, function (PDO $pdo) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS beta TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->exec("CREATE TABLE IF NOT EXISTS medallas_fechas (
                user_id  INT NOT NULL,
                medalla  VARCHAR(20) NOT NULL,
                obtenida DATETIME NOT NULL,
                PRIMARY KEY (user_id, medalla)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        });
    }

    /* ¿Está suscrito? Sin $userKey, el de la sesión. Una consulta por
       petición como mucho (solo la piden las páginas, no los sondeos). */
    function melon_esBeta(?PDO $pdo = null, ?string $userKey = null): bool {
        static $cache = [];
        $pdo = $pdo ?: (function_exists('melon_pdo') ? melon_pdo() : null);
        $userKey = $userKey ?? (string)($_SESSION['user'] ?? '');
        if (!$pdo || $userKey === '') return false;
        if (isset($cache[$userKey])) return $cache[$userKey];
        try {
            melon_betaEsquema($pdo);
            $st = $pdo->prepare("SELECT beta FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
            $st->execute([$userKey, $userKey]);
            return $cache[$userKey] = ((int)$st->fetchColumn() === 1);
        } catch (Throwable $e) {
            error_log('[beta] ' . $e->getMessage());
            return false;
        }
    }

    /* Clase para el <html> de las páginas con cosas beta. */
    function melon_betaClase(?PDO $pdo = null): string {
        return melon_esBeta($pdo) ? 'mh-beta' : '';
    }
}
