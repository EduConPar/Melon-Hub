package es.melonhub.MelonHub;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.text.Layout;
import android.text.StaticLayout;
import android.text.TextPaint;
import android.text.TextUtils;
import android.util.DisplayMetrics;
import android.util.LruCache;
import android.util.TypedValue;
import androidx.core.content.res.ResourcesCompat;

/* Textos del widget como imágenes. El lanzador pinta los widgets con sus
   propias fuentes: android:fontFamily="@font/…" no llega y saldría la
   fuente del sistema en vez de la pixel de Win98 / Pixelify Sans. Aquí
   se dibujan con la fuente buena, a la densidad de la pantalla (se ven
   1:1) y cortados con "…" al ancho que haya. */
final class WidgetText {

    private WidgetText() {}

    /* Los textos se repiten en cada repintado (título, botones, filas):
       dibujarlos cada vez era lo que más tardaba. Caché por todo lo que
       cambia el dibujo; ~2 MB. */
    private static final LruCache<String, Bitmap> CACHE = new LruCache<String, Bitmap>(2 * 1024 * 1024) {
        @Override
        protected int sizeOf(String key, Bitmap b) {
            return b.getByteCount();
        }
    };

    static final int TITLE = 0;   /* barra de título: negrita */
    static final int BODY = 1;

    /* sp: tamaño; maxWidthDp: ancho disponible; maxLines > 1 parte en
       líneas (mensaje de lista vacía). */
    static Bitmap draw(Context ctx, WidgetTheme t, int style, String text, float sp, int color,
                       float maxWidthDp, int maxLines, boolean center) {
        String key = (t.kawaii ? "k" : "w") + style + "|" + sp + "|" + color + "|" + Math.round(maxWidthDp)
            + "|" + maxLines + "|" + center + "|" + text;
        Bitmap cached = CACHE.get(key);
        if (cached != null) return cached;
        Bitmap b = render(ctx, t, style, text, sp, color, maxWidthDp, maxLines, center);
        CACHE.put(key, b);
        return b;
    }

    /* Un número de la cuadrícula del calendario. shadow: sobre la foto de
       un momento, con sombra para que se lea (como .cm-cell-foto). */
    static Bitmap drawShadowed(Context ctx, WidgetTheme t, int style, String text, float sp, int color, boolean shadow) {
        if (!shadow) return draw(ctx, t, style, text, sp, color, 40, 1, true);
        String key = "s" + (t.kawaii ? "k" : "w") + style + "|" + sp + "|" + color + "|" + text;
        Bitmap cached = CACHE.get(key);
        if (cached != null) return cached;
        DisplayMetrics dm = ctx.getResources().getDisplayMetrics();
        TextPaint paint = new TextPaint(TextPaint.ANTI_ALIAS_FLAG);
        paint.setColor(color);
        paint.setTextSize(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_SP, sp, dm));
        paint.setTypeface(typeface(ctx, t, style));
        float r = 3 * dm.density;
        paint.setShadowLayer(r, 0, 0, 0xFF000000);
        int pad = (int) Math.ceil(r);
        Paint.FontMetricsInt fm = paint.getFontMetricsInt();
        int w = (int) Math.ceil(paint.measureText(text)) + pad * 2;
        int h = fm.descent - fm.ascent + pad * 2;
        Bitmap b = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888);
        b.setDensity(dm.densityDpi);
        Canvas c = new Canvas(b);
        /* Dos pasadas: la sombra sola se queda flojita. */
        c.drawText(text, pad, pad - fm.ascent, paint);
        c.drawText(text, pad, pad - fm.ascent, paint);
        CACHE.put(key, b);
        return b;
    }

    private static Bitmap render(Context ctx, WidgetTheme t, int style, String text, float sp, int color,
                                 float maxWidthDp, int maxLines, boolean center) {
        DisplayMetrics dm = ctx.getResources().getDisplayMetrics();
        TextPaint paint = new TextPaint(TextPaint.ANTI_ALIAS_FLAG);
        paint.setColor(color);
        paint.setTextSize(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_SP, sp, dm));
        paint.setTypeface(typeface(ctx, t, style));
        /* La negrita de Win98 no trae tildes (á, é…): con letras que no
           tiene, la normal engrosada (si no, se mezclan dos fuentes). */
        if (style == TITLE && !t.kawaii && text != null && !hasAllGlyphs(paint, text)) {
            paint.setTypeface(typeface(ctx, t, BODY));
            paint.setFakeBoldText(true);
        }

        int maxWidth = Math.max(1, Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, maxWidthDp, dm)));
        CharSequence s = text != null ? text : "";
        if (maxLines <= 1) s = TextUtils.ellipsize(s, paint, maxWidth, TextUtils.TruncateAt.END);
        int width = maxLines <= 1 ? Math.max(1, (int) Math.ceil(paint.measureText(s, 0, s.length()))) : maxWidth;

        StaticLayout layout = StaticLayout.Builder.obtain(s, 0, s.length(), paint, width)
            .setAlignment(center ? Layout.Alignment.ALIGN_CENTER : Layout.Alignment.ALIGN_NORMAL)
            .setIncludePad(false)
            .setMaxLines(Math.max(1, maxLines))
            .setEllipsize(TextUtils.TruncateAt.END)
            .build();
        Bitmap b = Bitmap.createBitmap(width, Math.max(1, layout.getHeight()), Bitmap.Config.ARGB_8888);
        b.setDensity(dm.densityDpi);
        layout.draw(new Canvas(b));
        return b;
    }

    private static boolean hasAllGlyphs(TextPaint paint, String text) {
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            if (!Character.isWhitespace(cp) && !paint.hasGlyph(new String(Character.toChars(cp)))) return false;
            i += Character.charCount(cp);
        }
        return true;
    }

    private static Typeface typeface(Context ctx, WidgetTheme t, int style) {
        int res = t.kawaii ? R.font.pixelify_sans : (style == TITLE ? R.font.ms_sans_serif_bold : R.font.ms_sans_serif);
        try {
            Typeface f = ResourcesCompat.getFont(ctx, res);
            if (f != null) return f;
        } catch (Exception ignored) {}
        return Typeface.DEFAULT;
    }
}
