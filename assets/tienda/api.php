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

/* Compras que se gastan (Protector de racha): consumido_en. */
require_once __DIR__ . '/catalogo.php';
tiendaEnsureConsumibles($pdo);

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
    $ownStmt = $pdo->prepare('SELECT DISTINCT item_id FROM tienda_compras WHERE user_id = ? AND consumido_en IS NULL');
    $ownStmt->execute([$userId]);
    $ownedIds = array_map('intval', $ownStmt->fetchAll(PDO::FETCH_COLUMN));
    /* Fuentes que vienen con una interfaz tuya (Pixelify Sans con
       MelonOS Overdose): también "Ya lo tienes". */
    require_once dirname(__DIR__) . '/php/fuentes.php';
    $ownedIds = array_values(array_unique(array_merge($ownedIds, melonFuentesIncluidasIds($pdo, $userId))));
    $fuentes = melonFuentes();

    /* Resolvemos el nombre del rol de Discord de cada item (cacheado 1h). */
    $roles = discordGetRoles($pdo);
    /* Marcos de perfil: su dibujo, para la vista previa de la tarjeta. */
    require_once dirname(__DIR__) . '/profile/marcos.php';
    $marcos = marcos_defs();
    foreach ($itemsRows as &$it) {
        $it['marco'] = ($it['categoria'] === 'perfil' && isset($marcos[$it['slug']])) ? $marcos[$it['slug']] : null;
        /* Fuentes: familia y su CSS de Google Fonts, para la muestra. */
        $f = ($it['categoria'] === 'interfaces') ? ($fuentes[$it['slug']] ?? null) : null;
        $it['fuente'] = $f ? ['familia' => $f['familia'], 'url' => melonFuenteUrl($f), 'incluida' => $f['incluida']] : null;
        $rid = (string)($it['discord_role_id'] ?? '');
        $it['has_role'] = $rid !== '';      /* da un rol (aunque no se sepa su nombre) */
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
        $ownChk = $pdo->prepare('SELECT 1 FROM tienda_compras WHERE user_id = ? AND item_id = ? AND consumido_en IS NULL LIMIT 1');
        $ownChk->execute([$userId, $itemId]);
        if ($ownChk->fetchColumn()) {
            $pdo->rollBack();
            jsonError('Ya tienes este item', 409);
        }
        require_once dirname(__DIR__) . '/php/fuentes.php';
        if (in_array($itemId, melonFuentesIncluidasIds($pdo, $userId), true)) {
            $pdo->rollBack();
            jsonError('Ya tienes esta fuente: viene con una interfaz tuya.', 409);
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
        /* UNIQUE (user_id, item_id): un consumible gastado (Protector de
           racha) se vuelve a comprar sobre su misma fila. */
        $pdo->prepare('INSERT INTO tienda_compras (user_id, item_id, precio) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE precio = VALUES(precio), creado_en = NOW(), consumido_en = NULL')
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

/* ─── REGALOS (decidido así) ─────────────────────────────────────────
   Se regala a amigos mutuos desde el "modo regalo" de la tienda (la
   tienda se ve como si fueras esa persona). Se cobra al regalar; la otra
   persona tiene que abrirlo (aviso del haro con "Abrir" y "Regalos (N)" en
   la tienda): Aceptar lo pasa a sus compras (precio 0; los roles de
   Discord se le dan entonces), Rechazar devuelve los puntos a quien lo
   mandó. Si al aceptar ya lo tiene (lo compró mientras), o le falta la
   mejora anterior, también se devuelven. Tabla tienda_regalos. */
case 'gift-friends':
case 'gift-target':
case 'gift':
case 'gifts':
case 'gift-answer': {
    require_once __DIR__ . '/catalogo.php';
    require_once dirname(__DIR__) . '/social-helpers.php';
    tiendaEnsureRegalos($pdo);
    $persona = function (array $u): array {
        $label = (string)($u['label'] ?: $u['username'] ?: $u['user_key']);
        $img = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        return ['key' => $u['user_key'], 'label' => $label, 'image' => $img !== '' ? '../' . $img : ''];
    };
    $userByKey = function (string $key) use ($pdo) {
        $st = $pdo->prepare('SELECT id, user_key, username, label, discord_user_id FROM usuarios WHERE user_key = ?');
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $ownsSlug = function (int $uid, string $slug) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM tienda_compras c JOIN tienda_items i ON i.id = c.item_id WHERE c.user_id = ? AND i.slug = ? AND c.consumido_en IS NULL LIMIT 1');
        $st->execute([$uid, $slug]);
        return (bool)$st->fetchColumn();
    };

    if ($action === 'gift-friends') {
        $ids = mutualFollowerIds($pdo, $userId);
        $out = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, user_key, username, label FROM usuarios WHERE id IN ($ph) ORDER BY COALESCE(NULLIF(label, ''), username)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $out[] = $persona($u);
        }
        echo json_encode(['ok' => true, 'friends' => $out]);
        exit;
    }

    if ($action === 'gift-target') {
        $t = $userByKey((string)($_GET['user'] ?? ''));
        if (!$t || (int)$t['id'] === $userId || !isMutualFollow($pdo, $userId, (int)$t['id'])) jsonError('Solo puedes regalar a amigos mutuos.', 403);
        $st = $pdo->prepare('SELECT DISTINCT item_id FROM tienda_compras WHERE user_id = ? AND consumido_en IS NULL');
        $st->execute([(int)$t['id']]);
        $owned = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        require_once dirname(__DIR__) . '/php/fuentes.php';
        $owned = array_values(array_unique(array_merge($owned, melonFuentesIncluidasIds($pdo, (int)$t['id']))));
        $st = $pdo->prepare("SELECT DISTINCT item_id FROM tienda_regalos WHERE to_user_id = ? AND estado = 'pendiente'");
        $st->execute([(int)$t['id']]);
        $pending = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        echo json_encode(['ok' => true] + $persona($t) + [
            'discord' => (string)($t['discord_user_id'] ?? '') !== '',
            'owned'   => $owned,
            'pending' => $pending,
        ]);
        exit;
    }

    if ($action === 'gift') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
        $b = getBody();
        $itemId = (int)($b['item_id'] ?? 0);
        $mensaje = mb_substr(trim((string)($b['mensaje'] ?? '')), 0, 200);
        $t = $userByKey((string)($b['to'] ?? ''));
        if (!$t || (int)$t['id'] === $userId || !isMutualFollow($pdo, $userId, (int)$t['id'])) jsonError('Solo puedes regalar a amigos mutuos.', 403);
        $toId = (int)$t['id'];
        $pdo->beginTransaction();
        try {
            $itStmt = $pdo->prepare('SELECT id, nombre, slug, precio, discord_role_id FROM tienda_items WHERE id = ? AND activo = 1 AND precio > 0 FOR UPDATE');
            $itStmt->execute([$itemId]);
            $item = $itStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) { $pdo->rollBack(); jsonError('Item no disponible', 404); }
            $chk = $pdo->prepare('SELECT 1 FROM tienda_compras WHERE user_id = ? AND item_id = ? AND consumido_en IS NULL LIMIT 1');
            $chk->execute([$toId, $itemId]);
            if ($chk->fetchColumn()) { $pdo->rollBack(); jsonError('Ya lo tiene.', 409); }
            require_once dirname(__DIR__) . '/php/fuentes.php';
            if (in_array($itemId, melonFuentesIncluidasIds($pdo, $toId), true)) { $pdo->rollBack(); jsonError('Ya la tiene: viene con una interfaz suya.', 409); }
            $chk = $pdo->prepare("SELECT 1 FROM tienda_regalos WHERE to_user_id = ? AND item_id = ? AND estado = 'pendiente' LIMIT 1");
            $chk->execute([$toId, $itemId]);
            if ($chk->fetchColumn()) { $pdo->rollBack(); jsonError('Ya tiene ese regalo pendiente de abrir.', 409); }
            $prev = tiendaPrevSlug((string)($item['slug'] ?? ''));
            if ($prev !== null && !$ownsSlug($toId, $prev)) { $pdo->rollBack(); jsonError('Le falta la mejora anterior.', 409); }
            if ((string)($item['discord_role_id'] ?? '') !== '' && (string)($t['discord_user_id'] ?? '') === '') {
                $pdo->rollBack(); jsonError('No tiene su Discord vinculado: no puede recibir roles.', 403);
            }
            $uStmt = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ? FOR UPDATE');
            $uStmt->execute([$userId]);
            $balance = (int)$uStmt->fetchColumn();
            $precio = (int)$item['precio'];
            if ($balance < $precio) { $pdo->rollBack(); jsonError('Autismo insuficiente (te faltan ' . ($precio - $balance) . ')', 402); }
            $pdo->prepare('UPDATE usuarios SET autismo = autismo - ? WHERE id = ?')->execute([$precio, $userId]);
            $pdo->prepare("INSERT INTO tienda_regalos (from_user_id, to_user_id, item_id, precio, mensaje) VALUES (?, ?, ?, ?, ?)")
                ->execute([$userId, $toId, $itemId, $precio, $mensaje]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonError('Error al regalar: ' . $e->getMessage(), 500);
        }
        echo json_encode(['ok' => true, 'autismo' => $balance - $precio, 'item' => ['id' => $itemId, 'nombre' => $item['nombre'], 'precio' => $precio]]);
        exit;
    }

    if ($action === 'gifts') {
        $st = $pdo->prepare("SELECT r.id, r.item_id, r.mensaje, UNIX_TIMESTAMP(r.creado_en) AS ts,
                                    u.user_key, u.username, u.label, i.nombre, i.slug, i.categoria, i.descripcion, i.icono
                               FROM tienda_regalos r
                               JOIN usuarios u ON u.id = r.from_user_id
                               JOIN tienda_items i ON i.id = r.item_id
                              WHERE r.to_user_id = ? AND r.estado = 'pendiente'
                              ORDER BY r.creado_en ASC, r.id ASC");
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $from = $persona($r);
            $out[] = ['id' => (int)$r['id'], 'from' => $from, 'mensaje' => (string)$r['mensaje'], 'ts' => (int)$r['ts'],
                      'item' => ['id' => (int)$r['item_id'], 'nombre' => $r['nombre'], 'slug' => $r['slug'], 'categoria' => $r['categoria'],
                                 'descripcion' => (string)$r['descripcion'], 'icono' => (string)$r['icono']]];
        }
        echo json_encode(['ok' => true, 'gifts' => $out]);
        exit;
    }

    /* gift-answer */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b = getBody();
    $giftId = (int)($b['id'] ?? 0);
    $accept = !empty($b['accept']);
    $discord = ['attempted' => false];
    $refunded = '';
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT r.*, i.nombre, i.slug, i.discord_role_id FROM tienda_regalos r JOIN tienda_items i ON i.id = r.item_id
                              WHERE r.id = ? AND r.to_user_id = ? AND r.estado = 'pendiente' FOR UPDATE");
        $st->execute([$giftId, $userId]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) { $pdo->rollBack(); jsonError('Este regalo ya no está pendiente.', 404); }
        $refund = function (string $estado) use ($pdo, $g) {
            $pdo->prepare('UPDATE usuarios SET autismo = autismo + ? WHERE id = ?')->execute([(int)$g['precio'], (int)$g['from_user_id']]);
            $pdo->prepare('UPDATE tienda_regalos SET estado = ?, resuelto_en = NOW() WHERE id = ?')->execute([$estado, (int)$g['id']]);
        };
        if (!$accept) {
            $refund('rechazado');
        } else {
            $chk = $pdo->prepare('SELECT 1 FROM tienda_compras WHERE user_id = ? AND item_id = ? AND consumido_en IS NULL LIMIT 1');
            $chk->execute([$userId, (int)$g['item_id']]);
            $prev = tiendaPrevSlug((string)($g['slug'] ?? ''));
            require_once dirname(__DIR__) . '/php/fuentes.php';
            if ($chk->fetchColumn() || in_array((int)$g['item_id'], melonFuentesIncluidasIds($pdo, $userId), true)) {
                $refund('devuelto'); $refunded = 'Ya lo tenías: se le devuelven los puntos a quien te lo regaló.';
            } elseif ($prev !== null && !$ownsSlug($userId, $prev)) {
                $refund('devuelto'); $refunded = 'Te falta la mejora anterior: se le devuelven los puntos a quien te lo regaló.';
            } else {
                $roleId = (string)($g['discord_role_id'] ?? '');
                $dUser = (string)($user['discord_user_id'] ?? '');
                if ($roleId !== '' && $dUser === '') { $pdo->rollBack(); jsonError('Vincula tu Discord (aquí al lado) para aceptar este regalo.', 403); }
                $pdo->prepare('INSERT INTO tienda_compras (user_id, item_id, precio) VALUES (?, ?, 0)
                               ON DUPLICATE KEY UPDATE precio = 0, creado_en = NOW(), consumido_en = NULL')->execute([$userId, (int)$g['item_id']]);
                $pdo->prepare("UPDATE tienda_regalos SET estado = 'aceptado', resuelto_en = NOW() WHERE id = ?")->execute([(int)$g['id']]);
                if ($roleId !== '') {
                    $r = discordAssignRole($dUser, $roleId);
                    if (!$r['ok']) { $pdo->rollBack(); jsonError('No se pudo asignar el rol de Discord (' . ($r['error'] ?? 'error') . '). El regalo sigue pendiente.', 502); }
                    $discord = ['attempted' => true, 'ok' => true];
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError('Error con el regalo: ' . $e->getMessage(), 500);
    }
    echo json_encode(['ok' => true, 'accepted' => $accept && $refunded === '', 'refunded' => $refunded,
                      'item' => ['id' => (int)$g['item_id'], 'nombre' => $g['nombre']], 'discord' => $discord]);
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
