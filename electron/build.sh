#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────
# Construye los instalables de la app de escritorio dentro de contenedores
# (en el equipo solo hace falta podman; ni Node, ni Wine, ni flatpak-builder):
#   dist/MelonHub-Setup.exe   Windows (instalador NSIS)
#   dist/MelonHub.flatpak     Linux (la descarga por defecto)
#   dist/MelonHub.AppImage    Linux, alternativa sin instalar
#
#   ./build.sh              solo construye
#   ./build.sh --publish    además los sube a la release de GitHub de la que
#                           tira assets/desktop/downloads.php (necesita gh)
#
# Los nombres de los archivos no cambian entre versiones: así el enlace de
# descarga de la web es siempre el mismo.
# ──────────────────────────────────────────────────────────────────────
set -euo pipefail
cd "$(dirname "$0")"
export PATH="$HOME/.local/bin:$PATH"   # gh suele estar instalado aquí

RELEASE_TAG=escritorio-dev
ELECTRON_IMG=localhost/melonhub-electron-builder
FLATPAK_IMG=localhost/melonhub-flatpak-builder
FLATHUB=https://dl.flathub.org/repo/flathub.flatpakrepo

# Las imágenes se reconstruyen siempre: podman reutiliza las capas que no
# han cambiado, así que solo tarda la primera vez o si cambia un Containerfile.
podman build -q -t "$ELECTRON_IMG" -f build/Containerfile.electron build
podman build -q -t "$FLATPAK_IMG"  -f build/Containerfile.flatpak  build
rm -rf dist

echo "── Windows + AppImage (electron-builder)"
podman run --rm -v "$PWD":/app:Z -w /app "$ELECTRON_IMG" bash -c '
    set -e
    npm ci --no-audit --no-fund
    npx electron-builder --linux AppImage --win nsis
'

echo "── Flatpak"
# --privileged: flatpak-builder aísla cada paso con bubblewrap, que necesita
# crear namespaces dentro del contenedor.
podman run --rm --privileged -v "$PWD":/app:Z -v melonhub-flatpak:/root/.local/share/flatpak -w /app "$FLATPAK_IMG" bash -c "
    set -e
    flatpak remote-add --user --if-not-exists flathub $FLATHUB
    flatpak-builder --user --install-deps-from=flathub --disable-rofiles-fuse --force-clean \
        --state-dir=.flatpak-builder --repo=.flatpak-repo .flatpak-build flatpak/es.melonhub.MelonHub.yml
    flatpak build-bundle --runtime-repo=$FLATHUB .flatpak-repo dist/MelonHub.flatpak es.melonhub.MelonHub
"

ls -lh dist/MelonHub-Setup.exe dist/MelonHub.flatpak dist/MelonHub.AppImage

if [[ "${1:-}" == "--publish" ]]; then
    echo "── Subiendo a la release $RELEASE_TAG"
    if ! gh release view "$RELEASE_TAG" >/dev/null 2>&1; then
        gh release create "$RELEASE_TAG" --prerelease --target dev \
            --title "Melon Hub escritorio (dev)" \
            --notes "Instalables de la app de escritorio que apuntan a la web de dev (melonhubdev.melonhub.es). Se sustituyen en cada build."
    fi
    gh release upload "$RELEASE_TAG" dist/MelonHub-Setup.exe dist/MelonHub.flatpak dist/MelonHub.AppImage --clobber
fi
