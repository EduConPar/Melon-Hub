<?php
/* ──────────────────────────────────────────────────────────────────────
   active-interface.php
   ──────────────────────────────────────────────────────────────────────
   Helpers para el sistema de INTERFACES (looks completos de la app).

   - Cada interfaz vive en `assets/interfaces/<name>/` con:
       · style.css   — CSS overrides (vacío para la base Win98)
       · meta.json   — { name, label, description, tokens: { ... } }
       · preview.png — opcional, icono mostrado en Temas

   - La interfaz activa se persiste en una COOKIE `activeInterface` que
     el JS frontend (interface-loader.js) actualiza al cambiar y el PHP
     lee aquí para emitir el `<link>` correcto. Sin cookie → default
     'win98'.

   - SEGURIDAD: el nombre se sanitiza con preg_replace para evitar
     path-traversal. Cualquier valor no listado en la carpeta se
     ignora.
   ────────────────────────────────────────────────────────────────────── */

/* Resuelve la ruta absoluta a la carpeta de interfaces. */
function _interfacesDir(): string {
    /* dirname(__DIR__, 2) = raíz del proyecto (sube assets/php/ → /). */
    return dirname(__DIR__, 2) . '/assets/interfaces';
}

/* Lista todas las interfaces disponibles como
   [ [name, label, description, isDefault, previewRel, tokens], ... ]
   Lee meta.json de cada carpeta. */
function listInterfaces(): array {
    $base = _interfacesDir();
    if (!is_dir($base)) return [];
    $out = [];
    foreach (scandir($base) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $base . '/' . $entry;
        if (!is_dir($dir)) continue;
        $metaFile = $dir . '/meta.json';
        $meta = [];
        if (is_file($metaFile)) {
            $raw = file_get_contents($metaFile);
            $j = json_decode($raw, true);
            if (is_array($j)) $meta = $j;
        }
        /* Detectar preview opcional. */
        $previewRel = '';
        foreach (['preview.png', 'preview.jpg', 'preview.gif'] as $p) {
            if (is_file($dir . '/' . $p)) {
                $previewRel = 'assets/interfaces/' . $entry . '/' . $p;
                break;
            }
        }
        $out[] = [
            'name'        => $entry,
            'label'       => $meta['label']       ?? $entry,
            'description' => $meta['description'] ?? '',
            'isDefault'   => !empty($meta['isDefault']),
            'preview'     => $previewRel,
            'tokens'      => $meta['tokens']      ?? new stdClass(),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $out;
}

/* Variante per-user: oculta las interfaces "premium" (con fila en
   `tienda_items` categoria='interfaces' y precio>0) que el usuario NO
   haya comprado todavía.

   Lógica del gate:
     - Sin fila en tienda_items para ese slug → interfaz LIBRE, siempre se ve.
     - Fila con precio=0 → libre, siempre se ve.
     - Fila con precio>0 y compra en tienda_compras → se ve.
     - Fila con precio>0 y SIN compra → oculta.

   `win98` (no tiene fila) sigue siempre disponible. `kawaii` ("MelonOS
   Overdose") es premium: solo aparece tras comprarla. La que el user
   tenga activa cuando se gatea (active_interface_slug = 'kawaii') sigue
   funcionando — el shell la carga vía getActiveInterface(); solo deja
   de ofrecerse en la app de Temas. */
function listInterfacesForUser(PDO $pdo, ?int $userId): array {
    $all = listInterfaces();
    if ($userId === null) return $all;
    /* Slugs con fila en la tienda. Sin mirar `activo`: una interfaz
       premium quitada del catálogo (assets/tienda/catalogo.json) no pasa
       a ser gratis, solo deja de venderse; quien la compró la conserva. */
    $stmt = $pdo->prepare("SELECT slug, precio FROM tienda_items
                           WHERE categoria = 'interfaces' AND slug <> ''");
    $stmt->execute();
    $shopRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$shopRows) return $all;
    /* Compras del usuario indexadas por slug. */
    $stmt = $pdo->prepare("SELECT i.slug
                           FROM tienda_compras c
                           JOIN tienda_items i ON i.id = c.item_id
                           WHERE c.user_id = ? AND i.categoria = 'interfaces'");
    $stmt->execute([$userId]);
    $owned = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $s) $owned[$s] = true;
    /* Indexar items por slug para decidir gating. */
    $premiumBySlug = [];
    foreach ($shopRows as $r) {
        if ((int)$r['precio'] > 0) $premiumBySlug[$r['slug']] = true;
    }
    return array_values(array_filter($all, function($p) use ($premiumBySlug, $owned) {
        $slug = $p['name'];
        if (!isset($premiumBySlug[$slug])) return true; /* libre */
        return isset($owned[$slug]);
    }));
}

/* Devuelve el nombre de la interfaz activa (de la cookie o el default).
   Valida que la carpeta exista en disco; si no, cae a 'win98'. */
function getActiveInterface(): string {
    $name = $_COOKIE['activeInterface'] ?? '';
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$name);
    if ($name !== '' && is_dir(_interfacesDir() . '/' . $name) && is_file(_interfacesDir() . '/' . $name . '/style.css')) {
        return $name;
    }
    return 'win98';
}

/* Emite el <link> del CSS de la interfaz activa + el <script> init.js
   opcional si la interfaz lo trae. El path es relativo a la raíz del
   proyecto y debe ajustarse con un prefijo según desde dónde se llame
   (desktop-base.php → "" ; apps/X.php → "../" ;
   apps/mobile/X.php → "../../").

   init.js (opcional): si existe en la carpeta de la interfaz, se emite
   detrás del <link>. Lo usa Win7 para añadir `.active` a todas las
   `.window` y `.title-bar` que 7.css necesita para activar sus hovers
   Aero. Otras interfaces pueden añadirlo si necesitan init JS. */
function emitInterfaceCss(string $relPrefix = ''): void {
    $name   = getActiveInterface();
    $rel    = $relPrefix . 'assets/interfaces/' . $name . '/style.css';
    $cssAbs = dirname(__DIR__, 2) . '/assets/interfaces/' . $name . '/style.css';
    $cssV   = is_file($cssAbs) ? filemtime($cssAbs) : 0;
    echo '<link rel="stylesheet" id="active-interface-link" href="'
        . htmlspecialchars($rel) . '?v=' . $cssV . '">';

    /* init.js opcional. */
    $jsAbs = dirname(__DIR__, 2) . '/assets/interfaces/' . $name . '/init.js';
    if (is_file($jsAbs)) {
        $jsRel = $relPrefix . 'assets/interfaces/' . $name . '/init.js';
        $jsV   = filemtime($jsAbs);
        echo "\n    " . '<script src="' . htmlspecialchars($jsRel) . '?v=' . $jsV . '"></script>';
    }
}
