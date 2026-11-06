<?php
require_once __DIR__ . '/assets/config.php';

/* La conexión la abre melon_pdo() (assets/config.php): una sola por
   petición, compartida con ensureLoginUsers, con su reintento corto
   (y uno solo en los sondeos, MELON_DB_RAPIDO). Si ya falló al cargar
   los usuarios, aquí no se vuelve a esperar. */
$pdo = melon_pdo();

if (!$pdo) {
    /* Antes esto era un die() de texto plano CON HTTP 200. Los endpoints
       JSON devolvían entonces un cuerpo imparseable, el JSON.parse del
       cliente petaba y el frontend solo podía enseñar un error genérico
       sin causa. Respondemos en el formato que espera el llamante y con
       un status que refleje de verdad lo que pasa. El detalle del error
       ya está en el log (melon_pdo), nunca en la respuesta. */
    $mensaje = 'La base de datos no está disponible ahora mismo. Inténtalo de nuevo en unos segundos.';
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 5');
        $quiereJson = isset($_GET['action']) || isset($_POST['action'])
            || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php'
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($quiereJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $mensaje;
    exit;
}
