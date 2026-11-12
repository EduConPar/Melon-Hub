package es.melonhub.MelonHub;

import android.content.Intent;
import android.content.res.Configuration;
import android.net.Uri;
import android.graphics.Color;
import android.os.Build;
import android.os.Bundle;
import android.view.View;
import android.view.Window;
import android.webkit.WebView;
import androidx.core.graphics.ColorUtils;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.JSObject;
import com.getcapacitor.WebViewListener;

public class MainActivity extends BridgeActivity {

    /* La abrió el widget para poner una canción (WidgetPlayActivity). */
    static final String EXTRA_FROM_WIDGET = "fromWidget";

    /* La abrió un widget de calendario: abre el calendario en ese día
       ("AAAA-MM-DD"; "" = sin día). */
    static final String EXTRA_OPEN_DAY = "openDay";

    /* La abrió un aviso push (MelonNotifs): URL a abrir y clave del aviso
       (si es un chat, se olvidan sus mensajes apilados). */
    static final String EXTRA_AVISO_URL = "avisoUrl";
    static final String EXTRA_AVISO_KEY = "avisoKey";

    /* La app está delante: los avisos push no se enseñan (AvisosService). */
    static volatile boolean enPrimerPlano = false;

    /* Último color de barras de la página (null hasta que cargue). */
    private Integer barsColor = null;

