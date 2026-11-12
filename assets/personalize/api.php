<?php
/* ──────────────────────────────────────────────
   PERSONALIZE API — haros + mascotas activos del usuario
   ──────────────────────────────────────────────
   Endpoints (?action= o body):
     GET  ?action=inventory       → haros y mascotas del usuario + activos
     POST ?action=set-active-haro    {itemId}
     POST ?action=set-active-mascot  {itemId}

   Items "de base" = tienda_items.precio = 0 (todos los usuarios los
   tienen sin necesidad de compra).
   Items "comprados" = el usuario tiene fila en tienda_compras.

   La elección activa se guarda en user_settings con
     key_name = 'active_haro' o 'active_mascot', value = item_id.
   ────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/tienda/catalogo.php';   /* haros y mascotas: salen de assets/tienda/catalogo.json */

$u      = requireAuth();
$userKey = $u['key'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* user_key (string) → usuarios.id (int) */
$uid = (function() use ($pdo, $userKey) {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
})();
if (!$uid) jsonError('Usuario no encontrado en BD', 500);

/* Items que el usuario posee de una categoría (base + comprados). Los
   base solo si siguen activos; los comprados aunque se hayan quitado del
   catálogo (assets/tienda/catalogo.json): quien lo compró lo conserva. */
function loadOwnedItems(PDO $pdo, int $uid, string $categoria): array {
    $sql = "SELECT i.id, i.nombre, i.slug, i.icono, i.precio, i.descripcion,
                   (i.precio = 0) AS is_base,
                   EXISTS (SELECT 1 FROM tienda_compras c WHERE c.user_id = ? AND c.item_id = i.id) AS owned
            FROM tienda_items i
            WHERE i.categoria = ?
              AND ((i.precio = 0 AND i.activo = 1)
                   OR EXISTS (SELECT 1 FROM tienda_compras c WHERE c.user_id = ? AND c.item_id = i.id))
            ORDER BY i.orden ASC, i.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $categoria, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        return [
            'id'          => (int)$r['id'],
            'nombre'      => $r['nombre'],
            'slug'        => $r['slug'] ?? '',
            'icono'       => $r['icono'],
            'descripcion' => $r['descripcion'],
            'isBase'      => (bool)$r['is_base'],
        ];
    }, $rows);
}

function getActiveItemId(PDO $pdo, int $uid, string $keyName): ?int {
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
    $stmt->execute([$uid, $keyName]);
    $v = $stmt->fetchColumn();
    if ($v === false) return null;
    $v = is_numeric($v) ? (int)$v : null;
    return $v ?: null;
}

function setActiveItemId(PDO $pdo, int $uid, string $keyName, int $itemId): void {
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$uid, $keyName, (string)$itemId]);
}

/* Verifica que el usuario posea el ítem (base o comprado) antes de
   permitir activarlo. Uno comprado vale aunque ya no se venda. */
function userOwnsItem(PDO $pdo, int $uid, int $itemId, string $categoria): bool {
    $stmt = $pdo->prepare("SELECT id, precio, activo FROM tienda_items WHERE id = ? AND categoria = ?");
    $stmt->execute([$itemId, $categoria]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['precio'] === 0) return (bool)$row['activo']; /* base */
    $stmt = $pdo->prepare("SELECT 1 FROM tienda_compras WHERE user_id = ? AND item_id = ?");
    $stmt->execute([$uid, $itemId]);
    return (bool) $stmt->fetchColumn();
}

tiendaSyncCatalogo($pdo);

