<?php
/* Registro de usuario: INSERT en `usuarios` con password bcrypt + crea
   stub de escritorio + (opcional) sube foto de perfil. Sin tocar JSON.

   IMPORTANTE: garantizamos que SIEMPRE se devuelva JSON, incluso si hay un
   fatal error de PHP o el DB connect falla. Si no, el frontend ve la página
   HTML de error del host (InfinityFree etc.) y muestra "Error de red". */
header('Content-Type: application/json; charset=utf-8');
ob_start();   // capturar cualquier output accidental (warnings, BOM, etc.)

/* Handler de error/fatal que SI O SI emite JSON parseable */
function _regFatal($msg, $http = 500) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}
set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    _regFatal('PHP error: ' . $msg . ' @ ' . basename($file) . ':' . $line);
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        _regFatal('Fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
    }
});

try {
    require_once __DIR__ . '/db.php';   /* trae también assets/config.php */
} catch (Throwable $e) {
    _regFatal('DB include: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']); exit;
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($username === '' || mb_strlen($username) > 30) {
    echo json_encode(['error' => 'Nombre inválido (1-30 caracteres)']); exit;
}
if (!preg_match('/^[A-Za-z0-9_-]+$/', $username)) {
    echo json_encode(['error' => 'Solo letras, números, _ y -']); exit;
}
if (strlen($password) < 1) {
    echo json_encode(['error' => 'Contraseña vacía']); exit;
}

/* Comprobar nombre único (case-insensitive, contra `usuarios.label`) */
$lowerNew = mb_strtolower($username);
$stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE LOWER(label) = ? OR username = ?");
$stmt->execute([$lowerNew, $lowerNew]);
if ($stmt->fetch()) {
    echo json_encode(['error' => 'Ese nombre ya existe']); exit;
}

/* Foto opcional — la guardamos en uploads/profile-photos/ (fuera del
   directorio tracked por git para que `git reset --hard` del deploy
   no la borre) Y también en BD como blob (fuente de verdad para
   sobrevivir wipe de filesystem). Encajamos la subida a BD DESPUÉS
   del INSERT en usuarios (necesitamos el user_key). */
$hasPhoto = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && $_FILES['photo']['size'] > 0;
$photoExt = null;
$photoBlob = null;
if ($hasPhoto) {
    $f = $_FILES['photo'];
    if ($f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'Foto demasiado grande (máx 5MB)']); exit;
    }
    /* Detectamos el formato con `getimagesize` además de mime_content_type:
       más fiable en Hostinger (algunos hosts devuelven '' o
       application/octet-stream para mime_content_type sobre archivos
       en /tmp). getimagesize lee bytes mágicos del archivo, da el
       IMAGETYPE_* canónico. */
    $imgInfo = @getimagesize($f['tmp_name']);
    $imageType = $imgInfo ? (int)$imgInfo[2] : 0;
    $typeToExt = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (isset($typeToExt[$imageType])) {
        $photoExt = $typeToExt[$imageType];
    } else {
        /* Fallback a mime_content_type por si getimagesize tampoco le
           gusta (HEIC raros, etc.). Si NINGUNO funciona, error claro. */
        $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            echo json_encode(['error' => 'Foto inválida (jpg/png/gif/webp). Detectado: ' . ($mime ?: 'desconocido')]); exit;
        }
        $photoExt = $allowed[$mime];
    }

    /* Carpeta persistente (no tracked) */
    $uploadDir = __DIR__ . '/uploads/profile-photos';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        echo json_encode(['error' => 'No se pudo crear ' . $uploadDir]); exit;
    }
    if (!is_writable($uploadDir)) {
        echo json_encode(['error' => 'Sin permisos de escritura en ' . $uploadDir]); exit;
    }
    /* Limpiar versiones previas con el mismo nombre, en todas las exts
       que getUserImage también busca (jpeg incluido por compat). */
    foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $oldExt) {
        $oldPath = $uploadDir . '/' . $username . '.' . $oldExt;
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    $dest = $uploadDir . '/' . $username . '.' . $photoExt;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['error' => 'move_uploaded_file falló → ' . $dest]); exit;
    }
    /* Permisos legibles para el web server (algunos hosts crean el
       archivo con permisos restrictivos). */
    @chmod($dest, 0664);
    /* Lee el binario para guardarlo en BD justo después del INSERT. */
    $photoBlob = @file_get_contents($dest);
    if ($photoBlob === false) $photoBlob = null;
}

/* Siguiente user_key libre: maxN(user_key 'userN') + 1 */
$stmt = $pdo->query("SELECT user_key FROM usuarios WHERE user_key REGEXP '^user[0-9]+$'");
$maxNum = 0;
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
    if (preg_match('/^user(\d+)$/', $key, $m)) {
        $n = (int)$m[1];
        if ($n > $maxNum) $maxNum = $n;
    }
}
$newKey = 'user' . ($maxNum + 1);

