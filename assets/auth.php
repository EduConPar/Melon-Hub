<?php
/* ─────────────────────────────────────────────────────────────
   AUTH + JSON HELPERS — utilidades comunes a todos los endpoints
   ─────────────────────────────────────────────────────────────
   - requireAuth()  asegura sesión y user válido, devuelve datos
   - jsonBody()     parsea el body JSON entrante
   - jsonResponse() envía respuesta JSON y termina
   - jsonError()    atajo para respuesta de error con código HTTP
   ───────────────────────────────────────────────────────────── */

/* Permisos: que los JSON que cree Apache nazcan en 0664 (rw-rw-r--)
   para que el dueño del sistema (capi) pueda editarlos manualmente
   sin perder el acceso de escritura de Apache. Por defecto el umask
   es 022 → 0644, lo que provoca bugs de "los mensajes/playlists no
   se guardan" cuando un archivo es propiedad de capi y Apache es
   solo grupo. */
umask(0002);

/* IMPORTANTE: config.php se carga en SCOPE GLOBAL (no dentro de una
   función). Si se hace require_once dentro de requireAuth(), las vars
   definidas en config.php — en particular $loginUsers — quedan locales
   a la función y los endpoints que las usen vía $GLOBALS['loginUsers']
   no las verán. */
require_once __DIR__ . '/config.php';

if (!function_exists('requireAuth')) {
    function requireAuth() {
        global $loginUsers;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userKey = $_SESSION['user'] ?? null;
        /* Sesión del servidor caducada con el escritorio abierto: se
           rehace con el token recordado (remember-login.php). */
        if (!$userKey && !empty($_COOKIE['melon_remember'])) {
            require_once dirname(__DIR__) . '/db.php';
            require_once __DIR__ . '/remember-login.php';
            $userKey = restoreRememberedLogin(isset($pdo) ? $pdo : ($GLOBALS['pdo'] ?? null));
        }
        if (!$userKey || !isset($loginUsers[$userKey])) {
            jsonError('No autenticado', 401);
        }
        return [
            'key'   => $userKey,
            'label' => $loginUsers[$userKey]['label'],
        ];
    }

    function jsonBody() {
        $raw = file_get_contents('php://input');
        $d   = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    function jsonResponse($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    function jsonError(string $msg, int $status = 400): void {
        jsonResponse(['error' => $msg], $status);
    }
}