switch ($action) {

case 'inventory': {
    /* "Interfaces" = items con categoria='interfaces'. Son skins /
       apariencias completas, totalmente independientes de los TEMAS
       DE COLORES del usuario (esos viven en la tabla `themes` y los
       gestiona el editor del sidebar "Mis temas"). */
    $interfaces = loadOwnedItems($pdo, $uid, 'interfaces');
    $haros      = loadOwnedItems($pdo, $uid, 'haros');
    $mascots    = loadOwnedItems($pdo, $uid, 'mascotas');

    $activeInterface = getActiveItemId($pdo, $uid, 'active_interface');
    $activeHaro      = getActiveItemId($pdo, $uid, 'active_haro');
    $activeMascot    = getActiveItemId($pdo, $uid, 'active_mascot');

    /* Si no hay activo en BD, cae al primer ítem de la lista (siempre
       es base, ya que orden los pone primero). */
    if ($activeInterface === null && $interfaces) $activeInterface = $interfaces[0]['id'];
    if ($activeHaro      === null && $haros)      $activeHaro      = $haros[0]['id'];
    if ($activeMascot    === null && $mascots)    $activeMascot    = $mascots[0]['id'];

    jsonResponse([
        'ok'              => true,
        'interfaces'      => $interfaces,
        'haros'           => $haros,
        'mascots'         => $mascots,
        'activeInterface' => $activeInterface,
        'activeHaro'      => $activeHaro,
        'activeMascot'    => $activeMascot,
    ]);
}

case 'set-active-interface': {
    $body = jsonBody();
    $itemId = (int)($body['itemId'] ?? 0);
    if (!$itemId) jsonError('itemId requerido');
    if (!userOwnsItem($pdo, $uid, $itemId, 'interfaces')) jsonError('No tienes esta interfaz', 403);
    setActiveItemId($pdo, $uid, 'active_interface', $itemId);
    $stmt = $pdo->prepare("SELECT slug FROM tienda_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $slug = (string) $stmt->fetchColumn();
    jsonResponse(['ok' => true, 'slug' => $slug]);
}

case 'set-active-haro': {
    $body = jsonBody();
    $itemId = (int)($body['itemId'] ?? 0);
    if (!$itemId) jsonError('itemId requerido');
    if (!userOwnsItem($pdo, $uid, $itemId, 'haros')) jsonError('No tienes este haro', 403);
    setActiveItemId($pdo, $uid, 'active_haro', $itemId);
    /* Devuelve el slug para que el cliente actualice ya el sistema de
       notificaciones sin recargar. */
    $stmt = $pdo->prepare("SELECT slug FROM tienda_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $slug = (string) $stmt->fetchColumn();
    jsonResponse(['ok' => true, 'slug' => $slug]);
}

case 'set-active-mascot': {
    $body = jsonBody();
    $itemId = (int)($body['itemId'] ?? 0);
    if (!$itemId) jsonError('itemId requerido');
    if (!userOwnsItem($pdo, $uid, $itemId, 'mascotas')) jsonError('No tienes esta mascota', 403);
    setActiveItemId($pdo, $uid, 'active_mascot', $itemId);
    jsonResponse(['ok' => true]);
}

/* ── ICON PACK ──
   El icon pack lo guardaba sólo localStorage del cliente. Lo migramos
   a user_settings para que sea visible server-side (necesario para
   "visitar perfil ajeno" — el iframe carga sus iconos del visitado).

   Guardamos UN pack por (user, interface) — coherente con cómo lo
   maneja el cliente (`iconPack:<interface>`).
   key_name = 'icon_pack:<interface>', value = nombre del pack. */
/* ── SET ACTIVE INTERFACE (SLUG) ──
   Las interfaces ahora viven en el filesystem (assets/interfaces/<slug>/)
   y no en tienda_items, así que el endpoint viejo set-active-interface
   (basado en itemId) ya no se llama desde Temas. Este endpoint guarda
   la SLUG directamente en user_settings para que el sync de
   desktop-base.php / mobile.php pueda reproducirla en siguiente carga
   (sin tener que mapear a un item id). */
case 'set-active-interface-slug': {
    $body = jsonBody();
    $slug = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['slug'] ?? ''));
    if ($slug === '') jsonError('slug requerido');
    if (!is_dir(dirname(__DIR__, 2) . '/assets/interfaces/' . $slug . '/')) {
        jsonError('Interfaz no encontrada', 404);
    }
    /* La columna `value` tiene CHECK(json_valid(value)). Guardamos como
       string JSON (json_encode → "kawaii") en lugar de string crudo. */
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$uid, 'active_interface_slug', json_encode($slug)]);
    jsonResponse(['ok' => true]);
}

case 'set-icon-pack': {
    $body  = jsonBody();
    $pack  = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['pack'] ?? ''));
    $iface = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['interface'] ?? '')) ?: 'win98';
    if ($pack === '') jsonError('pack requerido');
    /* La columna `value` tiene CHECK(json_valid(value)). Guardamos como
       string JSON (con comillas): "Melon" en lugar de Melon. */
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$uid, 'icon_pack:' . $iface, json_encode($pack)]);
    jsonResponse(['ok' => true]);
}

/* ── FUENTES (assets/php/fuentes.php) ──
   fonts: todas las del catálogo con si las tienes, la puesta en la
   interfaz activa y la de serie. set-font {slug, interface}: la guarda
   para esa interfaz y, si es la activa, pone la cookie melonFuente (la
   página recarga después). */
