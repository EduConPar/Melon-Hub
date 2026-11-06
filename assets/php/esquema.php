<?php
/* ──────────────────────────────────────────────────────────────────────
   Migraciones "una vez por versión del archivo"
   ──────────────────────────────────────────────────────────────────────
   Muchas APIs crean o retocan sus tablas al arrancar (CREATE TABLE IF NOT
   EXISTS, ALTER TABLE … ADD COLUMN, SHOW COLUMNS). Hacerlo en CADA
   petición costaba más que la propia petición: la API del perfil lanzaba
   ~13 sentencias así y el escritorio la llama ~20 veces por minuto; la de
   Juegos otras ~13 por cada sondeo del ajedrez. Son DDL: idas y vueltas a
   la BD y bloqueos de metadatos de la tabla (dos ALTER a la vez sobre la
   misma tabla se esperan). Ver .claude/notas/rendimiento.md.

   melon_esquema($pdo, 'clave', __FILE__, fn) ejecuta fn solo si no hay
   marca para la versión actual del archivo que define las tablas. La
   versión es la fecha y el tamaño de ese archivo: al desplegar un
   cambio (git reset --hard reescribe el archivo) la migración vuelve a
   correr una vez, sin tener que acordarse de subir ningún número.
   La marca va también por base de datos (DB_NAME), por si se cambia.

   Marcas en uploads/esquema/ (gitignored: el auto-deploy no las borra).
   Si fn lanza una excepción no se deja marca y se reintenta en la
   siguiente petición. Si una tabla nueva no apareciera, borrar las
   marcas de esa carpeta la vuelve a crear.
   ────────────────────────────────────────────────────────────────────── */

function melon_esquema(PDO $pdo, string $clave, string $archivo, callable $fn): void {
    static $hechas = [];
    if (isset($hechas[$clave])) return;
    $hechas[$clave] = true;
    $dir = dirname(__DIR__, 2) . '/uploads/esquema';
    $ver = substr(md5(DB_NAME . '|' . @filemtime($archivo) . '|' . @filesize($archivo)), 0, 12);
    $marca = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $clave) . '-' . $ver . '.ok';
    if (is_file($marca)) return;
    try {
        $fn($pdo);
    } catch (Throwable $e) {
        error_log('[esquema] ' . $clave . ': ' . $e->getMessage());
        return;
    }
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    /* Las marcas viejas de esta clave sobran (versiones anteriores). */
    foreach (glob($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $clave) . '-*.ok') ?: [] as $vieja) @unlink($vieja);
    @file_put_contents($marca, date('c'));
}

/* Para el catch general de las APIs: si una consulta dice que falta una
   tabla o una columna (BD restaurada o vacía con las marcas aún
   puestas), se borran todas las marcas y la siguiente petición vuelve a
   crear lo que falte. Esta petición ya ha fallado; la siguiente no. */
function melon_esquemaRevisar(Throwable $e): void {
    if (!($e instanceof PDOException) || !in_array((string)$e->getCode(), ['42S02', '42S22'], true)) return;
    foreach (glob(dirname(__DIR__, 2) . '/uploads/esquema/*.ok') ?: [] as $m) @unlink($m);
    error_log('[esquema] falta una tabla o columna: marcas borradas (' . $e->getMessage() . ')');
}

/* Para las APIs sin catch general: lo mismo con las excepciones que no
   captura nadie (luego, como haría PHP: al log y error 500). */
function melon_esquemaVigilar(): void {
    set_exception_handler(function (Throwable $e) {
        melon_esquemaRevisar($e);
        error_log('PHP Fatal error:  Uncaught ' . $e);
        if (!headers_sent()) http_response_code(500);
    });
}
