#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────
# Construye los instalables de la app de escritorio dentro de contenedores
# (en el equipo solo hace falta podman; ni Node, ni Wine, ni flatpak-builder):
#   dist/MelonHub-Setup.exe   Windows (instalador NSIS) + latest.yml
#   dist/MelonHub.flatpak     Linux (la descarga por defecto)
#   dist/MelonHub.AppImage    Linux, alternativa sin instalar + latest-linux.yml
#   .flatpak-repo/            repositorio Flatpak firmado del que se actualiza
#                             el .flatpak instalado
#
#   ./build.sh                 solo construye
#   ./build.sh --publish       construye y publica (necesita gh con sesión):
#                                - .flatpak-repo → GitHub Pages ($PAGES_REPO)
#                                - instalables y latest*.yml → release de GitHub
#   ./build.sh --publish-only  publica lo ya construido, sin reconstruir
#
# Dos entornos, cada uno con su web, su release y su rama Flatpak (el
# repositorio Flatpak es el mismo y lleva las dos ramas):
#   dev  (por defecto)  melonhubdev.melonhub.es  release escritorio-dev  rama dev
#   --prod              melonhub.es              release escritorio      rama stable
#   ./build.sh --prod --publish
# La web y la dirección de las actualizaciones se meten en el package.json
# durante la build (y se deja como estaba): el package.json del repo es el
# de dev, el que usa `npm start`.
#
# Los nombres de los archivos no cambian entre versiones: así el enlace de
# descarga de la web y las URLs de actualización son siempre las mismas.
# ──────────────────────────────────────────────────────────────────────
set -euo pipefail
cd "$(dirname "$0")"
export PATH="$HOME/.local/bin:$PATH"   # gh suele estar instalado aquí

ENTORNO=dev
if [[ "${1:-}" == "--prod" ]]; then ENTORNO=prod; shift; fi
if [[ "$ENTORNO" == prod ]]; then
    RELEASE_TAG=escritorio
    FLATPAK_BRANCH=stable
    APP_URL=https://melonhub.es/
    GIT_TARGET=main
else
    RELEASE_TAG=escritorio-dev
    FLATPAK_BRANCH=dev
    APP_URL=https://melonhubdev.melonhub.es/
    GIT_TARGET=dev
fi
UPDATE_FEED=https://github.com/EduConPar/Scrapbook-Online/releases/download/$RELEASE_TAG/
PAGES_REPO=EduConPar/melonhub-flatpak
FLATPAK_REPO_URL=https://educonpar.github.io/melonhub-flatpak/
FLATHUB=https://dl.flathub.org/repo/flathub.flatpakrepo
# Clave privada con la que se firma el repositorio Flatpak. Vive fuera del
# repo de git: si se pierde, los Flatpak instalados no aceptarán versiones
# nuevas y habría que reinstalarlos. Haz copia de esta carpeta.
GPG_HOME="$HOME/.local/share/melonhub-flatpak/gnupg"

ELECTRON_IMG=localhost/melonhub-electron-builder
FLATPAK_IMG=localhost/melonhub-flatpak-builder
VERSION=$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json)
MODE="${1:-}"

if [[ "$MODE" != "--publish-only" ]]; then
# Las imágenes se reconstruyen siempre: podman reutiliza las capas que no
# han cambiado, así que solo tarda la primera vez o si cambia un Containerfile.
podman build -q -t "$ELECTRON_IMG" -f build/Containerfile.electron build
podman build -q -t "$FLATPAK_IMG"  -f build/Containerfile.flatpak  build
rm -rf dist
mkdir -p -m 700 "$GPG_HOME"

