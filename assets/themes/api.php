<?php
/* ──────────────────────────────────────────────
   THEMES API — router único (backend SQL)
   ──────────────────────────────────────────────
   Endpoints (acción vía ?action= o body JSON):
     GET  ?action=get          → lista temas + activo
     POST ?action=save         → {name, colors}
     POST ?action=set-active   → {name}  (vacío = ninguno)
     POST ?action=delete       → {name}
   ────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/theme-helpers.php';

$u      = requireAuth();
$me     = $u['key'];
$label  = $u['label'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$uid = userIdByKey($me);
if (!$uid) jsonError('Usuario no encontrado en BD', 500);

/* Cada acción opera dentro del scope de la interfaz ACTIVA del usuario.
   Se infiere de la cookie 'activeInterface' que interface-loader.js
   actualiza al cambiar de interfaz. Default 'win98' si no hay cookie. */
$iface = preg_replace('/[^A-Za-z0-9_-]/', '', $_COOKIE['activeInterface'] ?? '') ?: 'win98';

switch ($action) {

case 'get': {
    seedDefaultTheme($me, $label);
    /* Cargar SOLO los temas de la interfaz activa. */
    $stmt = $pdo->prepare("SELECT name, colors, wallpaper, start_icon, is_active, is_public, is_downloaded, UNIX_TIMESTAMP(updated_at) AS updated_ts
                           FROM themes WHERE user_id = ? AND interface_name = ?");
    $stmt->execute([$uid, $iface]);
    $themes = []; $active = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $themes[$r['name']] = [
            'colors'     => json_decode($r['colors'], true) ?: [],
            'wallpaper'  => $r['wallpaper'] ?: '',
            'startIcon'  => $r['start_icon'] ?: '',
            'updatedAt'  => (int)$r['updated_ts'],
            'public'     => (bool)$r['is_public'],
            'downloaded' => (bool)$r['is_downloaded'],
        ];
        if ($r['is_active']) $active = $r['name'];
        /* Regenerar el CSS según el generador actual */
        if (validateThemeColors($themes[$r['name']]['colors'])) {
            @file_put_contents(
                themeCssFile($r['name'], $label),
                generateThemeCss(themeCssClassName($r['name'], $label), $themes[$r['name']]['colors'])
            );
        }
    }
    jsonResponse(['ok' => true, 'themes' => $themes ?: new stdClass(), 'active' => $active]);
}

case 'save': {
    $body    = jsonBody();
    $name    = isset($body['name'])    ? sanitizeThemeName($body['name'])    : '';
    $oldName = isset($body['oldName']) ? sanitizeThemeName($body['oldName']) : '';
    $colors  = $body['colors'] ?? null;
    $downloaded = !empty($body['downloaded']) ? 1 : 0;
    if ($name === '' || mb_strlen($name) > 30) jsonError('Nombre inválido (1-30, letras/números/_/-)');
    if (!validateThemeColors($colors))         jsonError('Colores inválidos');

    /* Override de interfaz por payload: lo usa el import de temas para
       guardar el tema en SU interfaz de origen aunque el usuario esté
       en otra. Validamos contra la carpeta de interfaces para evitar
       interface_name arbitrarios en la BD. Si el slug no existe o no
       se pasó, $iface mantiene el valor de la cookie. */
    if (isset($body['interface'])) {
        $reqIface = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$body['interface']);
        if ($reqIface !== '' && is_dir(dirname(__DIR__) . '/interfaces/' . $reqIface)) {
            $iface = $reqIface;
        }
    }

    /* Al descargar un tema de la biblioteca NO se duplican archivos: se guarda
       una referencia validada al asset original del autor. Si el autor lo
       borra, el render cae al wallpaper/icono global del descargador. */
    $newWp = ''; $newSi = '';
    if ($downloaded) {
        $srcWp = isset($body['wallpaper']) ? (string)$body['wallpaper'] : '';
        $srcSi = isset($body['startIcon']) ? (string)$body['startIcon'] : '';
        if ($srcWp !== '') $newWp = validateThemeAssetPath($srcWp, 'wallpaper');
        if ($srcSi !== '') $newSi = validateThemeAssetPath($srcSi, 'start-icon');
    }

    if ($oldName !== '' && $oldName !== $name) {
        /* RENOMBRAR dentro de la interfaz actual. */
        $chk = $pdo->prepare("SELECT 1 FROM themes WHERE user_id = ? AND interface_name = ? AND name = ?");
        $chk->execute([$uid, $iface, $oldName]);
        if ($chk->fetch()) {
            $dup = $pdo->prepare("SELECT 1 FROM themes WHERE user_id = ? AND interface_name = ? AND name = ?");
            $dup->execute([$uid, $iface, $name]);
            if ($dup->fetch()) jsonError('Ya existe un tema con ese nombre');
            $pdo->prepare("UPDATE themes SET name = ?, colors = ?, updated_at = NOW()
                           WHERE user_id = ? AND interface_name = ? AND name = ?")
                ->execute([$name, json_encode($colors, JSON_UNESCAPED_UNICODE), $uid, $iface, $oldName]);
            @unlink(themeCssFile($oldName, $label));
        } else {
            $pdo->prepare("INSERT INTO themes (user_id, interface_name, name, colors, is_active, is_downloaded, updated_at)
                           VALUES (?, ?, ?, ?, 0, ?, NOW())
                           ON DUPLICATE KEY UPDATE colors=VALUES(colors), updated_at=NOW()")
                ->execute([$uid, $iface, $name, json_encode($colors, JSON_UNESCAPED_UNICODE), $downloaded]);
        }
    } else {
        /* Alta o actualización normal en la interfaz actual. */
        $pdo->prepare("INSERT INTO themes (user_id, interface_name, name, colors, wallpaper, start_icon, is_active, is_downloaded, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())
                       ON DUPLICATE KEY UPDATE colors=VALUES(colors), updated_at=NOW()")
            ->execute([$uid, $iface, $name, json_encode($colors, JSON_UNESCAPED_UNICODE),
                       $newWp ?: null, $newSi ?: null, $downloaded]);
    }

    file_put_contents(
        themeCssFile($name, $label),
        generateThemeCss(themeCssClassName($name, $label), $colors)
    );

    /* ── PREMIO POR DESCARGA ───────────────────────────────────────────
       Si esta llamada es una DESCARGA de un tema de la biblioteca
       (downloaded=1 + sourceUserKey del autor), premiamos al AUTOR
       con +50 puntos de autismo. Reglas:
         · El autor no puede ser el descargador (no auto-premio).
         · Solo 1 vez por par (autor_tema_iface, descargador): el UNIQUE
           de `theme_download_rewards` dedupea. INSERT IGNORE → si la
           fila ya existía rowCount=0 → no se da puntos.
       Errores se silencian (try/catch) — no queremos que un fallo en el
       premio rompa la propia descarga.
       ────────────────────────────────────────────────────────────────── */
    $awarded = false;
    if ($downloaded) {
        $srcKey  = isset($body['sourceUserKey'])   ? (string)$body['sourceUserKey']   : '';
        $srcName = isset($body['sourceThemeName']) ? sanitizeThemeName($body['sourceThemeName']) : '';
        if ($srcKey !== '' && $srcName !== '') {
            try {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
                $stmt->execute([$srcKey]);
                $ownerId = (int)($stmt->fetchColumn() ?: 0);
                if ($ownerId > 0 && $ownerId !== $uid) {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare(
                        "INSERT IGNORE INTO theme_download_rewards
                            (theme_owner_id, theme_name, interface_name, downloader_id)
                         VALUES (?, ?, ?, ?)"
                    );
                    $ins->execute([$ownerId, $srcName, $iface, $uid]);
                    if ($ins->rowCount() > 0) {
                        $pdo->prepare("UPDATE usuarios SET autismo = autismo + 50 WHERE id = ?")
                            ->execute([$ownerId]);
                        require_once dirname(__DIR__) . '/php/timbre.php';
                        rt_saldo((int)$ownerId);
                        $awarded = true;
                    }
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[themes/save] reward error: ' . $e->getMessage());
            }
        }
    }

    jsonResponse([
        'ok'        => true,
        'name'      => $name,
        'cssPath'   => themeCssRelPath($name, $label),
        'className' => themeCssClassName($name, $label),
        'wallpaper' => $newWp,
        'startIcon' => $newSi,
        'awarded'   => $awarded,
    ]);
}

case 'set-active': {
    $body = jsonBody();
    $name = isset($body['name']) ? sanitizeThemeName($body['name']) : '';
    $wallpaper = ''; $startIcon = '';
    /* Validar que el tema existe DENTRO de la interfaz activa. */
    if ($name !== '') {
        $stmt = $pdo->prepare("SELECT wallpaper, start_icon FROM themes
                               WHERE user_id = ? AND interface_name = ? AND name = ?");
        $stmt->execute([$uid, $iface, $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Tema no encontrado', 404);
        $wallpaper = $row['wallpaper'] ?: '';
        $startIcon = $row['start_icon'] ?: '';
    }
    /* is_active es per-(user, interface). Cambiar el activo de win98 NO
       afecta a win7. */
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE themes SET is_active = 0 WHERE user_id = ? AND interface_name = ?")
            ->execute([$uid, $iface]);
        if ($name !== '') {
            $pdo->prepare("UPDATE themes SET is_active = 1
                           WHERE user_id = ? AND interface_name = ? AND name = ?")
                ->execute([$uid, $iface, $name]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    /* Devolvemos los assets EFECTIVOS — no los crudos de BD. Si el tema
       no declara wallpaper/icono propios (o el archivo no existe),
       caemos al global del usuario en lugar de al default. Sin esto, al
       activar un tema "solo colores" el cliente reseteaba el wallpaper
       del usuario al base-wallpaper. La carga inicial de
       desktop-base.php ya tenía esta lógica; la portamos aquí para que
       la activación dinámica sea consistente. */
    if ($name !== '') {
        [$wallpaper, $startIcon] = effectiveThemeAssets($label, $wallpaper, $startIcon);
    }
    /* className/cssPath para que el cliente pueda re-pintar el <link
       active-theme-link> sin construir la ruta a mano (la ruta canónica
       es uploads/themes/, no assets/themes/ — themeCssRelPath lo sabe). */
    $resp = ['ok' => true, 'active' => $name, 'wallpaper' => $wallpaper, 'startIcon' => $startIcon];
    if ($name !== '') {
        $resp['className'] = themeCssClassName($name, $label);
        $resp['cssPath']   = themeCssRelPath($name, $label);
    }
    jsonResponse($resp);
}

case 'delete': {
    $body = jsonBody();
    $name = isset($body['name']) ? sanitizeThemeName($body['name']) : '';
    if ($name === '') jsonError('Nombre inválido');
    $pdo->prepare("DELETE FROM themes WHERE user_id = ? AND interface_name = ? AND name = ?")
        ->execute([$uid, $iface, $name]);
    @unlink(themeCssFile($name, $label));
    jsonResponse(['ok' => true]);
}

/* Publicar / despublicar un tema en la biblioteca compartida */
case 'set-public': {
    $body   = jsonBody();
    $name   = isset($body['name']) ? sanitizeThemeName($body['name']) : '';
    $public = !empty($body['public']) ? 1 : 0;
    if ($name === '') jsonError('Nombre inválido');
    /* No se puede publicar un tema descargado de la biblioteca (no es original) */
    if ($public) {
        $chk = $pdo->prepare("SELECT is_downloaded FROM themes
                              WHERE user_id = ? AND interface_name = ? AND name = ?");
        $chk->execute([$uid, $iface, $name]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Tema no encontrado', 404);
        if ((int)$row['is_downloaded'] === 1) jsonError('No puedes publicar un tema descargado de la biblioteca');
    }
    $stmt = $pdo->prepare("UPDATE themes SET is_public = ?
                           WHERE user_id = ? AND interface_name = ? AND name = ?");
    $stmt->execute([$public, $uid, $iface, $name]);
    if (!$stmt->rowCount()) {
        $chk = $pdo->prepare("SELECT 1 FROM themes
                              WHERE user_id = ? AND interface_name = ? AND name = ?");
        $chk->execute([$uid, $iface, $name]);
        if (!$chk->fetch()) jsonError('Tema no encontrado', 404);
    }
    jsonResponse(['ok' => true, 'public' => (bool)$public]);
}

/* Biblioteca: temas públicos FILTRADOS por la interfaz activa.
   Un tema diseñado para win98 no aparece en kawaii (y viceversa), porque
   las paletas son específicas a cada interfaz y aplicar un tema win98
   sobre kawaii (o al revés) da un resultado roto. */
case 'library': {
    $stmt = $pdo->prepare(
        "SELECT t.name, t.colors, t.wallpaper, t.start_icon, t.interface_name,
                u.user_key, u.label,
                UNIX_TIMESTAMP(t.updated_at) AS updated_ts
         FROM themes t
         JOIN usuarios u ON t.user_id = u.id
         WHERE t.is_public = 1 AND t.interface_name = ?
         ORDER BY t.updated_at DESC"
    );
    $stmt->execute([$iface]);

    /* Cargamos la lista de interfaces para mapear name → label legible
       (e.g. "kawaii" → "MelonOS Overdose"). Si listInterfaces() está
       disponible (active-interface.php), úsalo; si no, fallback al name. */
    $ifaceLabels = [];
    if (function_exists('listInterfaces')) {
        foreach (listInterfaces() as $p) {
            $ifaceLabels[$p['name']] = $p['label'] ?: $p['name'];
        }
    }

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        /* Asset «efectivo»: el propio del tema o, si no tiene, el fondo/icono
           global del autor (lo que realmente ve con ese tema). Así un tema
           publicado siempre lleva el fondo e icono del autor. */
        $wp = $r['wallpaper'] ?: userNamedWallpaper($r['label']);
        $si = $r['start_icon'] ?: (function_exists('getUserStartIcon') ? getUserStartIcon($r['label']) : '');
        $ifaceName = $r['interface_name'] ?: 'win98';
        $items[] = [
            'name'           => $r['name'],
            'colors'         => json_decode($r['colors'], true) ?: [],
            'wallpaper'      => $wp ?: '',
            'startIcon'      => $si ?: '',
            'userKey'        => $r['user_key'],
            'label'          => $r['label'],
            'image'          => function_exists('getUserImage') ? getUserImage($r['label']) : '',
            'updated'        => (int)$r['updated_ts'],
            'interface'      => $ifaceName,
            'interfaceLabel' => $ifaceLabels[$ifaceName] ?? $ifaceName,
        ];
    }
    jsonResponse(['ok' => true, 'items' => $items, 'interface' => $iface]);
}

default:
    jsonError('Acción no válida: ' . $action, 400);
}
