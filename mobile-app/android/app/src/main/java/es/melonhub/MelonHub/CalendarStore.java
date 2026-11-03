package es.melonhub.MelonHub;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.util.Log;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import org.json.JSONArray;
import org.json.JSONObject;

/* Datos de los widgets de calendario (CalendarWidget, CalendarAgendaWidget):
   lo mismo que carga el calendario del móvil (apps/mobile/calendario-mobile.js),
   pedido a assets/couple/api.php?action=widget-calendar con las cookies del
   WebView (como las playlists del widget de música). Se guarda en un
   archivo para pintar al momento y se refresca por detrás. Los
   recordatorios repetidos (semanal, mensual, anual) se expanden aquí igual
   que expandirRecordatorios. Las fechas van como "AAAA-MM-DD". */
final class CalendarStore {

    static final String STATUS_OK = "ok";
    static final String STATUS_LOGIN = "login";
    static final String STATUS_OFFLINE = "offline";

    private static final String FILE = "widget_calendar.json";
    private static final String PHOTOS_DIR = "widget_cal_photos";
    private static final String PREFS = "melon_calendar";
    private static final String TAG = "MelonCalendar";

    private CalendarStore() {}

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    /* ── Descarga ── */

    /* Sin pedir otra vez si se pidió hace poco (los widgets se repintan a
       menudo). */
    static boolean fresh(Context ctx) {
        return System.currentTimeMillis() - prefs(ctx).getLong("fetchedAt", 0) < 5 * 60 * 1000;
    }

    /* true si los datos han cambiado. Hilo aparte: puede tardar. */
    static boolean fetch(Context ctx) {
        HttpURLConnection c = null;
        String status;
        boolean changed = false;
        try {
            URL url = new URL(new URL(WidgetStore.baseUrl(ctx)), "assets/couple/api.php?action=widget-calendar");
            c = (HttpURLConnection) url.openConnection();
            c.setConnectTimeout(10000);
            c.setReadTimeout(15000);
            String cookies = WidgetStore.cookies(ctx);
            if (cookies != null) c.setRequestProperty("Cookie", cookies);
            int code = c.getResponseCode();
            WidgetStore.keepCookies(ctx, c);
            if (code == 401) {
                status = STATUS_LOGIN;
            } else if (code == 200) {
                String body;
                try (InputStream in = c.getInputStream()) {
                    body = WidgetStore.readAll(in);
                }
                new JSONObject(body); /* que sea JSON de verdad antes de guardarlo */
                File f = new File(ctx.getFilesDir(), FILE);
                changed = !body.equals(readFile(f));
                if (changed) {
                    try (OutputStream out = new FileOutputStream(f)) {
                        out.write(body.getBytes(StandardCharsets.UTF_8));
                    }
                }
                status = STATUS_OK;
            } else {
                status = STATUS_OFFLINE;
            }
        } catch (Exception e) {
            Log.w(TAG, "No se pudo cargar el calendario", e);
            status = STATUS_OFFLINE;
        } finally {
            if (c != null) c.disconnect();
        }
        prefs(ctx).edit().putString("status", status).putLong("fetchedAt", System.currentTimeMillis()).apply();
        return changed;
    }

    static String status(Context ctx) {
        return prefs(ctx).getString("status", STATUS_OK);
    }

    /* ── Datos ── */

    private static JSONObject cache;
    private static long cacheTime = -1;

    private static synchronized JSONObject data(Context ctx) {
        File f = new File(ctx.getFilesDir(), FILE);
        if (!f.exists()) return new JSONObject();
        if (cache != null && f.lastModified() == cacheTime) return cache;
        try {
            cache = new JSONObject(readFile(f));
            cacheTime = f.lastModified();
            return cache;
        } catch (Exception e) {
            return new JSONObject();
        }
    }

    /* Sin pareja el calendario no tiene nada (como en la app). */
    static boolean hasCouple(Context ctx) {
        return data(ctx).optBoolean("pareja", false);
    }

