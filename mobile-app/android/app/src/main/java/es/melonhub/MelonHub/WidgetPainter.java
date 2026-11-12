package es.melonhub.MelonHub;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Shader;
import android.os.Build;
import android.widget.RemoteViews;
import java.util.HashMap;
import java.util.Map;

/* Pinta los marcos de los layouts de los widgets (música y calendario)
   con los colores del tema. En Win98 cada relieve son 4 marcos anidados
   (<id>_1…_4: abajo-dcha externo, arriba-izda externo, abajo-dcha
   interno, arriba-izda interno) como los box-shadow de 98.css; en Kawaii,
   un marco (<id>_1) que hace de borde de 2 dp. Los botones se hunden al
   pulsarlos (Android 12+: los colores con estado solo se pueden cambiar
   desde ahí). Todos los layouts usan los mismos ids para la ventana
   (w_win), la barra de título (w_tb, w_tb_bg, w_title) y el cuerpo
   (w_body); ver gen_layouts.py. */
final class WidgetPainter {

    private final Context ctx;
    private final RemoteViews rv;
    private final WidgetTheme t;

    WidgetPainter(Context ctx, RemoteViews rv, WidgetTheme t) {
        this.ctx = ctx;
        this.rv = rv;
        this.t = t;
    }

    private void bg(int id, int color) {
        rv.setInt(id, "setBackgroundColor", color);
    }

    void bg(int id, int normal, int pressed) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            rv.setColorStateList(id, "setBackgroundTintList", states(normal, pressed));
        } else {
            bg(id, normal);
        }
    }

    void frame() {
        if (t.kawaii) {
            bg(R.id.w_win_1, t.border);
            bg(R.id.w_win, t.winBg);
            bg(R.id.w_tb_1, t.border);
            bg(R.id.w_tb, t.bodyBg);
            bg(R.id.w_body_1, t.border);
        } else {
            bevel(bevelIds(R.id.w_win_1), t.dark1, t.light2, t.dark2, t.light1);
            bg(R.id.w_win, t.winBg);
        }
        bg(R.id.w_body, t.bodyBg);
    }

    void titleBar() {
        if (t.kawaii) return;
        rv.setImageViewBitmap(R.id.w_tb_bg, gradient(t.tbStart, t.tbEnd));
    }

    /* on: botón activado (🔀): hundido con el icono de acento en Win98,
       relleno de acento en Kawaii. */
    void button(int outerId, int fillId, int iconId, boolean on) {
        if (t.kawaii) {
            bg(outerId, t.border);
            bg(fillId, on ? t.accent : t.btnBg, on ? t.accent : t.activeBg);
            rv.setInt(iconId, "setColorFilter", on ? t.accentText : t.btnText);
            return;
        }
        int[] ids = bevelIds(outerId);
        if (on) {
            bevel(ids, t.light1, t.dark1, t.light2, t.dark2);
        } else {
            bg(ids[0], t.dark1, t.light1);
            bg(ids[1], t.light1, t.dark1);
            bg(ids[2], t.dark2, t.light2);
            bg(ids[3], t.light2, t.dark2);
        }
        bg(fillId, t.btnBg);
        rv.setInt(iconId, "setColorFilter", on ? t.accent : t.btnText);
    }

    /* Campo hundido (carátula, barra, listas, mes) con el fondo de los inputs. */
    void field(int outerId, int fillId) {
        if (t.kawaii) {
            bg(outerId, t.border);
        } else {
            bevel(bevelIds(outerId), t.light1, t.dark2, t.light2, t.dark1);
        }
        bg(fillId, t.inputBg);
    }

    private void bevel(int[] ids, int c1, int c2, int c3, int c4) {
        bg(ids[0], c1);
        bg(ids[1], c2);
        bg(ids[2], c3);
        bg(ids[3], c4);
    }

    /* Los 4 marcos de un relieve de Win98 a partir del primero (<id>_1 →
       <id>_2…_4), por nombre; se buscan una vez. */
    private static final Map<Integer, int[]> BEVELS = new HashMap<>();

    private int[] bevelIds(int outerId) {
        synchronized (BEVELS) {
            int[] ids = BEVELS.get(outerId);
            if (ids != null) return ids;
            String name = ctx.getResources().getResourceEntryName(outerId);
            String base = name.substring(0, name.length() - 2);
            ids = new int[] { outerId, id(base + "_2"), id(base + "_3"), id(base + "_4") };
            BEVELS.put(outerId, ids);
            return ids;
        }
    }

    private int id(String name) {
        return ctx.getResources().getIdentifier(name, "id", ctx.getPackageName());
    }

    /* Una fila de las listas: fondo al pulsarla como la selección de la
       web (Win98: acento; Kawaii: hover-bg). El fondo del layout es opaco
       porque el tinte (SRC_IN) no pinta sobre transparente; sin pulsar,
       el tinte lo deja transparente. Los textos son imágenes y no cambian
       de color al pulsar (solo dura lo que el dedo está encima). */
    static void paintRow(RemoteViews rv, WidgetTheme t, int iconId) {
        int pressedBg = t.kawaii ? t.hoverBg : t.accent;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            rv.setColorStateList(R.id.w_item, "setBackgroundTintList", states(0x00000000, pressedBg));
            if (iconId != 0) rv.setColorStateList(iconId, "setImageTintList", states(t.text, t.kawaii ? t.text : t.accentText));
        } else {
            rv.setInt(R.id.w_item, "setBackgroundColor", 0x00000000);
            if (iconId != 0) rv.setInt(iconId, "setColorFilter", t.text);
        }
    }

    static ColorStateList states(int normal, int pressed) {
        return new ColorStateList(
            new int[][] { new int[] { android.R.attr.state_pressed }, new int[0] },
            new int[] { pressed, normal });
    }

    /* El degradado de la barra de título de Win98, como imagen. */
    private static Bitmap gradient(int start, int end) {
        Bitmap b = Bitmap.createBitmap(64, 1, Bitmap.Config.ARGB_8888);
        Paint g = new Paint();
        g.setShader(new LinearGradient(0, 0, 64, 0, start, end, Shader.TileMode.CLAMP));
        new Canvas(b).drawRect(0, 0, 64, 1, g);
        return b;
    }
}
