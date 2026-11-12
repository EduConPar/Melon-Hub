#!/bin/sh
# Lanzador dentro del Flatpak. zypak-wrapper (de la base de Electron) hace
# que el sandbox de Chromium funcione dentro del sandbox de Flatpak.
# X11 (XWayland en escritorios Wayland): en Wayland una app no puede
# quedarse siempre encima ni elegir su posición, y el reproductor flotante
# (mini-player.js) necesita las dos cosas. Si no hay X11 (sin $DISPLAY),
# se abre en Wayland antes que no abrirse: el flotante funciona igual,
# pero sin quedarse encima.
export TMPDIR="$XDG_RUNTIME_DIR/app/${FLATPAK_ID:-es.melonhub.MelonHub}"
if [ -n "$DISPLAY" ]; then
    PLATFORM=--ozone-platform=x11
else
    PLATFORM=--ozone-platform-hint=auto
fi
exec zypak-wrapper /app/melonhub/melonhub "$PLATFORM" "$@"
