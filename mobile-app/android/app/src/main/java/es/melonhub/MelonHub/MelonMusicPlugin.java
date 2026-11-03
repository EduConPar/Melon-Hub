package es.melonhub.MelonHub;

import android.app.Activity;
import android.content.Intent;
import android.os.Handler;
import android.os.Looper;
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
     { action: 'toggle' | 'prev' | 'next' | 'shuffle' }
     { action: 'set-shuffle', on }   (🔀 tocado con la app cerrada)
     { action: 'play-playlist', name, tracks, index }
   y la web le cuenta al widget qué suena y con qué colores pintarse:
     setWidgetState({ playing, title, artist, videoId, position, duration, shuffle })
     setWidgetTheme({ interface, colors: { 'win-bg': '#…', … } }) */
@CapacitorPlugin(name = "MelonMusic")
public class MelonMusicPlugin extends Plugin {

    /* El plugin de la app en marcha (null si Android la cerró): sin él no
       hay web a la que mandar órdenes. */
    private static MelonMusicPlugin instance;

    /* Orden del widget que abrió la app: se entrega al cargar el plugin. */
    static JSONObject pendingWidgetCommand;

    /* Avanza la barra de progreso del widget mientras suena. */
    private static final long PROGRESS_EVERY_MS = 5000;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable progressTick = new Runnable() {
        @Override
        public void run() {
            WidgetStore.savePosition(getContext());
            MusicWidget.refreshProgress(getContext());
            handler.postDelayed(this, PROGRESS_EVERY_MS);
        }
    };

    @Override
    public void load() {
        instance = this;
        /* Primero el 🔀 tocado con la app cerrada, luego la orden que la
           abrió (si fue ⏯, tiene que sonar ya con el aleatorio puesto). */
        Boolean shuffle = WidgetStore.takePendingShuffle(getContext());
        if (shuffle != null) {
            JSObject cmd = new JSObject();
            cmd.put("action", "set-shuffle");
            cmd.put("on", shuffle);
            deliver(cmd);
        }
        if (pendingWidgetCommand != null) {
            deliver(pendingWidgetCommand);
            pendingWidgetCommand = null;
        }
    }

    @Override
    protected void handleOnDestroy() {
        if (instance == this) instance = null;
        handler.removeCallbacks(progressTick);
        WidgetStore.savePosition(getContext());
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
        String action = cmd.optString("action");
        if (!action.equals("shuffle") && !action.equals("set-shuffle")) {
            getActivity().runOnUiThread(() -> {
                if (getBridge().getWebView() instanceof MusicWebView) {
                    ((MusicWebView) getBridge().getWebView()).setKeepVisible(true);
                }
            });
        }
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

    @PluginMethod
    public void setWidgetState(PluginCall call) {
        boolean playing = call.getBoolean("playing", false);
        WidgetStore.setState(getContext(), playing,
            call.getString("title", ""), call.getString("artist", ""), call.getString("videoId", ""),
            call.getDouble("position", 0.0), call.getDouble("duration", 0.0), call.getBoolean("shuffle", false));
        MusicWidget.refresh(getContext());
        handler.removeCallbacks(progressTick);
        if (playing) handler.postDelayed(progressTick, PROGRESS_EVERY_MS);
        call.resolve();
    }

    @PluginMethod
    public void setWidgetTheme(PluginCall call) {
        WidgetStore.setTheme(getContext(), call.getData());
        MusicWidget.refresh(getContext());
        call.resolve();
    }
}
