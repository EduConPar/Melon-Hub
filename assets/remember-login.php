<?php
/* ──────────────────────────────────────────────────────────────────────
   SESIÓN RECORDADA — seguir dentro entre visitas (navegador y app de
   escritorio)
   ──────────────────────────────────────────────────────────────────────
   La cookie de sesión dura 30 días (setLongSessionCookie), pero la sesión
   de PHP vive en el servidor y el hosting borra sus archivos mucho antes:
   al volver, la cookie apuntaba a una sesión que ya no existía y tocaba
   iniciar sesión otra vez.

   Al entrar se guarda además un token de dispositivo (device_tokens, el
   mismo de la PWA, ver mobile-token.php) en la cookie `melon_remember`,
   que dura un año y se renueva en cada uso. Si la sesión ya no existe
   pero la cookie sí, la sesión se rehace con el token. Cerrar sesión
   borra el token y la cookie.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/mobile-token.php';

if (!function_exists('rememberLogin')) {
    define('MELON_REMEMBER_COOKIE', 'melon_remember');
    define('MELON_REMEMBER_SECONDS', 60 * 60 * 24 * 365);

    function rememberCookie(string $value, int $expires): void {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(MELON_REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /* Tras un inicio de sesión correcto. Si el navegador ya recordaba
       otra cuenta (cambio de usuario), su token se borra. */
    function rememberLogin(?PDO $pdo, string $userKey): void {
        if (!$pdo) return;
        try {
            forgetLogin($pdo);
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
            $stmt->execute([$userKey]);
            $uid = (int)($stmt->fetchColumn() ?: 0);
            if (!$uid) return;
            $token = mtCreateToken($pdo, $uid, $_SERVER['HTTP_USER_AGENT'] ?? null);
            rememberCookie($token, time() + MELON_REMEMBER_SECONDS);
        } catch (Throwable $e) {
            /* Sin BD se queda solo la sesión normal. */
            error_log('[remember] ' . $e->getMessage());
        }
    }

    /* Llamar después de session_start() y antes de escribir nada. Si no
       hay sesión pero sí un token válido, rehace la sesión. Devuelve el
       user_key de la sesión (o null). */
    function restoreRememberedLogin(?PDO $pdo): ?string {
        global $loginUsers;
        if (!empty($_SESSION['user'])) return $_SESSION['user'];
        $token = (string)($_COOKIE[MELON_REMEMBER_COOKIE] ?? '');
        if ($token === '' || !$pdo) return null;
        try {
            $userKey = mtConsumeToken($pdo, $token);
        } catch (Throwable $e) {
            error_log('[remember] ' . $e->getMessage());
            return null;
        }
        if ($userKey === null || !isset($loginUsers[$userKey])) {
            /* Token revocado o de una cuenta borrada. */
            rememberCookie('', time() - 3600);
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $userKey;
        rememberCookie($token, time() + MELON_REMEMBER_SECONDS);
        return $userKey;
    }

    /* Cerrar sesión: el token de este navegador deja de valer. */
    function forgetLogin(?PDO $pdo): void {
        $token = (string)($_COOKIE[MELON_REMEMBER_COOKIE] ?? '');
        if ($token === '') return;
        if ($pdo && preg_match('/^[a-f0-9]{64}$/', $token)) {
            try {
                mtEnsureTable($pdo);
                $pdo->prepare("DELETE FROM device_tokens WHERE token = ?")->execute([$token]);
            } catch (Throwable $e) {
                error_log('[remember] ' . $e->getMessage());
            }
        }
        rememberCookie('', time() - 3600);
        unset($_COOKIE[MELON_REMEMBER_COOKIE]);
    }
}
