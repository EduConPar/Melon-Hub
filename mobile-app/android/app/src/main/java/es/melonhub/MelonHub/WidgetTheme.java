package es.melonhub.MelonHub;

import android.content.Context;
import android.graphics.Color;
import org.json.JSONObject;

/* Colores del widget: los del tema del usuario, que la web lee de sus
   variables CSS (--win-bg, --accent…) y manda con setWidgetTheme. Lo que
   falte (o si la web aún no ha mandado nada) sale de la interfaz sin
   tema: tokens.css para Win98 y kawaii/style.css para Kawaii. */
final class WidgetTheme {

    final boolean kawaii;
    final int winBg, bodyBg, tbStart, tbEnd, tbText, text, muted;
    final int btnBg, btnText, accent, accentText, inputBg;
    final int light1, light2, dark1, dark2, border, hoverBg, activeBg;

    private WidgetTheme(boolean kawaii, JSONObject c) {
        this.kawaii = kawaii;
        String[] d = kawaii ? KAWAII : WIN98;
        winBg = pick(c, d, 0);
        bodyBg = pick(c, d, 1);
        tbStart = pick(c, d, 2);
        tbEnd = pick(c, d, 3);
        tbText = pick(c, d, 4);
        text = pick(c, d, 5);
        muted = pick(c, d, 6);
        btnBg = pick(c, d, 7);
        btnText = pick(c, d, 8);
        accent = pick(c, d, 9);
        accentText = pick(c, d, 10);
        inputBg = pick(c, d, 11);
        light1 = pick(c, d, 12);
        light2 = pick(c, d, 13);
        dark1 = pick(c, d, 14);
        dark2 = pick(c, d, 15);
        border = pick(c, d, 16);
        hoverBg = pick(c, d, 17);
        activeBg = pick(c, d, 18);
    }

    /* Mismo orden que los campos de arriba. */
    static final String[] KEYS = {
        "win-bg", "win-body-bg", "titlebar-start", "titlebar-end", "titlebar-text", "text", "text-muted",
        "btn-bg", "btn-text", "accent", "accent-text", "input-bg",
        "bezel-light-1", "bezel-light-2", "bezel-dark-1", "bezel-dark-2", "border", "hover-bg", "active-rose",
    };
    private static final String[] WIN98 = {
        "#c0c0c0", "#c0c0c0", "#000080", "#1084d0", "#ffffff", "#000000", "#666666",
        "#c0c0c0", "#000000", "#000080", "#ffffff", "#ffffff",
        "#ffffff", "#dfdfdf", "#0a0a0a", "#808080", "#808080", "#000080", "#000080",
    };
    private static final String[] KAWAII = {
        "#E0D4E7", "#F0E8F3", "#F0E8F3", "#F0E8F3", "#472CC7", "#472CC7", "#6B5BA8",
        "#F0E8F3", "#472CC7", "#FF66B2", "#FFFFFF", "#FFFFFF",
        "#FFFFFF", "#FFFFFF", "#49388B", "#49388B", "#49388B", "#CCE7F7", "#FCE7F5",
    };

    private static int pick(JSONObject c, String[] defaults, int i) {
        String v = c != null ? c.optString(KEYS[i], "") : "";
        try {
            if (!v.isEmpty()) return Color.parseColor(v);
        } catch (IllegalArgumentException ignored) {}
        return Color.parseColor(defaults[i]);
    }

    static WidgetTheme load(Context ctx) {
        JSONObject t = WidgetStore.theme(ctx);
        String iface = t != null ? t.optString("interface", "") : "";
        boolean kawaii = iface.isEmpty() ? WidgetStore.cookieKawaii(ctx) : iface.equals("kawaii");
        return new WidgetTheme(kawaii, t != null ? t.optJSONObject("colors") : null);
    }
}
