<?php
/* ──────────────────────────────────────────────────────────────────────
   Tablas del uso de las apps (las rellena assets/stats/uso.php con lo
   que manda assets/js/uso-apps.js; las lee apps/devstats.php).
   - uso_apps : segundos y aperturas por usuario, día, app y plataforma.
   - uso_horas: segundos por usuario, día y hora (mapa de horas punta y
     usuarios activos por día).
   Un día y una fila por clave: se suma con ON DUPLICATE KEY, así que
   crece como mucho (usuarios × apps × días), no por cada envío.
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('melon_usoEsquema')) {
    function melon_usoEsquema(PDO $pdo): void {
        require_once dirname(__DIR__) . '/php/esquema.php';
        melon_esquema($pdo, 'uso-apps', __FILE__, function (PDO $pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS uso_apps (
                user_id    INT NOT NULL,
                dia        DATE NOT NULL,
                app        VARCHAR(32) NOT NULL,
                plataforma VARCHAR(12) NOT NULL,
                segundos   INT NOT NULL DEFAULT 0,
                aperturas  INT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, dia, app, plataforma),
                INDEX idx_dia (dia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS uso_horas (
                user_id  INT NOT NULL,
                dia      DATE NOT NULL,
                hora     TINYINT NOT NULL,
                segundos INT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, dia, hora),
                INDEX idx_dia (dia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }, true);
    }

    /* Nombre bonito de cada app (claves de uso-apps.js). Lo que no esté
       aquí se enseña tal cual. */
    function melon_usoNombreApp(string $k): string {
        static $n = [
            '_inicio' => 'Escritorio / menú (sin app)',
            'musica' => 'Música', 'album' => 'Música: álbum/playlist', 'artista' => 'Música: artista',
            'letra' => 'Música: letra', 'playlists' => 'Música: playlists',
            'perfil' => 'Perfil', 'juegos' => 'Juegos', 'tienda' => 'Tienda', 'temas' => 'Temas',
            'galeria' => 'Galería', 'dibujo' => 'Dibujo', 'calendario' => 'Calendario',
            'melonarchive' => 'MelonArchive', 'dnd' => 'Fichas D&D', 'mascota' => 'Mascota',
            'alimentar' => 'Mascota: alimentar', 'companion' => 'Companion', 'wrapped' => 'Wrapped',
            'chat' => 'Chat', 'carpeta' => 'Carpetas', 'kofi' => 'Ko-fi',
        ];
        return $n[$k] ?? $k;
    }
}
