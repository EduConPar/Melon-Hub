<?php
/* ──────────────────────────────────────────────────────────────────────
   Encuestas de satisfacción (assets/encuestas/encuestas.php).
     GET  ?action=toca                 → { ok, encuesta: {…} | null }
     POST ?action=responder {id, <ENC_NOTAS: 1..5>, comentario, plataforma}
                                       → { ok, puntos }
     POST ?action=rechazar  {id, plataforma} → { ok }
   "toca" lo pide el escritorio (desktop-base.php) y el móvil (mobile.php)
   una vez al abrirse, nada en bucle.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/encuestas.php';

$auth = requireAuth();
session_write_close();

try {
    enc_esquema($pdo);
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
    $st->execute([$auth['key'], $auth['key']]);
    $uid = (int)$st->fetchColumn();
    if (!$uid) jsonError('Usuario no encontrado', 404);

    $action = (string)($_GET['action'] ?? '');
    if ($action === 'toca') {
        jsonResponse(['ok' => true, 'encuesta' => enc_toca($pdo, $uid)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

    $b   = jsonBody();
    $id  = (int)($b['id'] ?? 0);
    $pl  = (string)($b['plataforma'] ?? '');
    if (!in_array($pl, ['web', 'electron', 'movil', 'app-movil'], true)) $pl = 'web';

    if ($action === 'responder') {
        $notas = [];
        foreach (ENC_NOTAS as $k) {
            $v = (int)($b[$k] ?? 0);
            if ($v < 1 || $v > 5) jsonError('Responde todas las preguntas del 1 al 5');
            $notas[$k] = $v;
        }
        $puntos = enc_responder($pdo, $uid, $id, $notas, (string)($b['comentario'] ?? ''), $pl);
        if ($puntos === null) jsonError('Esta encuesta ya no está disponible', 409);
        jsonResponse(['ok' => true, 'puntos' => $puntos]);
    }
    if ($action === 'rechazar') {
        enc_rechazar($pdo, $uid, $id, $pl);
        jsonResponse(['ok' => true]);
    }
    jsonError('Acción desconocida');
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquemaRevisar($e);
    error_log('[encuestas] ' . $e->getMessage());
    jsonError('No se ha podido guardar', 500);
}
