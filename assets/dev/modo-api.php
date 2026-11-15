<?php
/* ──────────────────────────────────────────────────────────────────────
   Modo dev (assets/php/modo-dev.php): POST {modo: true|false} → { ok, modo }.
   Solo quien tiene la tag dev; lo usa la casilla del menú Inicio.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/php/modo-dev.php';

$auth = requireAuth();
session_write_close();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

try {
    [$esDev] = melon_estadoDev($pdo, $auth['key']);
    if (!$esDev) jsonError('Solo para devs', 403);
    $modo = !empty(jsonBody()['modo']) ? 1 : 0;
    $pdo->prepare("UPDATE usuarios SET modo_dev = ? WHERE user_key = ? OR CONCAT('user', id) = ?")
        ->execute([$modo, $auth['key'], $auth['key']]);
    jsonResponse(['ok' => true, 'modo' => $modo === 1]);
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquemaRevisar($e);
    error_log('[modo-dev] ' . $e->getMessage());
    jsonError('No se ha podido guardar', 500);
}
