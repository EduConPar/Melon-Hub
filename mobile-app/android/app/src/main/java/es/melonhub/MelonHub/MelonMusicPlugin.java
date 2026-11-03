package es.melonhub.MelonHub;

import android.app.Activity;
import android.content.Intent;
import android.util.Log;
import androidx.core.content.ContextCompat;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import org.json.JSONObject;

/* La web (mobile.php) avisa de cuándo suena y cuándo se pausa:
     Capacitor.nativePromise('MelonMusic', 'setPlaying', { playing, title, artist, videoId })
   Mientras suena: servicio en primer plano + WebView "visible", para que
   ni salir de la app ni apagar la pantalla corten la música.

   También es el camino de vuelta del widget (MusicWidget): sus botones
   llegan a la web como el evento "widgetCommand"
     { action: 'toggle' | 'prev' | 'next' }
     { action: 'play-playlist', name, tracks, index }
   y lo que suena se le pasa al widget para que lo pinte. */
@CapacitorPlugin(name = "MelonMusic")
public class MelonMusicPlugin extends Plugin {

    /* El plugin de la app en marcha (null si Android la cerró): sin él no
       hay web a la que mandar órdenes. */
    private static MelonMusicPlugin instance;

    /* Orden del widget que abrió la app: se entrega al cargar el plugin. */
    static JSONObject pendingWidgetCommand;

    @Override
    public void load() {
        instance = this;
        if (pendingWidgetCommand != null) {
            deliver(pendingWidgetCommand);
            pendingWidgetCommand = null;
        }
    }

    @Override
    protected void handleOnDestroy() {
        if (instance == this) instance = null;
        MusicWidget.refresh(getContext());
    }

    static boolean isAlive() {
        return instance != null;
    }

    /* false si la app no está en marcha y la orden no llega a nadie. */
    static boolean sendWidgetCommand(JSONObject cmd) {
        MelonMusicPlugin p = instance;
        if (p == null) return false;
        p.deliver(cmd);
        return true;
    }

    private void deliver(JSONObject cmd) {
        /* Con la app en segundo plano y la música en pausa, la página está
           "oculta" y Chromium no deja arrancar el vídeo: se pone visible
           antes, como cuando suena. Al pausar, setPlaying(false) lo suelta. */
        getActivity().runOnUiThread(() -> {
            if (getBridge().getWebView() instanceof MusicWebView) {
                ((MusicWebView) getBridge().getWebView()).setKeepVisible(true);
            }
        });
        try {
            /* Se guarda hasta que la página ponga el listener (si la app
               se acaba de abrir, aún está cargando). */
            notifyListeners("widgetCommand", JSObject.fromJSONObject(cmd), true);
        } catch (Exception e) {
            Log.w("MelonMusic", "Orden del widget no válida", e);
        }
    }

    @PluginMethod
    public void setPlaying(PluginCall call) {
        boolean playing = call.getBoolean("playing", false);
        Intent svc = new Intent(getContext(), MusicService.class);

        if (playing) {
            svc.putExtra(MusicService.EXTRA_TITLE, call.getString("title", ""));
            svc.putExtra(MusicService.EXTRA_ARTIST, call.getString("artist", ""));
            try {
                ContextCompat.startForegroundService(getContext(), svc);
            } catch (Exception e) {
                /* Android 12+ no deja arrancarlo desde segundo plano (p. ej.
                   si la canción siguiente empieza con la app ya oculta y el
                   servicio se había parado). Si ya estaba en marcha, sigue. */
                Log.w("MelonMusic", "No se pudo arrancar el servicio", e);
            }
        } else {
            getContext().stopService(svc);
        }

        WidgetStore.setNowPlaying(getContext(), playing, call.getString("title", ""), call.getString("artist", ""), call.getString("videoId", ""));
        MusicWidget.refresh(getContext());

        getActivity().runOnUiThread(() -> {
            if (getBridge().getWebView() instanceof MusicWebView) {
                ((MusicWebView) getBridge().getWebView()).setKeepVisible(playing);
            }
            /* Si la app la abrió el widget, vuelve al fondo al sonar. */
            Activity a = getActivity();
            if (playing && a instanceof MainActivity && ((MainActivity) a).takeSendToBack()) {
                a.moveTaskToBack(true);
            }
        });
        call.resolve();
    }
}