    /* Abierta desde el widget: al empezar a sonar se va al fondo. */
    private boolean sendToBack = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        /* Los plugins propios se registran antes de crear el puente. */
        registerPlugin(MelonMusicPlugin.class);
        registerPlugin(MelonUpdaterPlugin.class);
        sendToBack = getIntent().getBooleanExtra(EXTRA_FROM_WIDGET, false);
        openDayFromWidget(getIntent());
        openLink(getIntent());
        openAviso(getIntent());
        super.onCreate(savedInstanceState);
        fitInsideSystemBars();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        if (intent.getBooleanExtra(EXTRA_FROM_WIDGET, false)) sendToBack = true;
        openDayFromWidget(intent);
        openLink(intent);
        openAviso(intent);
    }

    /* Tocar un aviso push: la web abre su URL ("open-push" en mobile.php,
       igual que el sw:deep-link de la PWA). */
    private void openAviso(Intent intent) {
        String url = intent.getStringExtra(EXTRA_AVISO_URL);
        if (url == null) return;
        String key = intent.getStringExtra(EXTRA_AVISO_KEY);
        intent.removeExtra(EXTRA_AVISO_URL);
        if (key != null) MelonNotifs.olvidarChat(key);
        if (url.isEmpty()) return;
        JSObject cmd = new JSObject();
        cmd.put("action", "open-push");
        cmd.put("url", url);
        if (!MelonMusicPlugin.sendWidgetCommand(cmd)) MelonMusicPlugin.pendingWidgetCommand = cmd;
    }

    @Override
    public void onResume() {
        super.onResume();
        enPrimerPlano = true;
    }

    /* Enlaces compartidos: melonhub://<tipo>/<valor> o
       https://…/enlace.php?<tipo>=<valor>. Llegan a la web como la orden
       "open-link" (mOpenLink en mobile.php) con "<tipo>:<valor>", igual
       que las del widget; si la app se está abriendo, al cargar. El data
       se quita del intent para que Capacitor no intente cargar esa URL. */
    private void openLink(Intent intent) {
        if (intent == null || !Intent.ACTION_VIEW.equals(intent.getAction())) return;
        String link = parseLink(intent.getData());
        intent.setData(null);
        if (link == null) return;
        JSObject cmd = new JSObject();
        cmd.put("action", "open-link");
        cmd.put("link", link);
        if (!MelonMusicPlugin.sendWidgetCommand(cmd)) MelonMusicPlugin.pendingWidgetCommand = cmd;
    }

    /* Igual que melonParseLink (assets/links.php). */
    private static final String[][] LINK_TYPES = {
        { "perfil",   "[A-Za-z0-9_-]{1,50}" },
        { "escuchar", "[A-Za-z0-9_-]{1,50}" },
        { "cancion",  "[A-Za-z0-9_-]{11}" },
        { "album",    "[a-z]{2,12}:[A-Za-z0-9_-]{1,80}" },
        { "playlist", "[0-9]{1,10}" },
        { "torneo",   "[0-9]{1,10}" },        /* ajedrez (juegos-mobile.php) */
        { "reto",     "[a-z0-9]{10}" },
        { "tv",       "[a-f0-9]{24}" },       /* QR de emparejar la TV (tv.php) */
    };

    static String parseLink(Uri uri) {
        if (uri == null) return null;
        String scheme = uri.getScheme();
        try {
            if ("melonhub".equals(scheme)) {
                String path = uri.getPath() == null ? "" : uri.getPath().replaceAll("^/+|/+$", "");
                return checkLink(uri.getHost(), path);
            }
            if ("https".equals(scheme) || "http".equals(scheme)) {
                for (String[] t : LINK_TYPES) {
                    String v = uri.getQueryParameter(t[0]);
                    if (v != null) return checkLink(t[0], v);
                }
            }
        } catch (Exception ignored) { /* enlace raro */ }
        return null;
    }

    private static String checkLink(String type, String value) {
        if (type == null || value == null) return null;
        for (String[] t : LINK_TYPES) {
            if (t[0].equals(type)) return value.matches(t[1]) ? type + ":" + value : null;
        }
        return null;
    }

    /* La orden llega a la web como las del widget de música; si la app se
       está abriendo, se entrega al cargar el plugin. */
    private void openDayFromWidget(Intent intent) {
        String day = intent.getStringExtra(EXTRA_OPEN_DAY);
        if (day == null) return;
        intent.removeExtra(EXTRA_OPEN_DAY);
        JSObject cmd = new JSObject();
        cmd.put("action", "open-calendar");
        cmd.put("day", day);
        if (!MelonMusicPlugin.sendWidgetCommand(cmd)) MelonMusicPlugin.pendingWidgetCommand = cmd;
    }

    /* Una sola vez: si luego el usuario la abre, se queda delante. */
    boolean takeSendToBack() {
        boolean b = sendToBack;
        sendToBack = false;
        return b;
    }

    /* Al salir de la app los widgets se repintan: así cogen la interfaz
       (Win98 / Kawaii) si se ha cambiado, y los de calendario lo que se
       haya añadido. */
    @Override
    public void onPause() {
        super.onPause();
        enPrimerPlano = false;
        PlayerWidget.refresh(this);
        CalendarWidget.refreshFromApp(this);
    }

    /* La web va DENTRO de la barra de notificaciones y la de navegación,
       no debajo. Desde Android 15 las apps se pintan de borde a borde y,
       como las páginas llevan viewport-fit=cover, Capacitor las dejaba
       debajo de las barras. Aquí la ventana deja hueco para las barras
       (y el teclado) y la web recibe safe-area 0. Por eso en
       capacitor.config.json va SystemBars.insetsHandling = "disable":
       así no hay dos sitios tocando lo mismo. */
    private void fitInsideSystemBars() {
        Window window = getWindow();
        /* Igual en todas las versiones: borde a borde con barras
           transparentes; lo que se ve detrás es el fondo de la ventana. */
        WindowCompat.setDecorFitsSystemWindows(window, false);
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.VANILLA_ICE_CREAM) {
            window.setStatusBarColor(Color.TRANSPARENT);
            window.setNavigationBarColor(Color.TRANSPARENT);
        }

        View decor = window.getDecorView();
        ViewCompat.setOnApplyWindowInsetsListener(decor, (v, insets) -> {
            int types = WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout();
            Insets bars = insets.getInsets(types);
            Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
            v.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, ime.bottom));
            /* Insets a 0 en vez de CONSUMED: con CONSUMED el WebView no
               recalcula env(safe-area-inset-*) (lo mismo hace Capacitor). */
            return new WindowInsetsCompat.Builder(insets).setInsets(types, Insets.NONE).build();
        });

        /* Color de las barras = <meta name="theme-color"> de la página (el
           fondo del tema del usuario), como hace Chrome con la PWA. */
        getBridge().addWebViewListener(new WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                webView.evaluateJavascript(
                    "(function(){var m=document.querySelector('meta[name=\"theme-color\"]');return m?m.content:'';})()",
                    MainActivity.this::applyBarsColor
                );
            }
        });
    }

    /* El plugin SystemBars de Capacitor repinta el fondo de la ventana
       al cambiar la configuración (girar, modo oscuro): lo devolvemos. */
    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        if (barsColor != null) getWindow().getDecorView().post(() -> setBarsColor(barsColor));
    }

    private void applyBarsColor(String jsResult) {
        Integer color = parseColor(jsResult);
        if (color == null) return;
        barsColor = color;
        setBarsColor(color);
    }

    private void setBarsColor(int color) {
        Window window = getWindow();
        window.getDecorView().setBackgroundColor(color);
        /* Iconos oscuros sobre fondo claro y al revés. */
        boolean light = ColorUtils.calculateLuminance(color) > 0.5;
        WindowCompat.getInsetsController(window, window.getDecorView()).setAppearanceLightStatusBars(light);
        WindowCompat.getInsetsController(window, window.getDecorView()).setAppearanceLightNavigationBars(light);
    }

    /* evaluateJavascript devuelve el texto en JSON ("\"#123456\""). La web
       solo pone #RRGGBB o #RGB (ver $themeBgColor en mobile.php). */
    private static Integer parseColor(String jsResult) {
        if (jsResult == null) return null;
        String hex = jsResult.replace("\"", "").trim();
        if (hex.matches("#[0-9a-fA-F]{3}")) {
            hex = "#" + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2) + hex.charAt(3) + hex.charAt(3);
        }
        if (!hex.matches("#[0-9a-fA-F]{6}")) return null;
        return Color.parseColor(hex);
    }
}
