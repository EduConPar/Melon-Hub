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
./build.sh             # dist/MelonHub-Setup.exe, dist/MelonHub.flatpak, dist/MelonHub.AppImage
./build.sh --publish   # además los sube a la release de GitHub (necesita gh con sesión)
```

La primera vez tarda (descarga Wine y los runtimes de Flathub); después reutiliza la caché.

La web descarga los instalables desde GitHub Releases con nombres fijos (`assets/desktop/downloads.php`), así que publicar una versión nueva no requiere tocar la web. Al subir de versión, cambia `version` en `package.json` y la `<release>` de `flatpak/es.melonhub.MelonHub.metainfo.xml`.

## Entornos

La build de la rama `dev` apunta a https://melonhubdev.melonhub.es/ y se publica en la release `escritorio-dev`. Para producción hará falta una build con la URL de `melonhub.es` y una release propia, configurada en `assets/desktop/downloads.php`.