case 'fonts':
case 'set-font': {
    require_once dirname(__DIR__) . '/php/fuentes.php';
    if ($action === 'set-font') {
        $body  = jsonBody();
        $slug  = (string)($body['slug'] ?? '');
        $iface = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['interface'] ?? '')) ?: getActiveInterface();
        if (!isset(melonFuentes()[$slug])) jsonError('Fuente no encontrada', 404);
        if (!isset(melonFuentesPoseidas($pdo, $uid)[$slug])) jsonError('No tienes esta fuente', 403);
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$uid, 'fuente:' . $iface, json_encode($slug)]);
        if ($iface === getActiveInterface()) melonFuenteCookie($slug === melonFuenteDefecto($iface) ? '' : $slug);
        jsonResponse(['ok' => true]);
    }
    $iface = getActiveInterface();
    $tiene = melonFuentesPoseidas($pdo, $uid);
    $out = [];
    foreach (melonFuentes() as $slug => $f) {
        $out[] = ['slug' => $slug, 'nombre' => $f['nombre'], 'familia' => $f['familia'],
                  'url' => melonFuenteUrl($f), 'tiene' => isset($tiene[$slug])];
    }
    jsonResponse(['ok' => true, 'interfaz' => $iface, 'fuentes' => $out,
                  'activa' => melonFuenteActiva($pdo, $uid, $iface), 'defecto' => melonFuenteDefecto($iface)]);
}

/* ── GET LOOK ──
   Devuelve el "look" visual completo de un usuario: interfaz activa
   (slug), icon pack activo, tema activo (nombre + colores + assets).
   Usado por perfil.php standalone cuando se visita el perfil ajeno
   con ?as=USERKEY → emula la apariencia del visitado.

   El caller debe estar autenticado (requireAuth ya lo asegura). Si
   no se pasa `?as`, devuelve el look del propio caller. */
case 'get-look': {
    $targetKey = (string)($_GET['as'] ?? $userKey);
    /* Resuelve target user_id + label */
    $stmt = $pdo->prepare("SELECT id, label, user_key FROM usuarios WHERE user_key = ?");
    $stmt->execute([$targetKey]);
    $targetRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetRow) jsonError('Usuario no encontrado', 404);
    $targetUid   = (int)$targetRow['id'];
    $targetLabel = $targetRow['label'];

    /* INTERFACE: lee `active_interface_slug` (string JSON). La key vieja
       `active_interface` (item_id) ya no se usa — la nueva guarda la
       slug directa via set-active-interface-slug endpoint. */
    $ifaceSlug = 'win98';
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_interface_slug'");
    $stmt->execute([$targetUid]);
    $raw = (string)$stmt->fetchColumn();
    if ($raw !== '') {
        $slug = json_decode($raw, true);
        if (is_string($slug) && $slug !== ''
            && is_dir(dirname(__DIR__, 2) . '/assets/interfaces/' . $slug)) {
            $ifaceSlug = $slug;
        }
    }

    /* ICON PACK por interfaz. La columna `value` es JSON (CHECK
       constraint), decodificamos. Fallback 'Melon' (default cliente). */
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
    $stmt->execute([$targetUid, 'icon_pack:' . $ifaceSlug]);
    $iconPackJson = (string)$stmt->fetchColumn();
    $iconPack     = $iconPackJson !== '' ? json_decode($iconPackJson, true) : null;
    if (!is_string($iconPack) || $iconPack === '') $iconPack = 'Melon';

    /* TEMA: el activo para esta interfaz (si el target tiene uno). */
    require_once dirname(__DIR__) . '/themes/theme-helpers.php';
    $stmt = $pdo->prepare("SELECT name, colors, wallpaper, start_icon
                           FROM themes
                           WHERE user_id = ? AND interface_name = ? AND is_active = 1
                           LIMIT 1");
    $stmt->execute([$targetUid, $ifaceSlug]);
    $themeRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $theme = null;
    if ($themeRow) {
        $theme = [
            'name'      => $themeRow['name'],
            'class'     => themeCssClassName($themeRow['name'], $targetLabel),
            'cssPath'   => themeCssRelPath($themeRow['name'], $targetLabel),
            'colors'    => json_decode($themeRow['colors'], true) ?: [],
            'wallpaper' => $themeRow['wallpaper'] ?: '',
            'startIcon' => $themeRow['start_icon'] ?: '',
        ];
    }

    jsonResponse([
        'ok'         => true,
        'userKey'    => $targetRow['user_key'],
        'label'      => $targetLabel,
        'interface'  => $ifaceSlug,
        'iconPack'   => $iconPack,
        'theme'      => $theme,
    ]);
}

default:
    jsonError('Acción no válida: ' . $action, 400);
}
