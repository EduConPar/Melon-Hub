/* ──────────────────────────────────────────────────────────────────────
   Auto-actualización dentro de Flatpak (la usa updater.js)
   ──────────────────────────────────────────────────────────────────────
   Una app Flatpak no puede reemplazar sus propios archivos, pero el
   portal de Flatpak (org.freedesktop.portal.Flatpak, viene con Flatpak)
   le deja pedir "actualízame" sin permisos extra:
     1. CreateUpdateMonitor → objeto UpdateMonitor.
     2. UpdateMonitor.Update → instala la versión nueva desde el
        repositorio de origen de la app (el .flatpak lleva su dirección:
        build.sh --repo-url) e informa con señales Progress.
     3. flatpak-spawn --latest-version → arranca la versión nueva en un
        sandbox nuevo; esta instancia sigue siendo la vieja hasta cerrarse.
   El portal se niega si la versión nueva pide permisos nuevos (finish-args
   del manifiesto): en ese caso hay que actualizar con `flatpak update`.

   No depende de Electron para poder probarlo con node a secas.
   ────────────────────────────────────────────────────────────────────── */
const { spawn } = require('child_process');

const PORTAL      = 'org.freedesktop.portal.Flatpak';
const PORTAL_PATH = '/org/freedesktop/portal/Flatpak';

/* Valores de "status" en la señal Progress del UpdateMonitor. */
const RUNNING = 0, EMPTY = 1, DONE = 2, FAILED = 3;

function isFlatpak() {
    return !!process.env.FLATPAK_ID;
}

/* Instala la versión nueva. onProgress(0-100) mientras se descarga.
   Resuelve con la app ya actualizada en disco; rechaza con el motivo si
   falla o si el repositorio no tenía nada nuevo. */
async function update(onProgress) {
    const dbus = require('dbus-next');
    const bus = dbus.sessionBus();
    try {
        const portal = (await bus.getProxyObject(PORTAL, PORTAL_PATH)).getInterface(PORTAL);
        const monitorPath = await portal.CreateUpdateMonitor({});
        const monitor = (await bus.getProxyObject(PORTAL, monitorPath)).getInterface(PORTAL + '.UpdateMonitor');
        await new Promise((resolve, reject) => {
            /* La suscripción viaja por el bus antes que la llamada a
               Update, así que no se pierde ninguna señal. */
            monitor.on('Progress', (info) => {
                const val = (key) => (info[key] ? info[key].value : undefined);
                switch (val('status')) {
                    case RUNNING: if (onProgress) onProgress(val('progress') || 0); break;
                    case DONE:    resolve(); break;
                    case EMPTY:   reject(new Error('El repositorio no tiene ninguna versión nueva.')); break;
                    case FAILED:  reject(new Error(val('error_message') || val('error') || 'Error desconocido')); break;
                }
            });
            monitor.Update('', {}).catch(reject);
        });
        try { await monitor.Close(); } catch (_) { /* da igual */ }
    } finally {
        bus.disconnect();
    }
}

/* Arranca la versión recién instalada. La pausa deja que esta instancia
   termine de cerrarse antes de que la nueva abra el mismo perfil. */
function relaunchLatest() {
    const env = ['DISPLAY', 'WAYLAND_DISPLAY', 'XDG_SESSION_TYPE']
        .filter((name) => process.env[name])
        .map((name) => `--env=${name}=${process.env[name]}`);
    const child = spawn('flatpak-spawn', [...env, '--latest-version', 'sh', '-c', 'sleep 2; exec melonhub'], {
        detached: true,
        stdio: 'ignore',
    });
    child.unref();
}

module.exports = { isFlatpak, update, relaunchLatest };
