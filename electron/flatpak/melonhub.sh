#!/bin/sh
# Lanzador dentro del Flatpak. zypak-wrapper (de la base de Electron) hace
# que el sandbox de Chromium funcione dentro del sandbox de Flatpak.
# X11 (XWayland en escritorios Wayland): en Wayland una app no puede
# quedarse siempre encima ni elegir su posición, y el reproductor flotante
# (mini-player.js) necesita las dos cosas.
export TMPDIR="$XDG_RUNTIME_DIR/app/${FLATPAK_ID:-es.melonhub.MelonHub}"
exec zypak-wrapper /app/melonhub/melonhub --ozone-platform=x11 "$@"