    /* Un día del mes: si tiene momento (y la foto del primero que la
       tenga) y si tiene recordatorio. */
    static final class Day {
        boolean moment;
        String photo;
        boolean reminder;
    }

    /* Los días con algo entre from y to (incluidos). */
    static Map<String, Day> days(Context ctx, String from, String to) {
        Map<String, Day> out = new HashMap<>();
        JSONArray moms = data(ctx).optJSONArray("momentos");
        if (moms != null) {
            for (int i = 0; i < moms.length(); i++) {
                JSONObject m = moms.optJSONObject(i);
                if (m == null) continue;
                String f = m.optString("fecha");
                if (f.length() < 10) continue;
                f = f.substring(0, 10);
                if (f.compareTo(from) < 0 || f.compareTo(to) > 0) continue;
                Day d = day(out, f);
                d.moment = true;
                String photo = m.optString("foto", "");
                if (d.photo == null && !photo.isEmpty() && !photo.equals("null")) d.photo = photo;
            }
        }
        for (Occurrence o : occurrences(ctx, from, to)) day(out, o.date).reminder = true;
        return out;
    }

    private static Day day(Map<String, Day> map, String date) {
        Day d = map.get(date);
        if (d == null) {
            d = new Day();
            map.put(date, d);
        }
        return d;
    }

    /* Una vez que cae un recordatorio (los repetidos, cada vez). */
    static final class Occurrence implements Comparable<Occurrence> {
        String date;
        String title;
        String period;

        @Override
        public int compareTo(Occurrence o) {
            int c = date.compareTo(o.date);
            return c != 0 ? c : title.compareToIgnoreCase(o.title);
        }
    }

    /* Recordatorios entre from y to, ordenados (como expandirRecordatorios
       de calendario-mobile.js). */
    static List<Occurrence> occurrences(Context ctx, String from, String to) {
        List<Occurrence> out = new ArrayList<>();
        JSONArray recs = data(ctx).optJSONArray("recordatorios");
        if (recs == null) return out;
        Calendar end = parse(to);
        for (int i = 0; i < recs.length(); i++) {
            JSONObject r = recs.optJSONObject(i);
            if (r == null) continue;
            Calendar base = parse(r.optString("fecha"));
            if (base == null || end == null) continue;
            String period = r.optString("periodicidad", "ninguna");
            String title = r.optString("titulo");
            if (period.equals("ninguna")) {
                add(out, format(base), title, period, from, to);
                continue;
            }
            Calendar start = parse(from);
            Calendar cur = (Calendar) (base.after(start) ? base : start).clone();
            if (period.equals("anual")) {
                cur.set(cur.get(Calendar.YEAR), base.get(Calendar.MONTH), base.get(Calendar.DAY_OF_MONTH));
                if (cur.before(start) || cur.before(base)) cur.add(Calendar.YEAR, 1);
            } else if (period.equals("mensual")) {
                cur.set(Calendar.DAY_OF_MONTH, Math.min(base.get(Calendar.DAY_OF_MONTH), cur.getActualMaximum(Calendar.DAY_OF_MONTH)));
                if (cur.before(start) || cur.before(base)) {
                    cur.set(Calendar.DAY_OF_MONTH, 1);
                    cur.add(Calendar.MONTH, 1);
                    cur.set(Calendar.DAY_OF_MONTH, Math.min(base.get(Calendar.DAY_OF_MONTH), cur.getActualMaximum(Calendar.DAY_OF_MONTH)));
                }
            } else if (period.equals("semanal")) {
                int diff = (base.get(Calendar.DAY_OF_WEEK) - cur.get(Calendar.DAY_OF_WEEK) + 7) % 7;
                cur.add(Calendar.DAY_OF_MONTH, diff);
            } else {
                continue;
            }
            while (!cur.after(end)) {
                add(out, format(cur), title, period, from, to);
                if (period.equals("anual")) {
                    cur.add(Calendar.YEAR, 1);
                } else if (period.equals("mensual")) {
                    cur.set(Calendar.DAY_OF_MONTH, 1);
                    cur.add(Calendar.MONTH, 1);
                    cur.set(Calendar.DAY_OF_MONTH, Math.min(base.get(Calendar.DAY_OF_MONTH), cur.getActualMaximum(Calendar.DAY_OF_MONTH)));
                } else {
                    cur.add(Calendar.DAY_OF_MONTH, 7);
                }
            }
        }
        Collections.sort(out);
        return out;
    }

