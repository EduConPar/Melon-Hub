# Melon Hub — app de escritorio

App de Electron que abre la web desde el servidor (la URL está en `package.json` → `melonhub.url`). El PHP no cambia: Electron entra como un Chrome de escritorio.

## Probar en local

Con Node instalado:

```bash
cd electron
npm install
npm start                                            # abre la URL de package.json
MELONHUB_URL=https://melonhub.es/ npm start          # contra otra web
```

F12 abre las herramientas de desarrollador; F5 o Ctrl+R recarga.

## Construir y publicar los instalables

Solo hace falta podman (Node, Wine y flatpak-builder van dentro de contenedores):

```bash
./build.sh                 # dist/MelonHub-Setup.exe, dist/MelonHub.flatpak, dist/MelonHub.AppImage
./build.sh --publish       # construye y publica (necesita gh con sesión)
./build.sh --publish-only  # publica lo ya construido
```

La primera vez tarda (descarga Wine y los runtimes de Flathub); después reutiliza la caché.

Publicar sube:
- los instalables y los `latest*.yml` a la release `escritorio-dev` de GitHub, de donde los descarga la web (`assets/desktop/downloads.php`) con nombres fijos;
- el repositorio Flatpak (`.flatpak-repo/`) a GitHub Pages: https://educonpar.github.io/melonhub-flatpak/ (repo `EduConPar/melonhub-flatpak`, se crea solo la primera vez).

## Versión nueva

1. Sube `version` en `package.json` y añade la `<release>` en `flatpak/es.melonhub.MelonHub.metainfo.xml`.
2. `./build.sh --publish`.

Las apps instaladas lo detectan al arrancar (o cada 6 h) y el haro ofrece "Actualizar" (`updater.js`):
- **Windows / AppImage**: electron-updater descarga la versión nueva de la release, la instala y reabre la app.
- **Flatpak**: el portal de Flatpak la actualiza desde el repositorio de GitHub Pages y la app se reabre en la versión nueva. La primera vez el sistema pregunta "¿Actualizar Melon Hub?"; después ya no. Si la versión nueva necesita permisos nuevos (`finish-args` del manifiesto), el portal no la instala: hay que hacerlo con `flatpak update`.

La web (PHP/JS/CSS) no necesita nada de esto: la app la carga del servidor y siempre está al día.

## Clave del repositorio Flatpak

El repositorio se firma con una clave GPG que `build.sh` crea la primera vez en `~/.local/share/melonhub-flatpak/gnupg` (fuera del repo: es privada). **Haz copia de esa carpeta**: si se pierde, los Flatpak instalados no aceptarán versiones nuevas y habría que reinstalarlos.

## Entornos

La build de la rama `dev` apunta a https://melonhubdev.melonhub.es/, se publica en la release `escritorio-dev` y en la rama Flatpak `dev`. Para producción hará falta una build con la URL de `melonhub.es`, su propia release y la rama Flatpak `stable`, configuradas en `build.sh`, `package.json` y `assets/desktop/downloads.php`.

El archivo más grande del repositorio Flatpak (el binario de Electron comprimido, ~93 MB) roza el límite de 100 MB por archivo de GitHub; `build.sh` se para antes de publicar si algún archivo pasa de 95 MB.
