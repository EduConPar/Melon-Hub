<?php
/* ──────────────────────────────────────────────────────────────────────
   fuentes.php — FUENTES de la interfaz (decidido así; solo escritorio)
   ──────────────────────────────────────────────────────────────────────
   - Se venden en la pestaña Interfaces de la tienda (sección FUENTES) y
     se eligen en Temas → Personalización → Fuentes.
   - Salen de assets/tienda/catalogo.json: objetos de la categoría
     interfaces con el campo "fuente" (familia de Google Fonts, o una que
     ya carga la web con "local": true). Precio 0 = la tiene todo el mundo
     (la de Win98). "incluida": "<interfaz>" = quien tiene esa interfaz la
     tiene sin pagarla (Pixelify Sans viene con MelonOS Overdose).
   - Cada interfaz guarda la suya (como los packs de iconos):
     user_settings `fuente:<interfaz>` = slug en JSON. Sin elegir, la de
     serie de la interfaz (campo "fuente" de su meta.json).
   - La de la interfaz activa viaja en la cookie `melonFuente` (la pone
     desktop-base.php desde la BD en cada carga y set-font al cambiarla) y
     emitInterfaceCss() la pinta en todas las páginas que cargan la
     interfaz, iframes incluidos. Si es la de serie, no se pinta nada.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/tienda/catalogo.php';
require_once __DIR__ . '/active-interface.php';

const MELON_FUENTE_COOKIE = 'melonFuente';

/* Todas las fuentes del catálogo, en su orden: slug => datos. */
function melonFuentes(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $c = tiendaCatalogo();
    if (!$c) return $cache;
    foreach ($c['objetos'] as $o) {
        if ($o['categoria'] !== 'interfaces' || empty($o['fuente'])) continue;
        $cache[$o['slug']] = [
            'slug'     => $o['slug'],
            'nombre'   => $o['nombre'],
            'familia'  => $o['fuente'],
            'local'    => !empty($o['fuente_local']),
            'pesos'    => $o['fuente_pesos'],
            'incluida' => $o['incluida'],
            'precio'   => $o['precio'],
        ];
    }
    return $cache;
}

/* URL de Google Fonts de una fuente ('' si es local). */
function melonFuenteUrl(array $f): string {
    if (!empty($f['local'])) return '';
    return 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $f['familia'])
         . ($f['pesos'] !== '' ? ':wght@' . $f['pesos'] : '') . '&display=swap';
}

/* Pila de font-family: la elegida y, para lo que no tenga (emojis,
   símbolos), la de Win98 y la del sistema. */
function melonFuenteFamilia(array $f): string {
    return '"' . $f['familia'] . '", "Pixelated MS Sans Serif", "ms_sans_serif", sans-serif';
}

/* Fuente de serie de una interfaz (meta.json "fuente"; si no, la de Win98). */
function melonFuenteDefecto(string $iface): string {
    static $cache = [];
    if (isset($cache[$iface])) return $cache[$iface];
    $slug = 'fuente-win98';
    $iface = preg_replace('/[^A-Za-z0-9_-]/', '', $iface);
    $meta = dirname(__DIR__) . '/interfaces/' . $iface . '/meta.json';
    if ($iface !== '' && is_file($meta)) {
        $j = json_decode((string)file_get_contents($meta), true);
        if (is_array($j) && is_string($j['fuente'] ?? null) && isset(melonFuentes()[$j['fuente']])) $slug = $j['fuente'];
    }
    return $cache[$iface] = $slug;
}

/* Fuentes que tiene un usuario: slug => true. Las de precio 0, las
   compradas y las que vienen con una interfaz que tiene (una interfaz
   sin fila de pago en la tienda la tiene todo el mundo). Las compradas
   valen aunque ya no se vendan. */
