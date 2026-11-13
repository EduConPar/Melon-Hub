<?php
/* ──────────────────────────────────────────────────────────────────────
   Programa beta (assets/php/beta.php).
     GET            → { ok, beta, tester }   (tester: ya tiene la medalla)
     POST {beta: true|false} → lo mismo, ya cambiado.
   Suscribirse da la medalla Tester (medallas_fechas 'tester', con la fecha
   de la primera vez); darse de baja no la quita.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/php/beta.php';

$auth = requireAuth();
session_write_close();

try {
    melon_betaEsquema($pdo);
    $st = $pdo->prepare("SELECT id, beta FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
    $st->execute([$auth['key'], $auth['key']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) jsonError('Usuario no encontrado', 404);
    $uid = (int)$u['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $quiere = !empty(jsonBody()['beta']);
        $pdo->prepare("UPDATE usuarios SET beta = ? WHERE id = ?")->execute([$quiere ? 1 : 0, $uid]);
        if ($quiere) {
            $pdo->prepare("INSERT IGNORE INTO medallas_fechas (user_id, medalla, obtenida) VALUES (?, 'tester', NOW())")->execute([$uid]);
        }
        $u['beta'] = $quiere ? 1 : 0;
    }
    $tester = (bool)$pdo->query("SELECT 1 FROM medallas_fechas WHERE user_id = " . $uid . " AND medalla = 'tester'")->fetchColumn();
    jsonResponse(['ok' => true, 'beta' => (int)$u['beta'] === 1, 'tester' => $tester]);
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquemaRevisar($e);
    error_log('[beta] ' . $e->getMessage());
    jsonError('No se ha podido guardar', 500);
}