    private static void add(List<Occurrence> out, String date, String title, String period, String from, String to) {
        if (date.compareTo(from) < 0 || date.compareTo(to) > 0) return;
        Occurrence o = new Occurrence();
        o.date = date;
        o.title = title;
        o.period = period;
        out.add(o);
    }

    static Calendar parse(String s) {
        if (s == null || s.length() < 10) return null;
        try {
            Calendar c = Calendar.getInstance();
            c.clear();
            c.set(Integer.parseInt(s.substring(0, 4)), Integer.parseInt(s.substring(5, 7)) - 1, Integer.parseInt(s.substring(8, 10)));
            return c;
        } catch (NumberFormatException e) {
            return null;
        }
    }

    static String format(Calendar c) {
        return String.format(Locale.ROOT, "%04d-%02d-%02d", c.get(Calendar.YEAR), c.get(Calendar.MONTH) + 1, c.get(Calendar.DAY_OF_MONTH));
    }

    /* ── Fotos de los momentos ── */

    /* La foto ya bajada (pequeña), o null. */
    static Bitmap photo(Context ctx, String photo) {
        File f = photoFile(ctx, photo);
        return f.exists() ? BitmapFactory.decodeFile(f.getPath()) : null;
    }

    /* Baja las que falten. true si ha bajado alguna. */
    static boolean downloadPhotos(Context ctx, List<String> photos) {
        boolean any = false;
        for (String p : photos) {
            File f = photoFile(ctx, p);
            if (f.exists()) continue;
            HttpURLConnection c = null;
            try {
                URL url = p.matches("(?i)^https?://.*") ? new URL(p)
                    : new URL(new URL(WidgetStore.baseUrl(ctx)), "uploads/momentos/" + p);
                c = (HttpURLConnection) url.openConnection();
                c.setConnectTimeout(10000);
                c.setReadTimeout(15000);
                String cookies = WidgetStore.cookies(ctx);
                if (cookies != null) c.setRequestProperty("Cookie", cookies);
                Bitmap src;
                try (InputStream in = c.getInputStream()) {
                    src = BitmapFactory.decodeStream(in);
                }
                if (src == null) continue;
                /* Pequeña: en la cuadrícula cada día mide ~20 dp. */
                int side = Math.min(src.getWidth(), src.getHeight());
                Bitmap sq = Bitmap.createBitmap(src, (src.getWidth() - side) / 2, (src.getHeight() - side) / 2, side, side);
                Bitmap small = Bitmap.createScaledBitmap(sq, 72, 72, true);
                f.getParentFile().mkdirs();
                try (OutputStream out = new FileOutputStream(f)) {
                    small.compress(Bitmap.CompressFormat.JPEG, 85, out);
                }
                any = true;
            } catch (Exception e) {
                Log.w(TAG, "No se pudo bajar la foto " + p, e);
            } finally {
                if (c != null) c.disconnect();
            }
        }
        return any;
    }

    private static File photoFile(Context ctx, String photo) {
        return new File(new File(ctx.getCacheDir(), PHOTOS_DIR), Integer.toHexString(photo.hashCode()) + ".jpg");
    }

    private static String readFile(File f) {
        if (!f.exists()) return null;
        try (InputStream in = new FileInputStream(f)) {
            return WidgetStore.readAll(in);
        } catch (Exception e) {
            return null;
        }
    }
}
