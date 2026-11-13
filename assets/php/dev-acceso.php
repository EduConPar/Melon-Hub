<?php
/* ──────────────────────────────────────────────────────────────────────
   Tag "dev" (usuarios.es_dev) y puerta de melonhubdev
   ──────────────────────────────────────────────────────────────────────
   - Solo quien tiene la tag puede usar la Melon Hub de dev (la rama `dev`,
     MELON_DEV_BUILD) y ver las estadísticas (apps/devstats.php, en las
     dos).
   - En dev, sin sesión se ve el login de siempre (si no, ningún dev
     podría entrar). En cuanto hay sesión de alguien sin la tag, se le
     cierra la sesión de dev (también la recordada, para no rebotar en
     bucle al volver) y se le manda a la misma ruta de la Melon Hub
     normal. Las peticiones de API reciben un 403 en JSON.
   - La primera vez se da la tag al usuario de menor id (el dueño, igual
     que wiki_admins) para que alguien pueda entrar y repartirla desde
     la página de estadísticas.
   - Para no consultar la BD en cada petición, el resultado se guarda 5
     min en la sesión: quitar la tag tarda como mucho eso en notarse.
   - MELON_PUERTA_DEV=0 en el .env la desactiva (pods de prueba con
     usuarios inventados).
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('melon_devEsquema')) {

    function melon_devEsquema(PDO $pdo): void {
        require_once __DIR__ . '/esquema.php';
        melon_esquema($pdo, 'dev-acceso', __FILE__, function (PDO $pdo) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS es_dev TINYINT(1) NOT NULL DEFAULT 0");
            if (!(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE es_dev = 1")->fetchColumn()) {
                $pdo->exec("UPDATE usuarios SET es_dev = 1 ORDER BY id ASC LIMIT 1");
            }
        });
    }

    /* user_key → ¿tiene la tag? null si no se ha podido mirar (sin BD). */
    function melon_esDev(?PDO $pdo, string $userKey): ?bool {
        if (!$pdo || $userKey === '') return null;
        try {
            melon_devEsquema($pdo);
            $st = $pdo->prepare("SELECT es_dev FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
            $st->execute([$userKey, $userKey]);
            $v = $st->fetchColumn();
            return $v === false ? false : ((int)$v === 1);
        } catch (Throwable $e) {
            error_log('[dev-acceso] ' . $e->getMessage());
            return null;
        }
    }

    /* Dirección de la Melon Hub normal (sin barra final). */
    function melon_urlMain(): string {
        $u = function_exists('env') ? (string)env('MELON_URL_MAIN', '') : '';
        return rtrim($u !== '' ? $u : 'https://melonhub.es', '/');
    }

    /* La puerta. Se llama desde config.php (páginas que abren la sesión
       antes de incluirlo, casi todas) y desde requireAuth (APIs que la
       abren después). No hace nada fuera de dev ni sin sesión. */
    function melon_puertaDev(): void {
        static $hecho = false;
        if ($hecho) return;
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user'])) return;
        require_once dirname(__DIR__) . '/dev-build.php';
        if (!defined('MELON_DEV_BUILD') || !MELON_DEV_BUILD) return;
        if (function_exists('env') && (string)env('MELON_PUERTA_DEV', '1') === '0') return;
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'logout.php') return;
        $hecho = true;

        $user = (string)$_SESSION['user'];
        $c = $_SESSION['_puerta_dev'] ?? null;
        if (is_array($c) && ($c['u'] ?? '') === $user && !empty($c['ok']) && time() - (int)($c['t'] ?? 0) < 300) return;

        $ok = melon_esDev(function_exists('melon_pdo') ? melon_pdo() : null, $user);
        /* Sin BD no se echa a nadie (tampoco podría hacer nada) y no se
           guarda: se vuelve a mirar en la siguiente petición. */
        if ($ok === null) return;
        if ($ok) { $_SESSION['_puerta_dev'] = ['u' => $user, 'ok' => 1, 't' => time()]; return; }

        /* Sin la tag: fuera de dev. */
        require_once dirname(__DIR__) . '/remember-login.php';
        forgetLogin(melon_pdo());
        $_SESSION = [];
        @session_destroy();

        $esApi = isset($_GET['action']) || isset($_POST['action'])
            || $_SERVER['REQUEST_METHOD'] === 'POST'
            || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php'
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream') !== false;
        if (!headers_sent()) {
            if ($esApi) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Sin acceso a la versión de desarrollo', 'redirect' => melon_urlMain()], JSON_UNESCAPED_UNICODE);
            } else {
                header('Cache-Control: no-store');
                header('Location: ' . melon_urlMain() . (string)($_SERVER['REQUEST_URI'] ?? '/'));
            }
        }
        exit;
    }
}
