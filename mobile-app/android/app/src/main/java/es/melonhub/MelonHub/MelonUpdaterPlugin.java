package es.melonhub.MelonHub;

import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import androidx.core.content.FileProvider;
import androidx.core.content.pm.PackageInfoCompat;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import org.json.JSONObject;

/* Actualizar la app sin pasar por la web: la ventana "Hay una versión
   nueva" de mobile.php llama a
     Capacitor.nativePromise('MelonUpdater', 'check')   → { available, versionCode, versionName }
     Capacitor.nativePromise('MelonUpdater', 'install') → { status: 'installing' | 'denied' }
   La última versión la dice version.json ({"versionCode": 2, "versionName": "1.1"}),
   subido a la release junto a MelonHub.apk. El APK se instala encima
   (misma clave, versionCode mayor), así que no se pierden datos; Android
   siempre pide confirmar y, la primera vez, el permiso de "instalar apps
   desconocidas" para Melon Hub. Progreso de la descarga: evento de
   window "melonUpdateProgress" con { percent }. */
@CapacitorPlugin(name = "MelonUpdater")
public class MelonUpdaterPlugin extends Plugin {

    /* Build de dev (apunta a melonhubdev) → release movil-dev, igual que
       assets/mobile-downloads.php. Producción aún sin configurar. */
    private static final String RELEASE_URL = "https://github.com/EduConPar/Scrapbook-Online/releases/download/movil-dev/";

    /* install() esperando a que el usuario vuelva de dar el permiso. */
    private PluginCall pendingInstall;

    @PluginMethod
    public void check(PluginCall call) {
        new Thread(() -> {
            try {
                JSONObject latest = new JSONObject(new String(download(RELEASE_URL + "version.json"), "UTF-8"));
                long latestCode = latest.optLong("versionCode", 0);
                JSObject res = new JSObject();
                res.put("available", latestCode > currentVersionCode());
                res.put("versionCode", latestCode);
                res.put("versionName", latest.optString("versionName", ""));
                res.put("currentVersionCode", currentVersionCode());
                call.resolve(res);
            } catch (Exception e) {
                call.reject("No se pudo comprobar la versión", e);
            }
        }).start();
    }

    @PluginMethod
    public void install(PluginCall call) {
        new Thread(() -> {
            try {
                downloadApk();
            } catch (Exception e) {
                call.reject("No se pudo descargar la actualización", e);
                return;
            }
            getActivity().runOnUiThread(() -> {
                if (canInstall()) {
                    launchInstaller(call);
                } else {
                    /* Pedir el permiso y seguir en handleOnResume. */
                    pendingInstall = call;
                    Intent perm = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getContext().getPackageName()));
                    getActivity().startActivity(perm);
                }
            });
        }).start();
    }

    @Override
    protected void handleOnResume() {
        super.handleOnResume();
        if (pendingInstall == null) return;
        PluginCall call = pendingInstall;
        pendingInstall = null;
        if (canInstall()) {
            launchInstaller(call);
        } else {
            JSObject res = new JSObject();
            res.put("status", "denied");
            call.resolve(res);
        }
    }

    private boolean canInstall() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O || getContext().getPackageManager().canRequestPackageInstalls();
    }

    private void launchInstaller(PluginCall call) {
        Uri uri = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", apkFile());
        Intent install = new Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
        getActivity().startActivity(install);
        JSObject res = new JSObject();
        res.put("status", "installing");
        call.resolve(res);
    }

    private File apkFile() {
        File dir = new File(getContext().getCacheDir(), "updates");
        dir.mkdirs();
        return new File(dir, "MelonHub.apk");
    }

    private long currentVersionCode() {
        try {
            PackageInfo info = getContext().getPackageManager().getPackageInfo(getContext().getPackageName(), 0);
            return PackageInfoCompat.getLongVersionCode(info);
        } catch (PackageManager.NameNotFoundException e) {
            return 0;
        }
    }

    private void downloadApk() throws Exception {
        HttpURLConnection conn = open(RELEASE_URL + "MelonHub.apk");
        long total = conn.getContentLengthLong();
        File tmp = new File(apkFile().getPath() + ".part");
        try (InputStream in = conn.getInputStream(); OutputStream out = new FileOutputStream(tmp)) {
            byte[] buf = new byte[64 * 1024];
            long done = 0;
            int lastPercent = -1;
            int n;
            while ((n = in.read(buf)) > 0) {
                out.write(buf, 0, n);
                done += n;
                int percent = total > 0 ? (int) (done * 100 / total) : -1;
                if (percent != lastPercent) {
                    lastPercent = percent;
                    getBridge().triggerWindowJSEvent("melonUpdateProgress", "{\"percent\":" + percent + "}");
                }
            }
        } finally {
            conn.disconnect();
        }
        if (!tmp.renameTo(apkFile())) throw new Exception("No se pudo guardar el APK");
    }

    private static byte[] download(String url) throws Exception {
        HttpURLConnection conn = open(url);
        try (InputStream in = conn.getInputStream()) {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
            return out.toByteArray();
        } finally {
            conn.disconnect();
        }
    }

    /* GitHub redirige las descargas a otro dominio (https → https: lo
       sigue HttpURLConnection). Sin caché: version.json cambia. */
    private static HttpURLConnection open(String url) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setInstanceFollowRedirects(true);
        conn.setUseCaches(false);
        conn.setConnectTimeout(15000);
        conn.setReadTimeout(30000);
        int code = conn.getResponseCode();
        if (code != HttpURLConnection.HTTP_OK) {
            conn.disconnect();
            throw new Exception("HTTP " + code + " en " + url);
        }
        return conn;
    }
}
