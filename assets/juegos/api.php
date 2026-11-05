<?php
/* ──────────────────────────────────────────────────────────────────────
   API de la app Juegos (apps/juegos.php). De momento solo Wordelon
   (assets/juegos/wordelon.php):
     GET  ?action=wordelon            → partida de hoy
     POST ?action=wordelon-intento    {intento, dia} → partida + ganados
   `dia` es el día que tenía cargado la ventana: si ya ha cambiado (se
   dejó abierta pasada la medianoche) no se apunta el intento al día
   nuevo sin que lo vea, se devuelve la partida nueva con diaNuevo.
   ────────────────────────────────────────────────────────────────────── */
@ini_set('display_errors', '0');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/wordelon.php';

$me = requireAuth();
$st = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
$st->execute([$me['key']]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) jsonError('Usuario no encontrado', 403);

wordelon_ensure($pdo);

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'wordelon':
            jsonResponse(['ok' => true, 'estado' => wordelon_estado($pdo, $uid)]);

        case 'wordelon-intento': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
            $b = jsonBody();
            if (isset($b['dia']) && $b['dia'] !== wordelon_hoy()) {
                jsonResponse(['ok' => false, 'diaNuevo' => true, 'estado' => wordelon_estado($pdo, $uid)]);
            }
            try {
                [$estado, $ganados] = wordelon_intentar($pdo, $uid, (string)($b['intento'] ?? ''));
            } catch (InvalidArgumentException $e) {
                jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
            }
            $st = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ?');
            $st->execute([$uid]);
            jsonResponse(['ok' => true, 'estado' => $estado, 'ganados' => $ganados, 'autismo' => (int)$st->fetchColumn()]);
        }

        default:
            jsonError('Acción desconocida');
    }
} catch (Throwable $e) {
    error_log('[juegos] ' . $e->getMessage());
    jsonError('Error del servidor', 500);
}
