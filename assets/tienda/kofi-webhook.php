<?php
/* ──────────────────────────────────────────────────────────────────────
   KO-FI WEBHOOK RECEIVER
   ──────────────────────────────────────────────────────────────────────
   Ko-fi envía un POST a esta URL cada vez que alguien dona desde su sitio.
   Para configurarlo:
     1) En ko-fi.com → Settings → API → Webhook URL → pegar la URL pública
        de este archivo (https://tu-dominio/scrapbookOnline/assets/tienda/kofi-webhook.php).
     2) En la misma página, copiar el "Verification Token" y pegarlo en
        .env como KOFI_WEBHOOK_TOKEN=...
   Formato del payload: Ko-fi manda `application/x-www-form-urlencoded`
   con un único campo `data` que contiene JSON.  Verificamos que el
   `verification_token` coincida con el nuestro antes de tocar la BD.
   Dedupe por `kofi_transaction_id` (UNIQUE en la tabla).
   ────────────────────────────────────────────────────────────────────── */
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__, 2) . '/assets/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'POST only'; exit; }

$raw = $_POST['data'] ?? null;
if (!$raw) { http_response_code(400); echo 'missing data'; exit; }

$payload = json_decode((string)$raw, true);
if (!is_array($payload)) { http_response_code(400); echo 'bad json'; exit; }

$token = env('KOFI_WEBHOOK_TOKEN', '');
if ($token === '' || !hash_equals($token, (string)($payload['verification_token'] ?? ''))) {
    http_response_code(401);
    echo 'unauthorized';
    error_log('[kofi-webhook] verification_token mismatch');
    exit;
}

/* Tipos aceptados: donaciones libres, suscripciones mensuales y compras
   de comisiones (Commission). Todos contribuyen a sostener el proyecto,
   así que todos entran en el mismo tablón de donantes. Shop Order
   también pasa por aquí si tuvieras shop items configurados — lo
   dejamos fuera por ahora porque no aplica. */
$type = $payload['type'] ?? '';
$typeMap = [
    'Donation'     => 'donacion',
    'Subscription' => 'suscripcion',
    'Commission'   => 'encargo',
];
if (!isset($typeMap[$type])) {
    http_response_code(204);
    exit;
}
$tipo = $typeMap[$type];

/* Nombre humano de cada tipo de encargo a partir del direct_link_code
   que Ko-fi mete en el payload. La parte que va después de /c/ en la URL
   pública del encargo es esa misma clave. Si en el futuro añades más
   tipos de comisión, sumas su código aquí. */
$COMMISSION_NAMES = [
    '064181251c' => 'Haro personalizado',
    '4de28dd45e' => 'Tema personalizado',
    '16c92f9fdf' => 'Mascota personalizada',
];

$txId = (string)($payload['kofi_transaction_id'] ?? '');
if ($txId === '') { http_response_code(400); echo 'missing tx id'; exit; }

/* Respetar `is_public`: si el donante eligió privado, lo guardamos como
   anónimo sin mensaje. Eso es lo que muestra la propia pestaña pública
   de Ko-fi. */
$isPublic = !empty($payload['is_public']);
$nombre   = $isPublic ? trim((string)($payload['from_name'] ?? 'Anónimo')) : 'Anónimo';
if ($nombre === '') $nombre = 'Anónimo';
$nombre   = mb_substr($nombre, 0, 80);

/* Para donaciones/suscripciones: el campo `message` del checkout es un
   textarea libre = mensaje público del donante. Para encargos: ese mismo
   campo son las Order Notes (descripción privada del pedido para el
   artista), NO un mensaje de cara al público. Por eso en encargos
   ignoramos `message` y mostramos "Encargó: {producto}" derivado del
   shop_item, así la card del tablón es informativa sin filtrar la
   descripción privada. */
if ($type === 'Commission') {
    $code = '';
    if (isset($payload['shop_items'][0]['direct_link_code'])) {
        $code = (string)$payload['shop_items'][0]['direct_link_code'];
    }
    $productName = $COMMISSION_NAMES[$code]
        ?? (isset($payload['shop_items'][0]['variation_name'])
            ? trim((string)$payload['shop_items'][0]['variation_name'])
            : 'algo personalizado');
    $mensaje = 'Encargó: ' . $productName;
    if (mb_strlen($mensaje) > 200) $mensaje = mb_substr($mensaje, 0, 200);
} else {
    $mensaje = $isPublic ? trim((string)($payload['message'] ?? '')) : '';
    $mensaje = $mensaje !== '' ? mb_substr($mensaje, 0, 200) : null;
}

$importe  = (float)($payload['amount'] ?? 0);
if ($importe <= 0) $importe = null;

/* Ko-fi no envía la URL del avatar en el webhook; queda NULL y el frontend
   usa el placeholder 👤. Si en el futuro Ko-fi añade ese campo, basta con
   asignarlo aquí. */
$avatar = null;

try {
    $stmt = $pdo->prepare('
        INSERT INTO donaciones (nombre, avatar_url, mensaje, importe, tipo, kofi_transaction_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            nombre  = VALUES(nombre),
            mensaje = VALUES(mensaje),
            importe = VALUES(importe),
            tipo    = VALUES(tipo)
    ');
    $stmt->execute([$nombre, $avatar, $mensaje, $importe, $tipo, $txId]);
    /* Timbre (assets/php/timbre.php): las tiendas abiertas miran
       rt/donantes.json y solo piden la lista cuando cambia (antes, cada 30 s). */
    require_once dirname(__DIR__) . '/php/timbre.php';
    rt_escribir('rt/donantes.json', ['v' => rt_version()]);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[kofi-webhook] ' . $e->getMessage());
    echo 'error';
}
