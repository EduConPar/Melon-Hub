<?php
// reproductor.php - All player HTML + PHP setup
// Included from desktop-base.php; expects $desktopUserKey and $hasPlayer already set.
require_once dirname(__DIR__) . '/assets/config.php';
require_once dirname(__DIR__) . '/db.php';

/* Defaults defensivos por si el archivo se incluye sin haber preparado
   el contexto. En el flujo real de desktop-base.php ambas variables
   vienen ya seteadas; el isset() corta el path de undefined-variable
   tanto para PHP runtime como para los analizadores estáticos. */
if (!isset($desktopUserKey)) { $desktopUserKey = ''; }
if (!isset($hasPlayer))      { $hasPlayer = false; }

/* Pool global de pistas que el reproductor muestra al inicio:
   1) Listado estático por defecto para user1/user2 (semilla histórica).
   2) Pistas añadidas vía add-track → tabla music_extras. */
$youtubePlaylist = [];
if ($desktopUserKey === 'user1') {
    require_once dirname(__DIR__) . '/assets/music/playlist.php';
} elseif ($desktopUserKey === 'user2') {
    require_once dirname(__DIR__) . '/assets/music/angie-playlist.php';
}
$stmt = $pdo->prepare("SELECT me.video_id AS videoId, me.title, me.artist
                       FROM music_extras me
                       JOIN usuarios u ON me.user_id = u.id
                       WHERE u.user_key = ?
                       ORDER BY me.id ASC");
$stmt->execute([$desktopUserKey]);
$youtubePlaylist = array_merge($youtubePlaylist, $stmt->fetchAll(PDO::FETCH_ASSOC));

?>

<?php if ($hasPlayer): ?>
<div id="yt-player"></div>
<!-- REPRODUCTOR -->
<div class="window" id="music-player">
    <div class="title-bar" id="player-titlebar">
        <div class="title-bar-text" id="player-tb-text"><?php echo appTitleIcon('musicaIcon', '♪'); ?><span id="player-pl-name">Reproductor</span></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize" id="player-minimize"></button>
            <button aria-label="Maximize" id="player-maximize"></button>
            <button aria-label="Close" id="player-close"></button>
        </div>
    </div>
    <div class="window-body" id="player-body">
        <div id="player-content">
            <div id="player-main">
                <div id="player-cover-wrap" style="position:relative;">
                    <img id="player-cover" src="" alt="">
                    <!-- Indicador LIVE (host/guest). Oculto por defecto. -->
                    <span id="lt-live-dot" title="" style="display:none;position:absolute;top:3px;right:3px;width:9px;height:9px;border-radius:50%;background:var(--accent, #1db954);box-shadow:0 0 5px var(--accent, #1db954),0 0 1px rgba(0,0,0,0.5);animation:ltLivePulse 1.2s ease-in-out infinite;z-index:5;"></span>
                </div>
                <div id="player-info">
                    <!-- Si el track actual tiene álbum resuelto, este
                         <p> se vuelve clickable: abre el álbum como
                         playlist temporal. El nombre del álbum NO se
                         muestra aquí — vive en el panel de Playlists. -->
                    <p id="player-title">Sin título</p>
                    <p id="player-artist">—</p>
                    <div id="player-addedby"></div>
                </div>
            </div>
            <input type="range" id="player-progress" min="0" max="100" value="0" step="0.1">
            <div id="player-time">
                <span id="player-current">0:00</span>
                <span id="player-duration">0:00</span>
            </div>
            <div id="player-controls">
                <button class="button" id="btn-prev">◄◄</button>
                <button class="button" id="btn-play">►</button>
                <button class="button" id="btn-next">►►</button>
            </div>
        </div>
        <button class="button" id="btn-shuffle" title="Reproducción aleatoria">⇄</button>
        <button class="button" id="btn-edit-playlist" title="Ver playlist">☰</button>
        <button class="button" id="btn-lyrics" title="Letra"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;margin:auto;"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="8" y1="22" x2="16" y2="22"/></svg></button>
        <div id="player-volume-wrap">
            <div id="volume-track-outer">
                <input type="range" id="player-volume" min="0" max="100" value="100" step="1" orient="vertical">
            </div>
            <span id="volume-icon">◄))</span>
        </div>
    </div>
</div>

<!-- FULLSCREEN PLAYER — versión "maximizada" del reproductor, con
     vinilo girando + controles grandes (clon visual del fullscreen
     player del móvil). El estado de reproducción se comparte con el
     reproductor normal (mismos ytPlayer + playlist + currentTrack);
     este overlay solo es una vista alternativa. -->
<div id="player-full" aria-hidden="true">
    <div class="window pf-window">
        <div class="title-bar pf-titlebar">
            <div class="title-bar-text"><?php echo appTitleIcon('songIcon', '♪'); ?>Melon Player — <span id="pf-pl-name">Reproductor</span></div>
            <div class="title-bar-controls">
                <button aria-label="Restore" id="pf-restore"></button>
                <button aria-label="Close" id="pf-close-x"></button>
            </div>
        </div>
        <div class="window-body pf-body">
            <!-- Cover difuminado de fondo — añade color de ambiente
                 sin ocultar el chrome Win98. -->
            <div class="pf-bg-cover" id="pf-bg-cover" aria-hidden="true"></div>

            <!-- ═══ VINILO FLOTANTE — encima del hifi, fuera del componente ═══ -->
            <div class="pf-vinyl-floating">
                <div class="pf-vinyl-glow"></div>
                <div class="pf-vinyl-wrap">
                    <div class="pf-vinyl" id="pf-vinyl">
                        <div class="pf-vinyl-label empty" id="pf-vinyl-label">♪</div>
                        <div class="pf-vinyl-hole"></div>
                    </div>
                </div>
            </div>

            <!-- ═══ HIFI COMPONENT — solo título, progreso y controles ═══ -->
            <div class="pf-unit">
                <!-- LCD: título / artista + clock digital. -->
                <div class="pf-lcd">
                    <div class="pf-lcd-left">
                        <div class="pf-lcd-marker">▶</div>
                        <div class="pf-lcd-info">
                            <div class="pf-title-wrap"><span class="pf-title" id="pf-title">—</span></div>
                            <div class="pf-artist-wrap"><span class="pf-artist" id="pf-artist">—</span></div>
                        </div>
                    </div>
                    <div class="pf-lcd-clock">
                        <span class="pf-clock-cur" id="pf-time-cur">0:00</span>
                        <span class="pf-clock-sep">/</span>
                        <span class="pf-clock-tot" id="pf-time-tot">0:00</span>
                    </div>
                </div>

                <!-- PROGRESS: cassette tape con dos carretes a los lados. -->
                <div class="pf-tape">
                    <div class="pf-reel pf-reel-l" id="pf-reel-l">
                        <span class="pf-reel-spoke" style="--rot: 0deg"></span>
                        <span class="pf-reel-spoke" style="--rot: 60deg"></span>
                        <span class="pf-reel-spoke" style="--rot: 120deg"></span>
                    </div>
                    <div class="pf-progress" id="pf-progress-track">
                        <div class="pf-progress-fill" id="pf-progress-fill"></div>
                    </div>
                    <div class="pf-reel pf-reel-r" id="pf-reel-r">
                        <span class="pf-reel-spoke" style="--rot: 0deg"></span>
                        <span class="pf-reel-spoke" style="--rot: 60deg"></span>
                        <span class="pf-reel-spoke" style="--rot: 120deg"></span>
                    </div>
                </div>

                <!-- TRANSPORT BAR Win98 con divider. -->
                <div class="pf-transport">
                    <!-- IZQUIERDA: shuffle + lyrics -->
                    <div class="pf-extras">
                        <button class="button pf-extra" id="pf-shuffle" type="button" aria-label="Aleatorio" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="16 3 21 3 21 8"/>
                                <line x1="4" y1="20" x2="21" y2="3"/>
                                <polyline points="21 16 21 21 16 21"/>
                                <line x1="15" y1="15" x2="21" y2="21"/>
                                <line x1="4" y1="4" x2="9" y2="9"/>
                            </svg>
                        </button>
                        <button class="button pf-extra" id="pf-lyrics" type="button" aria-label="Letra">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="9" y="2" width="6" height="12" rx="3"/>
                                <path d="M5 10v2a7 7 0 0 0 14 0v-2"/>
                                <line x1="12" y1="19" x2="12" y2="22"/>
                                <line x1="8" y1="22" x2="16" y2="22"/>
                            </svg>
                        </button>
                    </div>

                    <!-- CENTRO: prev / play / next -->
                    <div class="pf-controls">
                        <button class="button pf-btn" id="pf-prev" type="button" aria-label="Anterior">⏮</button>
                        <button class="button pf-btn pf-primary" id="pf-toggle" type="button" aria-label="Play/Pausa">
                            <span class="pf-icon-play">►</span>
                            <span class="pf-icon-pause">⏸</span>
                        </button>
                        <button class="button pf-btn" id="pf-next" type="button" aria-label="Siguiente">⏭</button>
                    </div>

                    <!-- DERECHA: Volumen -->
                    <div class="pf-volume-wrap">
                        <span class="pf-vol-icon" id="pf-vol-icon" aria-hidden="true">◄))</span>
                        <input type="range" id="pf-volume" class="pf-volume" min="0" max="100" value="100" step="1" aria-label="Volumen">
                    </div>
                </div>
            </div>

            <!-- Lyric video — solo la línea actualmente sonando flota
                 sobre el reproductor con tremble por carácter +
                 slide/fade al cambiar. Sin fondo, sin blur, sin scroll. -->
            <div id="pf-lyrics-overlay" aria-hidden="true">
                <div class="pf-lyr-stage" id="pf-lyr-stage"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- LYRICS WINDOW — panel con letra plana o sincronizada (LRCLIB). -->
<div class="window" id="lyrics-window" data-no-auto-z
     style="display:none; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); width:min(420px,92vw); height:min(560px,86vh); z-index:10004; flex-direction:column;">
    <div class="title-bar" id="lyrics-titlebar">
        <div class="title-bar-text" style="display:inline-flex;align-items:center;gap:6px;"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="8" y1="22" x2="16" y2="22"/></svg><span id="lyrics-title-text">Letra</span></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize" id="lyrics-min"></button>
            <button aria-label="Maximize" id="lyrics-max"></button>
            <button aria-label="Close" id="lyrics-close"></button>
        </div>
    </div>
    <div class="window-body" id="lyrics-body" style="flex:1;display:flex;flex-direction:column;overflow:hidden;padding:0;">
        <div id="lyrics-meta" style="padding:8px 10px;border-bottom:1px solid var(--bezel-dark-2,#808080);font-size:11px;display:flex;justify-content:space-between;align-items:center;gap:6px;">
            <span id="lyrics-track" style="font-weight:bold;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">—</span>
            <span id="lyrics-status" style="font-size:10px;color:var(--text-muted,#888);flex-shrink:0;"></span>
        </div>
        <div id="lyrics-scroll" style="flex:1;overflow-y:auto;padding:14px 16px;font-size:13px;line-height:1.6;text-align:center;white-space:pre-wrap;">
            <div id="lyrics-empty" style="opacity:0.6;padding-top:30%;">Cargando…</div>
            <div id="lyrics-lines"></div>
        </div>
    </div>
</div>
<style>
    .lyrics-line {
        padding: 4px 0;
        color: var(--text-muted, rgba(0,0,0,0.55));
        transition: color 0.25s, transform 0.25s, opacity 0.25s, font-weight 0.25s;
        opacity: 0.7;
    }
    .lyrics-line.active {
        color: var(--accent, #1db954);
        font-weight: bold;
        opacity: 1;
        transform: scale(1.06);
        text-shadow: 0 0 8px color-mix(in srgb, var(--accent) 30%, transparent);
    }
    .lyrics-line.past {
        opacity: 0.4;
    }
</style>

<!-- PLAYLIST EDITOR -->
<div class="window" id="playlist-editor">
    <div class="title-bar" id="pl-titlebar">
        <div class="title-bar-text" id="pl-title-text">♪ Playlists</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="pl-close"></button>
        </div>
    </div>
    <div class="window-body" id="pl-body">
        <!-- HOME VIEW -->
        <div id="pl-home">
            <div id="pl-search-row">
                <input type="text" id="pl-search-input" autocomplete="off"
                       placeholder="Buscar canciones, álbumes o artistas…">
            </div>
            <!-- Resultados de búsqueda (canciones / álbumes / artistas) y
                 página de artista. Oculto mientras no hay búsqueda. -->
            <div id="pl-search-results" style="display:none;"></div>
            <div id="pl-home-list"></div>
            <div id="pl-home-footer">
                <button class="button" id="pl-create">+ Crear playlist</button>
            </div>
        </div>
        <!-- EDITOR VIEW -->
        <div id="pl-editor-view">
            <div id="pl-name-row">
                <label for="pl-name-input">Nombre</label>
                <input type="text" id="pl-name-input" placeholder="Nombre de la playlist">
            </div>
            <div id="pl-image-row">
                <label for="pl-image-input">Imagen</label>
                <input type="url" id="pl-image-input" placeholder="URL de imagen (opcional)">
            </div>
            <div id="pl-editor-search-row">
                <input type="text" id="pl-editor-search-input" autocomplete="off"
                       placeholder="Filtrar canciones de esta playlist…">
            </div>
            <div id="pl-list"></div>
            <div id="pl-footer">
                <button class="button" id="pl-add">+ Añadir</button>
                <div id="pl-more-wrap">
                    <button class="button" id="pl-more">. . .</button>
                    <div id="pl-more-menu" class="window">
                        <div class="pl-menu-item" id="pl-more-import">Importar de YouTube</div>
                        <div class="pl-menu-item" id="pl-more-spotify">Importar de Spotify</div>
                        <div class="pl-menu-item" id="pl-more-tidal">Importar de Tidal</div>
                        <div class="pl-menu-item" id="pl-more-collab">Colaboradores</div>
                    </div>
                </div>
                <span style="flex:1"></span>
                <button class="button" id="pl-back">← Atrás</button>
                <button class="button" id="pl-save">Guardar</button>
            </div>
        </div>
    </div>
    <!-- Handles de resize (mismo patrón que el album-viewer). -->
    <div class="av-resize av-resize-n"  data-edge="n"></div>
    <div class="av-resize av-resize-s"  data-edge="s"></div>
    <div class="av-resize av-resize-w"  data-edge="w"></div>
    <div class="av-resize av-resize-e"  data-edge="e"></div>
    <div class="av-resize av-resize-nw" data-edge="nw"></div>
    <div class="av-resize av-resize-ne" data-edge="ne"></div>
    <div class="av-resize av-resize-sw" data-edge="sw"></div>
    <div class="av-resize av-resize-se" data-edge="se"></div>
</div>

<!-- ALBUM VIEWER — ventana de previsualización de un álbum.
     Se abre al hacer click en el título del track en el reproductor
     pequeño (cuando hay álbum resuelto) o en el nombre del álbum bajo
     un track de la lista del editor de playlist. NO se reproduce hasta
     que el usuario lo pide explícitamente con el botón "Reproducir
     álbum" o haciendo click en una canción concreta. -->
<div class="window" id="album-viewer">
    <div class="title-bar" id="album-viewer-titlebar">
        <div class="title-bar-text">
            <img src="assets/img/appIcons/musicaIcon.png" alt="" class="album-viewer-titlebar-icon">
            <span id="album-viewer-title-text">Álbum</span>
        </div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="album-viewer-close"></button>
        </div>
    </div>
    <div class="window-body" id="album-viewer-body">
        <div id="album-viewer-header">
            <!-- Cover clickable: izq → reproducir álbum; derecho → context
                 menu para añadirlo a playlist / perfil. -->
            <img id="album-viewer-cover" src="" alt="" title="Click derecho para más opciones">
            <div id="album-viewer-head-info">
                <div id="album-viewer-name">Cargando…</div>
                <div id="album-viewer-artist"></div>
                <div id="album-viewer-meta"></div>
            </div>
        </div>
        <div id="album-viewer-tracks"></div>
        <div id="album-viewer-footer">
            <button class="button" id="album-viewer-play" disabled>
                <span class="album-viewer-play-tri">▶</span> Reproducir álbum
            </button>
            <button class="button" id="album-viewer-download" disabled title="Descargar el álbum entero">⬇ Descargar</button>
            <!-- Solo con una playlist de otro abierta desde un enlace
                 (openSharedPlaylistViewer). -->
            <button class="button" id="album-viewer-save" style="display:none;">➕ Guardar en mis playlists</button>
        </div>
    </div>
    <!-- Resize handles por los 4 lados + 4 esquinas (8 direcciones).
         La esquina SE conserva el grip diagonal Win98; las demás son
         invisibles pero clickables. Min 280×220 para evitar colapso. -->
    <div class="av-resize av-resize-n"  data-edge="n"></div>
    <div class="av-resize av-resize-s"  data-edge="s"></div>
    <div class="av-resize av-resize-w"  data-edge="w"></div>
    <div class="av-resize av-resize-e"  data-edge="e"></div>
    <div class="av-resize av-resize-nw" data-edge="nw"></div>
    <div class="av-resize av-resize-ne" data-edge="ne"></div>
    <div class="av-resize av-resize-sw" data-edge="sw"></div>
    <div class="av-resize av-resize-se" data-edge="se"></div>
</div>

<!-- ARTIST WINDOW — todos los álbumes de un artista. -->
<div class="window" id="artist-window">
    <div class="title-bar" id="artist-window-titlebar">
        <div class="title-bar-text">
            <img src="assets/img/appIcons/musicaIcon.png" alt="" class="album-viewer-titlebar-icon">
            Artista
        </div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="artist-window-close"></button>
        </div>
    </div>
    <div class="window-body" id="artist-window-body">
        <div id="artist-window-banner">
            <div id="artist-window-banner-info">
                <div id="artist-window-banner-name">Artista</div>
                <div id="artist-window-listeners"></div>
            </div>
        </div>
        <div id="artist-window-content">
            <div class="aw-section-title">Popular</div>
            <div id="artist-window-top"></div>
            <div class="aw-section-title">Discografía</div>
            <div id="artist-window-tabs">
                <button class="aw-tab is-active" type="button" data-tab="popular">Popular releases</button>
                <button class="aw-tab" type="button" data-tab="album">Álbumes</button>
                <button class="aw-tab" type="button" data-tab="single">Singles y EPs</button>
            </div>
            <div id="artist-window-albums"></div>
        </div>
    </div>
    <!-- Resize handles por los 4 lados + 4 esquinas (mismo patrón que el album-viewer). -->
    <div class="av-resize av-resize-n"  data-edge="n"></div>
    <div class="av-resize av-resize-s"  data-edge="s"></div>
    <div class="av-resize av-resize-w"  data-edge="w"></div>
    <div class="av-resize av-resize-e"  data-edge="e"></div>
    <div class="av-resize av-resize-nw" data-edge="nw"></div>
    <div class="av-resize av-resize-ne" data-edge="ne"></div>
    <div class="av-resize av-resize-sw" data-edge="sw"></div>
    <div class="av-resize av-resize-se" data-edge="se"></div>
</div>

<!-- ADD TRACK DIALOG -->
<div class="window" id="add-track-dialog">
    <div class="title-bar">
        <div class="title-bar-text">+ Añadir canción</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="add-track-close"></button>
        </div>
    </div>
    <div class="window-body" id="add-track-body">
        <div class="field-row-stacked">
            <label for="add-yt-url">Enlace de YouTube, Spotify o Tidal</label>
            <input type="text" id="add-yt-url" placeholder="youtube.com/watch?v=… · open.spotify.com/track/… · tidal.com/track/…">
            <div id="add-title-preview-wrap"><span id="add-title-preview"></span></div>
        </div>
        <div class="field-row-stacked">
            <label for="add-artist">Artista</label>
            <input type="text" id="add-artist" placeholder="Nombre del artista">
        </div>
        <p id="add-track-error"></p>
        <div class="field-row" id="add-track-actions">
            <button class="button" id="add-track-cancel">Cancelar</button>
            <button class="button" id="add-track-submit">Añadir</button>
        </div>
    </div>
</div>

<!-- CREATE PLAYLIST DIALOG -->
<div class="window" id="create-playlist-dialog">
    <div class="title-bar">
        <div class="title-bar-text">+ Nueva playlist</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="create-pl-close"></button>
        </div>
    </div>
    <div class="window-body">
        <div class="field-row-stacked">
            <label for="create-pl-name">Nombre de la playlist</label>
            <input type="text" id="create-pl-name" placeholder="Mi playlist">
        </div>
        <div class="field-row-stacked" style="margin-top:8px;">
            <label for="create-pl-image">Imagen (URL, opcional)</label>
            <input type="url" id="create-pl-image" placeholder="https://…">
        </div>
        <div class="field-row" style="justify-content: flex-end; gap: 4px; margin-top: 8px;">
            <button class="button" id="create-pl-cancel">Cancelar</button>
            <button class="button" id="create-pl-submit">Crear</button>
        </div>
    </div>
</div>

<!-- EDIT PLAYLIST DIALOG (nombre + imagen) -->
<div class="window" id="edit-playlist-dialog">
    <div class="title-bar">
        <div class="title-bar-text">✎ Editar playlist</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="edit-pl-close"></button>
        </div>
    </div>
    <div class="window-body">
        <div class="field-row-stacked">
            <label for="edit-pl-name">Nombre</label>
            <input type="text" id="edit-pl-name" placeholder="Nombre de la playlist">
        </div>
        <div class="field-row-stacked" style="margin-top:8px;">
            <label for="edit-pl-image">Imagen (URL)</label>
            <input type="url" id="edit-pl-image" placeholder="https://…">
        </div>
        <div class="field-row" style="justify-content: flex-end; gap: 4px; margin-top: 8px;">
            <button class="button" id="edit-pl-cancel">Cancelar</button>
            <button class="button" id="edit-pl-save">Guardar</button>
        </div>
    </div>
    <div class="av-resize av-resize-n"  data-edge="n"></div>
    <div class="av-resize av-resize-s"  data-edge="s"></div>
    <div class="av-resize av-resize-w"  data-edge="w"></div>
    <div class="av-resize av-resize-e"  data-edge="e"></div>
    <div class="av-resize av-resize-nw" data-edge="nw"></div>
    <div class="av-resize av-resize-ne" data-edge="ne"></div>
    <div class="av-resize av-resize-sw" data-edge="sw"></div>
    <div class="av-resize av-resize-se" data-edge="se"></div>
</div>

<!-- IMPORT PLAYLIST DIALOG -->
<div class="window" id="import-playlist-dialog">
    <div class="title-bar">
        <div class="title-bar-text">Importar playlist de YouTube</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="import-pl-close"></button>
        </div>
    </div>
    <div class="window-body">
        <div class="field-row-stacked">
            <label for="import-pl-url">Enlace de playlist de YouTube</label>
            <input type="text" id="import-pl-url" placeholder="https://youtube.com/playlist?list=...">
        </div>
        <p id="import-pl-status"></p>
        <div class="field-row" style="justify-content: flex-end; gap: 4px;">
            <button class="button" id="import-pl-cancel">Cancelar</button>
            <button class="button" id="import-pl-submit">Importar</button>
        </div>
    </div>
</div>

<!-- COLLABORATOR DIALOG -->
<div class="window" id="collab-dialog">
    <div class="title-bar">
        <div class="title-bar-text">Colaboradores</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="collab-close"></button>
        </div>
    </div>
    <div class="window-body">
        <p style="margin:0 0 6px;font-size:11px;">Playlist: "<span id="collab-pl-name"></span>"</p>
        <div id="collab-user-list"></div>
        <p id="collab-status"></p>
        <div class="field-row" style="justify-content:flex-end;margin-top:6px;">
            <button class="button" id="collab-cancel">Cerrar</button>
        </div>
    </div>
</div>

<!-- SPOTIFY IMPORT DIALOG -->
<div class="window" id="spotify-import-dialog">
    <div class="title-bar">
        <div class="title-bar-text">Importar canciones</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="spotify-import-close"></button>
        </div>
    </div>
    <div class="window-body">
        <p id="spotify-import-hint" style="margin:0 0 6px;">
            Exporta tu playlist desde exportify.net y sube el CSV.
        </p>
        <div class="spotify-warn">
            ⚠️ La búsqueda en YouTube puede no coincidir exactamente con algunas canciones. Si alguna falla o es incorrecta, añádela tú mismo con el botón <strong>+ Añadir</strong> del editor de playlist.
        </div>
        <div class="field-row-stacked">
            <label for="spotify-file-name">Archivo CSV</label>
            <div class="field-row" style="gap:4px;">
                <input type="text" id="spotify-file-name" readonly placeholder="Sin archivo seleccionado" style="flex:1;min-width:0;cursor:default;">
                <button class="button" id="spotify-file-browse" style="min-width:70px;flex-shrink:0;margin-bottom:3px;">Examinar...</button>
            </div>
            <input type="file" id="spotify-import-file" accept=".csv,text/csv" style="display:none;">
        </div>
        <div id="spotify-progress-wrap" style="display:none;margin:6px 0 0;">
            <div class="progress-indicator segmented" style="height:18px;padding:2px;">
                <span id="spotify-progress-fill" class="progress-indicator-bar" style="width:0%;"></span>
            </div>
            <p id="spotify-progress-text" style="font-size:10px;margin:3px 0 0;text-align:center;"></p>
        </div>
        <p id="spotify-import-status"></p>
        <div class="field-row" style="justify-content: flex-end; gap: 4px; margin-top: 10px;">
            <button class="button" id="spotify-import-cancel">Cancelar</button>
            <button class="button" id="spotify-import-submit">Importar</button>
        </div>
    </div>
</div>

<!-- TIDAL IMPORT DIALOG -->
<div class="window" id="tidal-import-dialog">
    <div class="title-bar">
        <div class="title-bar-text">Importar de Tidal</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="tidal-import-close"></button>
        </div>
    </div>
    <div class="window-body">
        <p style="margin:0 0 6px;">
            Pega el enlace de una <strong>canción</strong> o <strong>playlist</strong> de Tidal.
        </p>
        <div class="spotify-warn">
            ⚠️ La búsqueda en YouTube puede no coincidir exactamente con algunas canciones. Si alguna falla o es incorrecta, añádela tú mismo con el botón <strong>+ Añadir</strong> del editor de playlist.
        </div>
        <div class="field-row-stacked">
            <label for="tidal-import-url">Enlace de Tidal</label>
            <input type="text" id="tidal-import-url" placeholder="https://tidal.com/browse/playlist/... o .../track/...">
        </div>
        <div id="tidal-progress-wrap" style="display:none;margin:6px 0 0;">
            <div class="progress-indicator segmented" style="height:18px;padding:2px;">
                <span id="tidal-progress-fill" class="progress-indicator-bar" style="width:0%;"></span>
            </div>
            <p id="tidal-progress-text" style="font-size:10px;margin:3px 0 0;text-align:center;"></p>
        </div>
        <p id="tidal-import-status"></p>
        <div class="field-row" style="justify-content: flex-end; gap: 4px; margin-top: 10px;">
            <button class="button" id="tidal-import-cancel">Cancelar</button>
            <button class="button" id="tidal-import-submit">Importar</button>
        </div>
    </div>
</div>


<!-- CONFIRM DIALOG -->
<div class="window" id="confirm-dialog" style="display:none;position:fixed;z-index:10003;top:50%;left:50%;transform:translate(-50%,-50%);width:280px;">
    <div class="title-bar">
        <div class="title-bar-text" id="confirm-dialog-title">Confirmar</div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <p id="confirm-dialog-msg" style="margin:0 0 12px;font-size:11px;"></p>
        <div class="field-row" style="justify-content:flex-end;gap:4px;">
            <button class="button" id="confirm-dialog-cancel">Cancelar</button>
            <button class="button" id="confirm-dialog-ok">Aceptar</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LISTEN TOGETHER — banner de estado + modal de invitar.
     ═══════════════════════════════════════════════════════════════ -->
<style>
    /* Indicador LIVE — punto pulsante con el accent del tema. Aparece
       sobre el cover cuando estás hosteando o eres guest. */
    @keyframes ltLivePulse {
        0%, 100% { opacity: 1;   transform: scale(1); }
        50%      { opacity: 0.3; transform: scale(0.75); }
    }
    #lt-banner {
        position: fixed;
        top: 4px; left: 50%;
        transform: translateX(-50%);
        background: var(--win-bg, silver);
        color: var(--text, #000);
        padding: 4px 10px;
        font-size: 11px;
        display: none;
        align-items: center;
        gap: 8px;
        z-index: 9000;
        box-shadow:
            inset -1px -1px var(--bezel-dark-1, #0a0a0a),
            inset  1px  1px var(--bezel-light-1, #fff),
            inset -2px -2px var(--bezel-dark-2, grey),
            inset  2px  2px var(--bezel-light-2, #dfdfdf);
        max-width: 90vw;
    }
    #lt-banner.visible { display: flex; }
    #lt-banner .lt-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--accent, #1db954);
        animation: ltBlink 1.4s ease-in-out infinite;
        flex-shrink: 0;
    }
    @keyframes ltBlink { 0%,100% { opacity: 0.4; } 50% { opacity: 1; } }
    /* Reset minimal — solo quita outlines/borders al click sin tocar
       altura ni line-height (esos vienen del CSS original y deben
       ser idénticos para los 3 items). */
    .pl-menu-item,
    .pl-menu-item:active,
    .pl-menu-item:focus,
    .pl-menu-item:focus-visible {
        outline: none !important;
        border: none !important;
        margin: 0 !important;
        -webkit-tap-highlight-color: transparent;
        user-select: none;
    }
    #lt-banner .lt-leave {
        background: var(--btn-bg, silver);
        color: var(--text, #000);
        border: 0; cursor: pointer;
        padding: 2px 8px; font-size: 11px;
        font-family: inherit;
        box-shadow:
            inset -1px -1px var(--bezel-dark-1, #0a0a0a),
            inset  1px  1px var(--bezel-light-1, #fff),
            inset -2px -2px var(--bezel-dark-2, grey),
            inset  2px  2px var(--bezel-light-2, #dfdfdf);
    }
    #lt-modal { width: 320px; max-width: 92vw; }
    #lt-modal .window-body { padding: 8px 10px; }
    #lt-modal .lt-section-title {
        font-size: 11px;
        font-weight: bold;
        margin: 4px 0 6px;
        opacity: 0.85;
    }
    #lt-user-list {
        max-height: 200px;
        overflow-y: auto;
        background: var(--input-bg, #fff);
        color: var(--input-text, #000);
        padding: 2px;
        box-shadow:
            inset  1px  1px var(--bezel-dark-2, grey),
            inset -1px -1px var(--bezel-light-1, #fff);
        margin-bottom: 8px;
    }
    .lt-user-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 4px 6px;
        font-size: 12px;
        cursor: default;
    }
    .lt-user-row + .lt-user-row {
        border-top: 1px dotted rgba(0,0,0,0.15);
    }
    .lt-user-row button {
        font-size: 11px; padding: 2px 8px;
        font-family: inherit;
        background: var(--btn-bg, silver);
        color: var(--text, #000);
        border: 0; cursor: pointer;
        box-shadow:
            inset -1px -1px var(--bezel-dark-1, #0a0a0a),
            inset  1px  1px var(--bezel-light-1, #fff),
            inset -2px -2px var(--bezel-dark-2, grey),
            inset  2px  2px var(--bezel-light-2, #dfdfdf);
    }
    .lt-user-row button.invited { opacity: 0.6; }
    .lt-status {
        font-size: 11px; opacity: 0.75; padding: 4px 0;
    }
</style>

<div id="lt-banner">
    <span class="lt-dot"></span>
    <span id="lt-banner-text"></span>
    <button class="lt-leave" id="lt-leave-btn">Salir</button>
</div>


<div class="window" id="lt-modal" data-no-auto-z style="display:none;position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:9995;">
    <div class="title-bar">
        <div class="title-bar-text">🎧 Escuchar juntos</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="lt-modal-close"></button>
        </div>
    </div>
    <div class="window-body">
        <div class="lt-section-title">Estado</div>
        <div class="lt-status" id="lt-status-text">No estás en ninguna sesión.</div>

        <div class="lt-section-title">Invitar a:</div>
        <div id="lt-user-list">
            <div class="lt-status" style="text-align:center;">Cargando usuarios…</div>
        </div>

        <div class="field-row" style="justify-content:flex-end;gap:4px;">
            <!-- Enlace "Escuchar conmigo" (enlace.php?escuchar=…): quien lo
                 abra se une aunque no seáis amigos. -->
            <button class="button" id="lt-link-btn" type="button">🔗 Copiar enlace</button>
            <button class="button" id="lt-end-btn" style="display:none;">Cerrar sesión</button>
        </div>
    </div>
    <div class="av-resize av-resize-n"  data-edge="n"></div>
    <div class="av-resize av-resize-s"  data-edge="s"></div>
    <div class="av-resize av-resize-w"  data-edge="w"></div>
    <div class="av-resize av-resize-e"  data-edge="e"></div>
    <div class="av-resize av-resize-nw" data-edge="nw"></div>
    <div class="av-resize av-resize-ne" data-edge="ne"></div>
    <div class="av-resize av-resize-sw" data-edge="sw"></div>
    <div class="av-resize av-resize-se" data-edge="se"></div>
</div>

<script>
<?php if ($hasPlayer): ?>
const playlist = <?php echo json_encode($youtubePlaylist); ?>;
var currentUserKey = '<?php echo $desktopUserKey; ?>';
var usersInfo = <?php
$_usersInfoArr = [];
foreach ($loginUsers as $_k => $_u) {
    $_usersInfoArr[$_k] = ['key' => $_k, 'label' => $_u['label'], 'img' => getUserImage($_u['label'])];
}
echo json_encode($_usersInfoArr);
?>;
var spotifyClientId   = '<?php echo SPOTIFY_CLIENT_ID; ?>';
/* Application ID de Discord (público): la app de escritorio lo usa para la
   presencia en Discord con lo que suena (electron/discord.js). */
var discordClientId   = <?php echo json_encode((string)env('DISCORD_CLIENT_ID', '')); ?>;

/* Estado del reproductor persistido en SQL (user_settings.key='player').
   Reemplaza la antigua copia en localStorage['melonOS_player']. La lectura
   inicial usa window.DesktopState (precargado por desktop-base) y los
   writes se hacen optimistas + POST debounced al servidor. */
var MelonPlayerState = (function(){
    var state = null;
    function get(){
        if (state) return state;
        if (window.DesktopState && window.DesktopState.player) state = window.DesktopState.player;
        return state;
    }
    var _t = null;
    function push(){
        clearTimeout(_t);
        _t = setTimeout(function(){
            fetch('assets/desktop/api.php?action=save-player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(state || {})
            }).catch(function(){});
        }, 200);
    }
    function setPlaylist(playlistId, trackIndex){
        state = { playlistId: playlistId, trackIndex: trackIndex|0 };
        if (window.DesktopState) window.DesktopState.player = state;
        push();
    }
    function setTrack(trackIndex){
        if (!state) state = { playlistId: null, trackIndex: 0 };
        state.trackIndex = trackIndex|0;
        if (window.DesktopState) window.DesktopState.player = state;
        push();
    }
    function setVolume(v){
        if (!state) state = { playlistId: null, trackIndex: 0 };
        state.volume = Math.max(0, Math.min(100, v|0));
        if (window.DesktopState) window.DesktopState.player = state;
        push();
    }
    return { get: get, setPlaylist: setPlaylist, setTrack: setTrack, setVolume: setVolume };
})();

let currentTrack      = 0;
let currentPlaylistId = null;
var currentPlaylistHasCollabs = false;

/* Última canción en este ordenador (app de escritorio y navegador): la
   cola entera y por dónde iba, para que al volver el reproductor salga
   con ella en pausa donde se quedó. MelonPlayerState (en el servidor)
   solo recuerda playlists guardadas y no la posición; esto vale también
   para álbumes, búsquedas y artistas. La posición va aparte porque se
   guarda cada pocos segundos y la cola puede ser larga. */
var MelonLastTrack = (function(){
    var KEY = 'reproductor:last:' + currentUserKey;
    var POS_KEY = KEY + ':pos';
    var title = '';
    function read() {
        try {
            var d = JSON.parse(localStorage.getItem(KEY) || 'null');
            return d && Array.isArray(d.queue) && d.queue.length ? d : null;
        } catch (_) { return null; }
    }
    function saveQueue(index) {
        if (!playlist.length || !playlist[index]) return;
        try {
            localStorage.setItem(KEY, JSON.stringify({
                queue: playlist.slice(), idx: index, title: title,
                playlistId: currentPlaylistId, hasCollabs: currentPlaylistHasCollabs
            }));
        } catch (_) { /* localStorage lleno: sin recordar */ }
    }
    function setTitle(name) {
        title = name || '';
        var d = read();
        if (!d) return;
        d.title = title;
        try { localStorage.setItem(KEY, JSON.stringify(d)); } catch (_) {}
    }
    function savePos() {
        var tr = playlist[currentTrack];
        if (!tr || !ytPlayer || !ytPlayer.getCurrentTime) return;
        /* Solo sonando o en pausa: recién recuperada (cued) YouTube dice
           0 y se perdería por dónde iba. */
        var st = -1;
        try { st = ytPlayer.getPlayerState(); } catch (_) {}
        if (st !== 1 && st !== 2) return;
        var cur = 0;
        try { cur = ytPlayer.getCurrentTime() || 0; } catch (_) {}
        try { localStorage.setItem(POS_KEY, JSON.stringify({ videoId: tr.videoId, pos: Math.floor(cur) })); } catch (_) {}
    }
    /* Segundo en que se quedó esa canción (0 si era otra). */
    function position(videoId) {
        try {
            var p = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
            return p && p.videoId === videoId ? (p.pos || 0) : 0;
        } catch (_) { return 0; }
    }
    /* Cerrar la app o la pestaña con música: que no se pierdan los
       últimos segundos. */
    window.addEventListener('pagehide', savePos);
    return { read: read, saveQueue: saveQueue, setTitle: setTitle, savePos: savePos, position: position };
})();

/* ── Escuchas recientes ──────────────────────────────────────────────
   Registra una escucha (canción / álbum / playlist) en el backend para
   la sección "Escuchas recientes" del menú. Best-effort, silencioso. */
function melonRecordRecent(type, key, name, image, artist) {
    if (!type || !key) return;
    try {
        fetch('assets/music/api.php?action=record-recent', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: type, key: String(key), name: name || '', image: image || '', artist: artist || '' }),
            keepalive: true,
        }).catch(function(){});
    } catch (_) {}
}

/* ── Medias de la comunidad (reseñas) para música ───────────────────
   Carga una vez (cacheado) el mapa de medias de canciones/álbumes
   reseñados desde el perfil. Sirve para pintar la nota a la derecha de
   cada resultado de búsqueda / canción del artista / pista de álbum. */
var _musicRatings = null, _musicRatingsPromise = null;
function _mrNorm(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }
function loadMusicRatings(force) {
    if (_musicRatings && !force) return Promise.resolve(_musicRatings);
    if (_musicRatingsPromise && !force) return _musicRatingsPromise;
    _musicRatingsPromise = fetch('assets/profile/api.php?action=music-ratings', { credentials: 'same-origin' })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
            _musicRatings = (d && d.ok) ? d : { songs:{}, albums:{}, songsTitle:{}, albumsTitle:{} };
            return _musicRatings;
        })
        .catch(function(){ _musicRatings = { songs:{}, albums:{}, songsTitle:{}, albumsTitle:{} }; return _musicRatings; });
    return _musicRatingsPromise;
}
/* Devuelve {avg,count} o null. Exige que coincidan título Y artista: dos
   álbumes/canciones con el mismo título pero distinto artista NO comparten
   nota. Solo si no se conoce el artista del resultado se cae a título solo. */
function mrLookup(kind, title, artist) {
    if (!_musicRatings) return null;
    var nt = _mrNorm(title); if (!nt) return null;
    var byTA = _musicRatings[kind] || {};
    var na = _mrNorm(artist);
    if (na) return byTA[nt + '\x1f' + na] || null;
    var byT = _musicRatings[kind === 'albums' ? 'albumsTitle' : 'songsTitle'] || {};
    return byT[nt] || null;
}
/* HTML del badge de nota (estrella + media). */
function mrBadge(rating) {
    if (!rating) return '';
    return '<span class="pl-sr-rating" title="' + rating.count + ' reseña' + (rating.count === 1 ? '' : 's') + '">★ ' + rating.avg.toFixed(1) + '</span>';
}
function mrAppendBadge(el, rating) { if (el && rating) el.insertAdjacentHTML('beforeend', mrBadge(rating)); }
/* Pinta las notas en las filas de resultados de búsqueda (canción/álbum). */
function decorateSearchRatings(container) {
    if (!container) return;
    loadMusicRatings().then(function(){
        container.querySelectorAll('.pl-sr-row[data-type="song"]').forEach(function(row){
            if (row.querySelector('.pl-sr-rating')) return;
            var r = mrLookup('songs', row.dataset.title, row.dataset.artist);
            if (!r) return;
            /* La nota va delante del tiempo de la canción. */
            var timeEl = row.querySelector('.pl-sr-time');
            if (timeEl) timeEl.insertAdjacentHTML('beforebegin', mrBadge(r));
            else row.insertAdjacentHTML('beforeend', mrBadge(r));
        });
        container.querySelectorAll('.pl-sr-row[data-type="album"]').forEach(function(row){
            if (row.querySelector('.pl-sr-rating')) return;
            mrAppendBadge(row, mrLookup('albums', row.dataset.name, row.dataset.artist));
        });
    });
}

/* ── Descargar la canción (solo en la app de escritorio) ──────────────
   Clic derecho → "⬇ Descargar…" abre esta ventana: formato (MP3 con su
   calidad, M4A original o WAV), dónde se guarda y una barra de progreso.
   La descarga la hace la app (electron/download.js); al terminar avisa el
   haro. El reproductor flotante tiene la misma ventana como panel. */
var DL_FORMATS = { mp3: 'MP3', m4a: 'M4A (original)', wav: 'WAV' };
function melonDownloadName(track, format) {
    var base = [track.artist, track.title].filter(Boolean).join(' - ')
        .replace(/[\\/:*?"<>|\x00-\x1f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'Canción';
    return base + '.' + format;
}
/* Texto de cada paso de la descarga ({ stage, percent }). */
function melonDownloadStep(p, format) {
    var pc = (p.percent == null) ? '' : ' ' + p.percent + ' %';
    if (p.stage === 'setup')    return 'Preparando el descargador (solo la primera vez)…' + pc;
    if (p.stage === 'update')   return 'Actualizando el descargador…';
    if (p.stage === 'download') return 'Descargando…' + pc;
    return format === 'm4a' ? 'Guardando…' : 'Convirtiendo a ' + format.toUpperCase() + '…';
}
function openDownloadDialog(track) {
    var md = window.melonDesktop;
    if (!track || !track.videoId || !md || !md.download) return;
    /* Carátula y álbum: los del reproductor si es la que suena; si es otra
       fila (playlist, búsqueda), la miniatura de YouTube en mqdefault (16:9
       sin franjas: la vista previa la recorta a cuadrado; hqdefault trae
       bandas negras arriba y abajo). */
    var cur = nowPlayingTrack();
    var playing = cur && cur.videoId === track.videoId;
    var info = {
        videoId: track.videoId, title: track.title || '', artist: track.artist || '',
        album: playing && _currentAlbum ? (_currentAlbum.albumName || '') : '',
        cover: (playing && playerCoverUrl()) || 'https://i.ytimg.com/vi/' + track.videoId + '/mqdefault.jpg',
    };
    var bd = document.createElement('div');
    bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:100000;display:flex;align-items:center;justify-content:center;';
    var win = document.createElement('div');
    win.className = 'window';
    win.style.cssText = 'width:340px;max-width:94vw;';
    win.innerHTML =
        '<div class="title-bar"><div class="title-bar-text">Descargar canción</div>' +
            '<div class="title-bar-controls"><button aria-label="Close" data-act="close"></button></div></div>' +
        '<div class="window-body" style="padding:10px;">' +
            '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">' +
                '<img data-dl="cover" alt="" style="width:48px;height:48px;object-fit:cover;flex:0 0 48px;background:var(--inset-bg,#222);">' +
                '<div style="min-width:0;flex:1;">' +
                    '<div data-dl="title" style="font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>' +
                    '<div data-dl="artist" style="font-size:10px;opacity:0.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>' +
                '</div>' +
            '</div>' +
            '<div class="field-row" style="margin-bottom:6px;"><label style="width:56px;">Formato:</label>' +
                '<select data-dl="format" style="flex:1;background-repeat:no-repeat;background-position:top 2px right 2px;"></select></div>' +
            '<div class="field-row" style="margin-bottom:8px;"><label style="width:56px;">Calidad:</label>' +
                '<select data-dl="kbps" style="flex:1;background-repeat:no-repeat;background-position:top 2px right 2px;"><option value="128">128 kbps</option><option value="192" selected>192 kbps</option><option value="320">320 kbps</option></select></div>' +
            '<div style="font-size:10px;line-height:1.4;">Se guarda en Descargas como</div>' +
            '<div data-dl="name" style="font-size:10px;line-height:1.4;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:8px;"></div>' +
            '<div class="progress-indicator" data-dl="bar" style="height:20px;padding:3px;display:none;"><span class="progress-indicator-bar" style="width:0%;background-color:var(--accent,navy);"></span></div>' +
            '<div data-dl="msg" style="font-size:10px;min-height:14px;margin-top:4px;"></div>' +
            '<div style="display:flex;justify-content:flex-end;gap:6px;margin-top:8px;">' +
                '<button class="button" data-act="cancel">Cancelar</button>' +
                '<button class="button" data-act="go">Descargar</button>' +
            '</div>' +
        '</div>';
    bd.appendChild(win);
    document.body.appendChild(bd);
    var q = function(k) { return win.querySelector('[data-dl="' + k + '"]'); };
    q('cover').src = info.cover;
    q('cover').onerror = function() { this.style.visibility = 'hidden'; };
    q('title').textContent = info.title;
    q('artist').textContent = info.artist;
    Object.keys(DL_FORMATS).forEach(function(f) {
        var o = document.createElement('option');
        o.value = f; o.textContent = DL_FORMATS[f];
        q('format').appendChild(o);
    });
    /* La calidad solo se elige en MP3: M4A va tal cual y WAV sin comprimir. */
    function refresh() {
        var f = q('format').value;
        q('kbps').disabled = f !== 'mp3';
        q('name').textContent = '"' + melonDownloadName(info, f) + '"';
    }
    q('format').addEventListener('change', refresh);
    refresh();

    var jobId = null;
    function close() {
        if (jobId) md.cancelDownload(jobId);
        document.removeEventListener('keydown', onKey, true);
        bd.remove();
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    document.addEventListener('keydown', onKey, true);
    win.querySelector('[data-act="close"]').addEventListener('click', close);
    win.querySelector('[data-act="cancel"]').addEventListener('click', close);

    var go = win.querySelector('[data-act="go"]');
    go.addEventListener('click', function() {
        var format = q('format').value;
        jobId = 'dl-' + Date.now();
        var myId = jobId;
        go.disabled = true;
        q('format').disabled = q('kbps').disabled = true;
        q('bar').style.display = '';
        q('msg').textContent = 'Empezando…';
        var bar = q('bar').firstChild;
        melonDownloadListen(myId, function(p) {
            bar.style.width = (p.percent == null ? 100 : p.percent) + '%';
            q('msg').textContent = melonDownloadStep(p, format);
        });
        md.download(Object.assign({ id: myId, format: format, kbps: Number(q('kbps').value) }, info)).then(function(r) {
            melonDownloadListen(myId, null);
            if (jobId !== myId) return;   /* cancelada (ventana cerrada) */
            jobId = null;
            if (r && r.ok) {
                close();
                if (window.notifSystem) notifSystem.show({ type: 'success', title: 'Canción descargada', message: 'Guardada en Descargas: ' + r.name });
                return;
            }
            go.disabled = false;
            q('format').disabled = false;
            refresh();
            q('bar').style.display = 'none';
            q('msg').textContent = r && r.cancelled ? '' : '✕ ' + ((r && r.error) || 'No se pudo descargar');
        });
    });
}
/* Un único oyente del progreso para todas las descargas de esta página. */
var _dlListeners = {}, _dlHooked = false;
function melonDownloadListen(id, cb) {
    if (!_dlHooked && window.melonDesktop && melonDesktop.onDownloadProgress) {
        _dlHooked = true;
        melonDesktop.onDownloadProgress(function(p) { if (_dlListeners[p.id]) _dlListeners[p.id](p); });
    }
    if (cb) _dlListeners[id] = cb; else delete _dlListeners[id];
}
window.openDownloadDialog = openDownloadDialog;

/* "Descargar…" fuera de la app: explica que solo funciona en la app de
   escritorio y ofrece su instalable (el mismo que el aviso del haro,
   assets/desktop/download.php). Una app vieja, sin descargas, pide
   actualizarla. */
var DL_APP_SYSTEMS = <?php echo json_encode(function_exists('desktopDownloadSystems') ? desktopDownloadSystems() : []); ?>;
function openGetAppDialog() {
    var platform = ((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '') + ' ' + navigator.userAgent;
    var os = /Win/i.test(platform) ? 'windows'
           : (/Linux/i.test(platform) && !/Android|CrOS/i.test(platform)) ? 'linux' : '';
    var canGet = !window.melonDesktop && os && DL_APP_SYSTEMS.indexOf(os) !== -1;
    var text = window.melonDesktop
        ? 'Tu app de escritorio es de una versión sin descargas. Actualízala desde el aviso del haro (o vuelve a abrirla) y podrás descargar canciones.'
        : canGet
            ? 'La descarga de canciones solo funciona en la app de escritorio de Melon Hub: desde el navegador no se puede bajar el audio de YouTube. ¿Quieres descargar la app para ' + (os === 'windows' ? 'Windows' : 'Linux') + '?'
            : 'La descarga de canciones solo funciona en la app de escritorio de Melon Hub, que está disponible para Windows y Linux.';
    var bd = document.createElement('div');
    bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:100000;display:flex;align-items:center;justify-content:center;';
    var win = document.createElement('div');
    win.className = 'window';
    win.style.cssText = 'width:320px;max-width:94vw;';
    win.innerHTML =
        '<div class="title-bar"><div class="title-bar-text">Descargar canción</div>' +
            '<div class="title-bar-controls"><button aria-label="Close" data-act="close"></button></div></div>' +
        '<div class="window-body" style="padding:10px;">' +
            '<div style="display:flex;gap:10px;align-items:flex-start;">' +
                '<img src="assets/img/appIcons/downloadIcon.png" alt="" style="width:32px;height:32px;object-fit:contain;image-rendering:pixelated;flex:0 0 32px;">' +
                '<p data-dl="text" style="margin:0;line-height:1.45;"></p>' +
            '</div>' +
            '<div style="display:flex;justify-content:flex-end;gap:6px;margin-top:12px;">' +
                '<button class="button" data-act="close">' + (canGet ? 'Ahora no' : 'Cerrar') + '</button>' +
                (canGet ? '<button class="button" data-act="get">Descargar la app</button>' : '') +
            '</div>' +
        '</div>';
    win.querySelector('[data-dl="text"]').textContent = text;
    bd.appendChild(win);
    document.body.appendChild(bd);
    function close() { document.removeEventListener('keydown', onKey, true); bd.remove(); }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    document.addEventListener('keydown', onKey, true);
    win.querySelectorAll('[data-act="close"]').forEach(function(b) { b.addEventListener('click', close); });
    var get = win.querySelector('[data-act="get"]');
    if (get) get.addEventListener('click', function() {
        /* Iframe oculto, como el haro: la descarga empieza sin salir del escritorio. */
        var f = document.createElement('iframe');
        f.style.display = 'none';
        f.src = 'assets/desktop/download.php?os=' + os;
        document.body.appendChild(f);
        setTimeout(function() { f.remove(); }, 60000);
        close();
    });
}

/* "⬇ Descargar todo…" de una playlist (clic derecho en su tarjeta) o un
   álbum (botón del visor): la misma ventana que una canción, con el formato
   elegido una vez para todas. Se descargan de una en una en
   Descargas/<nombre>/ ("3 de 12"); las que fallan se apuntan y se sigue
   con las demás. set: { name, cover, album, tracks: [{ videoId, title,
   artist }] }. */
function openBulkDownloadDialog(set) {
    var md = window.melonDesktop;
    if (!md || !md.download) { openGetAppDialog(); return; }
    var tracks = (set.tracks || []).filter(function(t) { return t && t.videoId; });
    if (!tracks.length) return;
    var folder = String(set.name || 'Melon Hub').replace(/[\\/:*?"<>|\x00-\x1f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'Melon Hub';
    var bd = document.createElement('div');
    bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:100000;display:flex;align-items:center;justify-content:center;';
    var win = document.createElement('div');
    win.className = 'window';
    win.style.cssText = 'width:360px;max-width:94vw;';
    var sel = 'flex:1;background-repeat:no-repeat;background-position:top 2px right 2px;';
    win.innerHTML =
        '<div class="title-bar"><div class="title-bar-text">Descargar todo</div>' +
            '<div class="title-bar-controls"><button aria-label="Close" data-act="close"></button></div></div>' +
        '<div class="window-body" style="padding:10px;">' +
            '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">' +
                '<img data-dl="cover" alt="" style="width:48px;height:48px;object-fit:cover;flex:0 0 48px;background:var(--inset-bg,#222);">' +
                '<div style="min-width:0;flex:1;">' +
                    '<div data-dl="title" style="font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>' +
                    '<div data-dl="count" style="font-size:10px;opacity:0.75;"></div>' +
                '</div>' +
            '</div>' +
            '<div class="field-row" style="margin-bottom:6px;"><label style="width:56px;">Formato:</label>' +
                '<select data-dl="format" style="' + sel + '"></select></div>' +
            '<div class="field-row" style="margin-bottom:8px;"><label style="width:56px;">Calidad:</label>' +
                '<select data-dl="kbps" style="' + sel + '"><option value="128">128 kbps</option><option value="192" selected>192 kbps</option><option value="320">320 kbps</option></select></div>' +
            '<div style="font-size:10px;line-height:1.4;">Se guardan en la carpeta</div>' +
            '<div data-dl="folder" style="font-size:10px;line-height:1.4;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:8px;"></div>' +
            '<div class="progress-indicator" data-dl="bar" style="height:20px;padding:3px;display:none;"><span class="progress-indicator-bar" style="width:0%;background-color:var(--accent,navy);"></span></div>' +
            '<div data-dl="msg" style="font-size:10px;min-height:14px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>' +
            '<div data-dl="failed" class="sunken-panel" style="display:none;max-height:80px;overflow-y:auto;margin-top:4px;padding:3px 5px;font-size:10px;line-height:1.4;"></div>' +
            '<div style="display:flex;justify-content:flex-end;gap:6px;margin-top:8px;">' +
                '<button class="button" data-act="cancel">Cancelar</button>' +
                '<button class="button" data-act="go">Descargar</button>' +
            '</div>' +
        '</div>';
    bd.appendChild(win);
    document.body.appendChild(bd);
    var q = function(k) { return win.querySelector('[data-dl="' + k + '"]'); };
    q('cover').src = set.cover || ('https://i.ytimg.com/vi/' + tracks[0].videoId + '/mqdefault.jpg');
    q('cover').onerror = function() { this.style.visibility = 'hidden'; };
    q('title').textContent = set.name || '';
    q('count').textContent = tracks.length + (tracks.length === 1 ? ' canción' : ' canciones');
    q('folder').textContent = 'Descargas/' + folder + '/';
    Object.keys(DL_FORMATS).forEach(function(f) {
        var o = document.createElement('option');
        o.value = f; o.textContent = DL_FORMATS[f];
        q('format').appendChild(o);
    });
    q('format').addEventListener('change', function() { q('kbps').disabled = this.value !== 'mp3'; });

    var running = false, cancelled = false, currentId = null;
    function close() {
        if (running) { cancelled = true; if (currentId) md.cancelDownload(currentId); }
        document.removeEventListener('keydown', onKey, true);
        bd.remove();
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    document.addEventListener('keydown', onKey, true);
    win.querySelector('[data-act="close"]').addEventListener('click', close);
    win.querySelector('[data-act="cancel"]').addEventListener('click', close);

    var go = win.querySelector('[data-act="go"]');
    go.addEventListener('click', function() {
        var format = q('format').value, kbps = Number(q('kbps').value);
        var bar = q('bar').firstChild, failed = [], done = 0;
        running = true;
        go.disabled = q('format').disabled = q('kbps').disabled = true;
        q('bar').style.display = '';
        (function next(i) {
            if (cancelled) return;
            if (i >= tracks.length) { finish(); return; }
            var t = tracks[i], label = (i + 1) + ' de ' + tracks.length + ' · ' + (t.title || '');
            currentId = 'dl-' + Date.now() + '-' + i;
            var id = currentId;
            q('msg').textContent = label;
            melonDownloadListen(id, function(p) {
                bar.style.width = (100 * (i + (p.percent == null ? 0.95 : p.percent / 100)) / tracks.length) + '%';
                q('msg').textContent = label + ' — ' + melonDownloadStep(p, format);
            });
            md.download({
                id: id, videoId: t.videoId, title: t.title || '', artist: t.artist || '', format: format, kbps: kbps, folder: folder,
                /* En un álbum todas llevan su nombre y su carátula; en una
                   playlist, cada una su miniatura de YouTube. */
                album: set.album || '',
                cover: set.album && set.cover ? set.cover : 'https://i.ytimg.com/vi/' + t.videoId + '/mqdefault.jpg',
            }).then(function(r) {
                melonDownloadListen(id, null);
                if (cancelled || (r && r.cancelled)) return;
                if (r && r.ok) done++;
                else failed.push((t.title || t.videoId) + ': ' + ((r && r.error) || 'no se pudo descargar'));
                bar.style.width = (100 * (i + 1) / tracks.length) + '%';
                next(i + 1);
            });
        })(0);
        function finish() {
            running = false;
            currentId = null;
            var msg = done + ' de ' + tracks.length + ' guardadas en Descargas/' + folder + '/';
            if (window.notifSystem) notifSystem.show({ type: failed.length ? 'info' : 'success', title: 'Descarga terminada', message: msg });
            if (!failed.length) { close(); return; }
            /* Con fallos, la ventana se queda para enseñar cuáles. */
            q('msg').textContent = '✓ ' + msg + ' · ✕ ' + failed.length + ' no se pudieron descargar:';
            q('failed').textContent = failed.join('\n');
            q('failed').style.whiteSpace = 'pre-line';
            q('failed').style.display = '';
            go.style.display = 'none';
            win.querySelector('[data-act="cancel"]').textContent = 'Cerrar';
        }
    });
}
window.openBulkDownloadDialog = openBulkDownloadDialog;

/* Reproduce una sola canción reemplazando la cola actual (usado por la
   búsqueda y por la sección de recientes). */
function melonPlaySong(tr) {
    if (!tr || !tr.videoId || typeof playlist === 'undefined') return;
    playlist.length = 0;
    playlist.push({ videoId: tr.videoId, title: tr.title || '', artist: tr.artist || '', duration: tr.duration || 0 });
    currentTrack = 0;
    currentPlaylistId = null;
    if (typeof updateTrackUI === 'function') updateTrackUI(0);
    if (typeof updatePlayerTitle === 'function') updatePlayerTitle(tr.title || 'Búsqueda');
    try { if (ytPlayer && ytPlayer.loadVideoById) ytPlayer.loadVideoById(tr.videoId); } catch (_) {}
    melonRecordRecent('song', tr.videoId, tr.title || '',
        'https://i.ytimg.com/vi/' + tr.videoId + '/mqdefault.jpg', tr.artist || '');
}

/* ── Ventana de ARTISTA (todos sus álbumes) ──────────────────────────
   Abierta al hacer click en el nombre del artista (reproductor, lista de
   playlist o visor de álbum). Resuelve el nombre → artista (search-artists),
   carga sus álbumes (artist-albums) y los muestra; click en un álbum abre
   su visor. */
var _artistWinToken = 0;
function closeArtistWindow() {
    var w = document.getElementById('artist-window');
    if (!w) return;
    if (window.taskbarManager) { try { taskbarManager.unregister('artist-window'); } catch (_) {} }
    w.style.display = 'none';
    _artistWinToken++;
}
function _awEsc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
/* Reproduce una canción del "Popular" resolviéndola a YouTube por nombre. */
function playArtistTrackByName(title, artist) {
    var q = (title + ' ' + (artist || '')).trim();
    fetch('assets/music/api.php?action=yt-search&q=' + encodeURIComponent(q))
    .then(function(r){ return r.json(); })
    .then(function(d){
        var res = (d && d.results) || [];
        if (res.length) melonPlaySong({ videoId: res[0].videoId, title: title, artist: artist || res[0].artist, duration: res[0].duration });
    }).catch(function(){});
}
/* Estado de la discografía (compartido con las pestañas). */
var _awAlbums = [], _awArtistName = '', _awTab = 'popular';
function _awBuildAlbumCard(a) {
    var card = document.createElement('div'); card.className = 'pl-sr-album-card';
    var img = document.createElement('img'); img.src = a.image || ''; img.alt = '';
    img.onerror = function(){ this.style.visibility = 'hidden'; };
    var nm = document.createElement('div'); nm.className = 'pl-sr-name';
    nm.textContent = a.name + (a.year ? (' (' + a.year + ')') : '');
    card.appendChild(img); card.appendChild(nm);
    /* Nota media del álbum (reseña propia del álbum) bajo el nombre. */
    loadMusicRatings().then(function(){
        if (card.querySelector('.pl-sr-rating')) return;
        var r = mrLookup('albums', a.name, _awArtistName);
        if (r) card.insertAdjacentHTML('beforeend', mrBadge(r));
    });
    card.addEventListener('click', function(){
        if (typeof openAlbumViewer === 'function') openAlbumViewer(a.albumKey, a.name, _awArtistName || '', a.image || '');
        melonRecordRecent('album', a.albumKey, a.name, a.image, _awArtistName || '');
    });
    /* Click derecho → añadir el álbum a una playlist o al perfil. */
    card.addEventListener('contextmenu', function(e){
        e.preventDefault();
        if (typeof showSearchAlbumCtxMenu === 'function') {
            showSearchAlbumCtxMenu(e.clientX, e.clientY, { albumKey: a.albumKey, name: a.name, artist: _awArtistName || '', image: a.image || '' });
        }
    });
    return card;
}
function _awApplyTab() {
    var el = document.getElementById('artist-window-albums');
    if (!el) return;
    var list = _awAlbums;
    if (_awTab === 'album')       list = _awAlbums.filter(function(a){ return (a.type || 'album') === 'album'; });
    else if (_awTab === 'single') list = _awAlbums.filter(function(a){ return a.type === 'single' || a.type === 'ep'; });
    /* 'popular' → todas. */
    document.querySelectorAll('#artist-window-tabs .aw-tab').forEach(function(b){
        b.classList.toggle('is-active', b.dataset.tab === _awTab);
    });
    if (!list.length) { el.innerHTML = '<div class="pl-sr-msg">Nada en esta sección.</div>'; return; }
    var grid = document.createElement('div'); grid.className = 'pl-sr-albums-grid';
    list.forEach(function(a){ grid.appendChild(_awBuildAlbumCard(a)); });
    el.innerHTML = ''; el.appendChild(grid);
}
function openArtistWindow(name) {
    name = (name || '').replace(/\s*-\s*topic$/i, '').trim();
    if (!name || name === '—') return;
    var win = document.getElementById('artist-window');
    if (!win) return;
    var banner   = document.getElementById('artist-window-banner');
    var bName    = document.getElementById('artist-window-banner-name');
    var listenEl = document.getElementById('artist-window-listeners');
    var topEl    = document.getElementById('artist-window-top');
    var albEl    = document.getElementById('artist-window-albums');
    var token    = ++_artistWinToken;
    var fmtNum = function(n){ return (parseInt(n,10) || 0).toLocaleString('es-ES'); };
    bName.textContent = name;
    if (banner) banner.style.backgroundImage = '';
    if (listenEl) listenEl.textContent = '';
    topEl.innerHTML = '<div class="pl-sr-msg">Cargando…</div>';
    albEl.innerHTML = '';
    /* El body vuelve arriba al abrir otro artista. */
    var body = document.getElementById('artist-window-body'); if (body) body.scrollTop = 0;
    if (window.taskbarManager) {
        if (taskbarManager.isRegistered('artist-window')) taskbarManager.restore('artist-window');
        else taskbarManager.register('artist-window', 'Artista', '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
    } else { win.style.display = 'flex'; }

    var _n = function(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim(); };

    function renderTop(d, artistName) {
        var tracks = (d && d.tracks) || [];
        if (!tracks.length) { topEl.innerHTML = '<div class="pl-sr-msg">Sin canciones populares.</div>'; return; }
        var html = '';
        tracks.forEach(function(t, i){
            html += '<div class="aw-top-row" data-title="'+_awEsc(t.title)+'" data-artist="'+_awEsc(t.artist||artistName)+'">' +
                        '<span class="aw-top-num">'+(i+1)+'</span>' +
                        '<img class="aw-top-thumb" src="'+_awEsc(t.image||'')+'" alt="" onerror="this.style.visibility=\'hidden\'">' +
                        '<span class="aw-top-title">'+_awEsc(t.title)+'</span>' +
                        '<span class="aw-top-plays">'+(t.rank?fmtNum(t.rank):'')+'</span>' +
                        '<span class="aw-top-dur">'+(t.duration?formatTime(t.duration):'')+'</span>' +
                    '</div>';
        });
        topEl.innerHTML = html;
        topEl.querySelectorAll('.aw-top-row').forEach(function(row){
            row.addEventListener('click', function(){ playArtistTrackByName(row.dataset.title, row.dataset.artist); });
            /* Click derecho → resolver en YouTube y abrir menú de track. */
            row.addEventListener('contextmenu', function(e){
                e.preventDefault();
                _resolveAndOpenTrackCtx(row.dataset.title, row.dataset.artist, e.clientX, e.clientY);
            });
        });
        /* Nota media de la comunidad, delante del tiempo de cada canción. */
        loadMusicRatings().then(function(){
            topEl.querySelectorAll('.aw-top-row').forEach(function(row){
                if (row.querySelector('.pl-sr-rating')) return;
                var r = mrLookup('songs', row.dataset.title, row.dataset.artist);
                if (!r) return;
                var durEl = row.querySelector('.aw-top-dur');
                if (durEl) durEl.insertAdjacentHTML('beforebegin', mrBadge(r));
                else row.insertAdjacentHTML('beforeend', mrBadge(r));
            });
        });
    }

    function renderAlbums(d, artistName) {
        var albums = (d && d.ok && d.albums) ? d.albums : [];
        var seen = {}, list = [];
        albums.forEach(function(a){ if (!a.image) return; var k = _n(a.name) + '|' + (a.year||''); if (seen[k]) return; seen[k] = 1; list.push(a); });
        _awAlbums = list; _awArtistName = artistName || ''; _awTab = 'popular';
        if (!list.length) { albEl.innerHTML = '<div class="pl-sr-msg">No se encontraron álbumes.</div>'; return; }
        _awApplyTab();
    }

    fetch('assets/music/api.php?action=search-artists&q=' + encodeURIComponent(name))
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (token !== _artistWinToken) return;
        var arts = (d && d.ok && d.results) || [];
        var nn = _n(name), chosen = null;
        arts.forEach(function(a){ if (!chosen && _n(a.name) === nn && a.image) chosen = a; });
        if (!chosen) arts.forEach(function(a){ if (!chosen && _n(a.name) === nn) chosen = a; });
        if (!chosen) chosen = arts[0];
        if (!chosen) { topEl.innerHTML = '<div class="pl-sr-msg">No se encontró el artista.</div>'; return; }
        bName.textContent = chosen.name;
        var big = chosen.imageBig || chosen.image;
        if (banner && big) banner.style.backgroundImage = "url('" + big.replace(/'/g, "%27") + "')";
        if (listenEl) listenEl.textContent = chosen.fans ? (fmtNum(chosen.fans) + ' oyentes') : '';
        Promise.all([
            fetch('assets/music/api.php?action=artist-top&name=' + encodeURIComponent(chosen.name)).then(function(r){ return r.json(); }).catch(function(){ return null; }),
            fetch('assets/music/api.php?action=artist-albums&source=' + encodeURIComponent(chosen.source) + '&artistId=' + encodeURIComponent(chosen.artistId)).then(function(r){ return r.json(); }).catch(function(){ return null; })
        ]).then(function(res){
            if (token !== _artistWinToken) return;
            renderTop(res[0], chosen.name);
            renderAlbums(res[1], chosen.name);
        });
    })
    .catch(function(){ if (token === _artistWinToken) { topEl.innerHTML = '<div class="pl-sr-msg">Error cargando el artista.</div>'; } });
}
window.openArtistWindow = openArtistWindow;
/* Exponemos el viewer de álbum para que otras apps (p.ej. Melon Reviews
   del perfil) puedan abrir la ventana del álbum. openAlbumViewer es una
   function declaration → está hoisted aunque se defina más abajo. */
window.openAlbumViewer = openAlbumViewer;

/* Re-renderiza la ventana del artista si está abierta. Se llama tras
   corregir una canción para que las populares/carátulas reflejen al
   instante los datos corregidos (artist-top aplica los overrides en el
   backend). Usa el nombre del artista mostrado en la cabecera. */
window.refreshArtistWindowIfOpen = function() {
    var win = document.getElementById('artist-window');
    if (!win || win.style.display === 'none') return;
    var bName = document.getElementById('artist-window-banner-name');
    var name  = bName ? bName.textContent.trim() : '';
    if (name && name !== 'Artista') openArtistWindow(name);
};

/* ── Drag + resize reutilizable para ventanas del reproductor ──
   Arrastra por su .title-bar y redimensiona por los handles .av-resize
   (8 lados/esquinas). Mismo patrón Pointer-Events que el album-viewer.
   Convierte el centrado por transform en left/top absolutos al primer
   gesto para mover/redimensionar sin saltos. Idempotente por ventana. */
function _winDragResize(winId, minW, minH) {
    var win = document.getElementById(winId);
    if (!win || win.dataset.dragResizeWired) return;
    win.dataset.dragResizeWired = '1';
    var MIN_W = minW || 260, MIN_H = minH || 180;

    function fixPos() {
        var r = win.getBoundingClientRect();
        win.style.left = r.left + 'px';
        win.style.top  = r.top  + 'px';
        win.style.transform = 'none';
        return r;
    }

    /* ── Drag por la title-bar ── */
    var titlebar = win.querySelector('.title-bar');
    if (titlebar) {
        var dragging = false, ox = 0, oy = 0, dpid = -1;
        titlebar.addEventListener('pointerdown', function(e){
            if (e.target.closest('.title-bar-controls') || e.target.tagName === 'BUTTON') return;
            dragging = true; dpid = e.pointerId;
            try { titlebar.setPointerCapture(dpid); } catch (_) {}
            var r = fixPos();
            ox = e.clientX - r.left; oy = e.clientY - r.top;
        });
        titlebar.addEventListener('pointermove', function(e){
            if (!dragging || e.pointerId !== dpid) return;
            var nl = Math.max(0, Math.min(e.clientX - ox, window.innerWidth  - 60));
            var nt = Math.max(0, Math.min(e.clientY - oy, window.innerHeight - 24));
            win.style.left = nl + 'px'; win.style.top = nt + 'px';
        });
        var dend = function(e){
            if (e && e.pointerId !== dpid) return;
            dragging = false;
            try { titlebar.releasePointerCapture(e ? e.pointerId : dpid); } catch (_) {}
            dpid = -1;
        };
        titlebar.addEventListener('pointerup', dend);
        titlebar.addEventListener('pointercancel', dend);
    }

    /* ── Resize por los handles .av-resize ── */
    var handles = win.querySelectorAll('.av-resize');
    if (handles.length) {
        var rpid = -1, edge = '', sx = 0, sy = 0, sw = 0, sh = 0, sl = 0, st = 0, ah = null;
        var rdown = function(e){
            e.preventDefault(); e.stopPropagation();
            ah = e.currentTarget; edge = ah.dataset.edge || ''; rpid = e.pointerId;
            try { ah.setPointerCapture(rpid); } catch (_) {}
            var r = fixPos();
            sx = e.clientX; sy = e.clientY; sw = win.offsetWidth; sh = win.offsetHeight; sl = r.left; st = r.top;
        };
        var rmove = function(e){
            if (e.pointerId !== rpid) return;
            var dx = e.clientX - sx, dy = e.clientY - sy, nw = sw, nh = sh, nl = sl, nt = st;
            if (edge.indexOf('e') !== -1) nw = Math.max(MIN_W, sw + dx);
            if (edge.indexOf('w') !== -1) { nw = Math.max(MIN_W, sw - dx); nl = sl + (sw - nw); }
            if (edge.indexOf('s') !== -1) nh = Math.max(MIN_H, sh + dy);
            if (edge.indexOf('n') !== -1) { nh = Math.max(MIN_H, sh - dy); nt = st + (sh - nh); }
            nw = Math.min(nw, window.innerWidth  - 16);
            nh = Math.min(nh, window.innerHeight - 16);
            nl = Math.max(8, Math.min(nl, window.innerWidth  - nw - 8));
            nt = Math.max(8, Math.min(nt, window.innerHeight - nh - 8));
            win.style.width = nw + 'px'; win.style.height = nh + 'px';
            win.style.left = nl + 'px'; win.style.top = nt + 'px';
        };
        var rup = function(e){
            if (e && e.pointerId !== rpid) return;
            try { ah && ah.releasePointerCapture(e ? e.pointerId : rpid); } catch (_) {}
            rpid = -1; ah = null; edge = '';
        };
        handles.forEach(function(h){
            h.addEventListener('pointerdown',   rdown);
            h.addEventListener('pointermove',   rmove);
            h.addEventListener('pointerup',     rup);
            h.addEventListener('pointercancel', rup);
        });
    }
}
/* Ventanas del reproductor que no pasan por el WindowManager del desktop
   → arrastrables + redimensionables aquí. (album-viewer y playlist-editor
   ya tienen su propio wiring; el resto va por el WindowManager.) */
_winDragResize('artist-window', 300, 260);
_winDragResize('edit-playlist-dialog', 260, 180);
_winDragResize('lt-modal', 260, 200);

(function(){
    var c = document.getElementById('artist-window-close');
    if (c) c.addEventListener('click', closeArtistWindow);
    /* Pestañas de la discografía. */
    document.querySelectorAll('#artist-window-tabs .aw-tab').forEach(function(b){
        b.addEventListener('click', function(){ _awTab = b.dataset.tab; _awApplyTab(); });
    });
    /* Click en el artista de la cabecera del visor de álbum → su ventana. */
    var av = document.getElementById('album-viewer-artist');
    if (av) {
        av.classList.add('artist-link');
        av.addEventListener('click', function(){
            var a = (av.textContent || '').trim();
            if (a && typeof openArtistWindow === 'function') openArtistWindow(a);
        });
    }
})();

let ytPlayer = null;
let progressInterval  = null;
let autoplayRandom    = false;
/* Historial de lo escuchado para el modo aleatorio: "anterior" vuelve a la
   canción que sonaba antes (no a la de antes en la lista) y "siguiente",
   después de volver atrás, rehace el mismo camino en vez de sortear otra.
   Cada entrada guarda la playlist y el videoId de la canción: si la cola ya
   no es la misma, se descarta. */
let shuffleBack = [], shuffleForward = [], shuffleNow = null, shuffleNav = false;
function rememberListened(index, track) {
    const entry = { pl: currentPlaylistId, idx: index, vid: track.videoId };
    if (shuffleNow && shuffleNow.vid === entry.vid && shuffleNow.pl === entry.pl) return;   /* repintado de la misma */
    if (!shuffleNav && shuffleNow) {
        shuffleBack.push(shuffleNow);
        if (shuffleBack.length > 200) shuffleBack.shift();
        shuffleForward = [];
    }
    shuffleNow = entry;
}
/* Saca del historial la última canción que siga en la cola (-1 si ninguna). */
function historyStep(stack) {
    while (stack.length) {
        const e = stack.pop();
        if (e.pl === currentPlaylistId && playlist[e.idx] && playlist[e.idx].videoId === e.vid) return e.idx;
    }
    return -1;
}
/* Siguiente en aleatorio: la del historial si se había vuelto atrás; si no,
   una al azar distinta de la actual. */
function nextShuffleIndex() {
    const fwd = historyStep(shuffleForward);
    if (fwd !== -1) {
        if (shuffleNow) shuffleBack.push(shuffleNow);
        shuffleNav = true;   /* rememberListened no debe borrar el resto del camino */
        return fwd;
    }
    let next;
    do { next = Math.floor(Math.random() * playlist.length); } while (next === currentTrack);
    return next;
}

/* Publica el track actual a save-now-playing para que (1) la TV lo lea
   por polling y (2) el apartado social del perfil muestre "escuchando
   ahora". El backend solo considera la canción si is_playing=1 y el
   update tiene < 90s, así que reportamos en cada play/pause/cambio de
   pista (force) y periódicamente desde startProgress (throttled). */
var __NP_LAST = 0;
/* Como GUEST de una sesión de escucha, lo que suena es el track de la
   sesión (el del host), NO el de mi playlist local. */
function nowPlayingTrack() {
    return (typeof LT_ROLE !== 'undefined' && LT_ROLE === 'guest' && _ltGuestTrack && _ltGuestTrack.videoId)
        ? _ltGuestTrack
        : ((typeof playlist !== 'undefined' && playlist[currentTrack]) || null);
}
function publishNowPlaying(isPlaying, force) {
    syncSystemMedia(isPlaying);
    var tr = nowPlayingTrack();
    if (!tr || !tr.videoId) return;
    var now = Date.now();
    if (!force && now - __NP_LAST < 2000) return;
    __NP_LAST = now;
    var pos = 0, dur = 0;
    try { pos = ytPlayer && ytPlayer.getCurrentTime ? ytPlayer.getCurrentTime() : 0; } catch (_) {}
    try { dur = ytPlayer && ytPlayer.getDuration    ? ytPlayer.getDuration()    : 0; } catch (_) {}
    var plName = '';
    try { var _e = document.getElementById('player-pl-name'); if (_e) plName = _e.textContent || ''; } catch (_) {}
    try {
        fetch('assets/music/api.php?action=save-now-playing', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                videoId:   tr.videoId,
                title:     tr.title  || '',
                artist:    tr.artist || '',
                plName:    plName,
                position:  pos,
                duration:  dur,
                isPlaying: !!isPlaying,
            }),
            keepalive: true
        }).catch(function(){});
    } catch (_) {}
}

/* ── Controles del sistema (MediaSession) y app de escritorio ──
   MediaSession le dice al navegador (o a la app de escritorio) qué suena:
   así funcionan las teclas multimedia, los controles del sistema (KDE /
   GNOME en Linux, los del volumen en Windows) y los botones de los
   auriculares Bluetooth. Dentro de la app de escritorio, además, se manda
   lo que enseña este reproductor al reproductor flotante
   (window.melonDesktop → electron/mini-player.js). Se llama desde
   publishNowPlaying (play, pausa y progreso) y desde updateTrackUI
   (cambio de pista). */
var __SM_VIDEO = null;
function syncSystemMedia(isPlaying) {
    var tr = nowPlayingTrack();
    if (typeof isPlaying !== 'boolean') {
        try { isPlaying = !!(ytPlayer && ytPlayer.getPlayerState && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING); }
        catch (_) { isPlaying = false; }
    }
    var dur = 0, pos = 0;
    try {
        dur = (ytPlayer && ytPlayer.getDuration    ? ytPlayer.getDuration()    : 0) || 0;
        pos = (ytPlayer && ytPlayer.getCurrentTime ? ytPlayer.getCurrentTime() : 0) || 0;
    } catch (_) {}
    if ('mediaSession' in navigator) {
        try {
            var ms = navigator.mediaSession;
            if (tr && tr.videoId && tr.videoId !== __SM_VIDEO) {
                __SM_VIDEO = tr.videoId;
                ms.metadata = new MediaMetadata({
                    title:  tr.title  || '',
                    artist: tr.artist || '',
                    artwork: [{ src: 'https://img.youtube.com/vi/' + tr.videoId + '/hqdefault.jpg', sizes: '480x360', type: 'image/jpeg' }]
                });
            }
            ms.playbackState = isPlaying ? 'playing' : 'paused';
            if (dur > 0 && typeof ms.setPositionState === 'function') {
                ms.setPositionState({ duration: dur, position: Math.min(pos, dur), playbackRate: 1 });
            }
        } catch (_) {}
    }
    var md = window.melonDesktop;
    if (md && typeof md.setNowPlaying === 'function') {
        var cover = document.getElementById('player-cover');
        var plName = document.getElementById('player-pl-name');
        var vol = document.getElementById('player-volume');
        md.setNowPlaying({
            hasTrack:   !!(tr && tr.videoId),
            videoId:    tr ? (tr.videoId || '') : '',
            title:      tr ? (tr.title  || '') : '',
            artist:     tr ? (tr.artist || '') : '',
            cover:      cover ? cover.src : '',
            coverUrl:   playerCoverUrl(),   /* la original (presencia de Discord, descargas) */
            playlist:   plName ? plName.textContent : '',
            playlistId: currentPlaylistId,
            trackIndex: currentTrack,
            playing:    !!(tr && isPlaying),
            position:   pos,
            duration:   dur,
            shuffle:    autoplayRandom,
            ltRole:     LT_ROLE,
            volume:     vol ? parseInt(vol.value, 10) : 100,
            album:      (_currentAlbum && _currentAlbum.albumName) || '',
            ltInvites:  Object.values(LT_PENDING),
            ltInvited:  LT_INVITED,
            userKey:    currentUserKey,   /* botón "Escuchar conmigo" de Discord */
            discordClientId: discordClientId
        });
    }
}
/* Los botones del sistema y del reproductor flotante hacen lo mismo que
   los del reproductor: así respetan el modo aleatorio, Listen Together, etc. */
(function setupSystemMediaControls() {
    function click(id) { var b = document.getElementById(id); if (b) b.click(); }
    function playing() {
        try { return !!(ytPlayer && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING); } catch (_) { return false; }
    }
    var actions = {
        play:          function() { if (!playing()) click('btn-play'); },
        pause:         function() { if (playing())  click('btn-play'); },
        previoustrack: function() { click('btn-prev'); },
        nexttrack:     function() { click('btn-next'); },
        seekto:        function(d) { if (ytPlayer && d && typeof d.seekTime === 'number') ytPlayer.seekTo(d.seekTime, true); }
    };
    if ('mediaSession' in navigator) {
        Object.keys(actions).forEach(function(a) {
            try { navigator.mediaSession.setActionHandler(a, actions[a]); } catch (_) {}
        });
    }
    var md = window.melonDesktop;
    if (md && typeof md.onPlayerCommand === 'function') {
        md.onPlayerCommand(function(action, value) {
            if (action === 'toggle') click('btn-play');
            else if (action === 'shuffle') click('btn-shuffle');
            else if (action === 'volume') {
                /* Mueve la barra del reproductor: su 'input' aplica el
                   volumen (con el general de la barra de tareas) y lo guarda. */
                var vol = document.getElementById('player-volume');
                if (vol) { vol.value = value; vol.dispatchEvent(new Event('input')); }
            }
            else if (action === 'volume-step') {
                /* Ctrl+Alt+↑ / ↓ (atajos globales de la app, desktop-extras.js). */
                var v = document.getElementById('player-volume');
                if (v) { v.value = Math.max(0, Math.min(100, parseInt(v.value, 10) + value)); v.dispatchEvent(new Event('input')); }
                syncSystemMedia();
            }
            else if (action === 'seekto') actions.seekto({ seekTime: value });
            else if (actions[action]) actions[action]();
            /* Playlists y opciones del clic derecho (editor de playlists). */
            else if (typeof window.melonTrackAction === 'function') window.melonTrackAction(action, value);
        });
    }
})();

/* Estado del WRAPPED tracking: guardamos referencia a la última canción
   que se EMPEZÓ a reproducir. Cuando cambia (skip / natural end / cierre
   de la ventana) la logueamos con el tiempo ESCUCHADO real (la
   `currentTime` del player en el momento de cambio) en lugar de su
   duración total. Si el usuario salta una canción en el segundo 30, se
   loguean 30s, no los 4 minutos completos. */
let _wrappedLastTrack       = null;
let _wrappedLastPlaylistId  = null;

function sendWrappedLog(track, listenedS, playlistId) {
    if (!track || !track.videoId || !track.title) return;
    listenedS = Math.max(0, Math.floor(listenedS || 0));
    /* Si escuchó menos de 3s no lo contamos como play — evita inflar
       counts por taps accidentales que cambian de track al instante. */
    if (listenedS < 3) return;
    /* IDs virtuales (e.g. "spotify-album:xxx" cuando el usuario carga
       un álbum como playlist temporal) no apuntan a ningún row real;
       el backend hace (int)playlistId y dejaría un 0 falso. Mandamos
       null en su lugar — la columna es NULLable. */
    if (typeof playlistId === 'string' && playlistId.indexOf(':') >= 0) {
        playlistId = null;
    }
    var body = JSON.stringify({
        videoId:    track.videoId,
        title:      track.title,
        artist:     track.artist || '',
        playlistId: playlistId,
        durationS:  listenedS,
    });
    /* sendBeacon: garantiza que la petición se envíe incluso si la
       página se está cerrando (pagehide). Si no está disponible o
       falla, fallback a fetch. */
    try {
        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon('assets/music/wrapped-api.php?action=log', blob)) return;
        }
    } catch (_) {}
    try {
        fetch('assets/music/wrapped-api.php?action=log', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            /* keepalive: lo mismo que sendBeacon, sobrevive al cierre. */
            keepalive: true,
        }).catch(function(){});
    } catch (_) {}
}

/* Al cerrar el escritorio (o cambiar a otra pestaña sin volver), logueamos
   la última canción con el tiempo escuchado. Sin esto, la PRIMERA canción
   nunca quedaría registrada porque solo se loguea al CAMBIAR de track. */
(function setupWrappedFlush() {
    function flush() {
        if (!_wrappedLastTrack) return;
        var listened = 0;
        try {
            if (ytPlayer && typeof ytPlayer.getCurrentTime === 'function') {
                listened = ytPlayer.getCurrentTime() || 0;
            }
        } catch (_) {}
        sendWrappedLog(_wrappedLastTrack, listened, _wrappedLastPlaylistId);
    }
    /* `pagehide` es más fiable que beforeunload (también dispara en BFCache). */
    window.addEventListener('pagehide', flush);
    /* `visibilitychange` para cuando el usuario cambia de tab o
       minimiza — guardamos lo escuchado HASTA AHORA por si no vuelve. */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
})();

/* ════════════════════════════════════════════════════════════════
   LISTEN TOGETHER — sesiones de escucha conjunta.

   Modelo: el HOST tiene el player real; postea estado a la API cada
   vez que cambia (track switch, play/pause, drift > 5s) — debounced
   ~600ms. El GUEST poll-ea cada 2s y aplica: loadVideoById si cambia
   videoId, seekTo si su currentTime difiere > 3s, play/pause para
   coincidir. Controles deshabilitados como guest (avisa que va a
   salir si los pulsa).

   Endpoints: assets/listen/api.php?action=<...>
═════════════════════════════════════════════════════════════════════ */
const LT_URL = 'assets/listen/api.php';
/* Umbral de re-sincronización del guest (segundos). Por debajo de esto NO
   se hace seek: evita micro-saltos atrás/alante por desfases pequeños. */
const LT_SEEK_THRESHOLD_S = 2;
let LT_ROLE         = null;     /* 'host' | 'guest' | null */
/* Track ACTUAL de la sesión cuando soy guest. Sirve para reportar el
   "escuchando ahora" y el wrapped con la canción REAL de la sesión (no la
   que tenía en mi playlist antes de unirme). */
let _ltGuestTrack   = null;
let LT_SESSION_ID   = null;
let LT_GUEST_POLL_T = null;
let LT_HOST_BCAST_T = null;
let LT_INVITES_POLL_T = null;
let LT_LAST_HOST_VID = null;    /* host: último videoId enviado */
let LT_SHOWN_INVITES = new Set(); /* IDs de invites ya mostrados como notif */
/* Para el reproductor flotante de la app de escritorio, que enseña
   "Escuchar juntos" mientras esta ventana está escondida (van en
   syncSystemMedia → apps/mini-player.html):
   - LT_PENDING: invitaciones recibidas sin contestar (id → invitación);
   - LT_INVITED: resultado de cada "Invitar" (user_key → 'ok' | error). */
let LT_PENDING = {};
let LT_INVITED = {};

function ltFetch(action, opts) {
    opts = opts || {};
    const isGet = !opts.body;
    const url = LT_URL + '?action=' + encodeURIComponent(action);
    return fetch(url, {
        method: isGet ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: isGet ? {} : { 'Content-Type': 'application/json' },
        body:    opts.body ? JSON.stringify(opts.body) : undefined,
    }).then(function(r){ return r.json(); }).catch(function(){ return { ok: false }; });
}

function ltBanner(text) {
    const b = document.getElementById('lt-banner');
    const t = document.getElementById('lt-banner-text');
    if (!b || !t) return;
    if (!text) { b.classList.remove('visible'); return; }
    t.textContent = text;
    b.classList.add('visible');
}

/* Indicador LIVE — muestra/oculta el punto pulsante sobre el cover del
   player. `mode`: 'host' / 'guest' / null (oculta). */
function ltUpdateLiveDot(mode, hostLabel) {
    const el = document.getElementById('lt-live-dot');
    if (!el) return;
    if (!mode) {
        el.style.display = 'none';
        el.title = '';
        return;
    }
    el.style.display = 'block';
    if (mode === 'host') {
        el.title = 'En directo · estás hosteando una sesión';
    } else if (mode === 'guest') {
        el.title = 'En directo · escuchando con ' + (hostLabel || 'el host');
    }
}

/* ── HOST: broadcast del estado ──────────────────────────────────── */
/* Set de user_keys de participantes que el host ya ha notificado, para
   evitar mostrar el toast "X se unió" repetidamente en cada poll. */
let LT_KNOWN_PARTICIPANTS = new Set();

async function ltHostBroadcast() {
    if (LT_ROLE !== 'host' || !ytPlayer) return;
    const track = playlist[currentTrack];
    if (!track) return;
    let ct = 0, dur = 0;
    try {
        ct  = Math.floor(ytPlayer.getCurrentTime() || 0);
        dur = Math.floor(ytPlayer.getDuration()    || 0);
    } catch (_) {}
    let isPlaying = false;
    try { isPlaying = ytPlayer.getPlayerState && ytPlayer.getPlayerState() === 1; } catch (_) {}
    const r = await ltFetch('update-state', { body: {
        videoId:      track.videoId,
        title:        track.title  || '',
        artist:       track.artist || '',
        coverUrl:     'https://i.ytimg.com/vi/' + track.videoId + '/mqdefault.jpg',
        currentTimeS: ct,
        durationS:    dur,
        isPlaying:    isPlaying,
    }});
    /* Detección de joiners nuevos. */
    if (r && r.ok && Array.isArray(r.participants_list)) {
        r.participants_list.forEach(p => {
            if (p.user_key && !LT_KNOWN_PARTICIPANTS.has(p.user_key)) {
                LT_KNOWN_PARTICIPANTS.add(p.user_key);
                if (window.notifSystem) {
                    const joinerName = p.label || '?';
                    window.notifSystem.show({
                        id:    'lt-joiner-' + p.user_key,
                        type:  'info',
                        title: joinerName + ' se unió',
                        message: joinerName + ' está escuchando contigo en tiempo real.',
                        autoDismissAfter: 4500,
                    });
                }
            }
        });
    }
}
function ltHostBroadcastDebounced() {
    if (LT_HOST_BCAST_T) clearTimeout(LT_HOST_BCAST_T);
    LT_HOST_BCAST_T = setTimeout(ltHostBroadcast, 600);
}

/* ── GUEST: poll + sync ──────────────────────────────────────────── */
async function ltGuestPoll() {
    if (LT_ROLE !== 'guest') return;
    const r = await ltFetch('poll');
    if (!r || !r.ok) return;
    if (r.closed || !r.session) {
        /* Host cerró → salimos. */
        ltLeaveLocal('El host cerró la sesión.');
        return;
    }
    const s = r.session;
    if (!ytPlayer || !s.video_id) return;

    /* Track real de la sesión → lo usa publishNowPlaying para el
       "escuchando ahora" del perfil. */
    _ltGuestTrack = { videoId: s.video_id, title: s.track_title || '', artist: s.track_artist || '' };

    const isPlaying = parseInt(s.is_playing, 10) === 1;
    /* Posición REAL estimada del host AHORA: su último current_time_s más
       el tiempo transcurrido desde que lo posteó (solo si está sonando).
       Esta compensación es la clave para que el seek apunte a donde el
       host está de verdad y no a un valor viejo. */
    const elapsed = isPlaying
        ? Math.max(0, (parseInt(r.server_time, 10) || 0) - (parseInt(s.updated_at_epoch, 10) || 0))
        : 0;
    const hostT = (parseInt(s.current_time_s, 10) || 0) + elapsed;

    /* Cambio de pista → cargar ya en la posición estimada del host. */
    let curVid = '';
    try {
        const d = ytPlayer.getVideoData && ytPlayer.getVideoData();
        if (d) curVid = d.video_id || '';
    } catch (_) {}
    if (curVid !== s.video_id) {
        /* WRAPPED: la pista de la sesión cambió. Logueamos la ANTERIOR con
           el tiempo escuchado (posición actual antes de cargar la nueva) y
           pasamos a seguir la nueva pista de la SESIÓN — no la de mi
           playlist local (que es de antes de unirme). Sin esto, el wrapped
           del guest registraba la canción equivocada. */
        try {
            var _wListened = 0;
            try { _wListened = (ytPlayer.getCurrentTime && ytPlayer.getCurrentTime()) || 0; } catch (_) {}
            if (_wrappedLastTrack && _wrappedLastTrack.videoId !== s.video_id) {
                sendWrappedLog(_wrappedLastTrack, _wListened, _wrappedLastPlaylistId);
            }
            _wrappedLastTrack      = { videoId: s.video_id, title: s.track_title || '', artist: s.track_artist || '' };
            _wrappedLastPlaylistId = null;
        } catch (_) {}
        try { ytPlayer.loadVideoById(s.video_id, hostT); } catch (_) {}
        /* Reporta de inmediato el "escuchando ahora" con la pista de la sesión. */
        try { publishNowPlaying(isPlaying, true); } catch (_) {}
        /* Actualizamos el mini-player UI manualmente (cover + texto).
           IMPORTANTE: actualizamos los elementos EXISTENTES por textContent,
           NO reemplazamos el innerHTML de #player-info. Reemplazarlo
           destruía los nodos #player-title/#player-artist que updateTrackUI
           tiene cacheados (consts playerTitle/playerArtist) → al salir de la
           sesión y poner otra canción, updateTrackUI escribía en nodos
           desconectados y el título se quedaba con el de la escucha conjunta. */
        try {
            const coverEl = document.getElementById('player-cover');
            if (coverEl) {
                coverEl.src = s.cover_url
                    || 'https://i.ytimg.com/vi/' + s.video_id + '/mqdefault.jpg';
            }
            const titleEl  = document.getElementById('player-title');
            const artistEl = document.getElementById('player-artist');
            const addedEl  = document.getElementById('player-addedby');
            if (titleEl)  titleEl.textContent  = s.track_title  || '';
            if (artistEl) artistEl.textContent = s.track_artist || '';
            if (addedEl)  { addedEl.innerHTML = ''; addedEl.textContent = '🎧 Sesión conjunta'; addedEl.style.display = ''; }
        } catch (_) {}
        return;
    }

    /* Corrección de drift con banda muerta: solo re-sincronizamos si la
       diferencia supera el umbral. Desfases pequeños se ignoran → sin
       saltos nerviosos. */
    let myT = 0;
    try { myT = ytPlayer.getCurrentTime() || 0; } catch (_) {}
    if (Math.abs(myT - hostT) > LT_SEEK_THRESHOLD_S) {
        try { ytPlayer.seekTo(hostT, true); } catch (_) {}
    }

    /* Sync play/pause. */
    try {
        const ps = ytPlayer.getPlayerState();
        if (isPlaying && ps !== 1) ytPlayer.playVideo();
        else if (!isPlaying && ps === 1) ytPlayer.pauseVideo();
    } catch (_) {}
}

/* ── INICIAR como HOST ───────────────────────────────────────────── */
async function ltStartAsHost() {
    const r = await ltFetch('create', { body: {} });
    if (!r || !r.ok) {
        if (window.notifSystem) window.notifSystem.show({ type: 'error', title: 'Error', message: 'No se pudo crear sesión.' });
        return;
    }
    LT_ROLE       = 'host';
    LT_SESSION_ID = r.session_id;
    ltHostBroadcast();   /* primer push inmediato */
    ltUpdateLiveDot('host');
    ltRefreshModalState();
}

/* ── INICIAR como GUEST (al aceptar invite) ──────────────────────── */
async function ltJoinAsGuest(inviteId) {
    const r = await ltFetch('accept', { body: { inviteId: inviteId } });
    if (!r || !r.ok) {
        if (window.notifSystem) window.notifSystem.show({ type: 'error', title: 'Error', message: r.error || 'No se pudo unir.' });
        return;
    }
    LT_ROLE       = 'guest';
    LT_SESSION_ID = r.session_id;
    if (LT_GUEST_POLL_T) clearInterval(LT_GUEST_POLL_T);
    ltGuestPoll();
    LT_GUEST_POLL_T = setInterval(ltGuestPoll, 2000);
    ltUpdateLiveDot('guest', r.host_label);
    ltRefreshModalState();
    /* Notif de confirmación al guest — incluye el nombre del host. */
    if (window.notifSystem) {
        const hostName = r.host_label || 'el host';
        window.notifSystem.show({
            id:    'lt-joined-' + r.session_id,
            type:  'info',
            title: 'Te has unido a la sesión de ' + hostName,
            message: 'Ahora escuchas en tiempo real con ' + hostName + '.',
            autoDismissAfter: 4500,
        });
    }
}

/* ── SALIR de la sesión ──────────────────────────────────────────── */
async function ltLeave() {
    await ltFetch('leave', { body: {} });
    ltLeaveLocal();
}
function ltLeaveLocal(msg) {
    LT_ROLE       = null;
    /* Dejo de seguir la pista de la sesión: el "escuchando ahora" vuelve a
       reportar mi reproducción local. El wrapped de la última canción de la
       sesión lo cerrará el próximo cambio de pista (updateTrackUI). */
    _ltGuestTrack = null;
    LT_SESSION_ID = null;
    if (LT_GUEST_POLL_T) { clearInterval(LT_GUEST_POLL_T); LT_GUEST_POLL_T = null; }
    if (LT_HOST_BCAST_T) { clearTimeout(LT_HOST_BCAST_T); LT_HOST_BCAST_T = null; }
    ltBanner(null);
    ltUpdateLiveDot(null);   /* oculta el punto LIVE */
    LT_KNOWN_PARTICIPANTS = new Set();
    LT_INVITED = {};
    ltRefreshModalState();
    syncSystemMedia();
    if (msg && window.notifSystem) window.notifSystem.show({ type: 'info', title: 'Sesión cerrada', message: msg });
}

/* ── MODAL: poblar y refrescar ───────────────────────────────────── */
async function ltOpenModal() {
    document.getElementById('lt-modal').style.display = 'block';
    ltRefreshModalState();
    /* Cargar lista de usuarios. */
    const r = await ltFetch('users');
    const list = document.getElementById('lt-user-list');
    if (!r || !r.ok || !r.users || r.users.length === 0) {
        list.innerHTML = '<div class="lt-status" style="text-align:center;line-height:1.45;">Aún no tienes amigos que invitar.<br><span style="opacity:0.75;font-size:11px;">Seguíos entre vosotros para haceros amigos.</span></div>';
        return;
    }
    list.innerHTML = r.users.map(function(u){
        return '<div class="lt-user-row">' +
            '<span>' + (u.label || u.user_key) + '</span>' +
            '<button data-userkey="' + u.user_key + '">Invitar</button>' +
            '</div>';
    }).join('');
    list.querySelectorAll('button[data-userkey]').forEach(function(b){
        b.addEventListener('click', async function(){
            const inv = await ltInviteUser(b.getAttribute('data-userkey'));
            if (inv && inv.ok) {
                b.textContent = '✓ Invitado';
                b.classList.add('invited');
                b.disabled = true;
            } else {
                if (window.notifSystem) window.notifSystem.show({ type: 'error', title: 'Error', message: inv.error || 'No se pudo invitar.' });
            }
        });
    });
}
/* Invita a un usuario; si no soy host aún, primero crea la sesión. Lo usan
   el modal y el reproductor flotante. */
async function ltInviteUser(userKey) {
    if (LT_ROLE !== 'host') await ltStartAsHost();
    const inv = await ltFetch('invite', { body: { toUser: userKey } });
    LT_INVITED[userKey] = (inv && inv.ok) ? 'ok' : ((inv && inv.error) || 'No se pudo invitar.');
    syncSystemMedia();
    return inv;
}
/* Contesta una invitación (desde su notificación o desde el flotante). */
function ltAnswerInvite(id, accept) {
    delete LT_PENDING[id];
    if (window.notifSystem && notifSystem.dismiss) notifSystem.dismiss('lt-invite-' + id);
    if (accept) ltJoinAsGuest(id);
    else ltFetch('decline', { body: { inviteId: id } });
    syncSystemMedia();
}
function ltCloseModal() {
    document.getElementById('lt-modal').style.display = 'none';
}
function ltRefreshModalState() {
    const t = document.getElementById('lt-status-text');
    const endBtn = document.getElementById('lt-end-btn');
    if (!t) return;
    if (LT_ROLE === 'host') {
        t.innerHTML = '🎤 <strong>Hosteando</strong> una sesión. Invita usuarios para que se unan.';
        endBtn.style.display = '';
    } else if (LT_ROLE === 'guest') {
        t.innerHTML = '🎧 Estás escuchando como invitado.';
        endBtn.style.display = '';
    } else {
        t.innerHTML = 'No estás en ninguna sesión. Invita a alguien para empezar una.';
        endBtn.style.display = 'none';
    }
}

/* ── POLL de invites para guests potenciales ─────────────────────── */
async function ltPollInvites() {
    if (LT_ROLE) return; /* ya en sesión, no procesa nuevos invites */
    const r = await ltFetch('invites');
    if (!r || !r.ok || !r.invites) return;
    r.invites.forEach(function(inv){
        if (LT_SHOWN_INVITES.has(inv.id)) return;
        LT_SHOWN_INVITES.add(inv.id);
        LT_PENDING[inv.id] = { id: inv.id, from: inv.from_label || '?', track: inv.track_title || '' };
        syncSystemMedia();
        if (!window.notifSystem) return;
        /* type: 'action' añade botones Aceptar/Rechazar y NO auto-dismissa
           — el usuario decide. */
        window.notifSystem.show({
            id:      'lt-invite-' + inv.id,
            type:    'action',
            title:   (inv.from_label || '?') + ' te invita',
            message: 'Escuchar juntos: ' + (inv.track_title || 'una canción'),
            onAccept: function(){ ltAnswerInvite(inv.id, true); },
            /* Marca el invite como declinado en el backend. */
            onReject: function(){ ltAnswerInvite(inv.id, false); },
        });
    });
}

/* ── HOOKS al player ─────────────────────────────────────────────── */
/* Cada cambio de track del HOST → broadcast. updateTrackUI es donde el
   desktop ya rastrea para wrapped; hooks el broadcast ahí también. */
(function(){
    const origUpdateTrackUI = (typeof updateTrackUI === 'function') ? updateTrackUI : null;
    if (origUpdateTrackUI) {
        window.updateTrackUI = function(track) {
            origUpdateTrackUI(track);
            if (LT_ROLE === 'host') ltHostBroadcastDebounced();
        };
    }
})();
/* Periódico: si soy host, mando state cada 5s para mantener participantes. */
setInterval(function(){
    if (LT_ROLE === 'host') ltHostBroadcast();
}, 5000);

/* Listeners UI del modal Listen Together. El item del context menu
   se inyecta en showTrackCtxMenu (más abajo) junto a "Añadir a otra
   playlist" y "Añadir a mi perfil". */
document.addEventListener('DOMContentLoaded', function(){
    const close = document.getElementById('lt-modal-close');
    if (close) close.addEventListener('click', ltCloseModal);
    const leave = document.getElementById('lt-leave-btn');
    if (leave) leave.addEventListener('click', ltLeave);
    const endBtn = document.getElementById('lt-end-btn');
    if (endBtn) endBtn.addEventListener('click', function(){ ltLeave(); ltCloseModal(); });
});
/* Exposición global para que showTrackCtxMenu pueda llamarlos. */
window.ltOpenModal = ltOpenModal;
window.ltLeave     = ltLeave;

/* (Click derecho sobre el reproductor lo maneja desktop-base.php sobre
   #player-main, que ahora incluye la opción "Escuchar juntos". Aquí no
   duplicamos el listener — duplicarlo provocaba 2 menús superpuestos.) */

/* Al cargar, checkear si ya estoy en sesión (refresh de pestaña). */
(async function ltBootstrap(){
    const r = await ltFetch('current');
    if (!r || !r.ok || !r.role) {
        /* Invitaciones: ahora y cuando el timbre del escritorio dice que
           ha llegado una ('lt', window.melonTimbre en desktop-base.php);
           si no responde, cada 5 s como antes. */
        ltPollInvites();
        if (window.melonTimbre) window.melonTimbre.on('lt', ltPollInvites, 5000);
        else LT_INVITES_POLL_T = setInterval(ltPollInvites, 5000);
        return;
    }
    LT_ROLE       = r.role;
    LT_SESSION_ID = r.session ? r.session.id : null;
    if (LT_ROLE === 'guest') {
        if (LT_GUEST_POLL_T) clearInterval(LT_GUEST_POLL_T);
        LT_GUEST_POLL_T = setInterval(ltGuestPoll, 2000);
        ltGuestPoll();
        ltUpdateLiveDot('guest', r.host_label);
        /* Guest sin banner — solo notif al unirse (que ya pasó). */
    } else if (LT_ROLE === 'host') {
        ltUpdateLiveDot('host');
    }
})();

/* ── "Escuchar conmigo" (botón de la presencia de Discord de la app de
   escritorio, electron/discord.js) ──────────────────────────────────
   - Quien lo pulsa llega por enlace.php?escuchar=<user_key del anfitrión>
     (intenta abrir su app; si no, sigue en la web aunque haya que iniciar
     sesión antes) y melonOpenLink (desktop-base.php) llama a
     melonJoinListen: se pide unirse (request-join; con el enlace vale
     aunque no seáis amigos) y, cuando llega la invitación del anfitrión, se
     acepta sola. ?escuchar= en la URL del escritorio sigue valiendo.
   - La app del anfitrión atiende esas peticiones cada 5 s: abre la sesión
     si no la tiene e invita a quien lo pidió (ltInviteUser). */
window.melonJoinListen = function(host) {
    if (!host || host === currentUserKey) return;
    ltFetch('request-join', { body: { host: host } }).then(function(r) {
        if (!r || !r.ok) return;
        const name = r.host_label || 'tu amigo';
        if (window.notifSystem) notifSystem.show({ id: 'lt-joining-' + host, type: 'info', title: '🎧 Escuchar juntos', message: 'Uniéndote a lo que escucha ' + name + '…' });
        const until = Date.now() + 120000;
        const t = setInterval(async function() {
            if (Date.now() > until) {
                clearInterval(t);
                if (window.notifSystem) notifSystem.show({ id: 'lt-joining-fail-' + host, type: 'info', title: '🎧 Escuchar juntos', message: name + ' no ha respondido: tiene que tener Melon Hub abierto.' });
                return;
            }
            const inv = await ltFetch('invites');
            const mine = inv && inv.ok && (inv.invites || []).find(function(i) { return i.from_user_key === host; });
            if (!mine) return;
            clearInterval(t);
            /* Que no se quede también como invitación por contestar. */
            LT_SHOWN_INVITES.add(mine.id);
            delete LT_PENDING[mine.id];
            if (window.notifSystem && notifSystem.dismiss) notifSystem.dismiss('lt-invite-' + mine.id);
            if (LT_ROLE) ltLeaveLocal();
            ltJoinAsGuest(mine.id);
        }, 2000);
    });
};
(function ltJoinFromUrl() {
    const m = /[?&]escuchar=([A-Za-z0-9_-]+)/.exec(location.search);
    if (!m) return;
    try {
        const qs = location.search.replace(/([?&])escuchar=[^&]*&?/, '$1').replace(/[?&]$/, '');
        history.replaceState(null, '', location.pathname + qs + location.hash);
    } catch (_) {}
    window.melonJoinListen(m[1]);
})();
/* Copiar el enlace desde la ventana de "Escuchar juntos". */
(function () {
    const b = document.getElementById('lt-link-btn');
    if (!b) return;
    b.addEventListener('click', function () {
        if (typeof window.melonCopyLink !== 'function') return;
        window.__ltLinkShared = true;
        window.melonCopyLink('escuchar', currentUserKey);
    });
})();
/* Peticiones de unirse por el enlace: la app de escritorio siempre; la web
   en el navegador si estás de anfitrión o has copiado el enlace. Se miran
   cuando el timbre del escritorio dice que ha llegado una ('lt',
   window.melonTimbre en desktop-base.php; antes, cada 5 s); si no
   responde, cada 5 s como antes. */
{
    const ltAtenderPeticiones = async function() {
        if (!window.melonDesktop && LT_ROLE !== 'host' && !window.__ltLinkShared) return;
        if (LT_ROLE === 'guest') return;   /* escuchando a otro: no se puede hostear */
        const r = await ltFetch('join-requests');
        if (!r || !r.ok || !r.requests || !r.requests.length) return;
        for (const req of r.requests) {
            await ltFetch('answer-join', { body: { requestId: req.id } });
            const inv = await ltInviteUser(req.from_user_key);
            if (inv && inv.ok && window.notifSystem) {
                notifSystem.show({ id: 'lt-joinreq-' + req.id, type: 'info', title: '🎧 ' + req.from_label + ' se une',
                    message: req.from_label + ' va a escuchar contigo con tu enlace.' });
            }
        }
    };
    /* La primera, a los 5 s como antes (ya se sabe si estoy en una sesión). */
    setTimeout(ltAtenderPeticiones, 5000);
    if (window.melonTimbre) window.melonTimbre.on('lt', ltAtenderPeticiones, 5000);
    else setInterval(ltAtenderPeticiones, 5000);
}

/* win98Confirm vive ahora en assets/js/win98-dialogs.js (compartido).
   La firma (message, title, onOk) se mantiene compatible con los callers. */
let stopTitleMarquee  = null;
let stopPlNameMarquee = null;
var refreshPlaylists  = null;

/* Spotify PKCE OAuth */
var spotifyPKCE = (function() {
    var REDIRECT_URI = location.origin + location.pathname;

    function rnd(n) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var arr = crypto.getRandomValues(new Uint8Array(n));
        return Array.from(arr, function(b) { return chars[b % chars.length]; }).join('');
    }
    function b64url(buf) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }
    function getToken() {
        try {
            var d = JSON.parse(localStorage.getItem('sp_token') || 'null');
            if (d && d.t && d.e > Date.now()) return d.t;
        } catch(e) {}
        return null;
    }
    function saveToken(data) {
        localStorage.setItem('sp_token', JSON.stringify({ t: data.access_token, e: Date.now() + data.expires_in * 1000 }));
    }

    /* Handle OAuth callback */
    var p = new URLSearchParams(location.search);
    if (p.get('state') === 'sp_import') {
        history.replaceState(null, '', location.pathname);
        if (p.get('error')) {
            window._spotifyAuthError = p.get('error');
        } else if (p.get('code')) {
            var code     = p.get('code');
            var verifier = localStorage.getItem('sp_verifier');
            if (verifier) {
                fetch('https://accounts.spotify.com/api/token', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ client_id: spotifyClientId, grant_type: 'authorization_code', code: code, redirect_uri: REDIRECT_URI, code_verifier: verifier }).toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    localStorage.removeItem('sp_verifier');
                    if (d.access_token) {
                        saveToken(d);
                        window._spotifyPendingUrl = localStorage.getItem('sp_pending_url') || '';
                        localStorage.removeItem('sp_pending_url');
                        window._spotifyJustAuthed = true;
                    } else {
                        window._spotifyAuthError = d.error || 'token_error';
                    }
                })
                .catch(function(e) { window._spotifyAuthError = e.message; });
            }
        }
    }

    return {
        getToken: getToken,
        clearToken: function() { localStorage.removeItem('sp_token'); localStorage.removeItem('sp_verifier'); },
        authorize: function(pendingUrl) {
            var verifier = rnd(128);
            localStorage.setItem('sp_verifier', verifier);
            if (pendingUrl) localStorage.setItem('sp_pending_url', pendingUrl);
            crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))
            .then(function(buf) {
                location.href = 'https://accounts.spotify.com/authorize?' + new URLSearchParams({
                    response_type: 'code', client_id: spotifyClientId, scope: 'playlist-read-public playlist-read-collaborative',
                    redirect_uri: REDIRECT_URI, code_challenge_method: 'S256', code_challenge: b64url(buf), state: 'sp_import'
                }).toString();
            });
        }
    };
})();

function updatePlayerTitle(name) {
    var el     = document.getElementById('player-pl-name');
    var parent = document.getElementById('player-tb-text');
    if (!el || !parent) return;
    /* El icono PNG ♪/musicaIcon vive ahora FUERA del span (en el HTML
       del title-bar), así que aquí solo escribimos el nombre del
       playlist — sin prefijo ♪ que duplicaría el icono. */
    el.textContent = name;
    MelonLastTrack.setTitle(name);
    if (stopPlNameMarquee) { stopPlNameMarquee(); stopPlNameMarquee = null; }
    setTimeout(function() {
        stopPlNameMarquee = marqueeScroll(el, parent, 40, 1500);
    }, 50);
}

function marqueeScroll(el, parent, speed, pauseMs) {
    el.style.transform = 'translateX(0)';
    var pos = 0, last = null, pausing = true, fromRight = false, raf = null, timer = null;
    var W = el.offsetWidth, C = parent.clientWidth;
    if (W <= C) return function(){};
    function tick(ts) {
        if (!last) last = ts;
        var dt = Math.min(ts - last, 50);
        last = ts;
        pos -= speed * dt / 1000;
        if (pos < -W) {
            pos = C + (pos + W);
            fromRight = true;
        }
        if (fromRight && pos <= 0) {
            pos = 0;
            fromRight = false;
            el.style.transform = 'translateX(0)';
            last = null; pausing = true;
            timer = setTimeout(function() { pausing = false; raf = requestAnimationFrame(tick); }, pauseMs);
            return;
        }
        el.style.transform = 'translateX(' + pos + 'px)';
        raf = requestAnimationFrame(tick);
    }
    timer = setTimeout(function() { pausing = false; raf = requestAnimationFrame(tick); }, pauseMs);
    return function() {
        clearTimeout(timer);
        if (raf) cancelAnimationFrame(raf);
        el.style.transform = '';
    };
}

const playerWindow  = document.getElementById('music-player');
const playerCover   = document.getElementById('player-cover');
const playerTitle   = document.getElementById('player-title');
const playerArtist  = document.getElementById('player-artist');
/* Click en el nombre del artista del reproductor → ventana del artista. */
if (playerArtist) {
    playerArtist.classList.add('artist-link');
    playerArtist.addEventListener('click', function(){
        var t = (typeof playlist !== 'undefined' && playlist[currentTrack]) || null;
        var a = t && t.artist;
        if (a && typeof openArtistWindow === 'function') openArtistWindow(a);
    });
}
const playerProg    = document.getElementById('player-progress');
const playerCurrent = document.getElementById('player-current');
const playerDur     = document.getElementById('player-duration');
const btnPlay       = document.getElementById('btn-play');
const volSlider     = document.getElementById('player-volume');

function pixelateImg(img, factor) {
    if (!img.naturalWidth || img.src.startsWith('data:')) return;
    const w = Math.max(1, Math.round(img.naturalWidth  * factor));
    const h = Math.max(1, Math.round(img.naturalHeight * factor));
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    try {
        ctx.drawImage(img, 0, 0, w, h);
        /* La URL de verdad se guarda: Discord y las descargas necesitan
           la imagen original, no el data: pixelado (playerCoverUrl). */
        img.dataset.origSrc = img.src;
        img.src = c.toDataURL('image/png');
    } catch (e) {}
}
/* URL de la carátula que enseña el reproductor, la de antes de pixelarla. */
function playerCoverUrl() {
    var c = document.getElementById('player-cover');
    if (!c) return '';
    if (/^https?:/.test(c.src)) return c.src;
    return c.dataset.origSrc || '';
}

playerCover.addEventListener('load', function() { pixelateImg(playerCover, 0.25); });

function formatTime(s)
{
    const m = Math.floor(s / 60);
    return m + ':' + String(Math.floor(s % 60)).padStart(2, '0');
}

function updateTrackUI(index)
{
    if (!playlist.length) return;
    const track = playlist[index];
    rememberListened(index, track);
    shuffleNav = false;
    playerTitle.textContent  = track.title;
    playerArtist.textContent = track.artist || '—';
    playerCover.crossOrigin = 'anonymous';
    playerCover.src = `https://img.youtube.com/vi/${track.videoId}/mqdefault.jpg`;
    resolveAndShowAlbum(track);

    var addedByEl = document.getElementById('player-addedby');
    addedByEl.innerHTML = '';
    if (currentPlaylistHasCollabs && track.addedBy) {
        var adderInfo = null;
        Object.keys(usersInfo).forEach(function(k) {
            if (usersInfo[k].label === track.addedBy) adderInfo = usersInfo[k];
        });
        if (adderInfo && adderInfo.img) {
            var wrap = document.createElement('div');
            wrap.className = 'player-addedby-wrap';
            wrap.setAttribute('data-marco-user', adderInfo.key);   /* marco (melon-marcos.js) */
            wrap.title = adderInfo.label;
            var img = document.createElement('img');
            img.className = 'player-addedby-img';
            img.src = adderInfo.img;
            img.alt = adderInfo.label;
            wrap.appendChild(img);
            addedByEl.appendChild(wrap);
            addedByEl.style.display = 'flex';
        } else {
            addedByEl.style.display = 'none';
        }
    } else {
        addedByEl.style.display = 'none';
    }
    playerProg.value = 0;
    playerCurrent.textContent = '0:00';
    playerDur.textContent = '0:00';
    if (stopTitleMarquee) { stopTitleMarquee(); stopTitleMarquee = null; }
    setTimeout(function() {
        stopTitleMarquee = marqueeScroll(playerTitle, playerTitle.parentElement, 50, 1000);
    }, 50);

    MelonPlayerState.setTrack(index);
    MelonLastTrack.saveQueue(index);

    if (typeof window.updatePlaylistPlayingHighlight === 'function') {
        window.updatePlaylistPlayingHighlight(currentPlaylistId, index);
    }

    /* WRAPPED tracking: ANTES de cambiar el track activo, logueamos
       el ANTERIOR con el tiempo que el usuario realmente escuchó
       (ytPlayer.getCurrentTime() en el momento del cambio).
       Se llama tanto en skip (switchTrack → updateTrackUI) como en
       fin natural (ENDED → updateTrackUI), así que cubre ambos casos. */
    if (_wrappedLastTrack && _wrappedLastTrack.videoId !== track.videoId) {
        var listened = 0;
        try {
            if (ytPlayer && typeof ytPlayer.getCurrentTime === 'function') {
                listened = ytPlayer.getCurrentTime() || 0;
            }
        } catch (_) {}
        sendWrappedLog(_wrappedLastTrack, listened, _wrappedLastPlaylistId);
    }
    _wrappedLastTrack      = track;
    _wrappedLastPlaylistId = currentPlaylistId;

    syncSystemMedia();
}

/* ── Resolución de álbum ──
   El track no trae el álbum (los datos vienen de YouTube). Lo
   resolvemos via Spotify Search en backend (music/api.php?action=
   find-album) y cacheamos por videoId en localStorage para no repetir
   la query — ni entre tracks, ni entre sesiones, ni entre tabs.

   Race: si el usuario cambia de track antes de que la query termine,
   la respuesta podría llegar para un track "viejo". Capturamos el
   videoId al iniciar el lookup y comprobamos al volver. */
/* :v5 — invalida los notFound:true cacheados después de quitar la
   rama sintética. Esos resultados quedaban locked en cliente y
   bloqueaban que las nuevas búsquedas (con la cascada del backend)
   intentaran resolver el álbum real. */
const ALBUM_CACHE_KEY = 'reproductor:album-cache:v7';
let _albumCacheMem = null;
function _loadAlbumCache() {
    if (_albumCacheMem) return _albumCacheMem;
    try { _albumCacheMem = JSON.parse(localStorage.getItem(ALBUM_CACHE_KEY) || '{}'); }
    catch (_) { _albumCacheMem = {}; }
    return _albumCacheMem;
}
function _saveAlbumCache() {
    try { localStorage.setItem(ALBUM_CACHE_KEY, JSON.stringify(_albumCacheMem)); }
    catch (_) { /* localStorage lleno — ignoramos, el backend también cachea */ }
}
function _albumCacheGet(videoId) {
    if (!videoId) return undefined;
    return _loadAlbumCache()[videoId];
}
function _albumCacheSet(videoId, payload) {
    if (!videoId) return;
    _loadAlbumCache()[videoId] = payload;
    _saveAlbumCache();
}

/* Estado del álbum del track activo. El reproductor pequeño no lo
   muestra como texto — solo hace clickable el título de la canción
   cuando hay un álbum resuelto, y el click abre el viewer. */
let _currentAlbum = null; /* { spotifyAlbumId, albumName, image, isSingle, matchTitle, albumUrl } */

/* videoId para el que el caller forzó el estado del álbum (vía
   setReproductorAlbumContext). El fetch async de resolveAndShowAlbum,
   al terminar, NO sobreescribe si el current track sigue siendo el
   mismo videoId que se forzó — evita la race "updateTrackUI resetea,
   setReproductorAlbumContext aplica, fetch async pisa con notFound". */
let _forcedAlbumForVideo = null;

/* Inyecta directamente el álbum activo cuando el caller ya conoce
   el spotifyAlbumId (e.g. el perfil al reproducir un item type=album).
   Bypasea find-album — más rápido y garantiza match exacto. Lo
   llamamos DESPUÉS de updateTrackUI; el _forcedAlbumForVideo bloquea
   que el fetch en vuelo desencadenado por updateTrackUI lo deshaga. */
window.setReproductorAlbumContext = function(albumInfo) {
    if (!albumInfo) return;
    /* Aceptamos albumKey (itunes:/deezer:) — IGNORAMOS spotify:* y los
       legacy spotifyAlbumId. Si no hay key útil, no seteamos contexto
       y dejamos que resolveAndShowAlbum haga find-album fresh. */
    var key = '';
    if (typeof albumInfo.albumKey === 'string'
        && albumInfo.albumKey
        && albumInfo.albumKey.indexOf('spotify:') !== 0) {
        key = albumInfo.albumKey;
    }
    if (!key) return;
    const t = (typeof playlist !== 'undefined' && playlist[currentTrack]) || null;
    _forcedAlbumForVideo = (t && t.videoId) || null;
    _applyAlbumState({
        notFound:    false,
        albumKey:    key,
        albumName:   albumInfo.albumName || '',
        albumArtist: albumInfo.albumArtist || '',
        albumImage:  albumInfo.image     || '',
        isSingle:    false,
        matchTitle:  albumInfo.matchTitle || '',
        albumUrl:    '',
    });
};

function _applyAlbumState(payload) {
    /* Reset por defecto: sin álbum, título no clickable. */
    _currentAlbum = null;
    if (playerTitle) playerTitle.classList.remove('has-album');
    /* Aceptamos payloads del nuevo formato (albumKey con prefijo) y los
       legacy (solo spotifyAlbumId). _normalizeAlbumPayload ya sintetiza
       albumKey si falta, pero por defensa lo replicamos aquí. */
    const albKey = (payload && (payload.albumKey
                                || (payload.spotifyAlbumId ? ('spotify:' + payload.spotifyAlbumId) : '')))
                   || '';
    if (payload && !payload.notFound && albKey) {
        _currentAlbum = {
            albumKey:       albKey,
            spotifyAlbumId: payload.spotifyAlbumId || '',
            albumName:      payload.albumName || '',
            albumArtist:    payload.albumArtist || payload.matchArtist || '',
            image:          payload.albumImage || '',
            isSingle:       !!payload.isSingle,
            /* Título del track que matcheó dentro del álbum — usado por
               el viewer para destacar la fila correspondiente. */
            matchTitle:     payload.matchTitle || '',
            albumUrl:       payload.albumUrl   || '',
        };
        if (playerTitle) {
            playerTitle.classList.add('has-album');
            playerTitle.title = 'Reproducir álbum completo: ' + _currentAlbum.albumName;
        }
        /* La carátula del reproductor pasa a ser la del ÁLBUM (no la
           miniatura de YouTube) cuando se resuelve. Si el álbum está
           vinculado pero sin imagen, la sacamos de album-tracks. */
        if (playerCover) {
            if (_currentAlbum.image) {
                playerCover.crossOrigin = 'anonymous';
                playerCover.src = _currentAlbum.image;
            } else if (_currentAlbum.albumKey && typeof _albumCoverFromKey === 'function') {
                var _curVid = (typeof playlist !== 'undefined' && playlist[currentTrack]) ? playlist[currentTrack].videoId : null;
                _albumCoverFromKey(_currentAlbum.albumKey, function(img){
                    /* Solo si seguimos en la misma canción. */
                    var v = (typeof playlist !== 'undefined' && playlist[currentTrack]) ? playlist[currentTrack].videoId : null;
                    if (v === _curVid) { playerCover.crossOrigin = 'anonymous'; playerCover.src = img; }
                });
            }
        }
    } else if (playerTitle) {
        playerTitle.title = '';
    }
}

/* ── Reproducir álbum como playlist temporal ──
   El album se trata como una "playlist no guardada": IDs virtuales con
   prefijo "spotify-album:" para que la sync entre tabs y el highlight
   de currentPlaylistId no choquen con IDs reales (que son numéricos).

   Estado:
     - _albumLoading: true mientras está en flight, para deduplicar
       clicks rápidos.
     - Si el track actual está en el tracklist resuelto, arrancamos
       desde esa posición — el cambio se siente como "seguir escuchando
       pero ahora con next/prev navegando el álbum". Si no, desde la 0. */
/* ── Album viewer ──
   El click en el título de la canción (reproductor pequeño) o en el
   nombre del álbum bajo un track del editor de playlist abre esta
   ventana de previsualización con la tracklist. NO reproduce nada hasta
   que el usuario lo pide explícitamente (botón "Reproducir álbum" o
   click en una canción). El loading sigue una secuencia:
     1) album-tracks → pintar metadata + lista al instante.
     2) yt-search-batch en background → habilita reproducción.
   Mientras (2) está en flight, el botón "Reproducir" y los clicks en
   tracks quedan disabled — el usuario ve la lista pero no puede tocar
   nada todavía. */
let _albumViewerCurrent = null; /* { albumId, meta, resolved } */
/* Cada apertura incrementa el token. Los awaits en background
   comparan contra el token vigente al volver: si cambió (otro click
   inició una nueva apertura, o el usuario cerró), descartan su
   resultado sin tocar nada. Patrón "cancelable async" — más robusto
   que un boolean _loading que puede quedar atascado en true si un
   error rompe el finally o el cierre interrumpe el flow. */
let _albumViewerToken = 0;

function _albumViewerEl(id) { return document.getElementById(id); }

/* La misma ventana sirve de visor de álbum y de playlist compartida
   (enlace.php?playlist=N). Cambia el título, el nombre en la barra de
   tareas y el botón de guardar. */
function _albumViewerSetMode(win, mode) {
    const prev = win.dataset.mode || 'album';
    win.dataset.mode = mode;
    const label = mode === 'playlist' ? 'Playlist' : 'Álbum';
    const t = _albumViewerEl('album-viewer-title-text');
    if (t) t.textContent = label;
    const save = _albumViewerEl('album-viewer-save');
    if (save) { save.style.display = 'none'; save.disabled = false; save.onclick = null; }
    if (prev !== mode && window.taskbarManager && taskbarManager.isRegistered('album-viewer')) {
        try { taskbarManager.unregister('album-viewer'); } catch (_) {}
    }
}

/* Playlist de otra persona abierta desde un enlace: la ve cualquiera con
   sesión (music api shared-playlist). Se reproduce como cola suelta (no
   queda como "playlist actual", que es de otro) y se puede guardar una
   copia en mis playlists. */
async function openSharedPlaylistViewer(playlistId) {
    const win = _albumViewerEl('album-viewer');
    if (!win || !playlistId) return;
    const myToken = ++_albumViewerToken;
    _albumViewerSetMode(win, 'playlist');
    const nameEl   = _albumViewerEl('album-viewer-name');
    const artistEl = _albumViewerEl('album-viewer-artist');
    const metaEl   = _albumViewerEl('album-viewer-meta');
    const coverEl  = _albumViewerEl('album-viewer-cover');
    const tracksEl = _albumViewerEl('album-viewer-tracks');
    const playBtn  = _albumViewerEl('album-viewer-play');
    const dlBtn    = _albumViewerEl('album-viewer-download');
    const saveBtn  = _albumViewerEl('album-viewer-save');
    nameEl.textContent = 'Cargando…';
    artistEl.textContent = '';
    metaEl.textContent = '';
    coverEl.src = '';
    tracksEl.innerHTML = '<div class="album-viewer-msg">Cargando…</div>';
    playBtn.disabled = true;
    playBtn.innerHTML = '<span class="album-viewer-play-tri">▶</span> Reproducir';
    dlBtn.disabled = true;
    _albumViewerCurrent = null;
    if (window.taskbarManager) {
        if (taskbarManager.isRegistered('album-viewer')) taskbarManager.restore('album-viewer');
        else taskbarManager.register('album-viewer', 'Playlist',
            '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
            'flex');
    } else {
        win.style.display = 'flex';
    }

    let pl;
    try {
        const r = await fetch('assets/music/api.php?action=shared-playlist&id=' + encodeURIComponent(playlistId));
        pl = await r.json();
    } catch (_) { pl = null; }
    if (myToken !== _albumViewerToken) return;
    if (!pl || !pl.ok) {
        nameEl.textContent = 'Playlist';
        tracksEl.innerHTML = '<div class="album-viewer-msg"></div>';
        tracksEl.firstChild.textContent = (pl && pl.error) || 'No se pudo abrir la playlist.';
        return;
    }
    const tracks = (pl.tracks || []).filter(t => t && t.videoId);
    nameEl.textContent   = pl.name || 'Playlist';
    artistEl.textContent = pl.mine ? '' : 'Playlist de ' + pl.ownerLabel;
    coverEl.src = pl.image || (tracks[0] ? 'https://i.ytimg.com/vi/' + tracks[0].videoId + '/mqdefault.jpg' : '');
    let totalSec = 0;
    tracks.forEach(t => { totalSec += t.duration || 0; });
    let metaText = tracks.length + (tracks.length === 1 ? ' canción' : ' canciones');
    if (totalSec) {
        const h = Math.floor(totalSec / 3600), m = Math.floor((totalSec % 3600) / 60);
        metaText += ' · ' + (h > 0 ? h + 'h ' : '') + m + 'm';
    }
    metaEl.textContent = metaText;

    tracksEl.innerHTML = '';
    if (!tracks.length) tracksEl.innerHTML = '<div class="album-viewer-msg">Esta playlist está vacía.</div>';
    tracks.forEach((t, i) => {
        const row = document.createElement('div');
        row.className = 'album-viewer-row';
        const num = document.createElement('span');
        num.className = 'album-viewer-num';
        num.textContent = i + 1;
        const info = document.createElement('div');
        info.className = 'album-viewer-info';
        const ttl = document.createElement('div');
        ttl.className = 'album-viewer-title';
        ttl.textContent = t.title;
        const ar = document.createElement('div');
        ar.className = 'album-viewer-artist';
        ar.textContent = t.artist || '';
        info.appendChild(ttl); info.appendChild(ar);
        const dur = document.createElement('span');
        dur.className = 'album-viewer-dur';
        dur.textContent = t.duration ? formatTime(t.duration) : '';
        row.appendChild(num); row.appendChild(info); row.appendChild(dur);
        row.addEventListener('click', () => _playSharedPlaylistFrom(i));
        row.addEventListener('contextmenu', ev => {
            ev.preventDefault();
            if (typeof window.openTrackCtxMenu === 'function') {
                window.openTrackCtxMenu(ev, { title: t.title, artist: t.artist, videoId: t.videoId, duration: t.duration });
            }
        });
        tracksEl.appendChild(row);
    });

    _albumViewerCurrent = { shared: true, playlistId: pl.id, meta: { name: pl.name, image: pl.image }, resolved: tracks };
    if (!tracks.length) return;
    playBtn.disabled = false;
    playBtn.onclick = () => _playSharedPlaylistFrom(0);
    dlBtn.disabled = false;
    dlBtn.onclick = () => openBulkDownloadDialog({ name: pl.name, cover: pl.image || '', tracks: tracks });
    if (!pl.mine) {
        saveBtn.style.display = '';
        saveBtn.onclick = () => _saveSharedPlaylist(pl, tracks, saveBtn);
    }
}

function _playSharedPlaylistFrom(i) {
    const cur = _albumViewerCurrent;
    if (!cur || !cur.shared || !cur.resolved.length) return;
    const start = Math.max(0, Math.min(i, cur.resolved.length - 1));
    window.__melonLinkPlayed = true;
    currentPlaylistId = null;
    currentPlaylistHasCollabs = false;
    playlist.length = 0;
    cur.resolved.forEach(t => playlist.push({ videoId: t.videoId, title: t.title, artist: t.artist, duration: t.duration || 0 }));
    currentTrack = start;
    updatePlayerTitle(cur.meta.name || 'Playlist');
    updateTrackUI(start);
    if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') ytPlayer.loadVideoById(playlist[start].videoId);
    closeAlbumViewer();
}

/* Reproduce una playlist por su id desde la canción `index` sin abrir el
   visor (expositor "Playlist destacada" del perfil). Si es mía o colaboro
   en ella, como desde Mis playlists (queda como playlist actual); si es
   de otro, como cola suelta, igual que el visor de playlists compartidas. */
async function melonPlayPlaylistById(playlistId, index) {
    let pl;
    try {
        const r = await fetch('assets/music/api.php?action=shared-playlist&id=' + encodeURIComponent(playlistId));
        pl = await r.json();
    } catch (_) { pl = null; }
    if (!pl || !pl.ok) { alert((pl && pl.error) || 'No se pudo abrir la playlist.'); return; }
    const tracks = (pl.tracks || []).filter(t => t && t.videoId);
    if (!tracks.length) return;
    const start = Math.max(0, Math.min(index | 0, tracks.length - 1));
    if (pl.mine && typeof window.melonTrackAction === 'function') {
        window.melonTrackAction('play-playlist', { playlistId: pl.id, index: start });
        return;
    }
    window.__melonLinkPlayed = true;
    currentPlaylistId = null;
    currentPlaylistHasCollabs = false;
    playlist.length = 0;
    tracks.forEach(t => playlist.push({ videoId: t.videoId, title: t.title, artist: t.artist, duration: t.duration || 0 }));
    currentTrack = start;
    updatePlayerTitle(pl.name || 'Playlist');
    updateTrackUI(start);
    if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') ytPlayer.loadVideoById(playlist[start].videoId);
}
window.melonPlayPlaylistById = melonPlayPlaylistById;

/* Copia la playlist (nombre, imagen y canciones) en una nueva mía. */
function _saveSharedPlaylist(pl, tracks, btn) {
    btn.disabled = true;
    const me = (typeof usersInfo !== 'undefined' && usersInfo[currentUserKey]) ? usersInfo[currentUserKey].label : '';
    fetch('assets/music/api.php?action=save-playlist-item', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: 'pl_' + Date.now(), name: pl.name, image: pl.image || '',
            tracks: tracks.map(t => ({ videoId: t.videoId, title: t.title, artist: t.artist || '', duration: t.duration || 0, addedBy: me })),
        }),
    }).then(r => r.json()).then(d => {
        if (!d || !d.ok) throw new Error((d && d.error) || 'No se pudo guardar');
        btn.textContent = '✓ Guardada';
        if (typeof refreshPlaylists === 'function') refreshPlaylists();
        if (window.notifSystem) notifSystem.show({ id: 'pl-saved-' + d.id, type: 'info', title: 'Playlists',
            message: '"' + pl.name + '" está ahora en tus playlists.', autoDismissAfter: 5000 });
    }).catch(e => {
        btn.disabled = false;
        alert(e.message || 'No se pudo guardar');
    });
}

function closeAlbumViewer() {
    const win = _albumViewerEl('album-viewer');
    if (!win) return;
    if (window.taskbarManager) {
        try { taskbarManager.unregister('album-viewer'); } catch (_) {}
    }
    win.style.display = 'none';
    _albumViewerCurrent = null;
    /* Invalida cualquier carga en vuelo — su .then() verá un token
       distinto al actual y se autodescartará al volver. */
    _albumViewerToken++;
}

async function openAlbumViewer(albumId, albumName, albumArtist, albumImage) {
    if (!albumId) return;
    const win = _albumViewerEl('album-viewer');
    if (!win) return;
    /* Token de esta apertura concreta. Cualquier await tardío que use
       _albumViewerToken para verificar verá un valor distinto si entre
       medias alguien cerró o abrió otro álbum. */
    const myToken = ++_albumViewerToken;
    _albumViewerSetMode(win, 'album');

    const nameEl   = _albumViewerEl('album-viewer-name');
    const artistEl = _albumViewerEl('album-viewer-artist');
    const metaEl   = _albumViewerEl('album-viewer-meta');
    const coverEl  = _albumViewerEl('album-viewer-cover');
    const tracksEl = _albumViewerEl('album-viewer-tracks');
    const playBtn  = _albumViewerEl('album-viewer-play');

    /* Reset visual al estado de "cargando". Usamos lo que ya
       conocemos de _currentAlbum (cover, nombre) para no esperar al
       endpoint para enseñar algo. */
    nameEl.textContent   = albumName || (_currentAlbum && _currentAlbum.albumName) || 'Cargando…';
    artistEl.textContent = albumArtist || '';
    metaEl.textContent   = '';
    /* Portada del álbum CLICADO (si la conocemos) — no la del que suena. */
    coverEl.src          = albumImage || (_currentAlbum && _currentAlbum.image) || '';
    tracksEl.innerHTML   = '<div class="album-viewer-msg">Cargando…</div>';
    playBtn.disabled     = true;
    playBtn.textContent  = '▶ Reproducir álbum';
    const dlBtn = _albumViewerEl('album-viewer-download');
    if (dlBtn) dlBtn.disabled = true;

    /* Abrir ventana usando taskbarManager (mismo patrón que
       playlist-editor) para que se registre en la taskbar. */
    if (window.taskbarManager) {
        if (taskbarManager.isRegistered('album-viewer')) {
            taskbarManager.restore('album-viewer');
        } else {
            taskbarManager.register('album-viewer', 'Álbum',
                '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
                'flex');
        }
    } else {
        win.style.display = 'flex';
    }

    try {
        /* Si el albumId es sintético (find-album no encontró match en
           Spotify), no fetcheamos: construimos la tracklist localmente
           a partir del track que está sonando ahora. El usuario igual
           ve la ventana con una entrada — el título sigue siendo
           clickable, mantenemos la promesa de "TODAS las canciones
           tienen álbum". */
        let meta;
        if (typeof albumId === 'string' && albumId.startsWith('synthetic:')) {
            const curTrack = (typeof playlist !== 'undefined' && playlist[currentTrack]) || null;
            const t = curTrack || {};
            meta = {
                name:   (_currentAlbum && _currentAlbum.albumName) || albumName || t.title || 'Single',
                artist: t.artist || '',
                image:  t.videoId ? ('https://img.youtube.com/vi/' + t.videoId + '/mqdefault.jpg') : '',
                tracks: [{
                    title:    t.title  || 'Track',
                    artist:   t.artist || '',
                    duration: 0,
                }],
                __synthetic: true,
                __syntheticVideoId: t.videoId || null,
            };
            if (myToken !== _albumViewerToken) return;
        } else {
            /* `albumId` puede venir como 'itunes:123' / 'deezer:456' /
               'spotify:abc' (formato albumKey) o como un id Spotify
               desnudo (legacy). El backend acepta ?key= para el primer
               formato y ?id= para el legacy, lo detectamos por el ':'.
               name + artist son HINTS para el fallback: si la key es
               spotify:* y Spotify está banneado, el server intenta
               resolver el mismo álbum en iTunes/Deezer por nombre. */
            const param = (typeof albumId === 'string' && albumId.indexOf(':') !== -1)
                ? 'key=' + encodeURIComponent(albumId)
                : 'id='  + encodeURIComponent(albumId);
            /* Si el caller no nos pasó hints, los tomamos del contexto
               actual (_currentAlbum.albumName/albumArtist o playlist
               track activo) — sin esto, álbumes spotify: del perfil
               sin artist daban 404 (el name solo no es discriminante
               en japonés, soundtracks, etc.). */
            const effName   = albumName
                            || (_currentAlbum && _currentAlbum.albumName)
                            || '';
            const effArtist = albumArtist
                            || (_currentAlbum && _currentAlbum.albumArtist)
                            || ((typeof playlist !== 'undefined' && playlist[currentTrack]) ? playlist[currentTrack].artist : '')
                            || '';
            let trackUrl = 'assets/music/api.php?action=album-tracks&' + param;
            if (effName)   trackUrl += '&name='   + encodeURIComponent(effName);
            if (effArtist) trackUrl += '&artist=' + encodeURIComponent(effArtist);
            const metaRes = await fetch(trackUrl);
            if (myToken !== _albumViewerToken) return; /* obsoleto */
            if (!metaRes.ok) throw new Error('No se pudo leer el álbum');
            meta = await metaRes.json();
            if (myToken !== _albumViewerToken) return;
            if (!meta.tracks || !meta.tracks.length) throw new Error('Álbum sin canciones');
        }

        nameEl.textContent   = meta.name || albumName || 'Álbum';
        artistEl.textContent = meta.artist || '';
        if (meta.image) coverEl.src = meta.image;

        /* Duración total bonita: "12 canciones · 48m" o similar. */
        let totalSec = 0, hasDur = false;
        meta.tracks.forEach(t => { if (t.duration) { totalSec += t.duration; hasDur = true; } });
        let metaText = meta.tracks.length + ' canciones';
        if (hasDur) {
            const h = Math.floor(totalSec / 3600);
            const m = Math.floor((totalSec % 3600) / 60);
            metaText += ' · ' + (h > 0 ? h + 'h ' : '') + m + 'm';
        }
        metaEl.textContent = metaText;

        /* Render de la lista. Los clicks llaman a _playAlbumFrom
           pasando el índice ORIGINAL; ahí decidimos si esa canción
           se resolvió en YouTube (y si no, alertamos). */
        tracksEl.innerHTML = '';
        meta.tracks.forEach((t, i) => {
            const row = document.createElement('div');
            row.className = 'album-viewer-row';
            row.dataset.idx = i;
            const num = document.createElement('span');
            num.className = 'album-viewer-num';
            num.textContent = i + 1;
            const info = document.createElement('div');
            info.className = 'album-viewer-info';
            const ttl = document.createElement('div');
            ttl.className = 'album-viewer-title';
            ttl.textContent = t.title;
            const ar = document.createElement('div');
            ar.className = 'album-viewer-artist';
            ar.textContent = t.artist;
            info.appendChild(ttl); info.appendChild(ar);
            const dur = document.createElement('span');
            dur.className = 'album-viewer-dur';
            dur.textContent = t.duration ? formatTime(t.duration) : '';
            row.appendChild(num); row.appendChild(info); row.appendChild(dur);
            row.dataset.title = t.title || '';
            row.dataset.artist = t.artist || meta.artist || '';
            row.addEventListener('click', () => _playAlbumFrom(i));
            /* Click derecho → menú contextual de la canción: añadir a
               playlist / al perfil. Reusamos el ctx menú de tracks ya
               existente del reproductor. */
            row.addEventListener('contextmenu', ev => {
                ev.preventDefault();
                const trackData = { title: t.title, artist: t.artist, videoId: null };
                const r = _albumViewerCurrent && _albumViewerCurrent.resolved;
                if (r && r[i] && r[i].videoId) trackData.videoId = r[i].videoId;
                if (typeof window.openTrackCtxMenu === 'function') {
                    window.openTrackCtxMenu(ev, trackData);
                }
            });
            tracksEl.appendChild(row);
        });

        /* Notas de la comunidad: la media del álbum (sus reseñas propias)
           en la cabecera, y la media de cada canción reseñada en su fila
           (las reseñas de canciones van por separado, no se mezclan con
           la del álbum). */
        loadMusicRatings().then(function(){
            if (myToken !== _albumViewerToken) return;
            Array.from(tracksEl.querySelectorAll('.album-viewer-row')).forEach(function(row){
                if (row.querySelector('.pl-sr-rating')) return;
                var rt = mrLookup('songs', row.dataset.title, row.dataset.artist || meta.artist);
                if (!rt) return;
                var durEl = row.querySelector('.album-viewer-dur');
                if (durEl) durEl.insertAdjacentHTML('beforebegin', mrBadge(rt));
                else row.insertAdjacentHTML('beforeend', mrBadge(rt));
            });
            var own = mrLookup('albums', meta.name || albumName, meta.artist);
            if (own && metaEl && !metaEl.querySelector('.pl-sr-rating')) {
                metaEl.insertAdjacentHTML('beforeend',
                    ' &nbsp; <span class="pl-sr-rating" title="Media del álbum (' + own.count + ' reseña' + (own.count === 1 ? '' : 's') + ')">★ ' + own.avg.toFixed(1) + '</span>');
            }
        });

        _albumViewerCurrent = { albumId, meta, resolved: null };
        /* Si find-album devolvió matchTrackId, lo guardamos para destacar
           la fila correspondiente (la canción desde la que se buscó). */
        if (_currentAlbum && _currentAlbum.matchTitle) {
            const matchNorm = (_currentAlbum.matchTitle || '').toLowerCase().trim();
            Array.from(tracksEl.querySelectorAll('.album-viewer-row')).forEach((r, i) => {
                if ((meta.tracks[i].title || '').toLowerCase().trim() === matchNorm) {
                    r.classList.add('is-playing');
                    /* Scroll para que el track encontrado esté visible. */
                    r.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        /* 2) Resolver videoIds. Para álbumes sintéticos saltamos el
           yt-search-batch — el videoId ya lo conocemos (es el del track
           sonando ahora). Para álbumes reales, fetch en background. */
        let resolved;
        if (meta.__synthetic) {
            resolved = [{
                title:    meta.tracks[0].title,
                artist:   meta.tracks[0].artist,
                duration: 0,
                videoId:  meta.__syntheticVideoId || null,
            }];
        } else {
            const ytRes = await fetch('assets/music/api.php?action=yt-search-batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tracks: meta.tracks }),
            });
            if (myToken !== _albumViewerToken) return;
            if (!ytRes.ok) throw new Error('No se pudo resolver el álbum en YouTube');
            const ytData = await ytRes.json();
            if (myToken !== _albumViewerToken) return;
            /* yt-search-batch puede devolver menos items que los enviados
               (descarta los que no tienen título). Mapeo por título. */
            const ytTracks = ytData.tracks || [];
            const byTitle = {};
            ytTracks.forEach(t => { if (t && t.title) byTitle[t.title] = t.videoId || null; });
            resolved = meta.tracks.map(t => ({
                title:   t.title,
                artist:  t.artist,
                duration: t.duration || 0,
                videoId: byTitle[t.title] || null,
            }));
        }

        _albumViewerCurrent.resolved = resolved;

        /* Marca los rows sin videoId como deshabilitados visualmente. */
        Array.from(tracksEl.querySelectorAll('.album-viewer-row')).forEach((row, i) => {
            if (!resolved[i].videoId) {
                row.classList.add('is-disabled');
                row.title = 'Esta canción no se encontró en YouTube';
            }
        });

        const anyPlayable = resolved.some(t => t.videoId);
        if (anyPlayable && dlBtn) {
            dlBtn.disabled = false;
            dlBtn.onclick = () => openBulkDownloadDialog({
                name: [meta.artist, meta.name].filter(Boolean).join(' - ') || 'Álbum',
                album: meta.name || albumName || '', cover: meta.image || albumImage || '',
                tracks: resolved.filter(t => t.videoId).map(t => ({ videoId: t.videoId, title: t.title, artist: t.artist || meta.artist || '' })),
            });
        }
        if (anyPlayable) {
            playBtn.disabled = false;
            playBtn.onclick = () => _playAlbumFrom(0);
        } else {
            playBtn.textContent = 'No disponible';
        }
    } catch (e) {
        /* Solo pintamos el error si nuestro token sigue siendo el
           vigente — si no, esta apertura fue superada por otra y la UI
           ya está mostrando el álbum nuevo. */
        if (myToken === _albumViewerToken) {
            /* No se pudieron leer las pistas del álbum. ANTES se pintaba
               aquí un falso álbum con la canción que estaba SONANDO (con su
               título e imagen), lo que confundía: parecía que el álbum era
               esa única canción. Ahora mostramos la cabecera REAL del álbum
               clicado (su nombre y portada) con un mensaje honesto. */
            nameEl.textContent   = albumName || (typeof meta !== 'undefined' && meta && meta.name) || 'Álbum';
            artistEl.textContent = albumArtist || (typeof meta !== 'undefined' && meta && meta.artist) || '';
            metaEl.textContent   = '';
            coverEl.src          = albumImage || coverEl.src || '';
            tracksEl.innerHTML   = '<div class="album-viewer-msg">No se pudieron cargar las canciones de este álbum.<br>Puede que no esté disponible en iTunes/Deezer.</div>';
            playBtn.disabled     = true;
            playBtn.textContent  = 'No disponible';
            _albumViewerCurrent  = { albumId, meta: null, resolved: null };
        }
    }
}

/* Carga el álbum como playlist activa y empieza a reproducir desde el
   índice original. Si la canción de inicio no se pudo resolver,
   buscamos la siguiente que sí. */
function _playAlbumFrom(origIdx) {
    if (!_albumViewerCurrent || !_albumViewerCurrent.resolved) {
        /* Aún cargando videoIds. */
        return;
    }
    if (_albumViewerCurrent.shared) return _playSharedPlaylistFrom(origIdx);
    const { albumId, meta, resolved } = _albumViewerCurrent;
    /* Filtrar a las canciones que SÍ tienen videoId — son las que se
       reproducirán. Mantenemos el orden del álbum original. */
    const playable = resolved.filter(t => t.videoId);
    if (!playable.length) {
        alert('Ninguna canción de este álbum se encontró en YouTube.');
        return;
    }
    /* Mapeo del índice original al índice en la lista filtrada. Si la
       canción concreta no se resolvió, arrancamos desde la SIGUIENTE
       que sí. */
    let actualStart = -1;
    for (let i = origIdx; i < resolved.length; i++) {
        if (resolved[i].videoId) {
            actualStart = playable.indexOf(resolved[i]);
            break;
        }
    }
    if (actualStart < 0) actualStart = 0;

    _loadAlbumQueue(albumId, meta, playable, actualStart);
    /* Cerrar el viewer al pulsar play — el usuario ya tiene el track
       sonando en el reproductor pequeño. */
    closeAlbumViewer();
}

/* Pone las canciones `playable` (todas con videoId) del álbum como cola
   y arranca en `start`. Lo usan el viewer y el reproductor flotante de
   la app de escritorio (melonTrackAction 'play-album'). */
function _loadAlbumQueue(albumId, meta, playable, start) {
    const virtualId = 'spotify-album:' + albumId;
    currentPlaylistId = virtualId;
    currentPlaylistHasCollabs = false;
    playlist.length = 0;
    playable.forEach(t => playlist.push({ videoId: t.videoId, title: t.title, artist: t.artist }));
    currentTrack = start;
    /* Sin emoji: el título de la ventana del reproductor identifica
       el álbum por el nombre solo. */
    updatePlayerTitle(meta.name || 'Álbum');
    updateTrackUI(start);
    if (typeof MelonPlayerState !== 'undefined') {
        try { MelonPlayerState.setPlaylist(virtualId, start); } catch (_) {}
    }
    if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
        ytPlayer.loadVideoById(playlist[start].videoId);
    }
    /* Loguear como álbum escuchado para wrapped. Una sola llamada por
       sesión de play (dedupe 60s en backend). */
    _logAlbumPlay({ albumId, meta });
}

/* Click en el TÍTULO de la canción → abre la ventana del álbum
   (no reproduce). Solo actúa cuando el título tiene la clase
   has-album. */
if (playerTitle) {
    playerTitle.addEventListener('click', () => {
        if (!playerTitle.classList.contains('has-album')) return;
        if (!_currentAlbum) return;
        openAlbumViewer(_currentAlbum.albumKey || _currentAlbum.spotifyAlbumId, _currentAlbum.albumName, _currentAlbum.albumArtist || '', _currentAlbum.image || '');
    });
}

/* Cerrar el viewer con el botón X de su title bar. */
(function() {
    const closeBtn = document.getElementById('album-viewer-close');
    if (closeBtn) closeBtn.addEventListener('click', closeAlbumViewer);
})();

/* ── Draggable de la title bar ── (mismo patrón que player-titlebar). */
(function() {
    const win      = document.getElementById('album-viewer');
    const titlebar = document.getElementById('album-viewer-titlebar');
    if (!win || !titlebar) return;
    let dragging = false, ox, oy, pid = -1;
    titlebar.addEventListener('pointerdown', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        dragging = true;
        pid = e.pointerId;
        try { titlebar.setPointerCapture(pid); } catch (_) {}
        const rect = win.getBoundingClientRect();
        /* Al arrastrar por primera vez: convertir el centrado
           (transform translate(-50%,-50%)) en left/top absolutos para
           que el mover por delta sea sin saltos. */
        win.style.left      = rect.left + 'px';
        win.style.top       = rect.top  + 'px';
        win.style.transform = 'none';
        ox = e.clientX - rect.left;
        oy = e.clientY - rect.top;
    });
    titlebar.addEventListener('pointermove', function(e) {
        if (!dragging || e.pointerId !== pid) return;
        win.style.left = (e.clientX - ox) + 'px';
        win.style.top  = (e.clientY - oy) + 'px';
    });
    function end(e) {
        if (e && e.pointerId !== pid) return;
        dragging = false; pid = -1;
        try { titlebar.releasePointerCapture(e ? e.pointerId : pid); } catch (_) {}
    }
    titlebar.addEventListener('pointerup',     end);
    titlebar.addEventListener('pointercancel', end);
})();

/* ── Resize handle (esquina inferior derecha) ──
   Pointer Events para mouse + touch. Min size definido en CSS pero lo
   replicamos aquí en JS para no depender de getComputedStyle por
   frame. */
(function() {
    const win = document.getElementById('album-viewer');
    if (!win) return;
    const handles = win.querySelectorAll('.av-resize');
    if (!handles.length) return;
    const MIN_W = 280, MIN_H = 220;
    /* Estado del drag actual: edge marca qué lados/esquinas mover. */
    let pid = -1, activeHandle = null;
    let edge = '', startX = 0, startY = 0;
    let startW = 0, startH = 0, startL = 0, startT = 0;

    function onDown(e) {
        e.preventDefault();
        e.stopPropagation();
        activeHandle = e.currentTarget;
        edge = activeHandle.dataset.edge || '';
        pid  = e.pointerId;
        try { activeHandle.setPointerCapture(pid); } catch (_) {}
        /* Si la ventana sigue centrada por transform, la pasamos a
           coords absolutas para que width/height/left/top se apliquen
           sin que el transform la re-centre. */
        const rect = win.getBoundingClientRect();
        win.style.left      = rect.left + 'px';
        win.style.top       = rect.top  + 'px';
        win.style.transform = 'none';
        startX = e.clientX;
        startY = e.clientY;
        startW = win.offsetWidth;
        startH = win.offsetHeight;
        startL = rect.left;
        startT = rect.top;
    }
    function onMove(e) {
        if (e.pointerId !== pid) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        let newW = startW, newH = startH, newL = startL, newT = startT;
        /* Lados W/E afectan width (y left si W). N/S afectan height
           (y top si N). Las esquinas combinan dos lados. */
        if (edge.indexOf('e') !== -1) {
            newW = Math.max(MIN_W, startW + dx);
        }
        if (edge.indexOf('w') !== -1) {
            newW = Math.max(MIN_W, startW - dx);
            /* Si chocamos con MIN_W, congelamos left para no resbalar. */
            newL = startL + (startW - newW);
        }
        if (edge.indexOf('s') !== -1) {
            newH = Math.max(MIN_H, startH + dy);
        }
        if (edge.indexOf('n') !== -1) {
            newH = Math.max(MIN_H, startH - dy);
            newT = startT + (startH - newH);
        }
        /* Cap a viewport: -16px de margen, sin permitir que la ventana
           se salga por arriba/izquierda. */
        newW = Math.min(newW, window.innerWidth  - 16);
        newH = Math.min(newH, window.innerHeight - 16);
        newL = Math.max(8, Math.min(newL, window.innerWidth  - newW - 8));
        newT = Math.max(8, Math.min(newT, window.innerHeight - newH - 8));
        win.style.width  = newW + 'px';
        win.style.height = newH + 'px';
        win.style.left   = newL + 'px';
        win.style.top    = newT + 'px';
    }
    function onUp(e) {
        if (e && e.pointerId !== pid) return;
        try { activeHandle && activeHandle.releasePointerCapture(e ? e.pointerId : pid); } catch (_) {}
        pid = -1; activeHandle = null; edge = '';
    }
    handles.forEach(function(h){
        h.addEventListener('pointerdown',   onDown);
        h.addEventListener('pointermove',   onMove);
        h.addEventListener('pointerup',     onUp);
        h.addEventListener('pointercancel', onUp);
    });
})();

/* ── Context menu del cover del álbum ──
   Click derecho → opciones para añadir el ÁLBUM ENTERO a una playlist
   o al perfil. Patrón: añadir todos los tracks resueltos a la playlist
   de destino (uno a uno respetando dedupe), o llamar al endpoint del
   perfil que registra un álbum. */
(function() {
    const cover = document.getElementById('album-viewer-cover');
    if (!cover) return;
    cover.addEventListener('contextmenu', ev => {
        ev.preventDefault();
        if (!_albumViewerCurrent) return;
        _showAlbumCtxMenu(ev.clientX, ev.clientY);
    });
})();

/* Construye un menú flotante reusando el chrome del ctx menu de
   tracks. Lo posicionamos absoluto al hacer click. */
function _showAlbumCtxMenu(x, y) {
    /* Reusamos el contenedor del ctx menu existente (#pl-ctx-menu-el),
       que ya tiene CSS + auto-cierre al click fuera. */
    const menu = document.getElementById('pl-ctx-menu-el');
    if (!menu || !_albumViewerCurrent) return;
    menu.innerHTML = '';
    const cur = _albumViewerCurrent;
    const linkItem = (tipo, valor) => {
        if (typeof window.melonCopyLink !== 'function') return;
        const it = document.createElement('div');
        it.className = 'pl-menu-item';
        it.textContent = '🔗 Copiar enlace';
        it.addEventListener('click', () => { menu.style.display = 'none'; window.melonCopyLink(tipo, valor); });
        menu.appendChild(it);
    };

    /* Playlist de un enlace: solo guardar copia y el enlace. */
    if (cur.shared) {
        const save = _albumViewerEl('album-viewer-save');
        if (save && save.style.display !== 'none' && !save.disabled) {
            const it = document.createElement('div');
            it.className = 'pl-menu-item';
            it.textContent = '➕ Guardar en mis playlists';
            it.addEventListener('click', () => { menu.style.display = 'none'; save.click(); });
            menu.appendChild(it);
        }
        linkItem('playlist', String(cur.playlistId));
    }

    if (!cur.shared) {
    const addPl = document.createElement('div');
    addPl.className = 'pl-menu-item';
    addPl.textContent = 'Añadir álbum a playlist';
    addPl.addEventListener('click', () => {
        menu.style.display = 'none';
        _addAlbumToPlaylistFlow();
    });
    menu.appendChild(addPl);

    const addProfile = document.createElement('div');
    addProfile.className = 'pl-menu-item';
    addProfile.textContent = 'Añadir álbum a mi perfil';
    addProfile.addEventListener('click', () => {
        menu.style.display = 'none';
        _addAlbumToProfile();
    });
    menu.appendChild(addProfile);

    /* Enlace del álbum (solo con clave real: itunes:/deezer:/spotify:). */
    if (typeof cur.albumId === 'string' && /^[a-z]+:[A-Za-z0-9_-]+$/.test(cur.albumId) && cur.albumId.indexOf('synthetic:') !== 0) {
        linkItem('album', cur.albumId);
    }
    }

    menu.style.display = 'block';
    const cw = menu.offsetWidth, ch = menu.offsetHeight;
    menu.style.left = Math.min(x, window.innerWidth  - cw - 8) + 'px';
    menu.style.top  = Math.min(y, window.innerHeight - ch - 8) + 'px';
}

/* Picker reutilizado para elegir playlist destino y volcar TODOS los
   tracks resueltos del álbum. Sin dedupe en backend para álbumes —
   confiamos en el check del cliente (mismo videoId no se duplica). */
async function _addAlbumToPlaylistFlow() {
    if (!_albumViewerCurrent || !_albumViewerCurrent.resolved) {
        alert('Aún resolviendo el álbum, prueba en un momento.');
        return;
    }
    const albumName = (_albumViewerCurrent.meta && _albumViewerCurrent.meta.name) || 'álbum';
    const tracksToAdd = _albumViewerCurrent.resolved
        .filter(t => t.videoId)
        .map(t => ({ title: t.title, artist: t.artist, videoId: t.videoId, duration: t.duration || 0 }));
    if (!tracksToAdd.length) { alert('El álbum no tiene canciones resueltas.'); return; }
    _showAlbumPlaylistPicker(albumName, tracksToAdd);
}

/* Carga un álbum por su key (itunes:/deezer:/spotify:), resuelve sus
   canciones en YouTube y abre el picker para añadirlas a una playlist.
   Lo usa el menú contextual de álbumes en la búsqueda y en la página de
   artista (donde el álbum NO está abierto en el visor). */
async function _addAlbumKeyToPlaylist(albumKey, albumName, albumArtist) {
    if (!albumKey) return;
    const param = (typeof albumKey === 'string' && albumKey.indexOf(':') !== -1)
        ? 'key=' + encodeURIComponent(albumKey)
        : 'id=' + encodeURIComponent(albumKey);
    let url = 'assets/music/api.php?action=album-tracks&' + param;
    if (albumName)   url += '&name='   + encodeURIComponent(albumName);
    if (albumArtist) url += '&artist=' + encodeURIComponent(albumArtist);
    let meta;
    try { const r = await fetch(url); if (!r.ok) throw 0; meta = await r.json(); }
    catch (_) { alert('No se pudo leer el álbum.'); return; }
    if (!meta || !meta.tracks || !meta.tracks.length) { alert('El álbum no tiene canciones.'); return; }
    let resolved;
    try {
        const yr = await fetch('assets/music/api.php?action=yt-search-batch', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tracks: meta.tracks }),
        });
        const yd = await yr.json();
        const byTitle = {};
        (yd.tracks || []).forEach(t => { if (t && t.title) byTitle[t.title] = t.videoId || null; });
        resolved = meta.tracks
            .map(t => ({ title: t.title, artist: t.artist, videoId: byTitle[t.title] || null, duration: t.duration || 0 }))
            .filter(t => t.videoId);
    } catch (_) { alert('No se pudo resolver el álbum en YouTube.'); return; }
    if (!resolved.length) { alert('Ninguna canción del álbum se encontró en YouTube.'); return; }
    _showAlbumPlaylistPicker(meta.name || albumName || 'álbum', resolved);
}

/* Picker de playlists destino para un conjunto de tracks ya resueltos
   (videoId). Reutilizable: lo usan el visor de álbum y el menú contextual
   de álbumes (búsqueda / página de artista). */
async function _showAlbumPlaylistPicker(albumName, tracksToAdd) {
    if (!tracksToAdd || !tracksToAdd.length) { alert('El álbum no tiene canciones.'); return; }
    /* Lista de playlists del usuario para elegir destino. */
    let playlists;
    try {
        const r = await fetch('assets/music/api.php?action=get-playlists');
        playlists = await r.json();
    } catch (_) { alert('Error de red.'); return; }
    if (!Array.isArray(playlists) || !playlists.length) {
        alert('No tienes playlists. Crea una primero.');
        return;
    }

    let picker = document.getElementById('add-album-picker');
    if (!picker) {
        picker = document.createElement('div');
        picker.className = 'window';
        picker.id = 'add-album-picker';
        picker.setAttribute('data-no-auto-z', '');
        picker.style.cssText = 'display:none;position:fixed;z-index:10000;min-width:200px;';
        picker.innerHTML =
            '<div class="title-bar"><div class="title-bar-text">Añadir álbum a playlist</div>' +
            '<div class="title-bar-controls"><button aria-label="Close" id="add-album-picker-close"></button></div></div>' +
            '<div class="window-body" style="padding:4px;max-height:240px;overflow-y:auto;" id="add-album-picker-list"></div>';
        document.body.appendChild(picker);
        document.getElementById('add-album-picker-close').addEventListener('click', () => {
            picker.style.display = 'none';
        });
    }
    const list = document.getElementById('add-album-picker-list');
    list.innerHTML = '';
    playlists.forEach((pl) => {
        const item = document.createElement('div');
        item.className = 'pl-menu-item';
        item.textContent = pl.name;
        item.addEventListener('click', async () => {
            picker.style.display = 'none';
            /* Fusiona los tracks del álbum con los de la playlist
               destino, omitiendo los videoIds ya presentes. */
            const existing = new Set((pl.tracks || []).map(t => t.videoId));
            const fresh = tracksToAdd.filter(t => !existing.has(t.videoId));
            if (!fresh.length) {
                alert('Todas las canciones del álbum ya estaban en "' + pl.name + '".');
                return;
            }
            const merged = (pl.tracks || []).concat(fresh);
            try {
                const r = await fetch('assets/music/api.php?action=save-playlist-item', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: pl.id, name: pl.name, tracks: merged }),
                });
                const data = await r.json();
                if (data && data.error) throw new Error(data.error);
                alert('✔ ' + fresh.length + ' canción(es) de "' + albumName + '" añadidas a "' + pl.name + '".');
            } catch (e) {
                alert('Error al guardar: ' + (e.message || e));
            }
        });
        list.appendChild(item);
    });

    /* Centra el picker en el viewport. */
    picker.style.display = 'block';
    const pw = picker.offsetWidth, ph = picker.offsetHeight;
    picker.style.left = Math.max(8, Math.round((window.innerWidth  - pw) / 2)) + 'px';
    picker.style.top  = Math.max(8, Math.round((window.innerHeight - ph) / 2)) + 'px';
}

/* Añade un objeto-álbum (de la búsqueda / página de artista) al perfil. */
function _addAlbumObjToProfile(album) {
    if (typeof window.profileAddAlbum !== 'function') {
        alert('La app de Perfil no está disponible en esta sesión.');
        return;
    }
    window.profileAddAlbum({
        name:           album.name   || '',
        artist:         album.artist || '',
        image:          album.image  || '',
        spotifyAlbumId: album.albumKey || '',
    });
}

/* Menú contextual de un ÁLBUM en la búsqueda / página de artista:
   añadir a una playlist o al perfil. Reusa el contenedor del ctx menu
   de tracks (#pl-ctx-menu-el) por su CSS + auto-cierre al click fuera. */
function showSearchAlbumCtxMenu(x, y, album) {
    const menu = document.getElementById('pl-ctx-menu-el');
    if (!menu || !album || !album.albumKey) return;
    menu.innerHTML = '';
    const addPl = document.createElement('div');
    addPl.className = 'pl-menu-item';
    addPl.textContent = '➕ Añadir a playlist';
    addPl.addEventListener('click', () => {
        menu.style.display = 'none';
        _addAlbumKeyToPlaylist(album.albumKey, album.name, album.artist);
    });
    menu.appendChild(addPl);
    const addProf = document.createElement('div');
    addProf.className = 'pl-menu-item';
    addProf.textContent = '🎵 Añadir a mi perfil';
    addProf.addEventListener('click', () => {
        menu.style.display = 'none';
        _addAlbumObjToProfile(album);
    });
    menu.appendChild(addProf);
    if (/^[a-z]+:[A-Za-z0-9_-]+$/.test(album.albumKey) && album.albumKey.indexOf('synthetic:') !== 0
            && typeof window.melonCopyLink === 'function') {
        const lnk = document.createElement('div');
        lnk.className = 'pl-menu-item';
        lnk.textContent = '🔗 Copiar enlace';
        lnk.addEventListener('click', () => { menu.style.display = 'none'; window.melonCopyLink('album', album.albumKey); });
        menu.appendChild(lnk);
    }
    menu.style.display = 'block';
    const cw = menu.offsetWidth, ch = menu.offsetHeight;
    menu.style.left = Math.min(x, window.innerWidth  - cw - 8) + 'px';
    menu.style.top  = Math.min(y, window.innerHeight - ch - 8) + 'px';
}

/* Una CANCIÓN de la página de artista (populares) no trae videoId: lo
   resolvemos en YouTube y abrimos el menú de track normal (añadir a
   playlist / al perfil / corregir). */
function _resolveAndOpenTrackCtx(title, artist, x, y) {
    if (!title) return;
    const q = (title + ' ' + (artist || '')).trim();
    fetch('assets/music/api.php?action=yt-search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(d => {
            const res = (d && d.results) || [];
            if (!res.length || !res[0].videoId) { alert('No se encontró la canción en YouTube.'); return; }
            if (typeof window.openTrackCtxMenu === 'function') {
                window.openTrackCtxMenu(
                    { preventDefault: function(){}, clientX: x, clientY: y },
                    { videoId: res[0].videoId, title: title, artist: artist || res[0].artist || '', duration: res[0].duration || 0 }
                );
            }
        })
        .catch(() => { alert('Error al resolver la canción.'); });
}

/* Registra el álbum en el perfil del usuario. Delegamos a
   window.profileAddAlbum (expuesto por perfil.php) — abre la app de
   perfil, mete el álbum en lists.music con type:'album' y ofrece
   reseña. Mantiene paridad con el flujo de "añadir canción al perfil". */
function _addAlbumToProfile() {
    if (!_albumViewerCurrent) return;
    const { albumId, meta } = _albumViewerCurrent;
    if (typeof window.profileAddAlbum !== 'function') {
        alert('La app de Perfil no está disponible en esta sesión.');
        return;
    }
    window.profileAddAlbum({
        name:           meta.name,
        artist:         meta.artist || '',
        /* La portada SIEMPRE es la del álbum que el usuario tiene en el
           viewer (meta.image). El fallback anterior a `_currentAlbum.image`
           era un bug: ese es el álbum del track SONANDO, no el que se
           está añadiendo. Si meta.image viene vacío, preferimos no foto
           a una foto equivocada. */
        image:          meta.image || '',
        spotifyAlbumId: albumId,
    });
}

/* Loguea una reproducción de álbum en wrapped — para que el resumen
   anual cuente "álbumes escuchados". Dedupe de 60s vive en backend.
   Lo llamamos desde _loadAlbumQueue (cuando el usuario realmente da
   play, sea botón "Reproducir álbum" o click directo en una canción).
   `album` = { albumId, meta }; sin él, el álbum abierto en el viewer. */
function _logAlbumPlay(album) {
    album = album || _albumViewerCurrent;
    if (!album) return;
    const { albumId, meta } = album;
    const body = JSON.stringify({
        albumTitle:     meta.name,
        artist:         meta.artist || '',
        actionType:     'play',
        spotifyAlbumId: albumId,
        /* Portada del álbum del viewer, no del track sonando — mismo
           bug que tenía `_addAlbumToProfile`. */
        coverUrl:       meta.image || '',
    });
    try {
        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon('assets/music/wrapped-api.php?action=log-album', blob)) return;
        }
    } catch (_) {}
    try {
        fetch('assets/music/wrapped-api.php?action=log-album', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true,
        }).catch(() => {});
    } catch (_) {}
}

/* Resuelve el álbum de un track concreto y rellena un <span> con su
   nombre, dejándolo clickable para abrir el viewer. Usado por la
   lista de canciones del editor de playlists. Mismo cache local que
   alimenta el reproductor pequeño, así repeticiones son instantáneas.

   Si el match cae bajo el threshold de find-album (devuelve notFound),
   el span queda vacío — no mostramos información ruidosa. */
/* ── Cola PARALELA con tope de concurrencia ──
   Con la cascada iTunes → Deezer → Spotify, la mayoría de hits los
   resuelve iTunes/Deezer (sin rate-limit estricto). Spotify queda como
   fallback y está protegido por el mutex server-side (600 ms entre
   requests, compartido entre todos los clientes). Así que en el
   cliente PODEMOS paralelizar las requests al backend — iTunes y
   Deezer responden en cientos de ms y nuestra única restricción real
   ya vive del lado del servidor. */
const ALBUM_MAX_PARALLEL = 5;
let _albumInFlight = 0;
const _albumQueue  = [];
function _albumNextSlot() {
    while (_albumInFlight < ALBUM_MAX_PARALLEL && _albumQueue.length) {
        _albumInFlight++;
        const job = _albumQueue.shift();
        job().finally(() => {
            _albumInFlight--;
            _albumNextSlot();
        });
    }
}
function _albumQueueRun(jobFn) {
    _albumQueue.push(jobFn);
    _albumNextSlot();
}

function _resolveAlbumForRow(track, albumSpan, thumbImg) {
    if (!track || !track.videoId) return;
    const vId = track.videoId;

    function paint(data) {
        /* Filtra a álbumes REALES (devuelve null si no hay). Sin álbum
           confirmado dejamos el span vacío — el CSS :empty lo oculta. */
        const norm = _normalizeAlbumPayload(data, track);
        if (!norm || !norm.albumName) {
            albumSpan.textContent = '';
            albumSpan.style.display = 'none';
            return;
        }
        /* Miniatura de la fila = carátula del álbum (no la de YouTube).
           Si el álbum está vinculado pero find-album no trajo imagen,
           la sacamos de album-tracks. */
        if (thumbImg) {
            if (norm.albumImage) thumbImg.src = norm.albumImage;
            else if (norm.albumKey) _albumCoverFromKey(norm.albumKey, function(img){ thumbImg.src = img; });
        }
        /* Sin separador textual: la división visual la da el
           border-left + padding del CSS (.pl-item-album-text). */
        albumSpan.textContent = norm.albumName;
        albumSpan.title = 'Ver álbum: ' + norm.albumName;
        /* dataset.albumId guarda el albumKey prefijado (itunes:/deezer:/
           spotify:) — lo aceptan tanto el openAlbumViewer como el viewer
           del endpoint album-tracks. */
        albumSpan.dataset.albumId   = norm.albumKey || norm.spotifyAlbumId || '';
        albumSpan.dataset.albumName = norm.albumName || '';
        albumSpan.style.display = '';
        /* Click → abre el viewer. Stop propagation para que no se cuente
           como click en la fila (que podría reproducir el track). */
        albumSpan.onclick = ev => {
            ev.stopPropagation();
            const id = albumSpan.dataset.albumId;
            const name = albumSpan.dataset.albumName;
            if (id && typeof openAlbumViewer === 'function') openAlbumViewer(id, name);
        };
    }

    /* Solo usamos la cache si normaliza a un álbum REAL; entradas viejas
       (p.ej. albumKey spotify:* legacy) se ignoran y se re-resuelven. */
    const cached = _albumCacheGet(vId);
    if (cached !== undefined && _normalizeAlbumPayload(cached, track)) { paint(cached); return; }

    const params = new URLSearchParams({
        title:   track.title || '',
        artist:  track.artist || '',
        /* videoId habilita el cache compartido por video del backend
           (#5): si otro usuario YA resolvió este mismo video, el
           backend devuelve hit sin tocar Spotify. */
        videoId: track.videoId || '',
    });
    /* La request va a la cola serial con espaciado. Si la queja del
       backend es "rate-limited" (HTTP 503 con code:rateLimited), no
       cacheamos nada — la próxima visita al editor la reintenta. */
    _albumQueueRun(() => fetch('assets/music/api.php?action=find-album&' + params.toString())
        .then(r => {
            if (r.status === 503) return null; /* rate-limit transitorio: skip silente */
            return r.ok ? r.json() : null;
        })
        .then(data => {
            if (!data) return;
            const norm = _normalizeAlbumPayload(data, track);
            if (norm) _albumCacheSet(vId, norm);
            paint(data);
        })
        .catch(() => { /* offline / endpoint caído → no se muestra nada */ }));
}

/* Obtiene la carátula de un álbum por su albumKey (itunes:/deezer:) vía
   album-tracks, cacheada en memoria. Fallback cuando find-album devolvió
   el álbum vinculado pero sin albumImage (p.ej. overrides sin imagen). */
var _albumCoverByKey = {};
function _albumCoverFromKey(albumKey, cb) {
    if (!albumKey || typeof cb !== 'function') return;
    if (_albumCoverByKey[albumKey]) { cb(_albumCoverByKey[albumKey]); return; }
    fetch('assets/music/api.php?action=album-tracks&key=' + encodeURIComponent(albumKey))
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){ if (d && d.image) { _albumCoverByKey[albumKey] = d.image; cb(d.image); } })
        .catch(function(){});
}

/* Pone en `imgEl` la carátula del álbum de la canción (find-album), con
   fallback a lo que ya tuviera. Reutiliza la cache local compartida.
   Usado por los resultados de búsqueda y los recientes de canción. */
function _resolveSongThumb(track, imgEl) {
    if (!track || !track.videoId || !imgEl) return;
    function apply(data) {
        var norm = (typeof _normalizeAlbumPayload === 'function') ? _normalizeAlbumPayload(data, track) : (data && !data.notFound ? data : null);
        if (!norm) return;
        if (norm.albumImage) imgEl.src = norm.albumImage;
        else if (norm.albumKey) _albumCoverFromKey(norm.albumKey, function(img){ imgEl.src = img; });
    }
    var cached = _albumCacheGet(track.videoId);
    if (cached !== undefined && _normalizeAlbumPayload(cached, track)) { apply(cached); return; }
    var p = new URLSearchParams({ title: track.title || '', artist: track.artist || '', videoId: track.videoId });
    _albumQueueRun(function(){
        return fetch('assets/music/api.php?action=find-album&' + p.toString())
            .then(function(r){ return (r.status === 503) ? null : (r.ok ? r.json() : null); })
            .then(function(d){ if (!d) return; var norm = _normalizeAlbumPayload(d, track); if (norm) _albumCacheSet(track.videoId, norm); apply(d); })
            .catch(function(){});
    });
}

/* Filtra el payload del backend: solo aceptamos respuestas con un
   spotifyAlbumId REAL. Si el backend devolvió notFound o algo raro,
   retornamos null y el caller trata la canción como "sin álbum"
   (título no clickable, span del row oculto). Antes sintetizábamos
   un álbum-fake con el nombre de la canción, pero confundía al
   usuario más de lo que ayudaba. */
function _normalizeAlbumPayload(data /*, track */) {
    if (!data || data.notFound) return null;
    /* SOLO aceptamos albumKey con prefijo itunes: o deezer:.
       Las keys spotify:* y los legacy spotifyAlbumId quedan descartados:
       devolvemos null para forzar un find-album fresh contra iTunes/Deezer.
       Antes sintetizábamos `spotify:` + spotifyAlbumId y eso arrastraba
       viewers rotos sobre álbumes que ya no se pueden cargar. */
    if (!data.albumKey || typeof data.albumKey !== 'string') return null;
    if (data.albumKey.indexOf('spotify:')   === 0) return null;
    if (data.albumKey.indexOf('synthetic:') === 0) return null;
    /* Limpiamos también el campo legacy para no contaminar el resto del
       código que aún lo mira de forma defensiva. */
    data.spotifyAlbumId = '';
    return data;
}

function resolveAndShowAlbum(track) {
    /* Si cambiamos de track distinto al "forzado externamente",
       invalida el lock — el nuevo videoId vuelve a pasar por find-album. */
    const incomingVid = track ? track.videoId : null;
    if (_forcedAlbumForVideo && incomingVid !== _forcedAlbumForVideo) {
        _forcedAlbumForVideo = null;
    }
    /* Reset entre tracks. */
    _applyAlbumState(null);
    if (!track || !track.title) return;

    /* Cache hit por videoId — SOLO si normaliza a un álbum real. Las
       entradas viejas (albumKey spotify:* legacy) se ignoran y se
       re-resuelven contra iTunes/Deezer (que sí traen carátula). */
    const vId = track.videoId;
    const cached = _albumCacheGet(vId);
    const cachedNorm = (cached !== undefined) ? _normalizeAlbumPayload(cached, track) : null;
    if (cachedNorm) {
        _applyAlbumState(cachedNorm);
        return;
    }

    /* Fetch al backend. Guardamos el videoId actual y verificamos al
       volver para no pintar el álbum de un track que ya no está. */
    const requestedFor = vId;
    const params = new URLSearchParams({
        title:   track.title,
        artist:  track.artist || '',
        videoId: vId,
    });
    fetch('assets/music/api.php?action=find-album&' + params.toString())
        .then(r => {
            /* 503 = rate-limited por Spotify. Tratamos como silente:
               no cacheamos nada, no actualizamos UI. La próxima
               visita al track reintentará. */
            if (r.status === 503) return null;
            return r.ok ? r.json() : null;
        })
        .then(data => {
            if (!data) return;
            /* Solo cacheamos álbumes REALES — un null (notFound o red
               caída) NO lo cacheamos, así la próxima vez se reintenta
               y puede encontrar el álbum cuando Spotify se recupere o
               el match mejore. Sin esto, una sola búsqueda fallida
               dejaba el videoId locked durante toda la sesión. */
            const normalized = _normalizeAlbumPayload(data, track);
            if (normalized) _albumCacheSet(requestedFor, normalized);
            const curTrack = (typeof playlist !== 'undefined' && playlist.length && typeof currentTrack === 'number')
                ? playlist[currentTrack] : null;
            if (curTrack && curTrack.videoId === requestedFor
                         && curTrack.videoId !== _forcedAlbumForVideo) {
                _applyAlbumState(normalized);
            }
        })
        .catch(() => {
            /* Offline / endpoint caído → la canción queda sin álbum
               asignado en el reproductor. El estado ya fue reseteado
               al inicio de resolveAndShowAlbum (no clickable). */
        });
}

var _lastPosSavedAt = 0;
function startProgress()
{
    clearInterval(progressInterval);
    progressInterval = setInterval(() => {
        if (!ytPlayer || !ytPlayer.getDuration) return;
        if (Date.now() - _lastPosSavedAt > 5000) {
            _lastPosSavedAt = Date.now();
            MelonLastTrack.savePos();
        }
        const dur = ytPlayer.getDuration();
        const cur = ytPlayer.getCurrentTime();
        if (dur) {
            playerProg.value = (cur / dur) * 100;
            playerCurrent.textContent = formatTime(cur);
            playerDur.textContent = formatTime(dur);
        }
        /* Refresca el now-playing del social/TV mientras suena (throttled
           a 2s dentro de publishNowPlaying) para no salir de la ventana
           de 90s del backend. */
        publishNowPlaying(true, false);
    }, 500);
}

function onYouTubeIframeAPIReady()
{
    ytPlayer = new YT.Player('yt-player', {
        width: 1, height: 1,
        playerVars: { controls: 0, rel: 0, modestbranding: 1 },
        events: {
            onReady: function() {
                /* Exposición a window para el control de volumen global
                   del taskbar (`let ytPlayer` no es accesible desde
                   fuera del script). Reasignamos en cada onReady por si
                   el player se recrea. */
                window.ytPlayer = ytPlayer;
                /* onReady puede dispararse antes de que fetchState haya
                   resuelto. whenReady aplica el volumen y la playlist
                   guardada en cuanto ambos extremos estén listos. */
                window.DesktopState.whenReady(function(){
                    var saved = MelonPlayerState.get();
                    var baseVol = (saved && typeof saved.volume === 'number')
                        ? saved.volume
                        : parseInt(volSlider.value);
                    /* `_userYtVolume` es el volumen "natural" del reproductor;
                       el control de volumen global del taskbar lo multiplica
                       por su propio factor para el setVolume real. */
                    window._userYtVolume = baseVol;
                    var globalF = (typeof window.getGlobalVolumeFactor === 'function')
                        ? window.getGlobalVolumeFactor() : 1;
                    ytPlayer.setVolume(Math.round(baseVol * globalF));
                    /* Se ha entrado por un enlace que pone música
                       (melonOpenLink en desktop-base.php): esa gana a la
                       última canción y a la playlist guardada. */
                    window.__melonPlayerReady = true;
                    if (typeof window.__melonLinkPlay === 'function') {
                        var linkPlay = window.__melonLinkPlay;
                        window.__melonLinkPlay = null;
                        linkPlay();
                        return;
                    }
                    var last = MelonLastTrack.read();
                    if (last) restoreLast(last);
                    else restorePlaylist(saved);
                });

                /* La última canción de este ordenador, en pausa donde iba. */
                function restoreLast(last) {
                    currentPlaylistId = last.playlistId || null;
                    currentPlaylistHasCollabs = !!last.hasCollabs;
                    playlist.length = 0;
                    last.queue.forEach(function(t) { playlist.push(t); });
                    var idx = Math.max(0, Math.min(last.idx | 0, playlist.length - 1));
                    currentTrack = idx;
                    updateTrackUI(idx);
                    if (last.title) updatePlayerTitle(last.title);
                    var pos = MelonLastTrack.position(playlist[idx].videoId);
                    ytPlayer.cueVideoById({ videoId: playlist[idx].videoId, startSeconds: pos });
                    var dur = playlist[idx].duration || 0;
                    if (pos) {
                        playerCurrent.textContent = formatTime(pos);
                        if (dur) {
                            playerProg.value = (pos / dur) * 100;
                            playerDur.textContent = formatTime(dur);
                        }
                    }
                }

                function restoreDefault() {
                    if (playlist.length) { updateTrackUI(0); ytPlayer.cueVideoById(playlist[0].videoId); }
                }

                function restorePlaylist(saved) {
                if (saved && saved.playlistId) {
                    fetch('assets/music/api.php?action=get-playlists')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        /* Mientras llegaba, un enlace ya puso otra cosa. */
                        if (window.__melonLinkPlayed) return;
                        if (!Array.isArray(data)) { restoreDefault(); return; }
                        var found = null;
                        for (var i = 0; i < data.length; i++) {
                            if (data[i].id === saved.playlistId) { found = data[i]; break; }
                        }
                        if (!found || !found.tracks.length) { restoreDefault(); return; }
                        currentPlaylistId = found.id;
                        currentPlaylistHasCollabs = !!(found.sharedFrom || (found.collaborators && found.collaborators.length > 0));
                        playlist.length = 0;
                        found.tracks.forEach(function(t) { playlist.push(t); });
                        var idx = (saved.trackIndex >= 0 && saved.trackIndex < playlist.length) ? saved.trackIndex : 0;
                        currentTrack = idx;
                        updateTrackUI(idx);
                        updatePlayerTitle(found.name);
                        ytPlayer.cueVideoById(playlist[idx].videoId);
                    })
                    .catch(restoreDefault);
                } else {
                    restoreDefault();
                }
                }
            },
            onStateChange: (e) => {
                if (e.data === YT.PlayerState.PLAYING) {
                    btnPlay.textContent = '⏸';
                    startProgress();
                    publishNowPlaying(true, true);
                } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.ENDED) {
                    btnPlay.textContent = '►';
                    clearInterval(progressInterval);
                    if (e.data === YT.PlayerState.PAUSED) MelonLastTrack.savePos();
                    publishNowPlaying(false, true);
                }
                /* Si soy host de una sesión conjunta, propago play/pausa al
                   instante (sin esperar al heartbeat de 5s) para que los
                   guests reaccionen rápido. */
                if (LT_ROLE === 'host'
                    && (e.data === YT.PlayerState.PLAYING || e.data === YT.PlayerState.PAUSED)) {
                    ltHostBroadcastDebounced();
                }
                /* Como GUEST NO avanzamos la playlist local al terminar la
                   canción: la siguiente la carga el poll de la sesión (el
                   track del host). Sin esta guarda, el guest saltaba a su
                   propia "siguiente" canción y, además, descuadraba el
                   wrapped/escuchando-ahora. */
                if (e.data === YT.PlayerState.ENDED && LT_ROLE !== 'guest') {
                    if (autoplayRandom && playlist.length > 1) {
                        currentTrack = nextShuffleIndex();
                    } else {
                        currentTrack = (currentTrack + 1) % playlist.length;
                    }
                    updateTrackUI(currentTrack);
                    ytPlayer.loadVideoById(playlist[currentTrack].videoId);
                }
            }
        }
    });
}

function switchTrack(index, keepPlaying)
{
    currentTrack = index;
    updateTrackUI(index);
    if (!ytPlayer) return;
    if (keepPlaying) {
        ytPlayer.loadVideoById(playlist[index].videoId);
    } else {
        ytPlayer.cueVideoById(playlist[index].videoId);
    }
}

btnPlay.addEventListener('click', () => {
    if (!ytPlayer || !playlist.length) return;
    const state = ytPlayer.getPlayerState();
    if (state === YT.PlayerState.PLAYING) {
        ytPlayer.pauseVideo();
    } else {
        ytPlayer.playVideo();
    }
});

document.getElementById('btn-prev').addEventListener('click', () => {
    if (!playlist.length) return;
    const playing = ytPlayer && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING;
    /* En aleatorio, a la que sonaba antes (si queda en el historial). */
    const back = autoplayRandom ? historyStep(shuffleBack) : -1;
    if (back !== -1) {
        if (shuffleNow) shuffleForward.push(shuffleNow);
        shuffleNav = true;
        switchTrack(back, playing);
        return;
    }
    switchTrack((currentTrack - 1 + playlist.length) % playlist.length, playing);
});

document.getElementById('btn-next').addEventListener('click', () => {
    if (!playlist.length) return;
    const playing = ytPlayer && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING;
    if (autoplayRandom && playlist.length > 1) {
        switchTrack(nextShuffleIndex(), playing);
    } else {
        switchTrack((currentTrack + 1) % playlist.length, playing);
    }
});

document.getElementById('btn-shuffle').addEventListener('click', () => {
    autoplayRandom = !autoplayRandom;
    document.getElementById('btn-shuffle').classList.toggle('btn-active', autoplayRandom);
});

playerProg.addEventListener('input', function() {
    if (ytPlayer && ytPlayer.getDuration) {
        ytPlayer.seekTo((this.value / 100) * ytPlayer.getDuration(), true);
    }
});

volSlider.addEventListener('input', function() {
    var vol = parseInt(this.value);
    /* `_userYtVolume` es el "natural" del reproductor; el control
       global del taskbar lo multiplica por su factor antes del
       setVolume real. Así el usuario puede tener el reproductor a 80%
       y el taskbar a 50% → suena al 40% sin que ninguno de los dos
       sliders se mueva. */
    window._userYtVolume = vol;
    var globalF = (typeof window.getGlobalVolumeFactor === 'function')
        ? window.getGlobalVolumeFactor() : 1;
    if (ytPlayer && ytPlayer.setVolume) {
        ytPlayer.setVolume(Math.round(vol * globalF));
    }
    const icon = document.getElementById('volume-icon');
    if (vol === 0) icon.textContent = '◄✕';
    else if (vol < 50) icon.textContent = '◄)';
    else icon.textContent = '◄))';
    MelonPlayerState.setVolume(vol);
});

/* Restore volume before player API loads.
   El estado vive en window.DesktopState, que se rellena de forma async
   por desktop-base.fetchState(). whenReady garantiza que aplicamos el
   volumen guardado AUNQUE el fetch aún no haya resuelto al evaluar este
   script — y también si el YT player ya está listo, le aplicamos el volumen. */
window.DesktopState.whenReady(function(){
    var s = MelonPlayerState.get() || {};
    if (typeof s.volume !== 'number') return;
    volSlider.value = s.volume;
    var icon = document.getElementById('volume-icon');
    if (s.volume === 0) icon.textContent = '◄✕';
    else if (s.volume < 50) icon.textContent = '◄)';
    else icon.textContent = '◄))';
    window._userYtVolume = s.volume;
    var globalF = (typeof window.getGlobalVolumeFactor === 'function')
        ? window.getGlobalVolumeFactor() : 1;
    if (ytPlayer && ytPlayer.setVolume) ytPlayer.setVolume(Math.round(s.volume * globalF));
});


document.getElementById('player-close').addEventListener('click', () => {
    if (ytPlayer) ytPlayer.pauseVideo();
    btnPlay.textContent = '►';
    clearInterval(progressInterval);
    taskbarManager.unregister('music-player');
});

document.getElementById('player-minimize').addEventListener('click', function() {
    playerWindow.style.display = 'none';
});

document.getElementById('tray-player-btn').addEventListener('click', function() {
    playerWindow.style.display = playerWindow.style.display === 'none' ? 'block' : 'none';
});

/* Cargar YouTube IFrame API */
const ytScript = document.createElement('script');
ytScript.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(ytScript);

/* Arrastrar ventana del reproductor — Pointer Events para mouse+touch. */
(function() {
    const titlebar = document.getElementById('player-titlebar');
    let dragging = false, ox, oy, pid = -1;
    titlebar.style.touchAction = 'none';
    titlebar.addEventListener('pointerdown', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        dragging = true;
        pid = e.pointerId;
        try { titlebar.setPointerCapture(pid); } catch (_) {}
        const rect = playerWindow.getBoundingClientRect();
        playerWindow.style.left   = rect.left + 'px';
        playerWindow.style.top    = rect.top  + 'px';
        playerWindow.style.right  = 'auto';
        playerWindow.style.bottom = 'auto';
        ox = e.clientX - rect.left;
        oy = e.clientY - rect.top;
    });
    titlebar.addEventListener('pointermove', function(e) {
        if (!dragging || e.pointerId !== pid) return;
        playerWindow.style.left = (e.clientX - ox) + 'px';
        playerWindow.style.top  = (e.clientY - oy) + 'px';
    });
    function end(e){ if (e && e.pointerId !== pid) return; dragging = false; pid = -1; }
    titlebar.addEventListener('pointerup', end);
    titlebar.addEventListener('pointercancel', end);
})();

var openAddDialog = null;
var addTrackCallback = null;

/* Añadir canción */
(function() {
    var dialog   = document.getElementById('add-track-dialog');
    var urlInput = document.getElementById('add-yt-url');
    var artInput = document.getElementById('add-artist');
    var errEl    = document.getElementById('add-track-error');
    var fetchedTitle = '';

    /* El artista se autocompleta desde los metadatos (Spotify/Tidal/YouTube)
       mientras el usuario no lo haya escrito a mano. */
    var artistTouched = false;
    artInput.addEventListener('input', function() { artistTouched = true; });
    function setArtist(name) {
        if (artistTouched) return;          // respeta lo que escribió el usuario
        artInput.value = name || '';
    }
    function cleanYtAuthor(name) {
        if (!name) return '';
        return name.replace(/\s*-\s*topic$/i, '').replace(/\s*VEVO$/i, '').trim();
    }

    function parseYouTubeId(raw) {
        var s = raw.trim();
        var m;
        m = s.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
        if (m) return m[1];
        m = s.match(/[?&]v=([A-Za-z0-9_-]{11})/);
        if (m) return m[1];
        m = s.match(/\/embed\/([A-Za-z0-9_-]{11})/);
        if (m) return m[1];
        if (/^[A-Za-z0-9_-]{11}$/.test(s)) return s;
        return null;
    }

    function openDialog() {
        urlInput.value = ''; artInput.value = '';
        artistTouched = false;
        errEl.textContent = '';
        resetTitlePreview();
        dialog.style.display = 'block';
        urlInput.focus();
    }
    function closeDialog() {
        dialog.style.display = 'none';
        if (!addTrackCallback) addTrackCallback = null;
    }
    openAddDialog = openDialog;

    var titlePreview = document.getElementById('add-title-preview');
    var fetchTimer   = null;
    var fetchedDuration = 0;
    var fetchedVideoId  = null;

    var stopPreviewMarquee = null;

    function resetTitlePreview() {
        fetchedTitle    = '';
        fetchedDuration = 0;
        fetchedVideoId  = null;
        titlePreview.textContent = '';
        titlePreview.style.color = '';
        if (stopPreviewMarquee) { stopPreviewMarquee(); stopPreviewMarquee = null; }
    }

    function applyScrollIfNeeded() {
        if (stopPreviewMarquee) { stopPreviewMarquee(); stopPreviewMarquee = null; }
        setTimeout(function() {
            stopPreviewMarquee = marqueeScroll(titlePreview, titlePreview.parentElement, 50, 1000);
        }, 50);
    }

    urlInput.addEventListener('input', function() {
        clearTimeout(fetchTimer);
        resetTitlePreview();
        var raw = urlInput.value.trim();

        if (/open\.spotify\.com\/.+\/track\/|spotify:track:/.test(raw)) {
            titlePreview.textContent = 'Buscando en Spotify...';
            fetchTimer = setTimeout(function() {
                fetch('assets/music/api.php?action=spotify-track&url=' + encodeURIComponent(raw))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        titlePreview.textContent = data.error;
                        titlePreview.style.color = '#c00';
                        return;
                    }
                    fetchedTitle    = data.title;
                    fetchedVideoId  = data.videoId;
                    fetchedDuration = data.duration;
                    setArtist(data.artist);
                    titlePreview.textContent = '♪ ' + data.title + ' [Spotify]';
                    titlePreview.style.color = '';
                    applyScrollIfNeeded();
                })
                .catch(function() {
                    titlePreview.textContent = 'Error de conexión';
                    titlePreview.style.color = '#c00';
                });
            }, 400);
            return;
        }

        if (/tidal\.com\/(?:browse\/)?track\/\d+/i.test(raw)) {
            titlePreview.textContent = 'Buscando en Tidal...';
            fetchTimer = setTimeout(function() {
                fetch('assets/music/api.php?action=tidal-track&url=' + encodeURIComponent(raw))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        titlePreview.textContent = data.error;
                        titlePreview.style.color = '#c00';
                        return;
                    }
                    fetchedTitle    = data.title;
                    fetchedVideoId  = data.videoId;
                    fetchedDuration = data.duration;
                    setArtist(data.artist);
                    titlePreview.textContent = '♪ ' + data.title + ' [Tidal]';
                    titlePreview.style.color = '';
                    applyScrollIfNeeded();
                })
                .catch(function() {
                    titlePreview.textContent = 'Error de conexión';
                    titlePreview.style.color = '#c00';
                });
            }, 400);
            return;
        }

        var videoId = parseYouTubeId(urlInput.value);
        if (!videoId) return;
        titlePreview.textContent = 'Obteniendo título...';
        fetchTimer = setTimeout(function() {
            fetch('assets/music/api.php?action=yt-title&id=' + videoId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.title) {
                    fetchedTitle = data.title;
                    setArtist(cleanYtAuthor(data.author));
                    titlePreview.textContent = '♪ ' + data.title;
                    titlePreview.style.color = '';
                    applyScrollIfNeeded();
                } else {
                    titlePreview.textContent = data.error || 'No se pudo obtener el título';
                    titlePreview.style.color = '#c00';
                }
            })
            .catch(function() {
                titlePreview.textContent = 'Error de conexión';
                titlePreview.style.color = '#c00';
            });

            fetch('assets/music/api.php?action=yt-duration&id=' + videoId)
            .then(function(r) { return r.json(); })
            .then(function(data) { if (data.duration) fetchedDuration = data.duration; })
            .catch(function() {});
        }, 400);
    });

    document.getElementById('add-track-close').addEventListener('click', function() { addTrackCallback = null; closeDialog(); });
    document.getElementById('add-track-cancel').addEventListener('click', function() { addTrackCallback = null; closeDialog(); });

    document.getElementById('add-track-submit').addEventListener('click', function() {
        var videoId = fetchedVideoId || parseYouTubeId(urlInput.value);
        var title   = fetchedTitle;
        var artist  = artInput.value.trim();

        if (!videoId) { errEl.textContent = 'No se pudo extraer el ID del enlace.'; return; }
        if (!title)   { errEl.textContent = 'No se pudo obtener el título. Espera un momento.'; return; }
        errEl.textContent = '';

        var newTrack = { videoId: videoId, title: title, artist: artist, duration: fetchedDuration, addedBy: (usersInfo[currentUserKey] ? usersInfo[currentUserKey].label : '') };

        if (addTrackCallback) {
            addTrackCallback(newTrack);
            addTrackCallback = null;
            closeDialog();
            return;
        }

        fetch('assets/music/api.php?action=add-track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newTrack)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { errEl.textContent = data.error; return; }
            playlist.push(newTrack);
            if (playlist.length === 1) {
                updateTrackUI(0);
                ytPlayer.cueVideoById(playlist[0].videoId);
            }
            closeDialog();
        })
        .catch(function(e) { errEl.textContent = 'Error: ' + e.message; });
    });
})();

/* Editor de playlist */
(function() {
    var editor       = document.getElementById('playlist-editor');
    var plList       = document.getElementById('pl-list');
    var plHome       = document.getElementById('pl-home');
    var plEditorView = document.getElementById('pl-editor-view');
    var plTitleText  = document.getElementById('pl-title-text');
    var plHomeList   = document.getElementById('pl-home-list');
    var plNameInput  = document.getElementById('pl-name-input');
    var plImageInput = document.getElementById('pl-image-input');
    var editList     = [];
    var allPlaylists = [];
    var editingPlIdx = -1;
    var dragSrc      = null;
    var editFilter   = '';   /* texto del buscador de la playlist abierta */
    var plEditorSearch = document.getElementById('pl-editor-search-input');

    function openEditor() {
        if (taskbarManager.isRegistered('playlist-editor')) {
            taskbarManager.restore('playlist-editor');
        } else {
            taskbarManager.register('playlist-editor', 'Playlists', '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
        showHome();
        /* Al abrir: si se está reproduciendo una playlist o un álbum,
           abrir automáticamente su vista. */
        loadPlaylists(autoOpenPlayingView);
    }
    /* Abre la vista de lo que se esté reproduciendo: playlist propia →
       vista de esa playlist dentro de la ventana; álbum → su visor. Las
       canciones sueltas (currentPlaylistId null) no abren nada. */
    function autoOpenPlayingView() {
        var pid = currentPlaylistId;
        if (!pid) return;
        if (typeof pid === 'string' && pid.indexOf('spotify-album:') === 0) {
            var albId = pid.slice('spotify-album:'.length);
            if (albId && typeof openAlbumViewer === 'function') openAlbumViewer(albId);
            return;
        }
        var idx = -1;
        for (var i = 0; i < allPlaylists.length; i++) {
            if (String(allPlaylists[i].id) === String(pid)) { idx = i; break; }
        }
        if (idx !== -1) showEditorView(idx);
    }
    function closeEditor() {
        taskbarManager.unregister('playlist-editor');
    }

    function showHome() {
        plHome.style.display       = 'flex';
        plEditorView.style.display = 'none';
        plTitleText.textContent    = '♪ Playlists';
        if (allPlaylists.length > 0) renderHome();
    }

    function showEditorView(idx) {
        var pl = allPlaylists[idx];
        editingPlIdx = idx;
        plNameInput.value    = pl.name;
        plNameInput.disabled = !!pl.sharedFrom;
        if (plImageInput) {
            plImageInput.value    = pl.image || '';
            plImageInput.disabled = !!pl.sharedFrom;
        }
        editList = pl.tracks.map(function(t) {
            return { title: t.title, artist: t.artist || '', videoId: t.videoId, duration: t.duration || 0, addedBy: t.addedBy || '' };
        });
        editFilter = '';
        if (plEditorSearch) plEditorSearch.value = '';
        plHome.style.display       = 'none';
        plEditorView.style.display = 'flex';
        plTitleText.textContent    = '▶ Ver playlist';
        renderList();
    }

    function loadPlaylists(onReady) {
        plHomeList.innerHTML = '<div class="pl-home-msg">Cargando...</div>';
        fetch('assets/music/api.php?action=get-playlists')
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(data) {
            if (data.error) { plHomeList.innerHTML = '<div class="pl-home-msg">Error: ' + data.error + '</div>'; return; }
            allPlaylists = data;
            renderHome();
            if (typeof onReady === 'function') onReady();
        })
        .catch(function(e) {
            plHomeList.innerHTML = '<div class="pl-home-msg">Error: ' + e.message + '</div>';
        });
    }

    /* Reproduce una playlist entera + la registra en recientes. */
    function playPlaylist(pl) {
        playPlaylistFrom(pl, 0);
        /* Antes se cerraba la ventana al reproducir; ahora se mantiene
           abierta (reproducir desde el botón ▶ de la tarjeta NO entra ni
           cierra la ventana). */
    }

    /* Reproduce la playlist empezando por la canción trackIndex (el resto
       queda en cola). La usan playPlaylist y el reproductor flotante. */
    function playPlaylistFrom(pl, trackIndex) {
        if (!pl || !pl.tracks || !pl.tracks.length) return;
        var i = Math.max(0, Math.min(trackIndex | 0, pl.tracks.length - 1));
        currentPlaylistId = pl.id;
        currentPlaylistHasCollabs = !!(pl.sharedFrom || (pl.collaborators && pl.collaborators.length > 0));
        MelonPlayerState.setPlaylist(pl.id, i);
        playlist.length = 0;
        pl.tracks.forEach(function(t) { playlist.push(t); });
        currentTrack = i;
        updateTrackUI(i);
        updatePlayerTitle(pl.name);
        if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
            ytPlayer.loadVideoById(playlist[i].videoId);
        }
        melonRecordRecent('playlist', String(pl.id), pl.name, pl.image || '', '');
    }

    function deletePlaylist(pl, idx) {
        if (pl.sharedFrom) {
            win98Confirm('¿Abandonar la playlist "' + pl.name + '"?', 'Abandonar playlist', function() {
                fetch('assets/music/api.php?action=leave-playlist', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: pl.id, sharedFrom: pl.sharedFrom })
                }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                  .then(function(d){ if(d.error){ alert(d.error); return; } allPlaylists.splice(idx,1); renderHome(); })
                  .catch(function(e){ alert('Error: '+e.message); });
            });
        } else {
            win98Confirm('¿Eliminar la playlist "' + pl.name + '"?', 'Eliminar playlist', function() {
                fetch('assets/music/api.php?action=delete-playlist', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: pl.id })
                }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                  .then(function(d){ if(d.error){ alert(d.error); return; } allPlaylists.splice(idx,1); renderHome(); })
                  .catch(function(e){ alert('Error: '+e.message); });
            });
        }
    }

    /* Menú contextual de una tarjeta de playlist (click derecho). */
    function showPlaylistCardMenu(e, pl, idx) {
        var menu = document.getElementById('pl-card-ctx');
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'pl-card-ctx';
            menu.className = 'window';
            menu.style.cssText = 'display:none;position:fixed;z-index:2100;padding:2px 0;min-width:150px;';
            document.body.appendChild(menu);
            document.addEventListener('click', function(){ menu.style.display = 'none'; });
        }
        menu.innerHTML = '';
        var edit = document.createElement('div');
        edit.className = 'pl-menu-item';
        edit.textContent = '✎ Editar';
        edit.addEventListener('click', function(){ menu.style.display = 'none'; openEditPlaylistDialog(idx); });
        menu.appendChild(edit);

        var addOther = document.createElement('div');
        addOther.className = 'pl-menu-item';
        addOther.textContent = '➕ Añadir a otra playlist';
        addOther.addEventListener('click', function(ev){
            if (ev && ev.stopPropagation) ev.stopPropagation();   /* no cerrar el menú */
            buildAddToOtherMenu(menu, pl);
        });
        menu.appendChild(addOther);

        /* Descargar todas sus canciones (en el navegador ofrece la app). */
        if ((pl.tracks || []).some(function(t) { return t && t.videoId; })) {
            var dlAll = document.createElement('div');
            dlAll.className = 'pl-menu-item';
            dlAll.textContent = '⬇ Descargar todo…';
            dlAll.addEventListener('click', function(){
                menu.style.display = 'none';
                openBulkDownloadDialog({ name: pl.name, cover: pl.image || '', tracks: pl.tracks });
            });
            menu.appendChild(dlAll);
        }

        /* Enlace para compartir: cualquiera con sesión la puede ver y
           copiar (enlace.php?playlist=N). */
        if (/^\d+$/.test(String(pl.id)) && typeof window.melonCopyLink === 'function') {
            var lnk = document.createElement('div');
            lnk.className = 'pl-menu-item';
            lnk.textContent = '🔗 Copiar enlace';
            lnk.addEventListener('click', function(){ menu.style.display = 'none'; window.melonCopyLink('playlist', String(pl.id)); });
            menu.appendChild(lnk);
        }

        var del = document.createElement('div');
        del.className = 'pl-menu-item';
        del.textContent = pl.sharedFrom ? '⊗ Abandonar' : '✕ Eliminar';
        del.addEventListener('click', function(){ menu.style.display = 'none'; deletePlaylist(pl, idx); });
        menu.appendChild(del);
        menu.style.display = 'block';
        var mw = menu.offsetWidth, mh = menu.offsetHeight;
        menu.style.left = Math.min(e.clientX, window.innerWidth  - mw - 8) + 'px';
        menu.style.top  = Math.min(e.clientY, window.innerHeight - mh - 8) + 'px';
    }

    /* Rellena el menú con la lista de OTRAS playlists destino. Al elegir
       una, copia todas las canciones de `srcPl` en ella. */
    function buildAddToOtherMenu(menu, srcPl) {
        menu.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'pl-menu-item';
        head.style.opacity = '0.7';
        head.style.cursor = 'default';
        head.textContent = 'Añadir "' + srcPl.name + '" a…';
        menu.appendChild(head);
        var targets = allPlaylists.filter(function(p){ return p.id !== srcPl.id; });
        if (!targets.length) {
            var none = document.createElement('div');
            none.className = 'pl-menu-item'; none.style.opacity = '0.7'; none.style.cursor = 'default';
            none.textContent = 'No hay otras playlists';
            menu.appendChild(none);
        } else {
            targets.forEach(function(tp){
                var it = document.createElement('div');
                it.className = 'pl-menu-item';
                it.textContent = (tp.sharedFrom ? '[+] ' : '') + tp.name;
                it.addEventListener('click', function(){
                    menu.style.display = 'none';
                    mergePlaylistInto(srcPl, tp);
                });
                menu.appendChild(it);
            });
        }
        /* Reposiciona por si la nueva altura se sale de la pantalla. */
        menu.style.display = 'block';
        var top = parseFloat(menu.style.top) || 0;
        menu.style.top = Math.max(8, Math.min(top, window.innerHeight - menu.offsetHeight - 8)) + 'px';
    }

    /* Copia todas las canciones de srcPl en targetPl (sin duplicar por
       videoId) y guarda la playlist destino. */
    function mergePlaylistInto(srcPl, targetPl) {
        var merged = (targetPl.tracks || []).slice();
        var seen = {};
        merged.forEach(function(t){ if (t.videoId) seen[t.videoId] = 1; });
        var added = 0;
        (srcPl.tracks || []).forEach(function(t){
            if (t.videoId && seen[t.videoId]) return;
            merged.push({ videoId: t.videoId, title: t.title, artist: t.artist || '', duration: t.duration || 0 });
            if (t.videoId) seen[t.videoId] = 1;
            added++;
        });
        var payload = { id: targetPl.id, name: targetPl.name, tracks: merged };
        if (targetPl.sharedFrom) payload.sharedFrom = targetPl.sharedFrom;
        else payload.image = targetPl.image || '';
        fetch('assets/music/api.php?action=save-playlist-item', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(function(d){
            if (d.error) { alert(d.error); return; }
            targetPl.tracks = merged;
            if (window.notifSystem) {
                window.notifSystem.show({ type: 'success', title: 'Añadido a "' + targetPl.name + '"',
                    message: added + ' canción(es) añadida(s).', autoDismissAfter: 3500 });
            } else {
                alert(added + ' canción(es) añadida(s) a "' + targetPl.name + '".');
            }
        })
        .catch(function(e){ alert('Error al añadir: ' + e.message); });
    }

    /* Tarjeta de playlist: click en el item → ENTRAR (como un álbum);
       ▶ → reproducir; click derecho → menú (Editar / Eliminar). */
    function buildPlaylistCard(pl, idx) {
        var card = document.createElement('div');
        card.className = 'pl-card';

        var cover = document.createElement('div');
        cover.className = 'pl-card-cover';
        if (pl.image) {
            var img = document.createElement('img');
            img.src = pl.image; img.alt = '';
            img.onerror = function(){ cover.classList.add('pl-card-cover--empty'); img.remove(); };
            cover.appendChild(img);
        } else {
            cover.classList.add('pl-card-cover--empty');
        }
        var play = document.createElement('div'); play.className = 'pl-card-play'; play.textContent = '▶';
        play.title = 'Reproducir';
        play.addEventListener('click', function(e){ e.stopPropagation(); playPlaylist(pl); });
        cover.appendChild(play);

        var name = document.createElement('div');
        name.className = 'pl-card-name';
        name.textContent = (pl.sharedFrom ? '[+] ' : '') + pl.name;
        name.title = pl.name;

        card.appendChild(cover); card.appendChild(name);
        card.addEventListener('click', function(){ showEditorView(idx); });
        card.addEventListener('contextmenu', function(e){ e.preventDefault(); showPlaylistCardMenu(e, pl, idx); });
        return card;
    }

    /* Diálogo "Editar": nombre + imagen de la playlist. */
    function openEditPlaylistDialog(idx) {
        var pl = allPlaylists[idx];
        if (!pl) return;
        if (pl.sharedFrom) { alert('No puedes editar una playlist compartida por otra persona.'); return; }
        var dlg   = document.getElementById('edit-playlist-dialog');
        var nameI = document.getElementById('edit-pl-name');
        var imgI  = document.getElementById('edit-pl-image');
        nameI.value = pl.name || '';
        imgI.value  = pl.image || '';
        dlg.dataset.idx = idx;
        dlg.style.display = 'block';
        setTimeout(function(){ nameI.focus(); nameI.select(); }, 50);
    }

    /* Sección "Escuchas recientes" (canciones, álbumes, playlists). */
    function renderRecentSection() {
        var title = document.createElement('div');
        title.className = 'pl-section-title';
        title.textContent = 'Escuchas recientes';
        plHomeList.appendChild(title);
        var box = document.createElement('div');
        box.className = 'pl-recent-box';
        box.innerHTML = '<div class="pl-home-msg">Cargando…</div>';
        plHomeList.appendChild(box);
        fetch('assets/music/api.php?action=get-recent')
        .then(function(r){ return r.json(); })
        .then(function(d){
            var items = (d && d.ok && d.items) ? d.items : [];
            if (!items.length) { box.innerHTML = '<div class="pl-home-msg">Aún no has escuchado nada.</div>'; return; }
            box.innerHTML = '';
            var grid = document.createElement('div'); grid.className = 'pl-card-grid';
            items.forEach(function(it){
                var card = document.createElement('div'); card.className = 'pl-card';
                var cover = document.createElement('div'); cover.className = 'pl-card-cover';
                var src = it.image || (it.type === 'song' && it.key ? ('https://i.ytimg.com/vi/' + it.key + '/mqdefault.jpg') : '');
                if (src) { var im = document.createElement('img'); im.src = src; im.alt = '';
                    im.onerror = function(){ cover.classList.add('pl-card-cover--empty'); im.remove(); }; cover.appendChild(im);
                    /* Canción → usa la carátula del álbum si se resuelve. */
                    if (it.type === 'song' && it.key && typeof _resolveSongThumb === 'function') {
                        _resolveSongThumb({ videoId: it.key, title: it.name, artist: it.artist }, im);
                    }
                }
                else cover.classList.add('pl-card-cover--empty');
                var nm = document.createElement('div'); nm.className = 'pl-card-name'; nm.textContent = it.name || ''; nm.title = it.name || '';
                var sub = document.createElement('div'); sub.className = 'pl-card-sub';
                sub.textContent = it.type === 'song' ? 'Canción' : (it.type === 'album' ? 'Álbum' : (it.type === 'playlist' ? 'Playlist' : 'Artista'));
                card.appendChild(cover); card.appendChild(nm); card.appendChild(sub);
                card.addEventListener('click', function(){
                    if (it.type === 'song') {
                        melonPlaySong({ videoId: it.key, title: it.name, artist: it.artist });
                    } else if (it.type === 'album') {
                        if (typeof openAlbumViewer === 'function') openAlbumViewer(it.key, it.name);
                        melonRecordRecent('album', it.key, it.name, it.image, it.artist);
                    } else if (it.type === 'playlist') {
                        var found = null;
                        allPlaylists.forEach(function(p){ if (String(p.id) === String(it.key)) found = p; });
                        if (found) playPlaylist(found);
                    }
                });
                /* Click derecho → añadir a playlist / al perfil (canciones y
                   álbumes; las playlists no tienen menú). */
                card.addEventListener('contextmenu', function(e){
                    if (it.type === 'song') {
                        e.preventDefault();
                        if (typeof window.openTrackCtxMenu === 'function') {
                            window.openTrackCtxMenu(e, { videoId: it.key, title: it.name, artist: it.artist || '', duration: 0 });
                        }
                    } else if (it.type === 'album') {
                        e.preventDefault();
                        if (typeof showSearchAlbumCtxMenu === 'function') {
                            showSearchAlbumCtxMenu(e.clientX, e.clientY, { albumKey: it.key, name: it.name, artist: it.artist || '', image: it.image || '' });
                        }
                    }
                });
                grid.appendChild(card);
            });
            box.appendChild(grid);
        })
        .catch(function(){ box.innerHTML = '<div class="pl-home-msg">No se pudieron cargar.</div>'; });
    }

    function renderHome() {
        plHomeList.innerHTML = '';
        var title = document.createElement('div');
        title.className = 'pl-section-title';
        title.textContent = 'Tus playlists';
        plHomeList.appendChild(title);
        if (allPlaylists.length === 0) {
            var msg = document.createElement('div');
            msg.className = 'pl-home-msg';
            msg.textContent = 'Sin playlists. Crea una nueva.';
            plHomeList.appendChild(msg);
        } else {
            var grid = document.createElement('div'); grid.className = 'pl-card-grid';
            allPlaylists.forEach(function(pl, idx){ grid.appendChild(buildPlaylistCard(pl, idx)); });
            plHomeList.appendChild(grid);
        }
        renderRecentSection();
    }

    window.updatePlaylistPlayingHighlight = function(playlistId, trackIndex) {
        if (!allPlaylists[editingPlIdx] || allPlaylists[editingPlIdx].id !== playlistId) return;
        /* Usa el índice REAL de la fila (dataset.index), no el posicional:
           con el filtro activo las filas visibles son un subconjunto. */
        document.querySelectorAll('#pl-list .pl-item').forEach(function(row) {
            row.classList.toggle('pl-item--playing', parseInt(row.dataset.index, 10) === trackIndex);
        });
    };

    function renderList() {
        plList.innerHTML = '';
        var isCurrentPl = allPlaylists[editingPlIdx] && allPlaylists[editingPlIdx].id === currentPlaylistId;
        /* Filtro del buscador de la playlist: solo se ocultan filas que no
           coinciden; el índice `i` sigue siendo el real dentro de editList,
           así que al darle play se sigue encolando la playlist completa.
           Mientras se filtra desactivamos el drag (reordenar con filas
           ocultas daría índices confusos). */
        var q = (editFilter || '').trim().toLowerCase();
        var filtering = q.length > 0;
        var shown = 0;
        editList.forEach(function(track, i) {
            if (filtering) {
                var hay = ((track.title || '') + ' ' + (track.artist || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return;
            }
            shown++;
            var row = document.createElement('div');
            row.className = 'pl-item' + (isCurrentPl && i === currentTrack ? ' pl-item--playing' : '');
            row.draggable = !filtering;
            row.dataset.index = i;

            var handle = document.createElement('div');
            handle.className = 'pl-drag-handle';
            handle.textContent = '⠿';

            var thumbWrap = document.createElement('div');
            thumbWrap.className = 'pl-item-thumb-wrap';
            var thumbImg = document.createElement('img');
            thumbImg.src = track.videoId ? 'https://img.youtube.com/vi/' + track.videoId + '/mqdefault.jpg' : '';
            thumbImg.alt = '';
            thumbImg.width = 44;
            thumbImg.height = 44;
            thumbWrap.appendChild(thumbImg);

            var infoDiv = document.createElement('div');
            infoDiv.className = 'pl-item-info';
            var t1 = document.createElement('div');
            t1.className = 'pl-item-title-text';
            t1.textContent = track.title;
            var t2 = document.createElement('div');
            t2.className = 'pl-item-artist-row';
            var artistSpan = document.createElement('span');
            artistSpan.className = 'pl-item-artist-text';
            artistSpan.textContent = track.artist || '—';
            if (track.artist) {
                artistSpan.classList.add('artist-link');
                (function(art){
                    artistSpan.addEventListener('click', function(ev){
                        ev.stopPropagation();
                        if (typeof openArtistWindow === 'function') openArtistWindow(art);
                    });
                })(track.artist);
            }
            t2.appendChild(artistSpan);
            /* Span del álbum: vacío al renderizar, se rellena async via
               find-album. Click → abre el viewer del álbum. Se usa el
               cache localStorage que ya alimenta el reproductor pequeño,
               así que tracks vistos antes pintan instantáneo. */
            var albumSpan = document.createElement('span');
            albumSpan.className = 'pl-item-album-text';
            albumSpan.dataset.videoId = track.videoId || '';
            t2.appendChild(albumSpan);
            if (typeof _resolveAlbumForRow === 'function') {
                _resolveAlbumForRow(track, albumSpan, thumbImg);
            }
            /* Click en el título → abre el álbum de la canción. Si ya se
               resolvió (albumSpan tiene dataset), lo abre directo; si no,
               hace un find-album al vuelo. */
            t1.classList.add('pl-item-title-link');
            (function(tr, aSpan){
                t1.addEventListener('click', function(ev){
                    ev.stopPropagation();
                    var id = aSpan.dataset.albumId, nm = aSpan.dataset.albumName;
                    if (id && typeof openAlbumViewer === 'function') { openAlbumViewer(id, nm); return; }
                    var p = new URLSearchParams({ title: tr.title || '', artist: tr.artist || '', videoId: tr.videoId || '' });
                    fetch('assets/music/api.php?action=find-album&' + p.toString())
                        .then(function(r){ return r.ok ? r.json() : null; })
                        .then(function(d){
                            var norm = (typeof _normalizeAlbumPayload === 'function') ? _normalizeAlbumPayload(d, tr) : (d && !d.notFound ? d : null);
                            if (norm && norm.albumKey && typeof openAlbumViewer === 'function') openAlbumViewer(norm.albumKey, norm.albumName);
                        }).catch(function(){});
                });
            })(track, albumSpan);
            if (track.addedBy) {
                var addedBySpan = document.createElement('span');
                addedBySpan.className = 'pl-item-addedby';
                addedBySpan.textContent = track.addedBy;
                t2.appendChild(addedBySpan);
            }
            infoDiv.appendChild(t1);
            infoDiv.appendChild(t2);

            var btnsDiv = document.createElement('div');
            btnsDiv.className = 'pl-item-btns';
            (function(trackIndex) {
                var playTrackBtn = makeBtn('▶', 'pl-action-btn', function() {
                    var pl = allPlaylists[editingPlIdx];
                    if (!pl || !editList.length) return;
                    currentPlaylistId = pl.id;
                    currentPlaylistHasCollabs = !!(pl.sharedFrom || (pl.collaborators && pl.collaborators.length > 0));
                    MelonPlayerState.setPlaylist(pl.id, trackIndex);
                    /* Encola SIEMPRE la playlist completa (editList, el estado
                       en vivo de lo que se ve), no solo lo filtrado: al darle
                       play a una canción el resto queda en cola igual. */
                    playlist.length = 0;
                    editList.forEach(function(t) { playlist.push(t); });
                    currentTrack = trackIndex;
                    updateTrackUI(trackIndex);
                    updatePlayerTitle(pl.name);
                    if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
                        ytPlayer.loadVideoById(playlist[trackIndex].videoId);
                    }
                });
                playTrackBtn.title = 'Reproducir (encola el resto de la playlist)';
                btnsDiv.appendChild(playTrackBtn);
            })(i);
            btnsDiv.appendChild(makeBtn('✎', 'pl-action-btn', function() { startEdit(row, infoDiv, btnsDiv, i); }));
            btnsDiv.appendChild(makeBtn('✕', 'pl-action-btn', function() { editList.splice(i, 1); renderList(); }));

            row.addEventListener('dragstart', function(e) {
                dragSrc = i;
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(function() { row.classList.add('dragging'); }, 0);
            });
            row.addEventListener('dragend', function() {
                row.classList.remove('dragging');
                plList.querySelectorAll('.pl-item').forEach(function(r) { r.classList.remove('drag-over'); });
            });
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                plList.querySelectorAll('.pl-item').forEach(function(r) { r.classList.remove('drag-over'); });
                row.classList.add('drag-over');
            });
            row.addEventListener('drop', function(e) {
                e.preventDefault();
                if (dragSrc !== null && dragSrc !== i) {
                    var moved = editList.splice(dragSrc, 1)[0];
                    editList.splice(i, 0, moved);
                    renderList();
                }
            });

            row.addEventListener('contextmenu', function(e) { showTrackCtxMenu(e, track); });

            row.appendChild(handle);
            row.appendChild(thumbWrap);
            row.appendChild(infoDiv);
            row.appendChild(btnsDiv);
            plList.appendChild(row);
        });
        if (filtering && shown === 0) {
            var empty = document.createElement('div');
            empty.className = 'pl-filter-empty';
            empty.textContent = 'No hay canciones que coincidan con "' + (editFilter || '').trim() + '".';
            plList.appendChild(empty);
        }
    }

    /* ──── Context menu: añadir a otra playlist ──── */
    var ctxMenu = document.createElement('div');
    ctxMenu.className = 'window';
    ctxMenu.setAttribute('data-no-auto-z', '');   // popup: z fijo, siempre encima
    ctxMenu.style.cssText = 'display:none;position:fixed;z-index:9999;padding:2px 0;min-width:160px;';
    document.body.appendChild(ctxMenu);

    var addToPicker = document.createElement('div');
    addToPicker.className = 'window';
    addToPicker.setAttribute('data-no-auto-z', '');
    addToPicker.style.cssText = 'display:none;position:fixed;z-index:10000;min-width:190px;';
    addToPicker.innerHTML =
        '<div class="title-bar"><div class="title-bar-text">Añadir a playlist</div>' +
        '<div class="title-bar-controls"><button aria-label="Close" id="add-to-picker-close"></button></div></div>' +
        '<div class="window-body" style="padding:4px;max-height:200px;overflow-y:auto;" id="add-to-picker-list"></div>';
    document.body.appendChild(addToPicker);

    document.getElementById('add-to-picker-close').addEventListener('click', function() {
        addToPicker.style.display = 'none';
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest || (!e.target.closest('#pl-ctx-menu-el') && !e.target.closest('#add-to-picker-close'))) {
            ctxMenu.style.display = 'none';
        }
    });
    ctxMenu.id = 'pl-ctx-menu-el';

    function showAddToPicker(anchorX, anchorY, track) {
        var pickerList = document.getElementById('add-to-picker-list');
        /* Lazy-load de playlists: el editor (loadPlaylists) solo se
           llama cuando el usuario abre el panel de playlists. Si nunca
           lo abrió, allPlaylists está vacío y "Añadir a otra playlist"
           mostraba "no hay otras". Hacemos un fetch en frío aquí y
           rellenamos allPlaylists; las próximas veces ya está caliente. */
        if (!allPlaylists || allPlaylists.length === 0) {
            pickerList.innerHTML = '<div style="font-size:11px;padding:4px;color:#808080;">Cargando…</div>';
            addToPicker.style.display = 'block';
            addToPicker.style.left = Math.min(anchorX, window.innerWidth  - 200) + 'px';
            addToPicker.style.top  = Math.min(anchorY, window.innerHeight - 100) + 'px';
            fetch('assets/music/api.php?action=get-playlists')
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data || data.error) {
                        pickerList.innerHTML = '<div style="font-size:11px;padding:4px;color:#808080;">Error cargando playlists.</div>';
                        return;
                    }
                    allPlaylists = data;
                    /* Re-pinta con datos frescos. */
                    renderAddToPicker(track, pickerList);
                })
                .catch(function(){
                    pickerList.innerHTML = '<div style="font-size:11px;padding:4px;color:#808080;">Error de red.</div>';
                });
            return;
        }
        renderAddToPicker(track, pickerList);
        addToPicker.style.display = 'block';
        var pw = addToPicker.offsetWidth, ph = addToPicker.offsetHeight;
        addToPicker.style.left = Math.min(anchorX, window.innerWidth  - pw - 8) + 'px';
        addToPicker.style.top  = Math.min(anchorY, window.innerHeight - ph - 8) + 'px';
    }
    /* Helper extraído — pinta las playlists destino en el picker. Reusable
       tras un lazy-fetch o si ya estaban cargadas. */
    function renderAddToPicker(track, pickerList) {
        pickerList.innerHTML = '';
        /* Este picker SIEMPRE se abre desde una canción/álbum que NO está
           dentro de una playlist (visor de álbum, reproductor, búsqueda),
           así que NO hay "playlist actual" que excluir: mostramos TODAS las
           playlists del usuario. (Antes se filtraba la última playlist
           abierta en el editor → si solo tenías esa, no salía ninguna.) */
        var targets = (allPlaylists || []).slice();

        if (!targets.length) {
            pickerList.innerHTML = '<div style="font-size:11px;padding:4px;color:#808080;">No tienes playlists. Crea una primero.</div>';
        } else {
            targets.forEach(function(pl) {
                var item = document.createElement('div');
                item.className = 'pl-menu-item';
                item.textContent = pl.name;
                item.addEventListener('click', function() {
                    addToPicker.style.display = 'none';
                    addTrackToPlaylist(pl, track);
                });
                pickerList.appendChild(item);
            });
        }
        /* No reposicionamos aquí — el caller (showAddToPicker o el
           callback del fetch) ya dejó el picker visible y anclado. */
    }

    /* Añade la canción al final de la playlist (si no estaba) y la guarda.
       La usan el selector "Añadir" y el reproductor flotante. */
    function addTrackToPlaylist(pl, track) {
        var already = pl.tracks.some(function(t) { return t.videoId === track.videoId; });
        if (!already) pl.tracks.push(Object.assign({}, track));
        fetch('assets/music/api.php?action=save-playlist-item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pl.id, name: pl.name, tracks: pl.tracks })
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) alert(data.error);
        }).catch(function() {});
    }

    function showTrackCtxMenu(e, track) {
        e.preventDefault();
        ctxMenu.innerHTML = '';
        var ex = e.clientX, ey = e.clientY;

        var addPl = document.createElement('div');
        addPl.className = 'pl-menu-item';
        addPl.textContent = '➕ Añadir';
        addPl.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            showAddToPicker(ex, ey, track);
        });
        ctxMenu.appendChild(addPl);

        /* Corregir los datos de esta canción: título, artista, álbum y/o
           el link de YouTube. Se guarda por videoId (global y persistente)
           → la canción se ve corregida en cualquier playlist y al importar. */
        var fixAlbum = document.createElement('div');
        fixAlbum.className = 'pl-menu-item';
        /* Icono musicaIcon.png + etiqueta. innerHTML porque incluimos
           el <img> del icono (lo reescribe icon-pack.js según el pack). */
        fixAlbum.innerHTML =
            '<img src="assets/img/appIcons/musicaIcon.png" alt="" ' +
                'style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:5px;">' +
            'Corregir…';
        fixAlbum.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            reportWrongAlbum(track);
        });
        ctxMenu.appendChild(fixAlbum);

        var addProfile = document.createElement('div');
        addProfile.className = 'pl-menu-item';
        addProfile.textContent = '🎵 Añadir a mi perfil';
        addProfile.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            /* Cierra el fullscreen llamando a la función real (expuesta
               como window.__closeFullscreenPlayer) en vez de simular
               click() en el botón restore. El click() simulado a veces
               no se propagaba bien, dejando el flag isOpen en true →
               el siguiente click en maximize hacía return temprano y
               necesitabas dos clicks. */
            if (typeof window.__closeFullscreenPlayer === 'function') {
                window.__closeFullscreenPlayer();
            }
            if (typeof window.profileAddTrackAndReview === 'function') {
                window.profileAddTrackAndReview(track);
            }
        });
        ctxMenu.appendChild(addProfile);

        /* Descargar: vale para la que suena y para las filas de las
           playlists y la búsqueda (sin videoId no hay qué descargar). Solo
           descarga la app de escritorio; en el navegador, que no puede bajar
           el audio de YouTube, ofrece la app (openGetAppDialog). */
        if (track && track.videoId) {
            var dl = document.createElement('div');
            dl.className = 'pl-menu-item';
            dl.textContent = '⬇ Descargar…';
            dl.addEventListener('click', function() {
                ctxMenu.style.display = 'none';
                if (window.melonDesktop && window.melonDesktop.download) openDownloadDialog(track);
                else openGetAppDialog();
            });
            ctxMenu.appendChild(dl);
        }

        /* Enlace para compartir (enlace.php?cancion=…, abre la app si la
           tiene). */
        if (track && track.videoId && typeof window.melonCopyLink === 'function') {
            var lnk = document.createElement('div');
            lnk.className = 'pl-menu-item';
            lnk.textContent = '🔗 Copiar enlace';
            lnk.addEventListener('click', function() {
                ctxMenu.style.display = 'none';
                window.melonCopyLink('cancion', track.videoId);
            });
            ctxMenu.appendChild(lnk);
        }

        /* Item Listen Together — abre el modal de invitar (o gestiona
           la sesión actual si ya estás en una). */
        var ltItem = document.createElement('div');
        ltItem.className = 'pl-menu-item';
        if (typeof LT_ROLE !== 'undefined' && LT_ROLE === 'host') {
            ltItem.textContent = '🎧 Invitar a más usuarios…';
        } else if (typeof LT_ROLE !== 'undefined' && LT_ROLE === 'guest') {
            ltItem.textContent = '🎧 Ver sesión actual';
        } else {
            ltItem.textContent = '🎧 Escuchar juntos…';
        }
        ltItem.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            if (typeof ltOpenModal === 'function') ltOpenModal();
        });
        ctxMenu.appendChild(ltItem);

        /* Item de salir, solo si estás en una sesión. */
        if (typeof LT_ROLE !== 'undefined' && LT_ROLE) {
            var ltLeave = document.createElement('div');
            ltLeave.className = 'pl-menu-item';
            ltLeave.textContent = '✕ Salir de la sesión';
            ltLeave.addEventListener('click', function() {
                ctxMenu.style.display = 'none';
                if (typeof window.ltLeave === 'function') window.ltLeave();
            });
            ctxMenu.appendChild(ltLeave);
        }

        ctxMenu.style.display = 'block';
        var cw = ctxMenu.offsetWidth, ch = ctxMenu.offsetHeight;
        ctxMenu.style.left = Math.min(ex, window.innerWidth  - cw - 8) + 'px';
        ctxMenu.style.top  = Math.min(ey, window.innerHeight - ch - 8) + 'px';
    }
    /* Diálogo para CORREGIR una canción: el usuario puede ajustar el
       título, el artista, el link de YouTube y/o el álbum. Todo se guarda
       por videoId vía report-album (global y persistente) → la canción se
       ve con esos valores en cualquier playlist y al volver a importarla.
       El álbum se busca en vivo (iTunes + Deezer) mientras se escribe; al
       elegir un resultado queda "seleccionado" y se envía con el resto al
       pulsar Guardar (ya no auto-envía). */
    /* Tras guardar una corrección (report-album): aplica los datos nuevos
       en memoria y repinta el reproductor y las filas. También la usa el
       reproductor flotante, que guarda la corrección por su cuenta
       (melonTrackAction 'fixed'). */
    function applyAlbumFix(track, d) {
        /* Si cambió el link de YouTube, el videoId vigente de la
           canción pasa a ser el nuevo: aplicamos in-memory para que
           el resto de la UI (mini-player, filas) use el id correcto. */
        var effectiveVid = (d.newVideoId && d.newVideoId.length === 11) ? d.newVideoId : track.videoId;
        if (d.title)  track.title  = d.title;
        if (d.artist) track.artist = d.artist;
        /* Guarda la corrección de álbum en la cache local por videoId
           (autoritativa) para repintar al instante sin re-fetch. */
        if (d.album) { try { _albumCacheSet(effectiveVid, d.album); _albumCacheSet(track.videoId, d.album); } catch (_) {} }
        else { try { var c = _loadAlbumCache(); delete c[track.videoId]; _saveAlbumCache(); } catch (_) {} }
        /* Mini-player. */
        var cur = (typeof playlist !== 'undefined' && playlist[currentTrack]) || null;
        if (cur && cur.videoId === track.videoId && typeof resolveAndShowAlbum === 'function') {
            resolveAndShowAlbum(cur);
        }
        /* Filas del editor con ese videoId → actualiza el nombre del
           álbum que aparece en la lista. */
        if (typeof plList !== 'undefined' && plList && typeof _resolveAlbumForRow === 'function') {
            plList.querySelectorAll('.pl-item-album-text').forEach(function(span){
                if (span.dataset.videoId !== track.videoId) return;
                var row = span.closest('.pl-item');
                var th  = row ? row.querySelector('.pl-item-thumb-wrap img') : null;
                _resolveAlbumForRow(track, span, th);
            });
        }
        /* Si la ventana del artista está abierta, refréscala para que
           muestre la carátula/datos corregidos al instante. */
        if (typeof window.refreshArtistWindowIfOpen === 'function') window.refreshArtistWindowIfOpen();
    }

    function reportWrongAlbum(track) {
        if (!track || !track.videoId) return;

        /* Álbum elegido (opcional). null = no se toca el álbum. */
        var chosenAlbum = null;

        var bd = document.createElement('div');
        bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:100000;display:flex;align-items:center;justify-content:center;';
        var win = document.createElement('div');
        win.className = 'window';
        win.style.cssText = 'width:420px;max-width:94vw;max-height:90vh;display:flex;flex-direction:column;';
        win.innerHTML =
            '<div class="title-bar" data-ra-titlebar><div class="title-bar-text">Corregir canción</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" data-act="close"></button></div></div>' +
            '<div class="window-body" style="padding:10px;flex:1;min-height:0;display:flex;flex-direction:column;overflow-y:auto;">' +
                '<p style="margin:0 0 10px;font-size:10px;line-height:1.45;opacity:0.85;">Corrige los datos de esta canción. Se guardan para siempre: cuando la añadas a otra playlist o la importes, se usarán estos valores.</p>' +
                '<label style="font-size:11px;font-weight:bold;display:block;margin-bottom:2px;">Título</label>' +
                '<input type="text" class="ra-title" autocomplete="off" placeholder="Título de la canción" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
                '<label style="font-size:11px;font-weight:bold;display:block;margin-bottom:2px;">Artista</label>' +
                '<input type="text" class="ra-artist" autocomplete="off" placeholder="Nombre del artista" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
                '<label style="font-size:11px;font-weight:bold;display:block;margin-bottom:2px;">Link de YouTube</label>' +
                '<input type="text" class="ra-link" autocomplete="off" placeholder="Pega un enlace de YouTube (opcional)" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
                '<label style="font-size:11px;font-weight:bold;display:block;margin-bottom:2px;">Álbum</label>' +
                '<input type="text" class="ra-input" autocomplete="off" placeholder="Escribe el nombre del álbum (opcional)…" style="width:100%;box-sizing:border-box;">' +
                '<div class="ra-chosen" style="display:none;align-items:center;gap:8px;margin-top:6px;padding:4px 6px;border:1px solid var(--border,#444);"></div>' +
                '<div class="ra-results" style="margin-top:6px;flex:1;min-height:0;overflow-y:auto;"></div>' +
                '<div class="ra-hint" style="font-size:10px;opacity:0.7;margin-top:6px;">Para el álbum, escribe y elige un resultado de la lista.</div>' +
                '<div style="display:flex;justify-content:flex-end;gap:6px;margin-top:10px;flex-shrink:0;">' +
                    '<button class="button" data-act="cancel">Cancelar</button>' +
                    '<button class="button" data-act="save">Guardar</button>' +
                '</div>' +
            '</div>';
        bd.appendChild(win);
        document.body.appendChild(bd);

        var titleIn  = win.querySelector('.ra-title');
        var artistIn = win.querySelector('.ra-artist');
        var linkIn   = win.querySelector('.ra-link');
        var input     = win.querySelector('.ra-input');
        var resultsEl = win.querySelector('.ra-results');
        var chosenEl  = win.querySelector('.ra-chosen');
        titleIn.value  = track.title  || '';
        artistIn.value = track.artist || '';
        setTimeout(function(){ titleIn.focus(); titleIn.select(); }, 30);

        function close(){ document.removeEventListener('keydown', onKey, true); bd.remove(); }
        function onKey(e){ if (e.key === 'Escape') { e.preventDefault(); close(); } }
        document.addEventListener('keydown', onKey, true);
        win.querySelector('[data-act="close"]').addEventListener('click', close);
        win.querySelector('[data-act="cancel"]').addEventListener('click', close);
        bd.addEventListener('click', function(e){ if (e.target === bd) close(); });

        /* Pinta el álbum elegido y permite quitarlo. */
        function renderChosen() {
            if (!chosenAlbum) { chosenEl.style.display = 'none'; chosenEl.innerHTML = ''; return; }
            chosenEl.style.display = 'flex';
            chosenEl.innerHTML = '';
            var img = document.createElement('img');
            img.src = chosenAlbum.image || '';
            img.style.cssText = 'width:34px;height:34px;object-fit:cover;flex:0 0 34px;background:#222;';
            img.onerror = function(){ this.style.visibility = 'hidden'; };
            var txt = document.createElement('div');
            txt.style.cssText = 'min-width:0;flex:1;';
            txt.innerHTML = '<div style="font-size:11px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>' +
                            '<div style="font-size:10px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>';
            txt.children[0].textContent = chosenAlbum.name || '';
            txt.children[1].textContent = chosenAlbum.artist || '';
            var rm = document.createElement('button');
            rm.className = 'button'; rm.textContent = 'Quitar'; rm.style.flexShrink = '0';
            rm.addEventListener('click', function(){ chosenAlbum = null; renderChosen(); });
            chosenEl.appendChild(img); chosenEl.appendChild(txt); chosenEl.appendChild(rm);
        }

        function submit() {
            var payload = {
                videoId: track.videoId,
                title:   titleIn.value.trim(),
                artist:  artistIn.value.trim(),
                videoLink: linkIn.value.trim()
            };
            if (chosenAlbum) {
                payload.albumKey    = chosenAlbum.albumKey || '';
                payload.albumName   = chosenAlbum.name   || '';
                payload.albumArtist = chosenAlbum.artist || '';
                payload.albumImage  = chosenAlbum.image  || '';
            }
            fetch('assets/music/api.php?action=report-album', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok) {
                    var msg = (d && d.error) || 'No se pudo guardar la corrección';
                    if (window.notifSystem) notifSystem.show({ type: 'error', title: 'No corregido', message: msg });
                    else alert(msg);
                    return;
                }
                applyAlbumFix(track, d);
                if (window.notifSystem) {
                    notifSystem.show({ type: 'success', title: 'Canción corregida',
                        message: (d.album && d.album.albumName) ? ('Álbum: ' + d.album.albumName) : 'Guardado correctamente' });
                }
                close();
            })
            .catch(function(){
                if (window.notifSystem) notifSystem.show({ type: 'error', title: 'Error de red', message: 'No se pudo guardar la corrección' });
            });
        }
        win.querySelector('[data-act="save"]').addEventListener('click', submit);

        function renderResults(list) {
            resultsEl.innerHTML = '';
            (list || []).forEach(function(a){
                var row = document.createElement('div');
                row.className = 'pl-menu-item';
                row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 6px;cursor:pointer;';
                var img = document.createElement('img');
                img.src = a.image || '';
                img.alt = '';
                img.style.cssText = 'width:34px;height:34px;object-fit:cover;flex:0 0 34px;background:#222;';
                img.onerror = function(){ this.style.visibility = 'hidden'; };
                var txt = document.createElement('div');
                txt.style.cssText = 'min-width:0;flex:1;';
                var nm = document.createElement('div');
                nm.textContent = a.name || '';
                nm.style.cssText = 'font-size:11px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                var ar = document.createElement('div');
                ar.textContent = a.artist || '';
                ar.style.cssText = 'font-size:10px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                txt.appendChild(nm); txt.appendChild(ar);
                row.appendChild(img); row.appendChild(txt);
                row.addEventListener('click', function(){
                    /* Selecciona el álbum (no envía aún): se manda con el
                       resto de campos al pulsar Guardar. */
                    chosenAlbum = { albumKey: a.albumKey, name: a.name || '', artist: a.artist || '', image: a.image || '' };
                    renderChosen();
                    input.value = '';
                    resultsEl.innerHTML = '';
                    lastQ = null;
                });
                resultsEl.appendChild(row);
            });
        }

        var searchT = null, lastQ = null;
        function doSearch() {
            var q = input.value.trim();
            if (q === lastQ) return;
            lastQ = q;
            if (q.length < 2) { resultsEl.innerHTML = ''; return; }
            fetch('assets/music/api.php?action=search-albums&q=' + encodeURIComponent(q) + '&artist=' + encodeURIComponent(artistIn.value.trim() || track.artist || ''))
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (input.value.trim() !== q) return;   /* respuesta obsoleta */
                    renderResults(d && d.ok ? d.results : []);
                })
                .catch(function(){});
        }
        input.addEventListener('input', function(){ clearTimeout(searchT); searchT = setTimeout(doSearch, 300); });
        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchT); doSearch(); }
        });
    }

    /* Exposición global → permite triggear el mismo menú desde el
       mini-player (no solo desde los rows de la lista). */
    window.openTrackCtxMenu = showTrackCtxMenu;

    /* Reproductor flotante de la app de escritorio (apps/mini-player.html):
       su panel de playlists y las opciones de su clic derecho llegan aquí
       y hacen lo mismo que este reproductor. Las playlists se recargan del
       servidor antes de tocarlas: guardar una playlist manda la lista
       entera, y una copia vieja borraría lo añadido desde otro sitio. */
    window.melonTrackAction = function(action, value) {
        var track = nowPlayingTrack();
        function withFreshPlaylist(id, fn) {
            loadPlaylists(function() {
                var pl = allPlaylists.find(function(p) { return String(p.id) === String(id); });
                if (pl) fn(pl);
            });
        }
        switch (action) {
            case 'play-playlist':      /* value: { playlistId, index } */
                withFreshPlaylist(value.playlistId, function(pl) { playPlaylistFrom(pl, value.index); });
                break;
            /* Búsqueda del flotante (canciones, álbumes y artistas), como
               la del menú de playlists. */
            case 'play-song':          /* value: { videoId, title, artist, duration } */
                melonPlaySong(value);
                break;
            case 'play-artist-track':  /* value: { title, artist } — "Populares" del artista */
                playArtistTrackByName(value.title, value.artist);
                break;
            case 'play-album':         /* value: { albumKey, name, artist, image, tracks, index } */
                if (!value.tracks || !value.tracks[value.index]) break;
                _loadAlbumQueue(value.albumKey, { name: value.name, artist: value.artist, image: value.image }, value.tracks, value.index);
                melonRecordRecent('album', value.albumKey, value.name || '', value.image || '', value.artist || '');
                break;
            case 'add-to-playlist':    /* value: id de la playlist */
                if (track && track.videoId) withFreshPlaylist(value, function(pl) { addTrackToPlaylist(pl, track); });
                break;
            case 'fix':
                if (track && track.videoId) reportWrongAlbum(track);
                break;
            case 'fixed':              /* value: { videoId, title, artist, result } — corregida en el flotante */
                if (!value || !value.result) break;
                applyAlbumFix(track && track.videoId === value.videoId ? track
                    : { videoId: value.videoId, title: value.title, artist: value.artist }, value.result);
                syncSystemMedia();
                break;
            case 'add-profile':
                if (typeof window.__closeFullscreenPlayer === 'function') window.__closeFullscreenPlayer();
                if (track && typeof window.profileAddTrackAndReview === 'function') window.profileAddTrackAndReview(track);
                break;
            case 'listen-together':
                if (typeof ltOpenModal === 'function') ltOpenModal();
                break;
            /* "Escuchar juntos" del flotante. */
            case 'lt-invite':          /* value: user_key */
                ltInviteUser(value);
                break;
            case 'lt-answer':          /* value: { id, accept } */
                ltAnswerInvite(value.id, !!value.accept);
                break;
            case 'leave-session':
                if (typeof window.ltLeave === 'function') window.ltLeave();
                break;
        }
    };

    function startEdit(row, infoDiv, btnsDiv, i) {
        var track = editList[i];
        var wrap  = document.createElement('div');
        wrap.className = 'pl-item-edit-inputs';
        var ti = document.createElement('input');
        ti.type = 'text'; ti.value = track.title;
        var ai = document.createElement('input');
        ai.type = 'text'; ai.value = track.artist || '';
        wrap.appendChild(ti); wrap.appendChild(ai);
        row.replaceChild(wrap, infoDiv);

        btnsDiv.innerHTML = '';
        btnsDiv.appendChild(makeBtn('✓', 'pl-action-btn', function() {
            editList[i].title  = ti.value.trim() || editList[i].title;
            editList[i].artist = ai.value.trim();
            renderList();
        }));
        btnsDiv.appendChild(makeBtn('✕', 'pl-action-btn', renderList));
        ti.focus(); ti.select();
    }

    function makeBtn(label, cls, fn) {
        var b = document.createElement('button');
        b.className = 'button ' + cls;
        b.textContent = label;
        b.addEventListener('click', fn);
        return b;
    }

    function saveCurrentPlaylist(onSuccess) {
        if (editingPlIdx < 0) return;
        var pl = allPlaylists[editingPlIdx];
        var newName = plNameInput.value.trim();
        if (newName) pl.name = newName;
        pl.tracks = editList.slice();
        /* Imagen de la playlist (solo en propias; en compartidas el input
           está deshabilitado y el backend la ignora). */
        if (plImageInput && !pl.sharedFrom) pl.image = plImageInput.value.trim();
        var savePayload = { id: pl.id, name: pl.name, tracks: pl.tracks };
        if (!pl.sharedFrom) savePayload.image = pl.image || '';
        if (pl.sharedFrom) savePayload.sharedFrom = pl.sharedFrom;
        fetch('assets/music/api.php?action=save-playlist-item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(savePayload)
        })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(data) {
            if (data.error) { alert(data.error); return; }
            if (onSuccess) onSuccess();
        })
        .catch(function(e) { alert('Error al guardar: ' + e.message); });
    }

    /* Autosave del editor: tras añadir canciones o importar una
       playlist completa, escribe automáticamente y propaga los tracks
       a las estructuras in-memory para que el botón ▶ del editor
       reproduzca SIN tener que cerrar la ventana y volver a abrirla.
       - pl.tracks se actualiza con el contenido actual de editList
         (el botón ▶ del editor lee de pl.tracks, no de editList).
       - Si la playlist editada coincide con la que ya está cargada en
         el player, también empujamos los nuevos tracks a `playlist[]`
         sin reiniciar la reproducción actual.
       - El POST a save-playlist-item va async — no bloqueamos la UI
         ni mostramos alert salvo en error. */
    function autosaveEditor() {
        if (editingPlIdx < 0) return;
        var pl = allPlaylists[editingPlIdx];
        if (!pl) return;
        pl.tracks = editList.slice();
        if (pl.id === currentPlaylistId) {
            playlist.length = 0;
            pl.tracks.forEach(function(t) { playlist.push(t); });
        }
        saveCurrentPlaylist();
    }

    document.getElementById('btn-edit-playlist').addEventListener('click', openEditor);
    document.getElementById('pl-close').addEventListener('click', closeEditor);
    document.getElementById('pl-back').addEventListener('click', showHome);

    /* Buscador/filtro de la playlist abierta: re-renderiza la lista
       aplicando el filtro por título/artista. */
    if (plEditorSearch) {
        plEditorSearch.addEventListener('input', function() {
            editFilter = plEditorSearch.value || '';
            renderList();
        });
    }

    /* ── Diálogo "Editar playlist" (nombre + imagen) ── */
    (function(){
        var dlg   = document.getElementById('edit-playlist-dialog');
        if (!dlg) return;
        function close(){ dlg.style.display = 'none'; }
        var cb = document.getElementById('edit-pl-close');  if (cb) cb.addEventListener('click', close);
        var cc = document.getElementById('edit-pl-cancel'); if (cc) cc.addEventListener('click', close);
        var sv = document.getElementById('edit-pl-save');
        if (sv) sv.addEventListener('click', function(){
            var idx = parseInt(dlg.dataset.idx, 10);
            var pl  = allPlaylists[idx];
            if (!pl || pl.sharedFrom) { close(); return; }
            var newName = document.getElementById('edit-pl-name').value.trim();
            var newImg  = document.getElementById('edit-pl-image').value.trim();
            if (newName) pl.name = newName;
            pl.image = newImg;
            fetch('assets/music/api.php?action=save-playlist-item', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: pl.id, name: pl.name, image: pl.image, tracks: pl.tracks })
            })
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(d){ if (d.error) { alert(d.error); return; } close(); renderHome(); })
            .catch(function(e){ alert('Error al guardar: ' + e.message); });
        });
    })();

    document.getElementById('pl-add').addEventListener('click', function() {
        addTrackCallback = function(track) {
            editList.push(track);
            renderList();
            /* Autoguarda + propaga a pl.tracks / playlist[] para que
               la nueva canción se pueda reproducir sin cerrar el editor. */
            autosaveEditor();
        };
        openAddDialog();
    });

    document.getElementById('pl-save').addEventListener('click', function() {
        saveCurrentPlaylist(function() {
            var pl = allPlaylists[editingPlIdx];
            if (pl.id === currentPlaylistId && pl.tracks.length > 0) {
                playlist.length = 0;
                pl.tracks.forEach(function(t) { playlist.push(t); });
                currentTrack = 0;
                updateTrackUI(0);
                updatePlayerTitle(pl.name);
                if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
                    ytPlayer.loadVideoById(playlist[0].videoId);
                }
            }
            showHome();
        });
    });

    /* Create playlist dialog */
    (function() {
        var createDlg    = document.getElementById('create-playlist-dialog');
        var createInput  = document.getElementById('create-pl-name');
        var createImage  = document.getElementById('create-pl-image');
        var createSubmit = document.getElementById('create-pl-submit');

        function openCreateDlg() {
            createInput.value = '';
            if (createImage) createImage.value = '';
            createDlg.style.display = 'block';
            setTimeout(function() { createInput.focus(); createInput.select(); }, 50);
        }
        function closeCreateDlg() { createDlg.style.display = 'none'; }

        document.getElementById('pl-create').addEventListener('click', openCreateDlg);
        document.getElementById('create-pl-close').addEventListener('click', closeCreateDlg);
        document.getElementById('create-pl-cancel').addEventListener('click', closeCreateDlg);

        function doCreate() {
            var name = createInput.value.trim();
            if (!name) return;
            var img = createImage ? createImage.value.trim() : '';
            closeCreateDlg();
            var newPl = { id: 'pl_' + Date.now(), name: name, image: img, tracks: [], collaborators: [] };
            fetch('assets/music/api.php?action=save-playlist-item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(newPl)
            })
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(data) {
                if (data.error) { alert(data.error); return; }
                /* El backend asigna el ID definitivo (BIGINT) → reemplazar
                   el placeholder pl_<timestamp> antes de meterlo en memoria
                   para que los próximos save/delete usen el ID real. */
                if (data.id) newPl.id = data.id;
                allPlaylists.push(newPl);
                showEditorView(allPlaylists.length - 1);
            })
            .catch(function(e) { alert('Error: ' + e.message); });
        }

        createSubmit.addEventListener('click', doCreate);
        createInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') doCreate(); });
    })();

    /* ... menu + import playlist */
    (function() {
        var moreBtn    = document.getElementById('pl-more');
        var moreMenu   = document.getElementById('pl-more-menu');
        var importDlg  = document.getElementById('import-playlist-dialog');
        var importUrl  = document.getElementById('import-pl-url');
        var importStat = document.getElementById('import-pl-status');
        var importBtn  = document.getElementById('import-pl-submit');

        moreBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            moreMenu.style.display = moreMenu.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function() { moreMenu.style.display = 'none'; });

        document.getElementById('pl-more-import').addEventListener('click', function() {
            moreMenu.style.display = 'none';
            importUrl.value = '';
            importStat.textContent = '';
            importBtn.style.pointerEvents = ''; importBtn.classList.remove('btn-busy');
            importDlg.style.display = 'block';
        });

        function closeImport() { importDlg.style.display = 'none'; }
        document.getElementById('import-pl-close').addEventListener('click', closeImport);
        document.getElementById('import-pl-cancel').addEventListener('click', closeImport);

        importBtn.addEventListener('click', function() {
            var url = importUrl.value.trim();
            if (!url) return;
            importStat.textContent = 'Importando...';
            importBtn.style.pointerEvents = 'none'; importBtn.classList.add('btn-busy');
            fetch('assets/music/api.php?action=import-playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url })
            })
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(data) {
                importBtn.style.pointerEvents = ''; importBtn.classList.remove('btn-busy');
                if (data.error) { importStat.textContent = 'Error: ' + data.error; return; }
                var importer = usersInfo[currentUserKey] ? usersInfo[currentUserKey].label : '';
                data.tracks.forEach(function(t) { editList.push(Object.assign({}, t, { addedBy: importer })); });
                renderList();
                /* Autoguarda + propaga a pl.tracks / playlist[] para que
                   las canciones importadas se puedan reproducir sin
                   cerrar el editor ni darle a "Guardar". */
                autosaveEditor();
                importStat.textContent = '';
                closeImport();
            })
            .catch(function(e) {
                importBtn.style.pointerEvents = ''; importBtn.classList.remove('btn-busy');
                importStat.textContent = 'Error: ' + e.message;
            });
        });

        /* Spotify CSV import */
        var spotifyDlg  = document.getElementById('spotify-import-dialog');
        var spotifyFile = document.getElementById('spotify-import-file');
        var spotifyStat = document.getElementById('spotify-import-status');
        var spotifyBtn  = document.getElementById('spotify-import-submit');

        function openSpotifyDialog() {
            moreMenu.style.display = 'none';
            spotifyFile.value = '';
            spotifyFileName.value = '';
            spotifyStat.textContent = '';
            spotifyBtn.style.pointerEvents = ''; spotifyBtn.classList.remove('btn-busy');
            spotifyProgressWrap.style.display = 'none';
            spotifyProgressFill.style.width = '0%';
            spotifyDlg.style.display = 'block';
        }

        document.getElementById('pl-more-spotify').addEventListener('click', openSpotifyDialog);

        var spotifyFileName = document.getElementById('spotify-file-name');
        document.getElementById('spotify-file-browse').addEventListener('click', function() { spotifyFile.click(); });
        spotifyFile.addEventListener('change', function() {
            spotifyFileName.value = spotifyFile.files[0] ? spotifyFile.files[0].name : '';
        });

        var spotifyAbortCtrl = null;

        function closeSpotifyImport() {
            if (spotifyAbortCtrl) { spotifyAbortCtrl.abort(); spotifyAbortCtrl = null; }
            spotifyBtn.style.pointerEvents = ''; spotifyBtn.classList.remove('btn-busy');
            spotifyProgressWrap.style.display = 'none';
            spotifyDlg.style.display = 'none';
        }
        document.getElementById('spotify-import-close').addEventListener('click', closeSpotifyImport);
        document.getElementById('spotify-import-cancel').addEventListener('click', closeSpotifyImport);

        function parseExportifyCSV(raw) {
            /* Parse RFC-4180-ish CSV properly (handles quoted fields with commas) */
            function parseLine(line) {
                var cols = [], cur = '', inQ = false;
                for (var i = 0; i < line.length; i++) {
                    var c = line[i];
                    if (c === '"') { inQ = !inQ; }
                    else if (c === ',' && !inQ) { cols.push(cur); cur = ''; }
                    else { cur += c; }
                }
                cols.push(cur);
                return cols;
            }
            var lines  = raw.split('\n');
            var header = parseLine(lines[0]);
            var tiIdx  = header.indexOf('Track Name');
            var arIdx  = header.indexOf('Artist Name(s)');
            if (tiIdx === -1) return [];
            var tracks = [];
            for (var i = 1; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                var cols   = parseLine(lines[i]);
                var title  = (cols[tiIdx] || '').trim();
                var artist = arIdx !== -1 ? (cols[arIdx] || '').trim() : '';
                if (title) tracks.push({ title: title, artist: artist, duration: 0 });
            }
            return tracks;
        }

        var spotifyProgressWrap = document.getElementById('spotify-progress-wrap');
        var spotifyProgressFill = document.getElementById('spotify-progress-fill');
        var spotifyProgressText = document.getElementById('spotify-progress-text');

        spotifyBtn.addEventListener('click', function() {
            var file = spotifyFile.files[0];
            if (!file) { spotifyStat.textContent = 'Selecciona un archivo CSV.'; return; }
            var reader = new FileReader();
            reader.onload = function(e) {
                var tracks = parseExportifyCSV(e.target.result);
                reader.onload = null;
                if (!tracks.length) { spotifyStat.textContent = 'No se encontraron canciones en el archivo.'; return; }

                spotifyAbortCtrl = new AbortController();
                var signal = spotifyAbortCtrl.signal;

                spotifyBtn.style.pointerEvents = 'none'; spotifyBtn.classList.add('btn-busy');
                spotifyStat.textContent = '';
                spotifyProgressWrap.style.display = 'block';
                spotifyProgressFill.style.width = '0%';
                spotifyProgressText.textContent = '0 encontradas · ' + tracks.length + ' restantes';

                var BATCH   = 10;
                var total   = tracks.length;
                var found   = 0;
                var done    = 0;
                var results = [];

                function nextBatch(offset) {
                    if (signal.aborted || offset >= total) return Promise.resolve();
                    var chunk = tracks.slice(offset, offset + BATCH);
                    return fetch('assets/music/api.php?action=yt-search-batch', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ tracks: chunk }),
                        signal:  signal
                    })
                    .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(function(data) {
                        if (data.tracks) {
                            results = results.concat(data.tracks);
                            found  += data.tracks.length;
                        }
                        done = Math.min(offset + BATCH, total);
                        var pct = Math.round(done / total * 100);
                        spotifyProgressFill.style.width = pct + '%';
                        spotifyProgressText.textContent = found + ' encontradas · ' + (total - done) + ' restantes';
                        return nextBatch(offset + BATCH);
                    });
                }

                nextBatch(0)
                .then(function() {
                    if (signal.aborted) return;
                    spotifyAbortCtrl = null;
                    spotifyBtn.style.pointerEvents = ''; spotifyBtn.classList.remove('btn-busy');
                    spotifyProgressWrap.style.display = 'none';
                    if (!results.length) { spotifyStat.textContent = 'No se encontraron vídeos en YouTube.'; return; }
                    var importer = usersInfo[currentUserKey] ? usersInfo[currentUserKey].label : '';
                    results.forEach(function(t) { editList.push(Object.assign({}, t, { addedBy: importer })); });
                    renderList();
                    /* Mismo autoguardado que en el import de URL: los
                       tracks del CSV de Spotify ya quedan listos para
                       reproducir sin cerrar el editor. */
                    autosaveEditor();
                    closeSpotifyImport();
                })
                .catch(function(e) {
                    if (e.name === 'AbortError') return;
                    spotifyAbortCtrl = null;
                    spotifyBtn.style.pointerEvents = ''; spotifyBtn.classList.remove('btn-busy');
                    spotifyProgressWrap.style.display = 'none';
                    spotifyStat.textContent = 'Error: ' + e.message;
                });
            };
            reader.readAsText(file);
        });

        /* ─── Importar de Tidal (API oficial → resolver a YouTube) ─── */
        var tidalDlg  = document.getElementById('tidal-import-dialog');
        var tidalUrl  = document.getElementById('tidal-import-url');
        var tidalStat = document.getElementById('tidal-import-status');
        var tidalBtn  = document.getElementById('tidal-import-submit');
        var tidalWrap = document.getElementById('tidal-progress-wrap');
        var tidalFill = document.getElementById('tidal-progress-fill');
        var tidalText = document.getElementById('tidal-progress-text');
        var tidalAbort = null;

        function tidalBusy(on) {
            tidalBtn.style.pointerEvents = on ? 'none' : '';
            tidalBtn.classList.toggle('btn-busy', on);
        }
        function openTidalDialog() {
            moreMenu.style.display = 'none';
            tidalUrl.value = '';
            tidalStat.textContent = ''; tidalStat.style.color = '';
            tidalWrap.style.display = 'none';
            tidalFill.style.width = '0%';
            tidalBusy(false);
            tidalDlg.style.display = 'block';
        }
        document.getElementById('pl-more-tidal').addEventListener('click', openTidalDialog);

        function closeTidalImport() {
            if (tidalAbort) { tidalAbort.abort(); tidalAbort = null; }
            tidalBusy(false);
            tidalWrap.style.display = 'none';
            tidalDlg.style.display = 'none';
        }
        document.getElementById('tidal-import-close').addEventListener('click', closeTidalImport);
        document.getElementById('tidal-import-cancel').addEventListener('click', closeTidalImport);

        /* Resuelve [{title,artist,duration}] a YouTube por lotes con progreso. */
        function tidalResolveBatches(tracks, signal) {
            var BATCH = 10, total = tracks.length, found = 0, results = [];
            tidalWrap.style.display = 'block';
            tidalFill.style.width = '0%';
            tidalText.textContent = '0 encontradas · ' + total + ' restantes';
            function nextBatch(offset) {
                if (signal.aborted || offset >= total) return Promise.resolve();
                var chunk = tracks.slice(offset, offset + BATCH);
                return fetch('assets/music/api.php?action=yt-search-batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ tracks: chunk }),
                    signal: signal
                })
                .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function(data) {
                    if (data.tracks) { results = results.concat(data.tracks); found += data.tracks.length; }
                    var done = Math.min(offset + BATCH, total);
                    tidalFill.style.width = Math.round(done / total * 100) + '%';
                    tidalText.textContent = found + ' encontradas · ' + (total - done) + ' restantes';
                    return nextBatch(offset + BATCH);
                });
            }
            return nextBatch(0).then(function() { return results; });
        }

        document.getElementById('tidal-import-submit').addEventListener('click', function() {
            var url = tidalUrl.value.trim();
            if (!url) { tidalStat.textContent = 'Pega un enlace de Tidal.'; return; }
            tidalStat.style.color = '';
            var importer   = usersInfo[currentUserKey] ? usersInfo[currentUserKey].label : '';
            var isTrack    = /tidal\.com\/(?:browse\/)?track\/\d+/i.test(url);
            var isPlaylist = /tidal\.com\/(?:browse\/)?playlist\/[0-9a-f-]{8,}/i.test(url);

            /* Canción suelta */
            if (isTrack && !isPlaylist) {
                tidalBusy(true);
                tidalStat.textContent = 'Buscando en Tidal…';
                fetch('assets/music/api.php?action=tidal-track&url=' + encodeURIComponent(url))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    tidalBusy(false);
                    if (data.error) { tidalStat.textContent = data.error; tidalStat.style.color = '#c00'; return; }
                    editList.push({ videoId: data.videoId, title: data.title, artist: data.artist, duration: data.duration, addedBy: importer });
                    renderList();
                    closeTidalImport();
                })
                .catch(function() { tidalBusy(false); tidalStat.textContent = 'Error de conexión'; tidalStat.style.color = '#c00'; });
                return;
            }

            if (!isPlaylist) { tidalStat.textContent = 'Enlace no reconocido (canción o playlist de Tidal).'; tidalStat.style.color = '#c00'; return; }

            /* Playlist: leer de Tidal y resolver a YouTube por lotes */
            tidalBusy(true);
            tidalStat.textContent = 'Leyendo playlist de Tidal…';
            tidalAbort = new AbortController();
            var signal = tidalAbort.signal;
            fetch('assets/music/api.php?action=tidal-playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url }),
                signal: signal
            })
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(data) {
                if (data.error) throw new Error(data.error);
                var plName = data.name || '';
                var tracks = data.tracks || [];
                if (!tracks.length) throw new Error('La playlist no tiene canciones.');
                tidalStat.textContent = '';
                return tidalResolveBatches(tracks, signal).then(function(results) {
                    if (signal.aborted) return;
                    tidalAbort = null;
                    tidalBusy(false);
                    tidalWrap.style.display = 'none';
                    if (!results.length) { tidalStat.textContent = 'No se encontraron vídeos en YouTube.'; tidalStat.style.color = '#c00'; return; }
                    var nameInput = document.getElementById('pl-name-input');
                    if (nameInput && !nameInput.value.trim() && plName) nameInput.value = plName;
                    results.forEach(function(t) { editList.push(Object.assign({}, t, { addedBy: importer })); });
                    renderList();
                    closeTidalImport();
                });
            })
            .catch(function(e) {
                if (e.name === 'AbortError') return;
                tidalAbort = null;
                tidalBusy(false);
                tidalWrap.style.display = 'none';
                tidalStat.textContent = 'Error: ' + e.message;
                tidalStat.style.color = '#c00';
            });
        });

        /* Colaborador */
        var collabDlg      = document.getElementById('collab-dialog');
        var collabPlName   = document.getElementById('collab-pl-name');
        var collabUserList = document.getElementById('collab-user-list');
        var collabStatus   = document.getElementById('collab-status');

        function openCollabDialog() {
            moreMenu.style.display = 'none';
            if (editingPlIdx < 0) return;
            var pl = allPlaylists[editingPlIdx];
            if (pl.sharedFrom) { alert('Solo el propietario puede gestionar colaboradores.'); return; }
            collabPlName.textContent = pl.name;
            collabStatus.textContent = '';
            collabUserList.innerHTML = '<div style="font-size:11px;color:#808080;">Cargando...</div>';
            collabDlg.style.display = 'block';
            fetch('assets/music/api.php?action=get-users')
            .then(function(r) { return r.json(); })
            .then(function(users) {
                collabUserList.innerHTML = '';
                if (!Array.isArray(users) || !users.length) {
                    collabUserList.innerHTML = '<div class="pl-home-msg" style="text-align:center;line-height:1.45;">Aún no tienes amigos que invitar.<br><span style="opacity:0.75;font-size:11px;">Seguíos entre vosotros para haceros amigos.</span></div>';
                    return;
                }
                users.forEach(function(u) {
                    var already = Array.isArray(pl.collaborators) && pl.collaborators.indexOf(u.key) >= 0;
                    var row = document.createElement('div');
                    row.className = 'collab-user-row';
                    var avatarWrap = document.createElement('div');
                    avatarWrap.className = 'collab-avatar-wrap';
                    avatarWrap.setAttribute('data-marco-user', u.key);   /* marco (melon-marcos.js) */
                    var uInfo = usersInfo[u.key];
                    if (uInfo && uInfo.img) {
                        var avatarImg = document.createElement('img');
                        avatarImg.className = 'collab-avatar-img';
                        avatarImg.src = uInfo.img;
                        avatarImg.alt = u.label;
                        avatarWrap.appendChild(avatarImg);
                    }
                    var nameSpan = document.createElement('span');
                    nameSpan.textContent = u.label;

                    if (already) {
                        var removeBtn = makeBtn('Eliminar', 'collab-invite-btn', function() {
                            collabStatus.textContent = 'Eliminando...';
                            removeBtn.disabled = true;
                            fetch('assets/music/api.php?action=remove-collaborator', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ playlistId: pl.id, collaborator: u.key })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.error) { collabStatus.textContent = data.error; removeBtn.disabled = false; return; }
                                if (pl.collaborators) {
                                    pl.collaborators = pl.collaborators.filter(function(c) { return c !== u.key; });
                                }
                                collabStatus.textContent = u.label + ' eliminado de la playlist.';
                                row.remove();
                            })
                            .catch(function(e) { collabStatus.textContent = 'Error: ' + e.message; removeBtn.disabled = false; });
                        });
                        row.appendChild(avatarWrap);
                        row.appendChild(nameSpan);
                        row.appendChild(removeBtn);
                    } else {
                        var invBtn = makeBtn('Invitar', 'collab-invite-btn', function() {
                            collabStatus.textContent = 'Enviando...';
                            invBtn.disabled = true;
                            fetch('assets/music/api.php?action=invite-collaborator', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ playlistId: pl.id, toUser: u.key })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.error) { collabStatus.textContent = data.error; invBtn.disabled = false; return; }
                                invBtn.textContent = 'Invitado';
                                invBtn.disabled = true;
                                collabStatus.textContent = 'Invitación enviada a ' + u.label;
                            })
                            .catch(function(e) { collabStatus.textContent = 'Error: ' + e.message; invBtn.disabled = false; });
                        });
                        row.appendChild(avatarWrap);
                        row.appendChild(nameSpan);
                        row.appendChild(invBtn);
                    }
                    collabUserList.appendChild(row);
                });
            })
            .catch(function(e) {
                collabUserList.innerHTML = '<div style="font-size:11px;color:#c00;">Error: ' + e.message + '</div>';
            });
        }

        document.getElementById('pl-more-collab').addEventListener('click', openCollabDialog);

        document.getElementById('collab-close').addEventListener('click', function() { collabDlg.style.display = 'none'; });
        document.getElementById('collab-cancel').addEventListener('click', function() { collabDlg.style.display = 'none'; });
    })();

    /* Drag window (Pointer Events para tablet/mouse). */
    (function() {
        var tb = document.getElementById('pl-titlebar');
        var dragging = false, ox, oy, pid = -1;
        tb.style.touchAction = 'none';
        tb.addEventListener('pointerdown', function(e) {
            if (e.target.tagName === 'BUTTON') return;
            dragging = true;
            pid = e.pointerId;
            try { tb.setPointerCapture(pid); } catch (_) {}
            var r = editor.getBoundingClientRect();
            editor.style.left = r.left + 'px'; editor.style.top = r.top + 'px';
            editor.style.transform = 'none';
            ox = e.clientX - r.left; oy = e.clientY - r.top;
        });
        tb.addEventListener('pointermove', function(e) {
            if (!dragging || e.pointerId !== pid) return;
            editor.style.left = (e.clientX - ox) + 'px';
            editor.style.top  = (e.clientY - oy) + 'px';
        });
        function end(e){ if (e && e.pointerId !== pid) return; dragging = false; pid = -1; }
        tb.addEventListener('pointerup', end);
        tb.addEventListener('pointercancel', end);
    })();

    refreshPlaylists = loadPlaylists;

    setInterval(function() {
        if (editor.style.display !== 'block') return;
        fetch('assets/music/api.php?action=get-playlists')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!Array.isArray(data) || data.error) return;
            allPlaylists = data;
            if (plHome.style.display !== 'none') renderHome();
        })
        .catch(function() {});
    }, 15000);
})();

/* ════════════════════════════════════════════════════════════════════
   BÚSQUEDA EN EL MENÚ DE PLAYLISTS (canciones / álbumes / artistas)
   + tamaño fijo redimensionable del menú.
   - Canción  → se reproduce al instante.
   - Álbum    → abre el viewer del álbum.
   - Artista  → página con todos sus álbumes (→ viewer al click).
   ════════════════════════════════════════════════════════════════════ */
(function(){
    var input    = document.getElementById('pl-search-input');
    var results  = document.getElementById('pl-search-results');
    var homeList = document.getElementById('pl-home-list');
    var homeFoot = document.getElementById('pl-home-footer');
    var editBtn  = document.getElementById('btn-edit-playlist');
    if (!input || !results) return;

    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmtDur(sec){ sec=parseInt(sec,10)||0; if(!sec) return ''; var m=Math.floor(sec/60), s=sec%60; return m+':'+(s<10?'0'+s:s); }

    function showPlaylists(){
        results.style.display = 'none';
        results.innerHTML = '';
        if (homeList) homeList.style.display = '';
        if (homeFoot) homeFoot.style.display = '';
    }
    function showResults(){
        if (homeList) homeList.style.display = 'none';
        if (homeFoot) homeFoot.style.display = 'none';
        results.style.display = 'block';
    }

    function playSong(tr){ melonPlaySong(tr); }
    function openAlbum(key, name, image, artist){
        if (typeof openAlbumViewer === 'function') openAlbumViewer(key, name, artist || '', image || '');
        melonRecordRecent('album', key, name || '', image || '', artist || '');
    }

    /* Página de artista: todos sus álbumes. */
    function openArtist(source, artistId, name){
        showResults();
        results.innerHTML = '<div id="pl-artist-back">← Volver a la búsqueda</div>' +
                            '<div class="pl-sr-msg">Cargando álbumes de ' + esc(name) + '…</div>';
        var back = results.querySelector('#pl-artist-back');
        if (back) back.addEventListener('click', function(){ runSearch(true); });
        fetch('assets/music/api.php?action=artist-albums&source=' + encodeURIComponent(source) + '&artistId=' + encodeURIComponent(artistId))
        .then(function(r){ return r.json(); })
        .then(function(d){
            var albums = (d && d.ok && d.albums) ? d.albums : [];
            /* Dedup por nombre+año y solo con imagen. */
            albums = dedupeBy(albums, function(a){ return _n(a.name) + '|' + (a.year||''); }, function(a){ return !!a.image; })
                        .filter(function(a){ return !!a.image; });
            var html = '<div id="pl-artist-back">← Volver a la búsqueda</div>' +
                       '<div class="pl-sr-group-title">' + esc(name) + ' — álbumes</div>';
            if (!albums.length) { html += '<div class="pl-sr-msg">No se encontraron álbumes.</div>'; }
            else {
                html += '<div class="pl-sr-albums-grid">';
                albums.forEach(function(a){
                    html += '<div class="pl-sr-album-card" data-key="'+esc(a.albumKey)+'" data-name="'+esc(a.name)+'" data-image="'+esc(a.image||'')+'">' +
                                '<img src="'+esc(a.image||'')+'" alt="" onerror="this.style.visibility=\'hidden\'">' +
                                '<div class="pl-sr-name">'+esc(a.name)+(a.year?(' ('+esc(a.year)+')'):'')+'</div>' +
                            '</div>';
                });
                html += '</div>';
            }
            results.innerHTML = html;
            var b = results.querySelector('#pl-artist-back');
            if (b) b.addEventListener('click', function(){ runSearch(true); });
            results.querySelectorAll('.pl-sr-album-card').forEach(function(card){
                card.addEventListener('click', function(){ openAlbum(card.dataset.key, card.dataset.name, card.dataset.image, ''); });
                card.addEventListener('contextmenu', function(e){
                    e.preventDefault();
                    if (typeof showSearchAlbumCtxMenu === 'function') {
                        showSearchAlbumCtxMenu(e.clientX, e.clientY, { albumKey: card.dataset.key, name: card.dataset.name, artist: name || '', image: card.dataset.image || '' });
                    }
                });
            });
        })
        .catch(function(){
            results.innerHTML = '<div id="pl-artist-back">← Volver</div><div class="pl-sr-msg">Error cargando álbumes.</div>';
            var b = results.querySelector('#pl-artist-back');
            if (b) b.addEventListener('click', function(){ runSearch(true); });
        });
    }

    /* Normaliza para comparar nombres (dedup). */
    function _n(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim(); }
    /* Quita duplicados por clave; si se pasa hasImg, prefiere la entrada
       que SÍ tiene imagen. */
    function dedupeBy(list, keyFn, hasImg){
        var idx = {}, out = [];
        (list || []).forEach(function(it){
            var k = keyFn(it); if (!k) return;
            if (!(k in idx)) { idx[k] = out.length; out.push(it); }
            else if (hasImg && !hasImg(out[idx[k]]) && hasImg(it)) { out[idx[k]] = it; }
        });
        return out;
    }

    function renderResults(q, songs, albums, artists){
        if (input.value.trim() !== q) return;   /* respuesta obsoleta */
        /* Dedup por nombre (las fuentes iTunes/Deezer/YouTube repiten) y
           garantía de imagen: álbumes y artistas sin imagen se descartan
           (las canciones siempre tienen miniatura de YouTube). */
        var hasImg = function(x){ return !!(x && x.image); };
        songs   = dedupeBy(songs,   function(s){ return _n(s.title) + '|' + _n(s.artist); });
        albums  = dedupeBy(albums,  function(a){ return _n(a.name) + '|' + _n(a.artist); }, hasImg).filter(hasImg);
        artists = dedupeBy(artists, function(a){ return _n(a.name); }, hasImg).filter(hasImg);
        var html = '';
        if (artists && artists.length){
            html += '<div class="pl-sr-group-title">Artistas</div>';
            artists.forEach(function(a){
                html += '<div class="pl-sr-row" data-type="artist" data-source="'+esc(a.source)+'" data-id="'+esc(a.artistId)+'" data-name="'+esc(a.name)+'">' +
                            '<img class="pl-sr-thumb is-artist" src="'+esc(a.image||'')+'" alt="" onerror="this.style.visibility=\'hidden\'">' +
                            '<div class="pl-sr-info"><div class="pl-sr-name">'+esc(a.name)+'</div><div class="pl-sr-sub">Artista</div></div>' +
                        '</div>';
            });
        }
        if (albums && albums.length){
            html += '<div class="pl-sr-group-title">Álbumes</div>';
            albums.forEach(function(a){
                html += '<div class="pl-sr-row" data-type="album" data-key="'+esc(a.albumKey)+'" data-name="'+esc(a.name)+'" data-image="'+esc(a.image||'')+'" data-artist="'+esc(a.artist||'')+'">' +
                            '<img class="pl-sr-thumb" src="'+esc(a.image||'')+'" alt="" onerror="this.style.visibility=\'hidden\'">' +
                            '<div class="pl-sr-info"><div class="pl-sr-name">'+esc(a.name)+'</div><div class="pl-sr-sub">'+esc(a.artist||'Álbum')+'</div></div>' +
                        '</div>';
            });
        }
        if (songs && songs.length){
            html += '<div class="pl-sr-group-title">Canciones</div>';
            songs.forEach(function(s){
                var thumb = 'https://i.ytimg.com/vi/' + encodeURIComponent(s.videoId) + '/default.jpg';
                var sdur = fmtDur(s.duration);
                html += '<div class="pl-sr-row" data-type="song" data-vid="'+esc(s.videoId)+'" data-title="'+esc(s.title)+'" data-artist="'+esc(s.artist)+'" data-dur="'+(parseInt(s.duration,10)||0)+'">' +
                            '<img class="pl-sr-thumb" src="'+thumb+'" alt="" onerror="this.style.visibility=\'hidden\'">' +
                            '<div class="pl-sr-info"><div class="pl-sr-name">'+esc(s.title)+'</div><div class="pl-sr-sub">'+esc(s.artist||'')+'</div></div>' +
                            (sdur ? '<span class="pl-sr-time">'+sdur+'</span>' : '') +
                        '</div>';
            });
        }
        if (!html) html = '<div class="pl-sr-msg">Sin resultados.</div>';
        results.innerHTML = html;
        showResults();
        decorateSearchRatings(results);
        /* Sube las miniaturas de canción de YouTube → carátula del álbum. */
        if (typeof _resolveSongThumb === 'function') {
            results.querySelectorAll('.pl-sr-row[data-type="song"]').forEach(function(row){
                var img = row.querySelector('.pl-sr-thumb');
                if (img) _resolveSongThumb({ videoId: row.dataset.vid, title: row.dataset.title, artist: row.dataset.artist }, img);
            });
        }
    }

    var lastResults = { songs:[], albums:[], artists:[], q:'' };
    function runSearch(useCache){
        var q = input.value.trim();
        if (q.length < 2) { showPlaylists(); return; }
        if (useCache && lastResults.q === q) {
            renderResults(q, lastResults.songs, lastResults.albums, lastResults.artists);
            return;
        }
        showResults();
        results.innerHTML = '<div class="pl-sr-msg">Buscando…</div>';
        var gp = function(action){
            return fetch('assets/music/api.php?action=' + action + '&q=' + encodeURIComponent(q))
                .then(function(r){ return r.json(); }).catch(function(){ return null; });
        };
        Promise.all([ gp('yt-search'), gp('search-albums'), gp('search-artists') ]).then(function(res){
            if (input.value.trim() !== q) return;
            var songs   = (res[0] && res[0].results) || [];
            var albums  = (res[1] && res[1].results) || [];
            var artists = (res[2] && res[2].results) || [];
            lastResults = { songs:songs, albums:albums, artists:artists, q:q };
            renderResults(q, songs, albums, artists);
        });
    }

    var t = null;
    input.addEventListener('input', function(){
        clearTimeout(t);
        if (input.value.trim().length < 2) { showPlaylists(); return; }
        t = setTimeout(function(){ runSearch(false); }, 350);
    });

    results.addEventListener('click', function(e){
        var row = e.target.closest('.pl-sr-row');
        if (!row) return;
        var type = row.dataset.type;
        if (type === 'song') {
            playSong({ videoId: row.dataset.vid, title: row.dataset.title, artist: row.dataset.artist, duration: parseInt(row.dataset.dur,10)||0 });
        } else if (type === 'album') {
            openAlbum(row.dataset.key, row.dataset.name, row.dataset.image, row.dataset.artist);
        } else if (type === 'artist') {
            /* Vista MODERNA del artista (banner + populares + discografía),
               no la rejilla antigua dentro del menú. */
            if (typeof openArtistWindow === 'function') openArtistWindow(row.dataset.name);
        }
    });

    /* Click derecho en un resultado de búsqueda → añadir a playlist / al
       perfil. Canciones: menú de track normal. Álbumes: menú de álbum. */
    results.addEventListener('contextmenu', function(e){
        var row = e.target.closest('.pl-sr-row');
        if (!row) return;
        var type = row.dataset.type;
        if (type === 'song') {
            e.preventDefault();
            if (typeof window.openTrackCtxMenu === 'function') {
                window.openTrackCtxMenu(e, { videoId: row.dataset.vid, title: row.dataset.title, artist: row.dataset.artist, duration: parseInt(row.dataset.dur,10)||0 });
            }
        } else if (type === 'album') {
            e.preventDefault();
            showSearchAlbumCtxMenu(e.clientX, e.clientY, { albumKey: row.dataset.key, name: row.dataset.name, artist: row.dataset.artist, image: row.dataset.image });
        }
    });

    /* Al abrir el menú: limpia la búsqueda (mostrar playlists). */
    if (editBtn) editBtn.addEventListener('click', function(){ input.value=''; showPlaylists(); });

    /* ── Tamaño fijo redimensionable del menú (persistente) ── */
    (function(){
        var win = document.getElementById('playlist-editor');
        if (!win) return;
        var handles = win.querySelectorAll('.av-resize');
        if (!handles.length) return;
        var MIN_W=320, MIN_H=300, KEY='reproductor:pl-editor-size';
        function applySaved(){
            try {
                var s = JSON.parse(localStorage.getItem(KEY)||'null');
                if (s && s.w && s.h) { win.style.width=s.w+'px'; win.style.height=s.h+'px'; }
            } catch(_){}
        }
        applySaved();
        if (editBtn) editBtn.addEventListener('click', applySaved);
        var pid=-1, edge='', sx=0, sy=0, sw=0, sh=0, sl=0, st=0, active=null;
        function down(e){
            e.preventDefault(); e.stopPropagation();
            active=e.currentTarget; edge=active.dataset.edge||''; pid=e.pointerId;
            try{ active.setPointerCapture(pid); }catch(_){}
            var r=win.getBoundingClientRect();
            win.style.left=r.left+'px'; win.style.top=r.top+'px'; win.style.transform='none';
            sx=e.clientX; sy=e.clientY; sw=win.offsetWidth; sh=win.offsetHeight; sl=r.left; st=r.top;
        }
        function move(e){
            if (e.pointerId!==pid) return;
            var dx=e.clientX-sx, dy=e.clientY-sy, nw=sw, nh=sh, nl=sl, nt=st;
            if (edge.indexOf('e')!==-1) nw=Math.max(MIN_W, sw+dx);
            if (edge.indexOf('w')!==-1){ nw=Math.max(MIN_W, sw-dx); nl=sl+(sw-nw); }
            if (edge.indexOf('s')!==-1) nh=Math.max(MIN_H, sh+dy);
            if (edge.indexOf('n')!==-1){ nh=Math.max(MIN_H, sh-dy); nt=st+(sh-nh); }
            win.style.width=nw+'px'; win.style.height=nh+'px'; win.style.left=nl+'px'; win.style.top=nt+'px';
        }
        function up(e){
            if (e.pointerId!==pid) return;
            pid=-1;
            try{ localStorage.setItem(KEY, JSON.stringify({ w:win.offsetWidth, h:win.offsetHeight })); }catch(_){}
        }
        handles.forEach(function(h){
            h.addEventListener('pointerdown', down);
            h.addEventListener('pointermove', move);
            h.addEventListener('pointerup', up);
            h.addEventListener('pointercancel', up);
        });
    })();
})();

function relTime(sentAt) {
    if (!sentAt) return '';
    var diff = Math.floor(Date.now() / 1000) - sentAt;
    if (diff < 60)   return 'ahora mismo';
    if (diff < 3600) return 'hace ' + Math.floor(diff / 60) + ' min';
    return 'hace ' + Math.floor(diff / 3600) + 'h';
}

/* Notificaciones del reproductor (playlist invites / collab) */
(function() {
    function serverPost(id, action) {
        return fetch('assets/music/api.php?action=respond-invite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ inviteId: id, action: action })
        });
    }

    var TITLES = {
        'removed':          'Eliminado de playlist',
        'collab-accepted':  'Colaboración aceptada',
        'collab-rejected':  'Colaboración rechazada',
        'collab-left':      'Colaborador ha salido'
    };

    function messageFor(inv) {
        switch (inv.type) {
            case 'removed':         return inv.fromLabel + ' te ha eliminado de "' + inv.playlistName + '"';
            case 'collab-accepted': return inv.fromLabel + ' ha aceptado tu solicitud de colaboración en "' + inv.playlistName + '"';
            case 'collab-rejected': return inv.fromLabel + ' ha rechazado tu solicitud de colaboración en "' + inv.playlistName + '"';
            case 'collab-left':     return inv.fromLabel + ' ha abandonado tu playlist "' + inv.playlistName + '"';
            default:                return inv.fromLabel + ' te invita a colaborar en "' + inv.playlistName + '"';
        }
    }

    function pushInvite(inv) {
        /* Id del aviso con prefijo: playlist_invites e item_invites
           (perfil.php, 'item-') numeran cada una por su cuenta y, con el
           número a secas, al cerrar una se daba por cerrada la otra con el
           mismo número y no llegaba a salir. */
        var avisoId = 'pl-' + inv.id;
        if (!window.notifSystem || window.notifSystem.isShown(avisoId) || window.notifSystem.isDismissed(avisoId)) return;
        var INFO_TYPES = { 'removed': true, 'collab-accepted': true, 'collab-rejected': true, 'collab-left': true };
        var isAction = !INFO_TYPES[inv.type];
        var senderImg = (typeof usersInfo !== 'undefined' && inv.fromUser && usersInfo[inv.fromUser]) ? usersInfo[inv.fromUser].img : null;

        if (isAction) {
            window.notifSystem.show({
                id:       avisoId,
                type:     'action',
                title:    TITLES[inv.type] || 'Invitación de playlist',
                message:  messageFor(inv),
                senderImage: senderImg,
                senderKey:   inv.fromUser,            /* su marco */
                sentAt:   inv.sentAt,
                onAccept: function() {
                    serverPost(inv.id, 'accept')
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d && d.error) { alert(d.error); return; }
                            if (typeof refreshPlaylists === 'function') refreshPlaylists();
                        })
                        .catch(function() {});
                },
                onReject: function() { serverPost(inv.id, 'reject').catch(function(){}); }
            });
        } else {
            window.notifSystem.show({
                id:      avisoId,
                type:    'info',
                title:   TITLES[inv.type] || 'Notificación',
                message: messageFor(inv),
                sentAt:  inv.sentAt,
                /* Al cerrarla, consumimos la fila en servidor (respond-invite
                   'dismiss' hace DELETE). Sin esto el SSE la reenvía en cada
                   sesión y la notif reaparece para siempre. */
                onDismiss: function() { serverPost(inv.id, 'dismiss').catch(function(){}); }
            });
        }
    }

    /* Invitaciones y avisos de playlists. Antes llegaban por un SSE
       (notifications-stream.php) que ocupaba un proceso PHP y una conexión
       a la BD todo el rato por escritorio. Ahora se piden
       (playlist-invites) al empezar y cuando el timbre del escritorio dice
       que han cambiado ('musica', window.melonTimbre en desktop-base.php);
       si no responde, cada 30 s. pushInvite no repite las ya vistas. Nunca
       dos peticiones a la vez: si se pide mientras va una, se repite al
       acabar. */
    var invPidiendo = false, invOtra = false;
    function cargarInvites() {
        if (invPidiendo) { invOtra = true; return; }
        invPidiendo = true;
        fetch('assets/music/api.php?action=playlist-invites', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var data = d && d.ok && Array.isArray(d.invites) ? d.invites : [];
                data.sort(function(a, b) { return (a.sentAt || 0) - (b.sentAt || 0); });
                data.forEach(pushInvite);
            })
            .catch(function() {})
            .then(function() {
                invPidiendo = false;
                if (invOtra) { invOtra = false; cargarInvites(); }
            });
    }

    cargarInvites();
    if (window.melonTimbre) window.melonTimbre.on('musica', cargarInvites, 30000);
    else setInterval(cargarInvites, 30000);
})();

/* ════════════════════════════════════════════════════════════════
   LETRAS (LRCLIB) — botón + panel + sincronización con ytPlayer.
   Estado:
     LYR_CURRENT_VID  → videoId de la canción cuya letra está cargada.
     LYR_LINES        → array { time:number, text:string } si synced.
     LYR_PLAIN        → string plana si no hay synced.
     LYR_LAST_ACTIVE  → índice del último .lyrics-line activo (evita
                        re-querys cada tick si no cambió).
     LYR_OPEN         → true cuando la ventana está visible.
     LYR_SYNC_TIMER   → setInterval que actualiza el highlight.
   ════════════════════════════════════════════════════════════════ */
(function lyricsModule() {
    let LYR_CURRENT_VID = null;
    let LYR_LINES       = null;
    let LYR_PLAIN       = null;
    let LYR_LAST_ACTIVE = -1;
    let LYR_OPEN        = false;
    let LYR_SYNC_TIMER  = null;

    const win   = document.getElementById('lyrics-window');
    const btn   = document.getElementById('btn-lyrics');
    const close = document.getElementById('lyrics-close');
    const linesEl  = document.getElementById('lyrics-lines');
    const emptyEl  = document.getElementById('lyrics-empty');
    const trackEl  = document.getElementById('lyrics-track');
    const statusEl = document.getElementById('lyrics-status');
    const scrollEl = document.getElementById('lyrics-scroll');

    /* Drag + resize vía WindowManager del desktop. Si aún no existe
       (reproductor partial cargado antes que WindowManager) reintenta
       hasta que esté listo. setup(id, false) → drag por title-bar +
       resize por las 8 esquinas/lados. */
    var __lyricsWmTries = 0;
    function setupLyricsWindowManager() {
        if (window.WindowManager && typeof window.WindowManager.setup === 'function') {
            window.WindowManager.setup('lyrics-window', false);
            return;
        }
        if (__lyricsWmTries++ < 30) setTimeout(setupLyricsWindowManager, 200);
    }
    setupLyricsWindowManager();

    /* Minimize / Maximize / Close: wire UI controls.
         - Minimize → taskbarManager.minimize (oculta + queda en la taskbar).
         - Maximize → toggle .win-maximized (full-viewport CSS de base.css).
         - Close   → ocultar + unregister + parar sync. */
    var minBtn = document.getElementById('lyrics-min');
    var maxBtn = document.getElementById('lyrics-max');
    if (minBtn) minBtn.addEventListener('click', function() {
        if (window.taskbarManager) window.taskbarManager.minimize('lyrics-window');
    });
    if (maxBtn) maxBtn.addEventListener('click', function() {
        if (win.classList.contains('win-maximized')) {
            win.classList.remove('win-maximized');
            maxBtn.setAttribute('aria-label', 'Maximize');
        } else {
            win.classList.add('win-maximized');
            maxBtn.setAttribute('aria-label', 'Restore');
        }
    });

    function open() {
        win.style.display = 'flex';
        LYR_OPEN = true;
        /* Registra en la taskbar (o restaura si estaba minimizada). */
        if (window.taskbarManager) {
            if (window.taskbarManager.isRegistered('lyrics-window')) {
                window.taskbarManager.restore('lyrics-window');
            } else {
                window.taskbarManager.register('lyrics-window', 'Letra', '🎤', 'flex');
            }
        }
        fetchForCurrent();
        startSync();
    }
    function close_() {
        win.style.display = 'none';
        LYR_OPEN = false;
        if (window.taskbarManager && window.taskbarManager.isRegistered('lyrics-window')) {
            window.taskbarManager.unregister('lyrics-window');
        }
        stopSync();
    }
    if (btn)   btn.addEventListener('click', open);
    if (close) close.addEventListener('click', close_);

    /* Parser LRC: cada línea "[mm:ss.xx]texto". Soporta multi-timestamp
       por línea ("[01:00.50][02:30.20]coro"). */
    function parseLRC(lrc) {
        if (!lrc) return [];
        const lines = [];
        lrc.split(/\r?\n/).forEach(raw => {
            const stamps = [];
            let rest = raw;
            const re = /^\s*\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/;
            let m;
            while ((m = re.exec(rest))) {
                const min = +m[1], sec = +m[2], ms = +(m[3] || 0);
                stamps.push(min * 60 + sec + ms / Math.pow(10, (m[3] || '').length));
                rest = rest.slice(m[0].length);
            }
            const text = rest.trim();
            if (stamps.length && text) {
                stamps.forEach(t => lines.push({ time: t, text }));
            }
        });
        lines.sort((a, b) => a.time - b.time);
        return lines;
    }

    function getCurrentTrack() {
        if (typeof playlist !== 'undefined' && playlist.length && typeof currentTrack === 'number') {
            return playlist[currentTrack];
        }
        return null;
    }

    function setStatus(s) { if (statusEl) statusEl.textContent = s || ''; }

    /* Controller del fetch activo del reproductor pequeño — se aborta
       al cambiar de canción para no sobreescribir el estado nuevo con
       respuestas viejas. */
    let LYR_SMALL_FETCH_CTRL = null;

    async function fetchForCurrent() {
        const tr = getCurrentTrack();
        if (!tr || !tr.videoId) {
            renderEmpty('No hay canción en reproducción.');
            return;
        }
        /* Si ya tenemos la letra de esta canción, no re-fetchear. */
        if (tr.videoId === LYR_CURRENT_VID && (LYR_LINES || LYR_PLAIN)) return;
        /* Cancela el fetch previo si sigue en vuelo — su respuesta
           sería para otra canción y no debe pisar el estado nuevo. */
        if (LYR_SMALL_FETCH_CTRL) {
            try { LYR_SMALL_FETCH_CTRL.abort(); } catch (_) {}
        }
        const ctrl = new AbortController();
        LYR_SMALL_FETCH_CTRL = ctrl;
        LYR_CURRENT_VID = tr.videoId;
        LYR_LINES = null;
        LYR_PLAIN = null;
        LYR_LAST_ACTIVE = -1;
        trackEl.textContent = (tr.title || '') + (tr.artist ? ' — ' + tr.artist : '');
        renderEmpty('Buscando letra…');
        setStatus('');
        let dur = 0;
        try { dur = (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getDuration) ? Math.floor(ytPlayer.getDuration() || 0) : 0; } catch (_) {}
        const qs = new URLSearchParams({
            title: tr.title || '',
            artist: tr.artist || '',
            duration: String(dur),
        });
        try {
            const r = await fetch('assets/music/api.php?action=get-lyrics&' + qs.toString(), {
                credentials: 'same-origin',
                signal: ctrl.signal,
            });
            const d = await r.json();
            /* GUARD STALE — antes de renderizar NADA. Si el track cambió
               mientras esperábamos, este response es para una canción
               vieja: no debe pisar el "Buscando letra…" del track nuevo
               con un "🥲 No se encontró". */
            if (ctrl.signal.aborted) return;
            if (LYR_CURRENT_VID !== tr.videoId) return;
            if (!d || !d.ok || !d.found) {
                renderEmpty('🥲 No se encontró letra para esta canción.');
                return;
            }
            if (d.synced) {
                LYR_LINES = parseLRC(d.synced);
                if (LYR_LINES.length) renderSynced();
                else { LYR_PLAIN = d.plain; renderPlain(); }
            } else if (d.plain) {
                LYR_PLAIN = d.plain;
                renderPlain();
            } else {
                renderEmpty('🥲 No se encontró letra para esta canción.');
            }
            if (d.matched) {
                setStatus(LYR_LINES ? '⏱ sincronizada' : 'sin sync');
            }
        } catch (e) {
            /* Abort no es error, es señal de track-change. */
            if (e && e.name === 'AbortError') return;
            /* Solo render error si seguimos en el track que pidió. */
            if (LYR_CURRENT_VID === tr.videoId) renderEmpty('Error de red al buscar la letra.');
        } finally {
            if (LYR_SMALL_FETCH_CTRL === ctrl) LYR_SMALL_FETCH_CTRL = null;
        }
    }

    function renderEmpty(msg) {
        emptyEl.style.display = 'block';
        emptyEl.textContent = msg;
        linesEl.innerHTML = '';
    }
    function renderSynced() {
        emptyEl.style.display = 'none';
        linesEl.innerHTML = LYR_LINES.map((ln, i) =>
            `<div class="lyrics-line" data-i="${i}">${escapeHtmlSimple(ln.text)}</div>`
        ).join('');
    }
    function renderPlain() {
        emptyEl.style.display = 'none';
        linesEl.innerHTML = '<div style="text-align:center;">' +
            escapeHtmlSimple(LYR_PLAIN).replace(/\n/g, '<br>') + '</div>';
    }
    function escapeHtmlSimple(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* SYNC: cada 250ms busca la línea actual según ytPlayer.currentTime
       y aplica .active. Auto-scroll para mantenerla a la vista. */
    function tick() {
        if (!LYR_OPEN || !LYR_LINES || !LYR_LINES.length) return;
        let t = 0;
        try {
            if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getCurrentTime) {
                t = ytPlayer.getCurrentTime() || 0;
            }
        } catch (_) {}
        /* Búsqueda binaria de la última línea cuyo time <= t. */
        let lo = 0, hi = LYR_LINES.length - 1, idx = -1;
        while (lo <= hi) {
            const mid = (lo + hi) >> 1;
            if (LYR_LINES[mid].time <= t) { idx = mid; lo = mid + 1; }
            else hi = mid - 1;
        }
        if (idx === LYR_LAST_ACTIVE) return;
        LYR_LAST_ACTIVE = idx;
        const all = linesEl.querySelectorAll('.lyrics-line');
        all.forEach((el, i) => {
            el.classList.remove('active', 'past');
            if (i < idx) el.classList.add('past');
            if (i === idx) el.classList.add('active');
        });
        const activeEl = all[idx];
        if (activeEl) {
            const top = activeEl.offsetTop - scrollEl.clientHeight / 2 + activeEl.clientHeight / 2;
            scrollEl.scrollTo({ top, behavior: 'smooth' });
        }
    }
    function startSync() { stopSync(); LYR_SYNC_TIMER = setInterval(tick, 250); }
    function stopSync()  { if (LYR_SYNC_TIMER) { clearInterval(LYR_SYNC_TIMER); LYR_SYNC_TIMER = null; } }

    /* Hook al cambio de track: si el panel está abierto, re-fetch. */
    const _origUpdateTrackUI = (typeof updateTrackUI === 'function') ? updateTrackUI : null;
    if (_origUpdateTrackUI) {
        window.updateTrackUI = function(idx) {
            _origUpdateTrackUI(idx);
            if (LYR_OPEN) fetchForCurrent();
        };
    }

    /* Expone close para que el módulo del fullscreen pueda cerrar la
       ventana de letras al maximizar (requisito UX del usuario). */
    window.__lyricsClose = close_;
})();

/* ════════════════════════════════════════════════════════════════
   FULLSCREEN PLAYER — minimize / maximize / sync / lyrics overlay.
   ════════════════════════════════════════════════════════════════ */
(function fullscreenPlayerModule() {
    const root       = document.getElementById('player-full');
    const win        = document.getElementById('music-player');
    if (!root || !win) return;

    const btnMin     = document.getElementById('player-minimize');
    const btnMax     = document.getElementById('player-maximize');
    const btnRestore = document.getElementById('pf-restore');
    const btnCloseX  = document.getElementById('pf-close-x');

    /* Refs UI fullscreen. */
    const vinyl    = document.getElementById('pf-vinyl');
    const vinylLbl = document.getElementById('pf-vinyl-label');
    const titleEl  = document.getElementById('pf-title');
    const artistEl = document.getElementById('pf-artist');
    const plNameEl = document.getElementById('pf-pl-name');
    const progFill = document.getElementById('pf-progress-fill');
    const progBar  = document.getElementById('pf-progress-track');
    const tCurEl   = document.getElementById('pf-time-cur');
    const tTotEl   = document.getElementById('pf-time-tot');
    const btnPrev2 = document.getElementById('pf-prev');
    const btnNext2 = document.getElementById('pf-next');
    const btnTgl2  = document.getElementById('pf-toggle');
    const btnSh2   = document.getElementById('pf-shuffle');
    const btnLyr   = document.getElementById('pf-lyrics');

    /* Helpers de formato y sync. */
    function fmtTime(s) {
        if (!s || isNaN(s)) return '0:00';
        s = Math.floor(s);
        const m = Math.floor(s / 60);
        const r = s % 60;
        return m + ':' + (r < 10 ? '0' + r : r);
    }
    /* Refs adicionales del nuevo layout. */
    const bgCover = document.getElementById('pf-bg-cover');
    const reelL   = document.getElementById('pf-reel-l');
    const reelR   = document.getElementById('pf-reel-r');

    function syncFromOriginalPlayer() {
        try {
            if (typeof playlist !== 'undefined' && playlist.length && typeof currentTrack === 'number') {
                const tr = playlist[currentTrack];
                if (tr) {
                    titleEl.textContent  = tr.title  || '—';
                    artistEl.textContent = tr.artist || '—';
                    if (tr.videoId) {
                        const cover = 'https://img.youtube.com/vi/' + tr.videoId + '/mqdefault.jpg';
                        vinylLbl.style.backgroundImage = 'url("' + cover + '")';
                        vinylLbl.classList.remove('empty');
                        vinylLbl.textContent = '';
                        /* Fondo difuminado con el cover de la canción. */
                        if (bgCover) bgCover.style.backgroundImage = 'url("' + cover + '")';
                    } else {
                        vinylLbl.classList.add('empty');
                        vinylLbl.textContent = '♪';
                        vinylLbl.style.backgroundImage = '';
                        if (bgCover) bgCover.style.backgroundImage = '';
                    }
                }
            }
            const plName = document.getElementById('player-pl-name');
            if (plName) plNameEl.textContent = plName.textContent;

            if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getDuration) {
                const dur = ytPlayer.getDuration() || 0;
                const cur = ytPlayer.getCurrentTime() || 0;
                if (dur > 0) progFill.style.width = ((cur / dur) * 100) + '%';
                tCurEl.textContent = fmtTime(cur);
                tTotEl.textContent = fmtTime(dur);
                const playing = ytPlayer.getPlayerState && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING;
                btnTgl2.classList.toggle('pf-playing', !!playing);
                vinyl.classList.toggle('pf-spinning', !!playing);
                /* Reels girando en sync con el play state. */
                if (reelL) reelL.style.animationPlayState = playing ? 'running' : 'paused';
                if (reelR) reelR.style.animationPlayState = playing ? 'running' : 'paused';
            }
            /* Sync shuffle visual desde el botón original. */
            if (typeof autoplayRandom !== 'undefined') {
                btnSh2.classList.toggle('is-on', !!autoplayRandom);
                btnSh2.setAttribute('aria-pressed', autoplayRandom ? 'true' : 'false');
            }
            /* Sync volumen (el slider y el icono) desde el slider
               original — atrapa cambios externos. */
            if (typeof window.__pfSyncVolume === 'function') window.__pfSyncVolume();
        } catch(_){}
    }

    let syncTimer = null;
    let isOpen = false;

    function openFullscreen() {
        if (isOpen) return;
        /* Cierra la ventana de letras flotante si estaba abierta —
           en el fullscreen las letras se muestran como overlay. */
        if (typeof window.__lyricsClose === 'function') {
            try { window.__lyricsClose(); } catch(_){}
        }
        root.classList.add('pf-active');
        isOpen = true;
        syncFromOriginalPlayer();
        if (syncTimer) clearInterval(syncTimer);
        syncTimer = setInterval(syncFromOriginalPlayer, 500);
    }
    function closeFullscreen() {
        if (!isOpen) return;
        /* Apaga letras COMPLETAMENTE antes de salir: pfCloseLyrics se
           encarga de cancelar el fetch en vuelo, limpiar el DOM del
           lyrStage, resetear todos los flags de estado (counters,
           repeatGroup, multiState…) y — clave — quitar la clase .is-on
           del botón micrófono para matar su glow pulsante.
           pfCloseLyrics() en sí ya chequea !pfLyricsOpen, pero el
           guard aquí evita trabajo innecesario. */
        if (pfLyricsOpen) pfCloseLyrics();
        root.classList.remove('pf-active');
        isOpen = false;
        if (syncTimer) { clearInterval(syncTimer); syncTimer = null; }
    }
    /* Exposed globally para que el ctxMenu pueda cerrarlo desde otro
       IIFE (showTrackCtxMenu vive en otro scope). Usar la función real
       en vez de simular click() en el botón restore evita un timing
       weird donde el listener no se dispara y el siguiente openFullscreen
       cree que sigue abierto (necesitando 2 clicks en max). */
    window.__closeFullscreenPlayer = closeFullscreen;

    /* Minimize: usa taskbarManager para llevar el reproductor a la
       taskbar — igual que cualquier otra ventana del desktop. */
    if (btnMin) btnMin.addEventListener('click', function() {
        if (window.taskbarManager) {
            if (!window.taskbarManager.isRegistered('music-player')) {
                window.taskbarManager.register('music-player', 'Reproductor', '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'block');
            }
            window.taskbarManager.minimize('music-player');
        } else {
            win.style.display = 'none';
        }
    });

    /* Maximize: abre fullscreen overlay. */
    if (btnMax) btnMax.addEventListener('click', openFullscreen);
    if (btnRestore) btnRestore.addEventListener('click', closeFullscreen);
    if (btnCloseX)  btnCloseX.addEventListener('click', closeFullscreen);

    /* Forward de controles al reproductor original — reusa toda la
       lógica de play/pause/prev/next/shuffle sin duplicar. */
    if (btnPrev2) btnPrev2.addEventListener('click', () => document.getElementById('btn-prev').click());
    if (btnNext2) btnNext2.addEventListener('click', () => document.getElementById('btn-next').click());
    if (btnTgl2)  btnTgl2.addEventListener('click',  () => document.getElementById('btn-play').click());
    if (btnSh2)   btnSh2.addEventListener('click',   () => document.getElementById('btn-shuffle').click());

    /* Seek en la progress bar. */
    if (progBar) progBar.addEventListener('click', function(e) {
        if (typeof ytPlayer === 'undefined' || !ytPlayer || !ytPlayer.getDuration) return;
        const rect = progBar.getBoundingClientRect();
        const pct = (e.clientX - rect.left) / rect.width;
        ytPlayer.seekTo(pct * ytPlayer.getDuration(), true);
        syncFromOriginalPlayer();
    });

    /* ── Volumen — proxy al slider del player original. ──
       Forward del input → setea el value del #player-volume y dispara
       su event 'input' para reusar toda la lógica de persistencia +
       icono altavoz + setVolume del ytPlayer. */
    const volSlider2 = document.getElementById('pf-volume');
    const volIcon2   = document.getElementById('pf-vol-icon');
    function updateVolIcon(v) {
        if (!volIcon2) return;
        if (v === 0) volIcon2.textContent = '◄✕';
        else if (v < 50) volIcon2.textContent = '◄)';
        else volIcon2.textContent = '◄))';
    }
    if (volSlider2) {
        volSlider2.addEventListener('input', function() {
            const v = parseInt(this.value, 10);
            const orig = document.getElementById('player-volume');
            if (orig) {
                orig.value = v;
                orig.dispatchEvent(new Event('input', { bubbles: true }));
            } else if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.setVolume) {
                ytPlayer.setVolume(v);
            }
            updateVolIcon(v);
        });
    }
    /* Sync inicial + cada vez que se abra el fullscreen: lee el value
       del slider original y refleja en el del fullscreen + icono.
       Expuesto en window.__pfSyncVolume → syncFromOriginalPlayer lo
       llama dentro de su tick para mantener consistencia con cambios
       externos (otra pestaña, restore desde state). */
    window.__pfSyncVolume = function() {
        const orig = document.getElementById('player-volume');
        if (orig && volSlider2) {
            volSlider2.value = orig.value;
            updateVolIcon(parseInt(orig.value, 10));
        }
    };
    window.__pfSyncVolume();

    /* Logger del módulo: envía cada paso a logs/lyrics-debug.log
       en el servidor + console.log local. NO bloquea (fire-and-forget). */
    function pfLog(msg) {
        try { console.log('[pf-lyrics]', msg); } catch(_){}
        try {
            fetch('assets/music/api.php?action=client-log', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ msg: '[pf-lyrics] ' + msg }),
                credentials: 'same-origin',
                keepalive: true,
            });
        } catch(_){}
    }

    /* ════════════════════════════════════════════════════════════
       LYRIC VIDEO — solo la línea ACTUAL flota sobre el reproductor.
       - Sin fondo, sin blur (integración directa con el player).
       - Cada char tiene su propio tremble (delay + duration random).
       - Al cambiar de línea: la anterior sube + fade-out, la nueva
         entra desde abajo + fade-in.
       - Si la canción está en gap instrumental (>7s entre líneas y
         ya pasaron 3.5s de la línea actual): no se muestra nada.
       - Solo letras SINCRONIZADAS (LRC). Si solo hay plain, no se
         muestra nada (no sabemos qué está sonando).
       ════════════════════════════════════════════════════════════ */
    let pfLyrLines = null;
    let pfLyrVid   = null;
    let pfLyrCurIdx = -1;     /* índice de línea actualmente mostrada (-1 = ninguna) */
    let pfLyricsOpen = false;
    let pfLyrTimer = null;
    const overlay  = document.getElementById('pf-lyrics-overlay');
    const lyrStage = document.getElementById('pf-lyr-stage');

    function escH(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function pfParseLRC(lrc) {
        const lines = [];
        if (!lrc) return lines;
        lrc.split(/\r?\n/).forEach(raw => {
            const stamps = []; let rest = raw;
            const re = /^\s*\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/;
            let m;
            while ((m = re.exec(rest))) {
                const min = +m[1], sec = +m[2], frac = m[3] ? +m[3] / Math.pow(10, m[3].length) : 0;
                stamps.push(min * 60 + sec + frac);
                rest = rest.slice(m[0].length);
            }
            const text = rest.trim();
            if (stamps.length && text) stamps.forEach(t => lines.push({ time: t, text }));
        });
        lines.sort((a, b) => a.time - b.time);
        return lines;
    }
    function pfCurTrack() {
        if (typeof playlist !== 'undefined' && playlist.length && typeof currentTrack === 'number') {
            return playlist[currentTrack] || null;
        }
        return null;
    }

    /* Construye el HTML de una línea agrupando los chars POR PALABRA.
       Cada palabra es un <span class="pf-lyr-word"> con white-space:
       nowrap → la palabra es indivisible: o cabe entera en la línea
       actual, o salta entera a la siguiente. Dentro de cada palabra,
       cada char es un <span class="pf-lyr-char"> con tremble propio. */
    function pfRenderLine(text) {
        if (!text) return '';
        const out = ['<span class="pf-lyr-line-content">'];
        /* Split por whitespace pero MANTENIENDO los espacios como tokens
           (para preservar separaciones múltiples). */
        const tokens = text.split(/(\s+)/);
        for (const tok of tokens) {
            if (tok === '') continue;
            if (/^\s+$/.test(tok)) {
                /* Solo whitespace → &nbsp; por cada espacio para que no
                   se colapsen. */
                out.push('&nbsp;');
                continue;
            }
            /* Palabra → wrappear todo el bloque para que sea indivisible. */
            out.push('<span class="pf-lyr-word">');
            for (let i = 0; i < tok.length; i++) {
                const c = tok[i];
                const delay = (Math.random() * 1.8).toFixed(2);
                const dur   = (1.4 + Math.random() * 1.4).toFixed(2);
                out.push('<span class="pf-lyr-char" style="--delay:' + delay + 's;--dur:' + dur + 's">' + escH(c) + '</span>');
            }
            out.push('</span>');
        }
        out.push('</span>');
        return out.join('');
    }

    /* Muestra una línea nueva o limpia el stage si text es null.
       Aprovecha CSS animations forwards: insertar el elemento dispara
       pfLyrSlideIn automáticamente; añadir .leaving dispara
       pfLyrSlideOut. Sin RAF, sin transitions class-swap-fragile. */
    /* Modos experimentales single-line + multi-line. */
    const PF_EXP_MODES = [
        'vert-left', 'tilt-r', 'corner-tl', 'top', 'mega',
        'diagonal', 'vert-right', 'tilt-l', 'corner-br', 'bottom',
    ];
    /* Multi-modes con totals reducidos para que la animación no se
       eternice. Cada one tiene un cap absoluto de tiempo (PF_MULTI_MAX_MS)
       que fuerza el final si los versos son lentos. */
    const PF_MULTI_MODES = [
        { mode: 'fill-vert',  total: 5, holdMs: 1100 },
        { mode: 'fill-stack', total: 4, holdMs: 900  },
    ];
    /* Cap absoluto de tiempo total para multi-modes y fast-stack. Si
       los versos son lentos y no completamos los slots a tiempo,
       cerramos el grupo aquí (5.5s + hold ≈ 6.5s total). */
    const PF_MULTI_MAX_MS      = 5500;
    const PF_FAST_STACK_MAX_MS = 5500;
    const PF_FAST_LINE_THRESHOLD = 2.0;
    const PF_FAST_STACK_TOTAL = 7;

    let pfLineCounter = 0;
    let pfMultiState = null;        /* {mode, total, slot, holdMs, startedAt} */
    let pfFastStackCounter = 0;
    let pfFastStackStartedAt = 0;   /* ms timestamp del primer fast-line en curso */
    /* Cache de estilo por línea — key = texto normalizado, value =
       descriptor del estilo elegido en la primera aparición. Si la
       misma línea ("Everybody, everybody, everybody living now")
       reaparece más adelante en la canción, reusamos su estilo para
       que el coro tenga consistencia visual.
       Tipos guardados:
         { kind: 'repeat-inline', layout: 'h'|'v' }   → fast-repeat inline
         { kind: 'repeat-scatter' }                   → scatter normal
         { kind: 'normal', mode: '<modeName>' }       → modo single-line
       Multi-mode (fill-vert/fill-stack/fast-stack) NO se cachean —
       dependen del estado de grupo y romperían el flow. */
    let pfLineStyleCache = {};
    function pfLineKey(text) {
        return text ? text.toLowerCase().replace(/\s+/g, ' ').trim() : '';
    }

    /* Devuelve la duración de la línea actual en segundos (tiempo
       hasta el siguiente verso). Si es la última, devuelve un valor
       alto (treated as long). */
    function pfGetCurLineDuration() {
        if (!pfLyrLines || pfLyrCurIdx < 0 || pfLyrCurIdx >= pfLyrLines.length) return 999;
        const cur = pfLyrLines[pfLyrCurIdx];
        const next = pfLyrLines[pfLyrCurIdx + 1];
        if (!next) return 999;
        return next.time - cur.time;
    }

    function pfPickMode(opts) {
        opts = opts || {};
        const avoidStack = !!opts.avoidStacking;
        const avoidVert  = !!opts.avoidVertical;
        let progress = 0;
        try {
            if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getDuration) {
                const d = ytPlayer.getDuration() || 0;
                const t = ytPlayer.getCurrentTime() || 0;
                if (d > 0) progress = t / d;
            }
        } catch(_){}
        /* Intro/outro → center + blur. Reset estados acumulativos. */
        if (progress < 0.25 || progress > 0.92) {
            pfMultiState = null;
            pfFastStackCounter = 0;
            pfFastStackStartedAt = 0;
            return { mode: 'center', blur: true };
        }
        /* Si esta línea no debe apilarse pero hay un multi-mode en
           curso → lo abortamos (la siguiente line will be normal). */
        if (avoidStack && pfMultiState) {
            pfMultiState = null;
        }
        const now = Date.now();
        /* Si hay un multi-mode en curso: continúa hasta completar o
           hasta el cap absoluto de tiempo (lo que ocurra primero). */
        if (pfMultiState) {
            const slot = pfMultiState.slot;
            const total = pfMultiState.total;
            const elapsed = now - pfMultiState.startedAt;
            const isLast = (slot + 1) >= total || elapsed >= PF_MULTI_MAX_MS;
            const result = {
                mode: pfMultiState.mode,
                blur: false,
                slot: slot,
                total: total,
                isLast: isLast,
                holdMs: pfMultiState.holdMs,
            };
            if (isLast) pfMultiState = null;
            else pfMultiState.slot++;
            return result;
        }
        /* ── Detección de línea rápida ──
           Si la línea actual dura menos del threshold → fast-stack.
           El stack tiene un cap de tiempo: si lleva >5.5s acumulando,
           lo forzamos a cerrar (treat as long line) para que no se
           eternice durante un verso de rap largo. */
        const lineDur = pfGetCurLineDuration();
        if (lineDur < PF_FAST_LINE_THRESHOLD && !avoidStack) {
            if (pfFastStackStartedAt === 0) pfFastStackStartedAt = now;
            const elapsedFast = now - pfFastStackStartedAt;
            if (elapsedFast < PF_FAST_STACK_MAX_MS) {
                const slot = pfFastStackCounter % PF_FAST_STACK_TOTAL;
                pfFastStackCounter++;
                return {
                    mode: 'fast-stack',
                    blur: false,
                    slot: slot,
                    total: PF_FAST_STACK_TOTAL,
                    isFastStack: true,
                };
            }
            /* Cap superado → forzar reset, esta línea va por modo normal. */
            pfFastStackCounter = 0;
            pfFastStackStartedAt = 0;
        } else {
            /* Línea larga o avoidStack → reset del fast-stack. */
            pfFastStackCounter = 0;
            pfFastStackStartedAt = 0;
        }
        /* Dado random: 22% chance de arrancar un multi-mode después
           del 4º verso. avoidStack excluye TODOS los multi-modes;
           avoidVert excluye fill-vert (también es apilado vertical
           de caracteres como vert-left/right). */
        if (pfLineCounter > 3 && Math.random() < 0.22 && !avoidStack) {
            let candidates = PF_MULTI_MODES;
            if (avoidVert) {
                candidates = candidates.filter(c => c.mode !== 'fill-vert');
            }
            if (candidates.length > 0) {
                const cfg = candidates[Math.floor(Math.random() * candidates.length)];
                pfMultiState = {
                    mode: cfg.mode, total: cfg.total, slot: 1,
                    holdMs: cfg.holdMs, startedAt: now,
                };
                return {
                    mode: cfg.mode,
                    blur: false,
                    slot: 0,
                    total: cfg.total,
                    isLast: false,
                    holdMs: cfg.holdMs,
                };
            }
        }
        /* Single-line: rotamos modos experimentales. Si avoidVert
           filtramos los modos verticales (vert-left/right) que no
           caben bien para verses largos. */
        let modes = PF_EXP_MODES;
        if (avoidVert) {
            modes = modes.filter(m => m !== 'vert-left' && m !== 'vert-right');
        }
        const idx = pfLineCounter % modes.length;
        return { mode: modes[idx], blur: false };
    }

    /* Extrae las partes entre paréntesis del verso. Soporta paréntesis
       ASCII y CJK fullwidth (）). Devuelve {main, asides}:
         - main: texto SIN las partes paréntesis, trim.
         - asides: array de strings con cada contenido de paréntesis.
       Si todo el verso son paréntesis → main será '' y asides tendrá
       el contenido. */
    function pfExtractParens(text) {
        if (!text) return { main: '', asides: [] };
        const re = /[(（]([^)）]+)[)）]/g;
        const asides = [];
        let m;
        while ((m = re.exec(text)) !== null) {
            const t = m[1].trim();
            if (t) asides.push(t);
        }
        const main = text.replace(re, '').replace(/\s+/g, ' ').trim();
        return { main, asides };
    }

    /* Contador de asides (paréntesis) para rotar la esquina en la que
       se muestran (tl → tr → bl → br). Reset al cerrar lyrics. */
    const PF_ASIDE_CORNERS = ['tl', 'tr', 'bl', 'br'];
    let pfAsideCounter = 0;

    /* State activo de un grupo de líneas consecutivas idénticas mostradas
       como scatter. {startIdx, endIdx, text, count}. Mientras pfLyrCurIdx
       esté en este rango, NO re-renderizamos — el scatter ya está visible. */
    let pfRepeatLineGroup = null;

    /* Detecta si la línea ACTUAL (pfLyrCurIdx) inicia un grupo de
       líneas consecutivas idénticas. Devuelve {startIdx, endIdx, text,
       count} si hay 3+ repeticiones consecutivas, o null. */
    function pfDetectMultiLineRepeat() {
        if (!pfLyrLines || pfLyrCurIdx < 0 || pfLyrCurIdx >= pfLyrLines.length) return null;
        const target = _pfNorm(pfLyrLines[pfLyrCurIdx].text);
        if (!target) return null;
        let endIdx = pfLyrCurIdx;
        while (endIdx + 1 < pfLyrLines.length && _pfNorm(pfLyrLines[endIdx + 1].text) === target) {
            endIdx++;
        }
        const count = endIdx - pfLyrCurIdx + 1;
        if (count >= 3) {
            return { startIdx: pfLyrCurIdx, endIdx, text: pfLyrLines[pfLyrCurIdx].text, count };
        }
        return null;
    }
    /* Cuánto tiempo permanece visible un aside antes de auto-cerrarse. */
    const PF_ASIDE_HOLD_MS = 4500;

    /* Renderiza una palabra/frase entre paréntesis como overlay pequeño
       en una esquina. NO limpia las líneas anteriores. Auto-fade tras
       PF_ASIDE_HOLD_MS para no quedar fantasma indefinidamente. */
    function pfRenderAsideCorner(text) {
        if (!lyrStage || !text) return;
        const corner = PF_ASIDE_CORNERS[pfAsideCounter++ % PF_ASIDE_CORNERS.length];
        const wrap = document.createElement('div');
        wrap.className = 'pf-lyr-line-wrap pf-lyr-mode-aside pf-lyr-aside-' + corner;
        wrap.innerHTML = pfRenderLine(text);
        lyrStage.appendChild(wrap);
        setTimeout(() => {
            if (!wrap.parentNode || wrap.classList.contains('leaving')) return;
            wrap.style.animationDelay = '0s';
            wrap.classList.add('leaving');
            setTimeout(() => { if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 700);
        }, PF_ASIDE_HOLD_MS);
    }

    /* Helper de normalización para comparaciones case/punctuation-insensitive. */
    function _pfNorm(s) {
        return (s || '').trim().toLowerCase().replace(/[.,!?;:¡¿]+$/, '');
    }

    /* Detecta si una línea consiste en UNA ÚNICA palabra enfática:
         - Palabra de 2+ chars seguida de 1-3 signos de exclamación
           (FIRE!, GO!, wow!!!).
         - O ALL CAPS de 4+ chars (FIRE, BURN, WHATEVER).
       Solo aplica cuando la línea trim+limpia-puntuación es UN solo
       token (sin espacios ni más palabras). Devuelve la palabra
       enfática o null. */
    function pfDetectSingleEmphasis(text) {
        if (!text) return null;
        /* Trim outer punctuation (excepto !) y whitespace. */
        const trimmed = text.trim().replace(/^[.,;:¡¿\-—\s]+|[.,;:\-—\s]+$/g, '').trim();
        if (!trimmed) return null;
        /* Debe ser UNA sola palabra (sin espacios). */
        if (/\s/.test(trimmed)) return null;
        /* Palabra + 1-3 exclamaciones. */
        const exclMatch = trimmed.match(/^([\p{L}\d]+)(!{1,3})$/u);
        if (exclMatch && exclMatch[1].length >= 2) return trimmed;
        /* ALL CAPS de 4+ chars (sin exclamación). */
        if (/^[\p{Lu}][\p{Lu}\d]{3,}$/u.test(trimmed)) return trimmed;
        return null;
    }

    /* Renderiza una palabra enfática como overlay GIGANTE centrado.
       Auto-fade tras 2.5s. Múltiples emph pueden coexistir si el line
       tiene varias (cada una con su delay distinto). */
    function pfRenderEmphasis(word) {
        if (!lyrStage || !word) return;
        const wrap = document.createElement('div');
        wrap.className = 'pf-lyr-line-wrap pf-lyr-mode-emph';
        wrap.innerHTML = pfRenderLine(word);
        lyrStage.appendChild(wrap);
        setTimeout(() => {
            if (!wrap.parentNode || wrap.classList.contains('leaving')) return;
            wrap.style.animationDelay = '0s';
            wrap.classList.add('leaving');
            setTimeout(() => { if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 600);
        }, 2500);
    }

    /* Render INLINE para repeticiones DEMASIADO RÁPIDAS para scatter.
       REUSAMOS los modos multi-line ya existentes — mismo color, fondo,
       animaciones y tipografía que el resto:
         layout='v' → cada frase es un wrap fill-stack (fila horizontal
                      en su slot vertical) → apiladas verticalmente.
         layout='h' → cada frase es un wrap fill-vert (columna vertical
                      en su slot horizontal) → alineadas lado a lado.
       delaysSec: array de delays absolutos por slot — cada wrap recibe
       su animationDelay para entrar escalonadamente al ritmo de la
       música, no todas a la vez. */
    function pfRenderInlineRepeat(texts, layout, delaysSec) {
        if (!lyrStage || !texts || !texts.length) return;
        const mode = layout === 'v' ? 'fill-stack' : 'fill-vert';
        const total = texts.length;
        texts.forEach((text, slot) => {
            const wrap = document.createElement('div');
            wrap.className = 'pf-lyr-line-wrap pf-lyr-mode-' + mode;
            wrap.style.setProperty('--slot', String(slot));
            wrap.style.setProperty('--total', String(total));
            wrap.setAttribute('data-pf-slot', String(slot));
            const delay = (delaysSec && delaysSec[slot] !== undefined) ? delaysSec[slot] : 0;
            if (delay > 0) {
                /* Pre-oculta el wrap mientras espera su delay: las reglas
                   base de fill-stack/fill-vert no traen opacity:0, así
                   que durante el animation-delay el wrap es VISIBLE a
                   opacidad normal y solo se aplica la animación cuando
                   arranca → todas aparecían "ya puestas" antes de
                   animarse. Inline opacity:0 + animation forwards arregla
                   esto: invisible durante el delay, la animación llega y
                   anima 0→1, al terminar el frame 100% sostiene opacity:1. */
                wrap.style.opacity = '0';
                wrap.style.animationDelay = delay.toFixed(2) + 's';
            }
            wrap.innerHTML = pfRenderLine(text);
            lyrStage.appendChild(wrap);
        });
    }

    /* Detecta repeticiones de palabra o FRASE dentro de una línea:
         - "Sí, sí, sí, sí" → palabra "Sí" repetida.
         - "everybody it's all up in my, everybody it's all up in my, everybody it's all in my"
           → frase "everybody it's all up in my" repetida (con la 3ª ligeramente diferente, igualmente cuenta).
       Devuelve {word, count} o null. La detección es tolerante: si la
       frase MÁS COMÚN aparece 2+ veces en una línea con 3+ frases,
       se considera repetición. */
    function pfDetectRepeatedWord(text) {
        if (!text) return null;

        /* Paso 1: palabra única repetida. Todas iguales → outliers vacíos. */
        const words = text.split(/[^\p{L}\p{N}]+/u).filter(p => p.length > 0);
        if (words.length >= 3) {
            const first = words[0].toLowerCase();
            if (words.every(p => p.toLowerCase() === first)) {
                return { word: words[0], count: words.length, triggerCount: words.length, outliers: [] };
            }
        }

        /* Paso 2: frase repetida — split por comas/puntos/punto-coma. */
        const phrases = text.split(/[,;.!?¡¿]+\s*/).map(s => s.trim()).filter(s => s.length > 0);
        if (phrases.length >= 3) {
            const counts = {};
            for (const p of phrases) {
                const k = _pfNorm(p);
                if (k) counts[k] = (counts[k] || 0) + 1;
            }
            let bestKey = null, bestCount = 0;
            for (const k in counts) {
                if (counts[k] > bestCount) { bestKey = k; bestCount = counts[k]; }
            }
            if (bestCount >= 2) {
                const phrase = phrases.find(p => _pfNorm(p) === bestKey) || phrases[0];
                const outliers = phrases.filter(p => _pfNorm(p) !== bestKey);
                const orderedPhrases = phrases.map(p => ({
                    text: p,
                    isOutlier: _pfNorm(p) !== bestKey,
                    length: p.length,
                }));
                return {
                    word: phrase,
                    count: bestCount,
                    triggerCount: phrases.length,
                    outliers,
                    orderedPhrases,
                };
            }
        }

        /* Paso 3: TOKEN GROUP repetido (sin comas). Cubre versos como
           "i love you i love you i love you" — separados por espacios
           pero sin puntuación. Busca el grupo más pequeño que cubra
           todos los tokens repitiendo 3+ veces. */
        const tokens = text.trim().split(/\s+/).filter(t => t.length > 0);
        if (tokens.length >= 3) {
            for (let groupSize = 1; groupSize <= Math.floor(tokens.length / 3); groupSize++) {
                const candidate = tokens.slice(0, groupSize).join(' ');
                const candLower = candidate.toLowerCase();
                let matchCount = 0;
                for (let i = 0; i + groupSize <= tokens.length; i += groupSize) {
                    const slice = tokens.slice(i, i + groupSize).join(' ').toLowerCase();
                    if (slice === candLower) matchCount++;
                    else break;
                }
                if (matchCount * groupSize === tokens.length && matchCount >= 3) {
                    const op = [];
                    for (let k = 0; k < matchCount; k++) {
                        op.push({ text: candidate, isOutlier: false, length: candidate.length });
                    }
                    return {
                        word: candidate, count: matchCount,
                        triggerCount: matchCount, outliers: [],
                        orderedPhrases: op,
                    };
                }
            }
        }

        /* Paso 4: CHAR-LEVEL substring repetido (sin separadores ni
           espacios). Cubre "くるくるくるくる" (Japonés), "lalala",
           "OhOhOhOh", etc. Encuentra el sub-patrón más corto que
           cubre el texto entero repitiendo 3+ veces. */
        const charTrimmed = text.trim();
        if (charTrimmed.length >= 4) {
            for (let L = 1; L <= Math.floor(charTrimmed.length / 3); L++) {
                const candidate = charTrimmed.substring(0, L);
                if (!candidate.trim()) continue;
                const candLower = candidate.toLowerCase();
                let cnt = 0, i = 0;
                while (i + L <= charTrimmed.length
                    && charTrimmed.substring(i, i + L).toLowerCase() === candLower) {
                    cnt++;
                    i += L;
                }
                /* Cobertura total Y 3+ repeticiones. */
                if (i === charTrimmed.length && cnt >= 3) {
                    const op = [];
                    for (let k = 0; k < cnt; k++) {
                        op.push({ text: candidate, isOutlier: false, length: candidate.length });
                    }
                    return {
                        word: candidate, count: cnt,
                        triggerCount: cnt, outliers: [],
                        orderedPhrases: op,
                    };
                }
            }
        }

        return null;
    }

    /* Construye y suelta N copias de la misma palabra esparcidas en
       posiciones de un GRID virtual. Stepwise entry — el delay entre
       cada copia se calcula a partir de la duración del verso ÷ total
       de frases (incluyendo outliers). Sincroniza con la música:
         - "sí, sí, sí" (línea 1s / 3 frases) → step ~0.33s (rápido)
         - "im i bad × 4" (línea 4s / 4 frases) → step ~1s (lento)
       opts.lineDurMs: duración del verso (defecto 2s).
       opts.totalCount: total de frases incluyendo outliers (defecto count).
       Devuelve el stepDelay calculado (s) para que el caller sincronice
       los outliers también con el mismo ritmo. */
    function pfRenderScatter(word, count, opts) {
        opts = opts || {};
        /* delaysSec: array de delays absolutos en segundos para cada
           copia (en orden de aparición temporal). Si se proporciona,
           tiene prioridad. Si no, se cae al cálculo legacy basado en
           lineDurMs/totalCount. */
        const delaysSec = opts.delaysSec || null;
        const lineDurMs = opts.lineDurMs || 2000;
        const totalCount = opts.totalCount || count;
        const max = Math.min(count, 8);
        const cols = Math.ceil(Math.sqrt(max));
        const rows = Math.ceil(max / cols);
        const padX = 12, padY = 18;
        const usableW = 100 - padX * 2;
        const usableH = 100 - padY * 2;
        const cellW = usableW / cols;
        const cellH = usableH / rows;
        /* Si NO viene delaysSec, calculamos step uniforme basado en
           lineDurMs (modo legacy). */
        const stepRaw = (lineDurMs / 1000) / Math.max(totalCount, 1);
        const fallbackStep = Math.max(0.18, Math.min(stepRaw, 1.2));
        const order = [];
        for (let i = 0; i < max; i++) order.push(i);
        for (let i = order.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = order[i]; order[i] = order[j]; order[j] = tmp;
        }
        order.forEach((cellIdx, displayIdx) => {
            const col = cellIdx % cols;
            const row = Math.floor(cellIdx / cols);
            const cx = padX + (col + 0.5) * cellW;
            const cy = padY + (row + 0.5) * cellH;
            const jx = (Math.random() - 0.5) * cellW * 0.4;
            const jy = (Math.random() - 0.5) * cellH * 0.4;
            const wrap = document.createElement('div');
            wrap.className = 'pf-lyr-line-wrap pf-lyr-mode-scatter';
            wrap.style.setProperty('--x', (cx + jx).toFixed(2));
            wrap.style.setProperty('--y', (cy + jy).toFixed(2));
            wrap.style.setProperty('--r', String(-14 + Math.random() * 28));
            wrap.style.setProperty('--s', String(0.75 + Math.random() * 0.45));
            /* displayIdx es el orden VISUAL (post-shuffle). Para el
               TIEMPO usamos directamente displayIdx también — i.e., la
               copia visualmente N-ésima aparece en el delay N-ésimo.
               El shuffle de cells es solo para no leer en grid. */
            const delay = (delaysSec && delaysSec[displayIdx] !== undefined)
                ? delaysSec[displayIdx]
                : displayIdx * fallbackStep;
            wrap.style.animationDelay = delay.toFixed(2) + 's';
            wrap.innerHTML = pfRenderLine(word);
            lyrStage.appendChild(wrap);
        });
        return delaysSec ? delaysSec[delaysSec.length - 1] || 0 : (max - 1) * fallbackStep;
    }

    /* Helper para marcar como leaving los wraps existentes. Limpia el
       animation-delay inline para que TODAS las salidas arranquen a la
       vez — sin esto, las copias del scatter heredaban el delay
       escalonado de entrada (0.4s × idx) y salían también escalonadas. */
    function pfFadeOutWraps(selector) {
        const els = lyrStage ? lyrStage.querySelectorAll(selector || '.pf-lyr-line-wrap') : [];
        Array.from(els).forEach(el => {
            if (el.classList.contains('leaving')) return;
            el.style.animationDelay = '0s';
            el.classList.add('leaving');
            const toRemove = el;
            setTimeout(() => { if (toRemove.parentNode) toRemove.parentNode.removeChild(toRemove); }, 800);
        });
    }

    function pfShowLine(text) {
        if (!lyrStage) { pfLog('showLine: lyrStage is null'); return; }
        pfLog('showLine called, text=' + JSON.stringify(text ? text.substring(0, 80) : null));
        if (text) {
            const status = lyrStage.querySelector('.pf-lyr-status');
            if (status) status.remove();
        }
        if (!text) {
            /* Silencio → quita las líneas principales. Asides se mantienen
               hasta su auto-fade (4.5s tras aparecer). */
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            pfRepeatLineGroup = null;
            return;
        }
        /* ── Multi-line repeat ──
           Si estamos DENTRO de un grupo ya activo (idx en rango), no
           renderizamos nada nuevo — el scatter previo sigue en pantalla. */
        if (pfRepeatLineGroup && pfLyrCurIdx >= pfRepeatLineGroup.startIdx && pfLyrCurIdx <= pfRepeatLineGroup.endIdx) {
            pfLog('  skip: still in active multi-line repeat group');
            return;
        }
        /* Si salimos del grupo, lo limpiamos y seguimos al check de
           nuevo grupo. */
        pfRepeatLineGroup = null;
        /* Detecta si la línea ACTUAL inicia un grupo de N líneas
           consecutivas idénticas → scatter del verso. */
        const multiRepeat = pfDetectMultiLineRepeat();
        if (multiRepeat) {
            /* Para multi-line TENEMOS timestamps reales de cada línea
               — los usamos directamente para sincronizar las copias del
               scatter con el ritmo del canto. Cada copia aparece en el
               momento EXACTO en que su línea del LRC tocaría. */
            const startTime = pfLyrLines[multiRepeat.startIdx]?.time || 0;
            const delaysSec = [];
            for (let i = 0; i < multiRepeat.count; i++) {
                const t = pfLyrLines[multiRepeat.startIdx + i]?.time;
                delaysSec.push(t !== undefined ? Math.max(0, t - startTime) : i * 0.5);
            }
            pfLog('  multi-line repeat: idx ' + multiRepeat.startIdx + '-' + multiRepeat.endIdx
                + ' count=' + multiRepeat.count
                + ' delaysSec=[' + delaysSec.map(d => d.toFixed(2)).join(',') + '] "'
                + multiRepeat.text.substring(0, 40) + '"');
            pfRepeatLineGroup = multiRepeat;
            pfLineCounter++;
            root.classList.remove('pf-lyr-blurred');
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            pfRenderScatter(multiRepeat.text, multiRepeat.count, {
                delaysSec,
            });
            return;
        }

        /* ── Detección de paréntesis ──
           Si el verso tiene paréntesis, separamos el main (lo de fuera)
           del aside (lo de dentro). Aside se renderiza en esquina
           pequeña SIN borrar la línea anterior. */
        const parens = pfExtractParens(text);
        if (parens.asides.length > 0 && !parens.main) {
            /* Verso TOTALMENTE entre paréntesis → no borra la previa,
               solo añade el aside en esquina. */
            pfLog('  aside-only: "' + parens.asides.join('|') + '"');
            parens.asides.forEach(a => pfRenderAsideCorner(a));
            return;
        }
        /* El texto principal a renderizar es lo de fuera de los
           paréntesis. Si no había paréntesis, parens.main === text. */
        const mainText = parens.main || text;

        /* Si la línea entera es UNA sola palabra enfática (FIRE!, GO!,
           BURN, etc.) → render como overlay grande explosivo en lugar
           del modo normal. NO emph para versos con texto regular. */
        const singleEmph = pfDetectSingleEmphasis(mainText);
        if (singleEmph) {
            pfLog('  single-word emph: "' + singleEmph + '"');
            pfLineCounter++;
            root.classList.remove('pf-lyr-blurred');
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            pfRenderEmphasis(singleEmph);
            /* Asides del verso (paréntesis) también se muestran. */
            parens.asides.forEach(a => pfRenderAsideCorner(a));
            return;
        }

        /* Caso especial: palabra/frase repetida ("Sí, sí, sí, sí" o
           "im i bad, im i bad, im i bad, im i really that bad") → scatter
           + emphasis para outliers. triggerCount >= 3 gates. */
        const repeated = pfDetectRepeatedWord(mainText);
        if (repeated && repeated.triggerCount >= 3) {
            const outliers = repeated.outliers || [];
            const ordered = repeated.orderedPhrases || [];
            const lineKey = pfLineKey(mainText);
            const cached = pfLineStyleCache[lineKey];
            /* lineDurMs = duración del verso actual en ms (gap hasta el
               siguiente verso del LRC). Usado para decidir si hay tiempo
               para el scatter o si hay que fallback a inline. */
            const lineDurMs = Math.max(500, pfGetCurLineDuration() * 1000);
            /* ── Sincronización por LONGITUD DE FRASE ──
               El tiempo de cada frase se estima como (chars × SEC_PER_CHAR).
               Tomamos longitud antes que duración del verso porque a veces
               el verso es largo en tiempo pero las frases cortas (silencio
               final, como "oh, oh, right"). */
            const SEC_PER_CHAR = 0.1;
            /* Calcula span total estimado del scatter (cumChars × SEC_PER_CHAR).
               Si la línea no llega a cubrir ese span con buffer suficiente
               para la animación de entrada (0.7s), el scatter se buguea:
               las últimas copias no terminan de entrar antes de que llegue
               la siguiente línea → solo se ven sus fades de salida fantasma.
               En esos casos, render INLINE (horizontal o vertical según
               cantidad), sin destacar outliers — todo en línea. */
            let totalChars = 0;
            ordered.forEach(p => { totalChars += Math.max(2, p.length); });
            const lineDurSec = lineDurMs / 1000;
            const estimatedSpan = totalChars * SEC_PER_CHAR;
            const ENTRY_BUFFER_SEC = 0.7;
            /* Decisión cacheable: si la línea YA apareció antes con un
               estilo, lo reusamos para consistencia visual del coro.
               Si no, decidimos por isTooFast y guardamos. */
            let useInline;
            if (cached && cached.kind === 'repeat-inline') {
                useInline = true;
            } else if (cached && cached.kind === 'repeat-scatter') {
                useInline = false;
            } else {
                useInline = estimatedSpan + ENTRY_BUFFER_SEC > lineDurSec;
            }
            if (useInline) {
                /* INLINE MODE — todas las frases en wraps fill-stack o
                   fill-vert con delays escalonados → aparecen al ritmo
                   de la música, una tras otra, no todas a la vez. Sin
                   emphasis para outliers — todo al mismo nivel. */
                const allTexts = ordered.map(p => p.text);
                const layout = allTexts.length >= 4 ? 'v' : 'h';
                /* Cachea decisión para próximas apariciones del mismo verso. */
                if (!cached) pfLineStyleCache[lineKey] = { kind: 'repeat-inline', layout };
                /* Delays por longitud de frase. Mismo principio que el
                   scatter (chars × SEC_PER_CHAR) pero con compresión
                   para que el último entre antes del final de la línea.
                   Buffer 0.4s: la entrada del fill-* dura 0.85s con
                   opacity 1 al 60% = 0.51s — el último necesita ese
                   tiempo desde su delay hasta el final del verso. */
                let cumChars2 = 0;
                const rawDelays = ordered.map(p => {
                    const d = cumChars2 * SEC_PER_CHAR;
                    cumChars2 += Math.max(2, p.length);
                    return d;
                });
                const INLINE_BUFFER = 0.4;
                const maxSpan2 = Math.max(0.15, lineDurSec - INLINE_BUFFER);
                const lastRaw = rawDelays[rawDelays.length - 1] || 0;
                const inlineDelays = (lastRaw > maxSpan2 && lastRaw > 0)
                    ? rawDelays.map(d => d * (maxSpan2 / lastRaw))
                    : rawDelays;
                pfLog('  fast-repeat (inline-' + layout + '): ' + allTexts.length
                    + ' phrases, lineDur=' + lineDurSec.toFixed(2)
                    + 's delays=[' + inlineDelays.map(d => d.toFixed(2)).join(',') + ']');
                pfLineCounter++;
                root.classList.remove('pf-lyr-blurred');
                pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
                pfRenderInlineRepeat(allTexts, layout, inlineDelays);
                parens.asides.forEach(a => pfRenderAsideCorner(a));
                return;
            }
            /* Modo scatter normal — la línea tiene tiempo suficiente. */
            if (!cached) pfLineStyleCache[lineKey] = { kind: 'repeat-scatter' };
            let cumChars = 0;
            const scatterDelays = [];
            const outlierTimings = [];
            ordered.forEach(p => {
                const startTimeSec = cumChars * SEC_PER_CHAR;
                if (p.isOutlier) {
                    outlierTimings.push({ text: p.text, delaySec: startTimeSec });
                } else {
                    scatterDelays.push(startTimeSec);
                }
                cumChars += Math.max(2, p.length);
            });
            pfLog('  scatter: word="' + repeated.word + '" copies=' + repeated.count
                + ' outliers=' + outliers.length
                + ' scatterDelays=[' + scatterDelays.map(d => d.toFixed(2)).join(',') + ']');
            pfLineCounter++;
            root.classList.remove('pf-lyr-blurred');
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            pfRenderScatter(repeated.word, repeated.count, {
                delaysSec: scatterDelays,
            });
            const expectedIdx = pfLyrCurIdx;
            outlierTimings.forEach(({ text, delaySec }) => {
                setTimeout(() => {
                    if (!pfLyricsOpen) return;
                    if (pfLyrCurIdx !== expectedIdx) return;
                    pfRenderEmphasis(text);
                }, delaySec * 1000);
            });
            parens.asides.forEach(a => pfRenderAsideCorner(a));
            return;
        }
        /* ── Detección de línea larga ──
           Thresholds independientes:
             - avoidVertical: para vert-left/right (1 char por fila).
               20 chars máx (más allá no cabe verticalmente en 90vh).
             - avoidStacking: para multi-modes (fill-vert/stack, fast).
               40 chars máx — el font es más chico, caben más. */
        const lineDur = pfGetCurLineDuration();
        let choice;
        const normalKey = pfLineKey(mainText);
        const normalCached = pfLineStyleCache[normalKey];
        if (normalCached && normalCached.kind === 'normal') {
            /* Repetición de un verso ya visto en modo single-line →
               reusamos su mode exacto para que el coro siempre se vea
               igual. Forzamos blur=false para no interferir con el
               intro/outro auto-toggle del picker. */
            choice = { mode: normalCached.mode, blur: false };
        } else {
            choice = pfPickMode({
                avoidVertical: mainText.length > 20 || lineDur > 6,
                avoidStacking: mainText.length > 40 || lineDur > 6,
            });
            /* Solo cacheamos modos SINGLE-LINE (los multi y fast-stack
               dependen del estado de grupo — no son seguros de reusar
               por línea aislada). choice.slot está sólo en multi/fast. */
            if (choice.slot === undefined) {
                pfLineStyleCache[normalKey] = { kind: 'normal', mode: choice.mode };
            }
        }
        pfLineCounter++;
        const isMulti     = (choice.slot !== undefined) && !choice.isFastStack;
        const isFastStack = !!choice.isFastStack;
        pfLog('  mode=' + choice.mode + ' blur=' + choice.blur + ' lineCounter=' + pfLineCounter
            + (isMulti     ? (' slot=' + choice.slot + '/' + choice.total + (choice.isLast?' (LAST)':'')) : '')
            + (isFastStack ? (' fast-slot=' + choice.slot + '/' + choice.total) : ''));
        if (choice.blur) root.classList.add('pf-lyr-blurred');
        else root.classList.remove('pf-lyr-blurred');

        /* Los asides (paréntesis en esquinas) NUNCA se limpian por el
           flujo principal — se autoextinguen tras PF_ASIDE_HOLD_MS.
           Por eso todos los selectores de limpieza excluyen .pf-lyr-mode-aside. */
        if (isFastStack) {
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-fast-stack):not(.pf-lyr-mode-aside)');
            pfFadeOutWraps('.pf-lyr-line-wrap.pf-lyr-mode-fast-stack[data-pf-slot="' + choice.slot + '"]');
        } else if (isMulti) {
            if (choice.slot === 0) {
                pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            }
        } else {
            pfFadeOutWraps('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
        }

        const wrap = document.createElement('div');
        wrap.className = 'pf-lyr-line-wrap pf-lyr-mode-' + choice.mode;
        if (isMulti || isFastStack) {
            wrap.style.setProperty('--slot', String(choice.slot));
            wrap.style.setProperty('--total', String(choice.total));
            wrap.setAttribute('data-pf-slot', String(choice.slot));
        }
        wrap.innerHTML = pfRenderLine(mainText);
        lyrStage.appendChild(wrap);

        /* Schedule fade-out grupal del multi-mode al completar. */
        if (isMulti && choice.isLast) {
            const groupSelector = '.pf-lyr-line-wrap.pf-lyr-mode-' + choice.mode;
            setTimeout(() => pfFadeOutWraps(groupSelector), choice.holdMs);
        }

        /* Después de renderizar el main, añadimos los asides (si los hay)
           en esquinas — no reemplazan el main, son overlay adicional. */
        parens.asides.forEach(a => pfRenderAsideCorner(a));
    }

    /* Controller del fetch activo — al iniciar un nuevo fetch (track
       change) se aborta el anterior para no malgastar recursos ni
       sobreescribir el estado del track nuevo con la respuesta vieja. */
    let pfFetchController = null;

    async function pfFetchForCurrent() {
        /* Aborta el fetch anterior si existe — al cambiar de canción,
           la request vieja se cancela inmediatamente. */
        if (pfFetchController) {
            try { pfFetchController.abort(); } catch(_){}
        }
        const controller = new AbortController();
        pfFetchController = controller;

        const tr = pfCurTrack();
        if (!tr || !tr.videoId) {
            pfLog('fetch: no track or videoId');
            pfLyrLines = null; pfLyrVid = null; pfLyrCurIdx = -1;
            pfShowLine(null);
            return;
        }
        if (tr.videoId === pfLyrVid && pfLyrLines) {
            pfLog('fetch: cache hit, lines=' + pfLyrLines.length);
            pfLyrCurIdx = -2;
            if (pfLyricsOpen) pfTick();
            return;
        }
        pfLyrVid = tr.videoId; pfLyrLines = null; pfLyrCurIdx = -1;

        /* Espera hasta 3s a que el YT player reporte una duración real
           (>0). Sin esto, fetches inmediatos tras cambiar de track usan
           dur=0 y caen en cache miss (server-side cache key no incluye
           dur ya, pero LRCLIB hace una búsqueda menos precisa). */
        let dur = 0;
        const waitStart = Date.now();
        while (Date.now() - waitStart < 3000) {
            try {
                if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getDuration) {
                    dur = Math.floor(ytPlayer.getDuration() || 0);
                }
            } catch(_){}
            if (dur > 0) break;
            await new Promise(r => setTimeout(r, 200));
            /* Salidas tempranas — si nos abortaron o el track cambió. */
            if (controller.signal.aborted) { pfLog('fetch: aborted during dur-wait'); return; }
            const curT = pfCurTrack();
            if (!curT || curT.videoId !== tr.videoId) { pfLog('fetch: track changed during dur-wait'); return; }
        }

        const qs = new URLSearchParams({ title: tr.title || '', artist: tr.artist || '', duration: String(dur) });
        pfLog('fetch: GET title=' + (tr.title||'') + ' artist=' + (tr.artist||'') + ' dur=' + dur);
        try {
            const r = await fetch('assets/music/api.php?action=get-lyrics&' + qs.toString(), {
                credentials: 'same-origin',
                signal: controller.signal,
            });
            pfLog('fetch: response status=' + r.status);
            const d = await r.json();
            pfLog('fetch: response ok=' + (d&&d.ok) + ' found=' + (d&&d.found) + ' hasSynced=' + !!(d&&d.synced) + ' syncedLen=' + ((d&&d.synced||'').length));
            if (controller.signal.aborted) { pfLog('fetch: aborted post-response'); return; }
            if (pfLyrVid !== tr.videoId) { pfLog('fetch: track changed mid-fetch, ignoring'); return; }
            if (!d || !d.ok || !d.found || !d.synced) { pfLog('fetch: no synced data, returning'); return; }
            const parsed = pfParseLRC(d.synced);
            pfLog('fetch: parsed ' + parsed.length + ' synced lines');
            if (parsed.length > 0) {
                pfLog('fetch: first line @' + parsed[0].time + 's "' + parsed[0].text.substring(0, 40) + '"');
                pfLog('fetch: last  line @' + parsed[parsed.length-1].time + 's');
            }
            if (parsed.length) {
                pfLyrLines = parsed;
                pfLyrCurIdx = -2;
                if (pfLyricsOpen) pfTick();
            }
        } catch(e) {
            if (e && e.name === 'AbortError') {
                pfLog('fetch: aborted (track changed)');
                return;
            }
            pfLog('fetch: exception: ' + (e && e.message ? e.message : String(e)));
        } finally {
            /* Si este controller sigue siendo el activo, lo limpiamos.
               Si ya fue reemplazado por otro fetch, no lo tocamos. */
            if (pfFetchController === controller) pfFetchController = null;
        }
    }

    /* Tick: calcula qué línea (si alguna) debería estar visible AHORA.
       Detección de gap → durante huecos instrumentales largos no
       mostramos nada, pero más permisiva que antes (15s threshold).
       Heartbeat cada 5 ticks (1s) loguea estado del player + tiempo
       para diagnosticar si el sync funciona. */
    let pfTickCount = 0;
    function pfTick() {
        if (!pfLyricsOpen) return;
        if (!pfLyrLines || !pfLyrLines.length) {
            if (pfLyrCurIdx !== -1) { pfLyrCurIdx = -1; pfShowLine(null); }
            return;
        }
        let t = 0;
        try { if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getCurrentTime) t = ytPlayer.getCurrentTime() || 0; } catch(_){}
        /* Heartbeat — cada 5 ticks (1s) loguea estado actual. Solo
           durante los primeros 30s tras open para no llenar el log. */
        pfTickCount++;
        if (pfTickCount % 5 === 0 && pfTickCount <= 150) {
            let st = 'unknown';
            try {
                if (typeof ytPlayer !== 'undefined' && ytPlayer && ytPlayer.getPlayerState) {
                    const s = ytPlayer.getPlayerState();
                    st = ({'-1':'UNSTARTED','0':'ENDED','1':'PLAYING','2':'PAUSED','3':'BUFFERING','5':'CUED'})[String(s)] || ('STATE_' + s);
                }
            } catch(_){}
            pfLog('heartbeat: tick#' + pfTickCount + ' t=' + t.toFixed(2) + ' state=' + st + ' firstLine@=' + (pfLyrLines && pfLyrLines[0] ? pfLyrLines[0].time : '?'));
        }
        /* Binary search: última línea con time <= t. */
        let lo = 0, hi = pfLyrLines.length - 1, idx = -1;
        while (lo <= hi) {
            const mid = (lo + hi) >> 1;
            if (pfLyrLines[mid].time <= t) { idx = mid; lo = mid + 1; } else { hi = mid - 1; }
        }
        /* Detección de gap — relajada: solo ocultar si el gap a la
           siguiente es >15s Y ya pasaron 8s desde la actual. Antes era
           7s/3.5s lo que ocultaba demasiado durante canciones con
           pausas naturales entre versos. */
        let showIdx = idx;
        if (showIdx >= 0) {
            const cur  = pfLyrLines[showIdx];
            const next = pfLyrLines[showIdx + 1];
            const elapsed = t - cur.time;
            if (next) {
                const gap = next.time - cur.time;
                if (gap > 15 && elapsed > 8) showIdx = -1;
            } else {
                /* Última línea: ocultar después de 20s (outro largo). */
                if (elapsed > 20) showIdx = -1;
            }
        }
        if (showIdx !== pfLyrCurIdx) {
            pfLog('tick: t=' + t.toFixed(2) + ' rawIdx=' + idx + ' showIdx=' + showIdx + ' prevIdx=' + pfLyrCurIdx
                + (showIdx >= 0 ? ' line="' + pfLyrLines[showIdx].text.substring(0, 50) + '"' : ' (no line)'));
            pfLyrCurIdx = showIdx;
            pfShowLine(showIdx >= 0 ? pfLyrLines[showIdx].text : null);
        }
    }

    /* Toast con un solo texto (para "Sin letra…", etc). */
    function pfFlashStatus(msg, ms) {
        if (!lyrStage) return;
        const old = lyrStage.querySelector('.pf-lyr-status');
        if (old) old.remove();
        const el = document.createElement('div');
        el.className = 'pf-lyr-status';
        el.textContent = msg;
        lyrStage.appendChild(el);
        setTimeout(() => { el.classList.add('fade'); setTimeout(() => el.remove(), 500); }, ms || 1600);
    }
    /* Loader minimalista — solo 3 dots bounceando, sin texto.
       (El parámetro label se ignora; se mantiene por compatibilidad). */
    function pfFlashLoading(label, ms) {
        if (!lyrStage) return;
        const old = lyrStage.querySelector('.pf-lyr-status');
        if (old) old.remove();
        const el = document.createElement('div');
        el.className = 'pf-lyr-status pf-lyr-status-loading';
        const dotsWrap = document.createElement('span');
        dotsWrap.className = 'pf-loading-dots';
        for (let i = 0; i < 3; i++) {
            const d = document.createElement('span');
            d.className = 'pf-loading-dot';
            d.textContent = '.';
            dotsWrap.appendChild(d);
        }
        el.appendChild(dotsWrap);
        lyrStage.appendChild(el);
        setTimeout(() => { el.classList.add('fade'); setTimeout(() => el.remove(), 500); }, ms || 60000);
    }
    /* Toast estilo "Now Playing" — título grande + artista debajo.
       Se muestra al cargar lyrics correctamente. */
    function pfFlashTrack(title, artist, ms) {
        if (!lyrStage) return;
        const old = lyrStage.querySelector('.pf-lyr-status');
        if (old) old.remove();
        const el = document.createElement('div');
        el.className = 'pf-lyr-status pf-lyr-status-track';
        const tEl = document.createElement('div');
        tEl.className = 'pf-lyr-status-title';
        tEl.textContent = title || '—';
        const aEl = document.createElement('div');
        aEl.className = 'pf-lyr-status-artist';
        aEl.textContent = artist || '';
        el.appendChild(tEl);
        if (artist) el.appendChild(aEl);
        lyrStage.appendChild(el);
        setTimeout(() => { el.classList.add('fade'); setTimeout(() => el.remove(), 500); }, ms || 2600);
    }

    function pfUpdateBtnState() {
        if (!btnLyr) return;
        btnLyr.classList.toggle('is-on', pfLyricsOpen);
        btnLyr.setAttribute('aria-pressed', pfLyricsOpen ? 'true' : 'false');
    }

    function pfOpenLyrics() {
        root.classList.add('pf-lyrics-active');
        pfLyricsOpen = true;
        pfUpdateBtnState();
        pfLyrCurIdx = -2;
        const tr = pfCurTrack();
        /* Captura videoId al abrir — el .then() compara contra esto para
           descartar callbacks de fetches abortados de canciones previas
           (que devuelven igual via Promise resolution). */
        const openedFor = tr && tr.videoId;
        try { console.log('[pf-lyrics] open. track=', tr && tr.title, 'videoId=', tr && tr.videoId, 'cached=', tr && tr.videoId === pfLyrVid && !!pfLyrLines); } catch(_){}
        if (!pfLyrLines || !tr || tr.videoId !== pfLyrVid) {
            pfFlashLoading('🎤 Buscando letra', 60000);   /* persiste hasta cambio */
        }
        pfFetchForCurrent().then(() => {
            if (!pfLyricsOpen) return;
            /* Guard contra stale callback: si el track cambió mientras
               el fetch estaba en vuelo, este callback es de la canción
               vieja — el reload de la nueva ya está corriendo. */
            const tNow = pfCurTrack();
            if (!tNow || tNow.videoId !== openedFor) {
                try { console.log('[pf-lyrics] open.then: stale (track changed)'); } catch(_){}
                return;
            }
            try { console.log('[pf-lyrics] fetch done. lines=', (pfLyrLines||[]).length); } catch(_){}
            if (!pfLyrLines || !pfLyrLines.length) {
                pfFlashStatus('🥲 Sin letra sincronizada para esta canción', 8000);
                return;
            }
            /* Si al activar las letras ya hay una línea visible (porque
               la canción está en mitad de un verso y pfTick disparó
               showLine en cache hit), NO mostramos el toast "Now
               Playing" — taparía el verso ya visible. Solo cleanup del
               loading toast. */
            const lineVisible = lyrStage && lyrStage.querySelector('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
            if (lineVisible) {
                const status = lyrStage.querySelector('.pf-lyr-status');
                if (status) status.remove();
                return;
            }
            const t = pfCurTrack();
            pfFlashTrack(t && t.title || 'Canción', t && t.artist || '', 2800);
        });
        if (pfLyrTimer) clearInterval(pfLyrTimer);
        pfLyrTimer = setInterval(pfTick, 200);
    }
    function pfCloseLyrics() {
        root.classList.remove('pf-lyrics-active');
        root.classList.remove('pf-lyr-blurred');
        pfLyricsOpen = false;
        pfUpdateBtnState();
        if (pfLyrTimer) { clearInterval(pfLyrTimer); pfLyrTimer = null; }
        pfLyrCurIdx = -1;
        pfLineCounter = 0;
        pfMultiState = null;
        pfFastStackCounter = 0;
        pfFastStackStartedAt = 0;
        pfAsideCounter = 0;
        pfRepeatLineGroup = null;
        pfLineStyleCache = {};  /* cache vacío por canción */
        /* Cancela cualquier fetch en vuelo al cerrar. */
        if (pfFetchController) { try { pfFetchController.abort(); } catch(_){} pfFetchController = null; }
        /* Cancela debounce de reload pendiente. */
        if (pfReloadDebounceTimer) { clearTimeout(pfReloadDebounceTimer); pfReloadDebounceTimer = null; }
        if (lyrStage) lyrStage.innerHTML = '';
    }

    /* Botón micrófono: toggle on/off + visual feedback (.is-on). */
    if (btnLyr) btnLyr.addEventListener('click', function() {
        if (pfLyricsOpen) pfCloseLyrics();
        else pfOpenLyrics();
    });
    /* Debounce del reload — si el usuario salta rápido entre canciones,
       no queremos disparar un fetch + render por cada skip. Solo cuando
       la canción se "asienta" 1.5s arrancamos la carga real. */
    let pfReloadDebounceTimer = null;
    const PF_RELOAD_DEBOUNCE_MS = 1500;

    /* Limpieza ligera inmediata al cambiar de track — quita los wraps
       visibles SIN animación (mucho más rápido que pfFadeOutWraps que
       crea 50+ animations CSS por wrap). Asides se preservan. */
    function pfQuickClearStage() {
        if (!lyrStage) return;
        const els = lyrStage.querySelectorAll('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
        els.forEach(el => el.remove());
        const status = lyrStage.querySelector('.pf-lyr-status');
        if (status) status.remove();
    }

    /* Cuando cambia el track con las letras abiertas:
       1. INMEDIATO: cancela fetch en vuelo + clear DOM + loading dots
          + reset de TODO el estado de letra previa.
       2. DEBOUNCED: tras 1.5s sin más cambios, arranca el fetch real
          de la nueva canción.
       Si el usuario salta otra vez antes de 1.5s, el timer se cancela
       y se reinicia → solo trabajo de carga REAL cuando el usuario
       "se queda" en una canción. */
    function pfReloadForTrack() {
        if (!pfLyricsOpen) return;

        /* === FASE 1 inmediata: cleanup ligero === */

        /* Cancela el debounce pendiente si existía. */
        if (pfReloadDebounceTimer) {
            clearTimeout(pfReloadDebounceTimer);
            pfReloadDebounceTimer = null;
        }
        /* Cancela cualquier fetch en vuelo. */
        if (pfFetchController) {
            try { pfFetchController.abort(); } catch(_){}
            pfFetchController = null;
        }
        /* Reset completo del estado de letra previa. */
        pfLyrLines = null;
        pfLyrVid = null;
        pfLyrCurIdx = -2;
        pfLineCounter = 0;
        pfMultiState = null;
        pfFastStackCounter = 0;
        pfFastStackStartedAt = 0;
        pfRepeatLineGroup = null;
        pfLineStyleCache = {};  /* cada canción tiene su propio cache */
        root.classList.remove('pf-lyr-blurred');
        /* DOM cleanup INSTANTÁNEO (sin animación). */
        pfQuickClearStage();
        /* Loading dots como feedback visual mientras se debouncea. */
        pfFlashLoading('', 60000);

        /* === FASE 2 debounced: fetch real tras 1.5s === */

        pfReloadDebounceTimer = setTimeout(() => {
            pfReloadDebounceTimer = null;
            if (!pfLyricsOpen) return;
            const tr = pfCurTrack();
            pfLog('reloadForTrack (debounced): ' + (tr ? (tr.title + ' / ' + tr.videoId) : 'no-track'));
            if (!tr || !tr.videoId) {
                /* Sin track activo → solo quita el loading. */
                const st = lyrStage && lyrStage.querySelector('.pf-lyr-status');
                if (st) st.remove();
                return;
            }
            /* Captura videoId — el .then() compara contra esto para
               descartar callbacks stale si el usuario salta otra vez
               durante el fetch. */
            const reloadFor = tr.videoId;
            pfFetchForCurrent().then(() => {
                if (!pfLyricsOpen) return;
                /* Guard stale callback: si saltaron a otra canción mientras
                   el fetch estaba en vuelo, este callback es de la vieja —
                   un reload nuevo está en camino, no spameamos el toast. */
                const tNow = pfCurTrack();
                if (!tNow || tNow.videoId !== reloadFor) {
                    pfLog('reloadForTrack.then: stale (track changed)');
                    return;
                }
                if (!pfLyrLines || !pfLyrLines.length) {
                    pfFlashStatus('🥲 Sin letra sincronizada para esta canción', 8000);
                } else {
                    /* No mostrar el toast "Now Playing" si ya hay una
                       línea visible (cache hit con el reproductor en
                       mitad de un verso). */
                    const lineVisible = lyrStage && lyrStage.querySelector('.pf-lyr-line-wrap:not(.pf-lyr-mode-aside)');
                    if (!lineVisible) {
                        pfFlashTrack(tNow.title || 'Canción', tNow.artist || '', 2800);
                    } else {
                        const st = lyrStage.querySelector('.pf-lyr-status');
                        if (st) st.remove();
                    }
                }
            });
        }, PF_RELOAD_DEBOUNCE_MS);
    }

    /* Hook a updateTrackUI: al cambiar de track con lyrics abiertas,
       dispara el reload completo. */
    const __origUpdateTrackUI2 = (typeof window.updateTrackUI === 'function') ? window.updateTrackUI : null;
    if (__origUpdateTrackUI2) {
        window.updateTrackUI = function(idx) {
            __origUpdateTrackUI2(idx);
            if (pfLyricsOpen) pfReloadForTrack();
        };
    }

    /* ── Right-click menu ──
       Mismo menú contextual que la playlist (Añadir a playlist /
       Añadir a perfil / Escuchar juntos), accesible con right-click
       en cualquier parte del player pequeño Y del fullscreen. */
    function getCurTrackForCtx() {
        if (typeof playlist !== 'undefined' && playlist.length && typeof currentTrack === 'number') {
            return playlist[currentTrack];
        }
        return null;
    }
    function attachCtxMenu(el) {
        if (!el) return;
        el.addEventListener('contextmenu', function(e) {
            const tr = getCurTrackForCtx();
            if (!tr) return;
            /* Evita que el evento siga burbujeando a contenedores con su
               propio handler de contextmenu (p.ej. #player-cover-wrap →
               #player-content) y mostrara el menú dos veces. */
            e.stopPropagation();
            if (typeof window.openTrackCtxMenu === 'function') {
                window.openTrackCtxMenu(e, tr);
            }
        });
    }
    /* Player pequeño: cualquier zona excepto los botones interactivos. */
    attachCtxMenu(document.getElementById('player-titlebar'));
    attachCtxMenu(document.getElementById('player-content'));
    attachCtxMenu(document.getElementById('player-cover-wrap'));
    /* Fullscreen: title-bar + body (que cubre el contenido entero del
       overlay). Los selectors antiguos .pf-display y .pf-info ya no
       existían en el HTML actualizado, por eso el menú no aparecía. */
    attachCtxMenu(document.querySelector('#player-full .pf-titlebar'));
    attachCtxMenu(document.querySelector('#player-full .pf-body'));
    attachCtxMenu(document.querySelector('#player-full .pf-lcd'));
    attachCtxMenu(document.querySelector('#player-full .pf-vinyl-floating'));
})();
<?php endif; ?>
</script>
