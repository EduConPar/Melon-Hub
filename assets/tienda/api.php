<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/discord-oauth/helpers.php';

/* ── Auth ── */
$userKey = $_SESSION['user'] ?? null;
if (!$userKey || !isset($loginUsers[$userKey])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}
$stmt = $pdo->prepare('SELECT id, autismo, discord_user_id FROM usuarios WHERE user_key = ?');
$stmt->execute([$userKey]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'Usuario no encontrado']);
    exit;
}
$userId = (int)$user['id'];

$action = $_GET['action'] ?? '';

function getBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

switch ($action) {

/* Estado completo: balance del usuario + items activos + historial. */
case 'state': {
    /* Los objetos salen de assets/tienda/catalogo.json: si el archivo
       cambió, se pasan a tienda_items antes de leerlos. */
    require_once __DIR__ . '/catalogo.php';
    tiendaSyncCatalogo($pdo);
    /* Solo items comprables (precio > 0). Los de precio 0 son "base"
       — todos los usuarios los tienen sin necesidad de comprarlos, y
       aparecen solo en la vista de personalización. */
    $items = $pdo->prepare('
        SELECT id, nombre, slug, descripcion, precio, icono, categoria, discord_role_id
        FROM tienda_items
        WHERE activo = 1 AND precio > 0
        ORDER BY categoria ASC, orden ASC, id ASC
    ');
    $items->execute();
    $itemsRows = $items->fetchAll(PDO::FETCH_ASSOC);

    /* IDs de items ya comprados por el usuario — el frontend los usa para
       deshabilitar el botón "Comprar" y mostrar "Ya lo tienes". */
    $ownStmt = $pdo->prepare('SELECT DISTINCT item_id FROM tienda_compras WHERE user_id = ?');
    $ownStmt->execute([$userId]);
    $ownedIds = array_map('intval', $ownStmt->fetchAll(PDO::FETCH_COLUMN));

    /* Resolvemos el nombre del rol de Discord de cada item (cacheado 1h). */
    $roles = discordGetRoles($pdo);
    /* Marcos de perfil: su dibujo, para la vista previa de la tarjeta. */
    require_once dirname(__DIR__) . '/profile/marcos.php';
    $marcos = marcos_defs();
    foreach ($itemsRows as &$it) {
        $it['marco'] = ($it['categoria'] === 'perfil' && isset($marcos[$it['slug']])) ? $marcos[$it['slug']] : null;
        $rid = (string)($it['discord_role_id'] ?? '');
        if ($rid !== '' && isset($roles[$rid])) {
            $it['discord_role_name']  = $roles[$rid]['name'];
            $it['discord_role_color'] = $roles[$rid]['color'];
        } else {
            $it['discord_role_name']  = null;
            $it['discord_role_color'] = null;
        }
        unset($it['discord_role_id']);    /* no exponemos el ID al frontend */
    }
    unset($it);

    $hist = $pdo->prepare('
        SELECT c.id, c.item_id, c.precio, UNIX_TIMESTAMP(c.creado_en) AS ts,
               i.nombre, i.icono
        FROM tienda_compras c
        LEFT JOIN tienda_items i ON i.id = c.item_id
        WHERE c.user_id = ?
        ORDER BY c.creado_en DESC, c.id DESC
        LIMIT 50
    ');
    $hist->execute([$userId]);

    echo json_encode([
        'ok'      => true,
        'autismo' => (int)$user['autismo'],
        'items'   => $itemsRows,
        'owned'   => $ownedIds,
        'compras' => $hist->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}

/* SOLO DEV (MELON_DEV_BUILD, assets/dev-build.php): darse puntos para
   probar la tienda. En main la constante vale false y esto responde 403
   aunque alguien llame a la acción a mano. */
case 'dev-grant': {
    require_once dirname(__DIR__) . '/dev-build.php';
    if (!defined('MELON_DEV_BUILD') || !MELON_DEV_BUILD) jsonError('Solo en la build de desarrollo', 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $amount = (int)(getBody()['amount'] ?? 0);
    if ($amount < 1 || $amount > 100000) jsonError('Cantidad entre 1 y 100000');
    $pdo->prepare('UPDATE usuarios SET autismo = autismo + ? WHERE id = ?')->execute([$amount, $userId]);
    $st = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ?');
    $st->execute([$userId]);
    echo json_encode(['ok' => true, 'autismo' => (int)$st->fetchColumn()]);
    exit;
}

/* Endpoint ligero para polling — devuelve solo el balance actual. Lo usa
   la tienda para refrescar el widget en vivo sin pedir items + compras +
   discord_role_name cada 15 s. */
case 'balance': {
    echo json_encode(['ok' => true, 'autismo' => (int)$user['autismo']]);
    exit;
}

/* Compra atómica: descuenta el precio del item del balance del usuario y
   registra la fila en tienda_compras. Bloquea ambas filas con SELECT … FOR
   UPDATE para evitar comprar el mismo item dos veces con clic rápido. */
case 'buy': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b = getBody();
    $itemId = (int)($b['item_id'] ?? 0);
    if ($itemId <= 0) jsonError('item_id inválido');

    $discord = ['attempted' => false];
    $pdo->beginTransaction();
    try {
        $itStmt = $pdo->prepare('SELECT id, nombre, slug, precio, discord_role_id FROM tienda_items WHERE id = ? AND activo = 1 FOR UPDATE');
        $itStmt->execute([$itemId]);
        $item = $itStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $pdo->rollBack(); jsonError('Item no disponible', 404); }

        /* Una recompensa solo se puede adquirir UNA vez por usuario. */
        $ownChk = $pdo->prepare('SELECT 1 FROM tienda_compras WHERE user_id = ? AND item_id = ? LIMIT 1');
        $ownChk->execute([$userId, $itemId]);
        if ($ownChk->fetchColumn()) {
            $pdo->rollBack();
            jsonError('Ya tienes este item', 409);
        }

        /* Compras en cadena del perfil (huecos de expositor, mejoras de
           Media favorita y filas de logros de Steam): en orden, el 4 pide
           el 3… (tiendaPrevSlug en catalogo.php). */
        require_once __DIR__ . '/catalogo.php';
        $prevSlug = tiendaPrevSlug((string)($item['slug'] ?? ''));
        if ($prevSlug !== null) {
            $prevChk = $pdo->prepare('SELECT 1 FROM tienda_compras c JOIN tienda_items i ON i.id = c.item_id
                                      WHERE c.user_id = ? AND i.slug = ? LIMIT 1');
            $prevChk->execute([$userId, $prevSlug]);
            if (!$prevChk->fetchColumn()) {
                $pdo->rollBack();
                jsonError(preg_match('/^expositor-hueco-(\d)$/', $prevSlug, $m)
                    ? 'Compra antes el hueco ' . $m[1] . '.' : 'Compra antes la mejora anterior.', 409);
            }
        }

        /* Los items que conceden un rol de Discord requieren vinculación
           obligatoria. Sin Discord vinculado el bot no podría darle el rol
           al comprador → la compra no tendría efecto. Rechazamos antes de
           cobrar para que el usuario no pierda puntos en una compra inútil. */
        $itemRoleId = (string)($item['discord_role_id'] ?? '');
        if ($itemRoleId !== '' && (string)($user['discord_user_id'] ?? '') === '') {
            $pdo->rollBack();
            jsonError('Vincula tu cuenta de Discord antes de comprar este item.', 403);
        }

        $uStmt = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ? FOR UPDATE');
        $uStmt->execute([$userId]);
        $row = $uStmt->fetch(PDO::FETCH_ASSOC);
        $balance = (int)$row['autismo'];
        $precio  = (int)$item['precio'];
        if ($balance < $precio) {
            $pdo->rollBack();
            jsonError('Autismo insuficiente (te faltan ' . ($precio - $balance) . ')', 402);
        }
        $newBalance = $balance - $precio;

        $pdo->prepare('UPDATE usuarios SET autismo = ? WHERE id = ?')
            ->execute([$newBalance, $userId]);
        $pdo->prepare('INSERT INTO tienda_compras (user_id, item_id, precio) VALUES (?, ?, ?)')
            ->execute([$userId, $itemId, $precio]);

        /* Si el item lleva rol asignado Y el usuario tiene Discord vinculado,
           intentamos asignárselo ANTES del commit. Si Discord rechaza la
           operación (jerarquía, permisos, etc.), hacemos rollback de toda
           la compra: el usuario no pierde puntos y no aparece como dueño
           del item. Solo se cobra cuando TODO ha ido bien. */
        $discordUserId = (string)($user['discord_user_id'] ?? '');
        $roleId        = (string)($item['discord_role_id'] ?? '');
        if ($discordUserId !== '' && $roleId !== '') {
            $r = discordAssignRole($discordUserId, $roleId);
            if (!$r['ok']) {
                $pdo->rollBack();
                jsonError(
                    'No se pudo asignar el rol de Discord (' . ($r['error'] ?? 'error') . '). '
                    . 'La compra ha sido cancelada y no se te han descontado puntos.',
                    502
                );
            }
            $discord = [
                'attempted' => true,
                'ok'        => true,
                'status'    => $r['status'],
                'error'     => null,
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError('Error al comprar: ' . $e->getMessage(), 500);
    }

    echo json_encode([
        'ok'      => true,
        'autismo' => $newBalance,
        'item'    => ['id' => $itemId, 'nombre' => $item['nombre'], 'precio' => $precio],
        'discord' => $discord,
    ]);
    exit;
}

/* Lista de donantes — visible en la pestaña Donaciones. Devuelve nombre,
   avatar (resuelto a ruta absoluta del proyecto) y un mensaje opcional.
   Sin importe ni timestamp en la respuesta para no exponer datos
   sensibles si en el futuro hay donantes reales. */
case 'donors': {
    $rows = $pdo->query('
        SELECT nombre, avatar_url, mensaje, tipo
        FROM donaciones
        ORDER BY creado_en DESC, id DESC
        LIMIT 100
    ');
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    $base = substr($base, 0, strrpos($base, '/'));    /* sube de /assets a / */
    $out = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['avatar_url'] = $r['avatar_url'] ? $base . '/' . ltrim($r['avatar_url'], '/') : null;
        $out[] = $r;
    }
    echo json_encode(['ok' => true, 'donors' => $out]);
    exit;
}

default:
    http_response_code(400);
    echo json_encode(['error' => 'Acción desconocida: ' . htmlspecialchars((string)$action)]);
}