echo "── Melon Hub $VERSION ($ENTORNO → $APP_URL): Windows + AppImage (electron-builder)"
# melonhub.url (la web que abre), melonhub.updateFeed (updater.js) y
# build.publish (app-update.yml de electron-updater) según el entorno; al
# terminar, aunque falle, el package.json vuelve a ser el de antes.
podman run --rm -v "$PWD":/app:Z -w /app -e APP_URL="$APP_URL" -e UPDATE_FEED="$UPDATE_FEED" "$ELECTRON_IMG" bash -c '
    set -e
    cp package.json /tmp/package.json.orig
    trap "cp /tmp/package.json.orig package.json" EXIT
    node -e "
        const fs = require(\"fs\");
        const p = JSON.parse(fs.readFileSync(\"package.json\", \"utf8\"));
        p.melonhub.url = process.env.APP_URL;
        p.melonhub.updateFeed = process.env.UPDATE_FEED;
        p.build.publish = [{ provider: \"generic\", url: process.env.UPDATE_FEED }];
        fs.writeFileSync(\"package.json\", JSON.stringify(p, null, 2) + \"\\n\");
    "
    npm ci --omit=optional --no-audit --no-fund
    npx electron-builder --linux AppImage --win nsis --publish never
    grep -q "\"url\": \"$APP_URL\"" dist/linux-unpacked/resources/app.asar || { echo "La build no lleva $APP_URL" >&2; exit 1; }
'

echo "── Flatpak"
# --privileged: flatpak-builder aísla cada paso con bubblewrap, que necesita
# crear namespaces dentro del contenedor.
podman run --rm --privileged -v "$PWD":/app:Z -v "$GPG_HOME":/gpg:Z \
    -v melonhub-flatpak:/root/.local/share/flatpak -w /app "$FLATPAK_IMG" bash -c "
    set -e
    export GNUPGHOME=/gpg
    if ! gpg --list-secret-keys --with-colons 2>/dev/null | grep -q '^sec'; then
        echo 'Creando la clave GPG del repositorio Flatpak…'
        gpg --batch --passphrase '' --quick-gen-key 'Melon Hub Flatpak <flatpak@melonhub.es>' rsa4096 sign never
    fi
    KEY=\$(gpg --list-secret-keys --with-colons | awk -F: '/^fpr/ { print \$10; exit }')
    gpg --export \"\$KEY\" > /gpg/melonhub.gpg

    flatpak remote-add --user --if-not-exists flathub $FLATHUB
    flatpak-builder --user --install-deps-from=flathub --disable-rofiles-fuse --force-clean \
        --default-branch=$FLATPAK_BRANCH --gpg-sign=\"\$KEY\" --gpg-homedir=/gpg \
        --state-dir=.flatpak-builder --repo=.flatpak-repo .flatpak-build flatpak/es.melonhub.MelonHub.yml
    flatpak build-update-repo --title='Melon Hub' --default-branch=$FLATPAK_BRANCH \
        --gpg-sign=\"\$KEY\" --gpg-homedir=/gpg --prune --prune-depth=3 .flatpak-repo
    cp /gpg/melonhub.gpg .flatpak-repo/melonhub.gpg
    # --repo-url y --gpg-keys: el Flatpak instalado desde este archivo sabe
    # de dónde actualizarse (flatpak update, o el botón del haro).
    flatpak build-bundle --repo-url=$FLATPAK_REPO_URL --gpg-keys=/gpg/melonhub.gpg --runtime-repo=$FLATHUB \
        .flatpak-repo dist/MelonHub.flatpak es.melonhub.MelonHub $FLATPAK_BRANCH
"
fi

ls -lh dist/MelonHub-Setup.exe dist/latest.yml dist/MelonHub.flatpak dist/MelonHub.AppImage dist/latest-linux.yml

if [[ "$MODE" == "--publish" || "$MODE" == "--publish-only" ]]; then
    # GitHub rechaza archivos de más de 100 MB en git.
    if find .flatpak-repo -type f -size +95M | grep -q .; then
        echo "Hay archivos de más de 95 MB en .flatpak-repo: GitHub Pages no los aceptaría." >&2
        exit 1
    fi

    echo "── Repositorio Flatpak → $FLATPAK_REPO_URL"
    gh repo view "$PAGES_REPO" >/dev/null 2>&1 || gh repo create "$PAGES_REPO" --public \
        --description "Repositorio Flatpak de la app de escritorio de Melon Hub (lo genera electron/build.sh)"
    # Un solo commit que sustituye al anterior: el historial no crece.
    tmp=$(mktemp -d)
    cp -a .flatpak-repo/. "$tmp"/
    rm -rf "$tmp/tmp" "$tmp/.lock"
    touch "$tmp/.nojekyll"
    git -C "$tmp" init -q -b gh-pages
    git -C "$tmp" add -A
    git -C "$tmp" commit -q -m "Melon Hub $VERSION"
    git -C "$tmp" push -q -f "https://github.com/$PAGES_REPO.git" gh-pages
    pages_commit=$(git -C "$tmp" rev-parse HEAD)
    rm -rf "$tmp"
    # GitHub suele activar Pages él solo al recibir la rama gh-pages (y
    # entonces el POST responde "ya está activado"): nada de eso debe parar
    # la publicación antes de subir la release.
    gh api "repos/$PAGES_REPO/pages" >/dev/null 2>&1 \
        || gh api -X POST "repos/$PAGES_REPO/pages" -f "source[branch]=gh-pages" -f "source[path]=/" >/dev/null 2>&1 \
        || echo "Aviso: no se pudo activar GitHub Pages; revísalo en https://github.com/$PAGES_REPO/settings/pages" >&2
    # Las apps abiertas miran cada pocos minutos si hay versión nueva: el
    # aviso no puede salir antes de que Pages sirva el repositorio, o
    # "Actualizar" no encontraría nada. Se espera a que publique este commit.
    echo "── Esperando a que GitHub Pages publique el repositorio…"
    pages_ok=
    for _ in $(seq 72); do
        st=$(gh api "repos/$PAGES_REPO/pages/builds/latest" --jq '.commit + " " + .status' 2>/dev/null || true)
        if [[ "$st" == "$pages_commit built" ]]; then pages_ok=1; break; fi
        if [[ "$st" == "$pages_commit errored" ]]; then echo "GitHub Pages no ha podido publicar el repositorio." >&2; exit 1; fi
        sleep 5
    done
    [[ -n "$pages_ok" ]] || echo "Aviso: GitHub Pages aún no ha terminado (6 min); se sigue igualmente." >&2

    # Va después del repositorio Flatpak: latest-linux.yml es lo que hace
    # saltar el aviso de actualización, y para entonces todo debe estar subido.
    echo "── Release $RELEASE_TAG"
    if ! gh release view "$RELEASE_TAG" >/dev/null 2>&1; then
        if [[ "$ENTORNO" == prod ]]; then
            gh release create "$RELEASE_TAG" --target "$GIT_TARGET" \
                --title "Melon Hub escritorio" \
                --notes "Instalables de la app de escritorio de Melon Hub (melonhub.es). Se sustituyen en cada build."
        else
            gh release create "$RELEASE_TAG" --prerelease --target "$GIT_TARGET" \
                --title "Melon Hub escritorio (dev)" \
                --notes "Instalables de la app de escritorio que apuntan a la web de dev (melonhubdev.melonhub.es). Se sustituyen en cada build."
        fi
    fi
    gh release upload "$RELEASE_TAG" --clobber \
        dist/MelonHub-Setup.exe dist/MelonHub-Setup.exe.blockmap \
        dist/MelonHub.AppImage dist/MelonHub.flatpak
    # Los latest*.yml, los últimos y aparte: son los que hacen saltar el
    # aviso, y para entonces los instalables ya tienen que estar subidos.
    gh release upload "$RELEASE_TAG" --clobber dist/latest.yml dist/latest-linux.yml
fi
