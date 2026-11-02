#!/bin/sh
# Lanzador dentro del Flatpak. zypak-wrapper (de la base de Electron) hace
# que el sandbox de Chromium funcione dentro del sandbox de Flatpak.
export TMPDIR="$XDG_RUNTIME_DIR/app/${FLATPAK_ID:-es.melonhub.MelonHub}"
exec zypak-wrapper /app/melonhub/melonhub --ozone-platform-hint=auto "$@"
