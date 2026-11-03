<?php
/* ──────────────────────────────────────────────────────────────────────
   melonSongInfo — una canción (por videoId) como se ve en Melon Hub
   ──────────────────────────────────────────────────────────────────────
   La usan los enlaces de canción: music api `song-info` (al abrirla) y la
   vista previa de Discord/WhatsApp (assets/link-preview.php, sin sesión).
   No el título crudo de YouTube ("Artista - Canción (Official Video)" y el
   canal como artista, con lo que find-album sacaba otro álbum).
   Título/artista, por orden: la corrección guardada ("Corregir…", que
   además puede cambiar el vídeo), una playlist, las canciones añadidas,
   las escuchas recientes de cualquiera y, si no está en ningún sitio,
   YouTube. Portada: la del álbum de la corrección o la que find-album
   dejó en caché; '' si no hay (quien la pinte pone la de YouTube).
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/cache-helpers.php';

function melonSongInfo(PDO $pdo, string $videoId, bool $askYoutube = true): array {
    $title = ''; $artist = ''; $duration = 0; $cover = '';
    $one = function (string $sql) use ($pdo, $videoId) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$videoId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    };
    $r = $one("SELECT title, artist, duration FROM playlist_tracks WHERE video_id = ? AND title <> '' ORDER BY playlist_id DESC LIMIT 1")
      ?: $one("SELECT title, artist, 0 AS duration FROM music_extras WHERE video_id = ? AND title <> '' LIMIT 1")
      ?: $one("SELECT name AS title, artist, 0 AS duration FROM music_recent WHERE item_type = 'song' AND item_key = ? AND name <> '' ORDER BY played_at DESC LIMIT 1");
    if ($r) { $title = (string)$r['title']; $artist = (string)$r['artist']; $duration = (int)$r['duration']; }

    $ov = $one("SELECT * FROM song_album_overrides WHERE video_id = ?");
    if ($ov) {
        if (($ov['song_title'] ?? '') !== '')  $title  = (string)$ov['song_title'];
        if (($ov['song_artist'] ?? '') !== '') $artist = (string)$ov['song_artist'];
        if (($ov['album_image'] ?? '') !== '') $cover  = (string)$ov['album_image'];
        if (strlen((string)($ov['new_video_id'] ?? '')) === 11) $videoId = (string)$ov['new_video_id'];
    }
    if ($cover === '') {
        try {
            $c = json_decode((string)cacheGet('album_track_v1_' . $videoId), true);
            if (is_array($c) && !empty($c['albumImage'])) $cover = (string)$c['albumImage'];
        } catch (Throwable $e) { /* sin caché */ }
    }
    if ($title === '' && $askYoutube) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=' . $videoId . '&format=json', false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        if (isset($data['title'])) { $title = (string)$data['title']; $artist = (string)($data['author_name'] ?? ''); }
    }
    return ['videoId' => $videoId, 'title' => $title, 'artist' => $artist, 'duration' => $duration, 'cover' => $cover];
}