function melonFuentesPoseidas(PDO $pdo, int $uid): array {
    $out = [];
    $todas = melonFuentes();
    foreach ($todas as $slug => $f) if ((int)$f['precio'] === 0) $out[$slug] = true;
    $st = $pdo->prepare("SELECT i.slug FROM tienda_compras c JOIN tienda_items i ON i.id = c.item_id
                          WHERE c.user_id = ? AND i.categoria = 'interfaces' AND c.consumido_en IS NULL");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) if (isset($todas[$s])) $out[$s] = true;
    $ifaces = [];
    foreach (listInterfacesForUser($pdo, $uid) as $i) $ifaces[$i['name']] = true;
    foreach ($todas as $slug => $f) if ($f['incluida'] && isset($ifaces[$f['incluida']])) $out[$slug] = true;
    return $out;
}

/* ids de tienda_items de las fuentes que el usuario tiene por venir con
   una interfaz suya (para que la tienda las enseñe como "Ya lo tienes"
   y no deje comprarlas ni regalarlas). */
function melonFuentesIncluidasIds(PDO $pdo, int $uid): array {
    $incl = [];
    $ifaces = null;
    foreach (melonFuentes() as $slug => $f) {
        if (!$f['incluida']) continue;
        if ($ifaces === null) {
            $ifaces = [];
            foreach (listInterfacesForUser($pdo, $uid) as $i) $ifaces[$i['name']] = true;
        }
        if (isset($ifaces[$f['incluida']])) $incl[] = $slug;
    }
    if (!$incl) return [];
    $ph = implode(',', array_fill(0, count($incl), '?'));
    $st = $pdo->prepare("SELECT id FROM tienda_items WHERE categoria = 'interfaces' AND slug IN ($ph)");
    $st->execute($incl);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/* Fuente puesta por el usuario en una interfaz (la de serie si no eligió
   o si la elegida ya no es suya). */
function melonFuenteActiva(PDO $pdo, int $uid, string $iface): string {
    $def = melonFuenteDefecto($iface);
    if ($uid <= 0) return $def;
    $st = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
    $st->execute([$uid, 'fuente:' . $iface]);
    $raw = (string)$st->fetchColumn();
    $slug = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_string($slug) || !isset(melonFuentes()[$slug])) return $def;
    if (!isset(melonFuentesPoseidas($pdo, $uid)[$slug])) return $def;
    return $slug;
}

/* Pone la cookie con la fuente de la interfaz activa ('' = la de serie). */
function melonFuenteCookie(string $slug): void {
    if (($_COOKIE[MELON_FUENTE_COOKIE] ?? '') === $slug) return;
    setcookie(MELON_FUENTE_COOKIE, $slug, [
        'expires'  => $slug === '' ? time() - 3600 : time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    if ($slug === '') unset($_COOKIE[MELON_FUENTE_COOKIE]);
    else $_COOKIE[MELON_FUENTE_COOKIE] = $slug;
}

/* <link> + <style> que cambian la fuente de toda la página. Va detrás del
   CSS de la interfaz (emitInterfaceCss). Overdose pone su fuente con
   !important y selectores de clase e id, así que la regla lleva
   !important y tres :not(#…) para ganar en especificidad (2-3 ids) a
   cualquier selector de la interfaz. pre/code/kbd/samp se quedan con su
   monoespaciada, y las muestras de la tienda y de Temas
   (.mh-fuente-muestra) con la suya. `font-size-adjust` iguala la altura de las minúsculas a
   la de la fuente de Win98: sin él, VT323 o Tiny5 salían diminutas y
   Press Start 2P enorme con los mismos px. La de Win98 (local) es la
   referencia y no lo lleva: con él salía diminuta. */
function melonFuenteHead(string $slug, string $iface): string {
    $todas = melonFuentes();
    if ($slug === '' || !isset($todas[$slug]) || $slug === melonFuenteDefecto($iface)) return '';
    $f = $todas[$slug];
    $url = melonFuenteUrl($f);
    $html = '';
    if ($url !== '') {
        $html .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
               . '<link rel="stylesheet" id="melon-fuente-link" href="' . htmlspecialchars($url) . '">';
    }
    $sel = ':not(#mhf-a):not(#mhf-b):not(#mhf-c)';
    $html .= '<style id="melon-fuente">'
           . 'html body' . $sel . ', html body' . $sel . ' *:not(pre):not(code):not(kbd):not(samp):not(.mh-fuente-muestra)'
           . '{font-family:' . melonFuenteFamilia($f) . ' !important;'
           . ($f['local'] ? '' : 'font-size-adjust:ex-height ' . MELON_FUENTE_AJUSTE . ' !important;') . '}'
           /* font-size-adjust se hereda: las muestras, sin él. */
           . '.mh-fuente-muestra{font-size-adjust:none !important;}'
           . '</style>';
    return $html;
}

/* Altura de las minúsculas (ex / tamaño) de "Pixelated MS Sans Serif". */
const MELON_FUENTE_AJUSTE = '0.5';