/* Stub de escritorio (desktops/<label-lowercase>-desktop.php) — se crea ANTES
   del INSERT para no dejar un usuario sin escritorio si falla la escritura.
   `$createdStub` marca si fuimos nosotros quien lo creamos: solo en ese caso
   el rollback debe borrarlo. Sin esta marca, registrar dos veces con el
   mismo username (INSERT falla por UNIQUE) borraba el stub del usuario
   legítimo que ya existía. */
$desktopStub = __DIR__ . '/desktops/' . strtolower($username) . '-desktop.php';
$createdStub = false;
if (!file_exists($desktopStub)) {
    $stubContent = "<?php \$desktopLabel = " . var_export($username, true) . "; require __DIR__ . '/../desktop-base.php';\n";
    if (@file_put_contents($desktopStub, $stubContent) === false) {
        echo json_encode(['error' => 'No se pudo crear el escritorio del usuario']); exit;
    }
    $createdStub = true;
}

/* INSERT en usuarios con bcrypt */
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO usuarios (user_key, username, label, password)
                           VALUES (?, ?, ?, ?)");
    $stmt->execute([$newKey, $lowerNew, $username, $hash]);
} catch (Throwable $e) {
    /* Solo rollback del stub si lo creamos en ESTA invocación. */
    if ($createdStub) @unlink($desktopStub);
    echo json_encode(['error' => 'No se pudo guardar el usuario en BD']); exit;
}

/* Llegó por un enlace de invitación (cookie de enlace.php?invitar=…):
   se apunta para los 500 puntos de los dos (assets/profile/racha.php). */
try {
    require_once __DIR__ . '/assets/profile/racha.php';
    invita_registrar($pdo, (int)$pdo->lastInsertId());
} catch (Throwable $_) { /* sin invitación: la cuenta ya está creada */ }

/* Persiste el blob de la foto en usuarios.photo_data + photo_ext. Es
   la fuente de verdad: si el filesystem se borra en un deploy,
   getUserImage() la restaura desde aquí. Sin esto la foto del registro
   inicial moría con el primer `git reset --hard`. */
if ($photoBlob !== null && $photoExt !== null) {
    try {
        if (function_exists('_ensureUserPhotoColumns')) {
            _ensureUserPhotoColumns($pdo);
        }
        $stmt = $pdo->prepare("UPDATE usuarios SET photo_data = ?, photo_ext = ? WHERE user_key = ?");
        $stmt->bindParam(1, $photoBlob, PDO::PARAM_LOB);
        $stmt->bindValue(2, $photoExt);
        $stmt->bindValue(3, $newKey);
        $stmt->execute();
    } catch (Throwable $_) {
        /* La foto sigue accesible vía filesystem; al primer deploy se
           perderá, pero el registro del usuario está hecho. No abortamos. */
    }
}

/* Verificación final + diagnóstico detallado.
   Tras meses de bugs intermitentes con la foto del registro, devolvemos
   al cliente TODA la info necesaria para identificar el problema en
   producción sin tener que abrir DevTools ni meterse en SSH:
     - photoSaved: ¿getUserImage la ve?
     - photoUrl: si sí, su URL relativa.
     - photoDebug: paths, tamaños, permisos, sanitización del label. */
$response = ['ok' => true, 'userKey' => $newKey, 'label' => $username];
if ($hasPhoto) {
    $photoUrl = getUserImage($username);
    $response['photoUrl']   = $photoUrl;
    $response['photoSaved'] = ($photoUrl !== '');

    /* Diagnóstico detallado: solo si algo va mal (photoSaved=false). */
    if ($photoUrl === '') {
        $expected = isset($dest) ? $dest : '';
        $debug = [
            'expected_path'     => $expected,
            'file_exists'       => $expected !== '' ? file_exists($expected) : null,
            'file_size'         => ($expected !== '' && file_exists($expected)) ? filesize($expected) : null,
            'file_readable'     => $expected !== '' && file_exists($expected) ? is_readable($expected) : null,
            'username_input'    => $username,
            'sanitized_label'   => preg_replace('/[^A-Za-z0-9_-]/', '', $username),
            'upload_dir'        => $uploadDir ?? '',
            'upload_dir_exists' => isset($uploadDir) ? is_dir($uploadDir) : null,
            'upload_dir_writable' => isset($uploadDir) ? is_writable($uploadDir) : null,
            'photo_ext'         => $photoExt,
            'blob_persisted'    => $photoBlob !== null,
        ];
        $response['photoDebug']   = $debug;
        $response['photoWarning'] = 'getUserImage("' . $username . '") devolvió vacío. '
            . 'Path esperado: ' . $expected . '. '
            . 'file_exists=' . ($debug['file_exists'] ? 'sí' : 'NO')
            . ', readable=' . ($debug['file_readable'] ? 'sí' : 'NO')
            . ', size=' . ($debug['file_size'] ?? 'n/a');
    }
}
echo json_encode($response);
