package es.melonhub.MelonHub;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.view.View;
import android.widget.RemoteViews;
import androidx.core.graphics.ColorUtils;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/* Widget de calendario 2×2: el mes, como la cuadrícula del calendario del
   móvil (calendario-mobile.js): siempre 5 filas (los meses que necesitarían
   6 se desplazan una semana, igual que allí), hoy con el color de acento,
   puntos de momento (acento) y recordatorio (acento oscuro), y la foto del
   momento de fondo en su día. ◀ ▶ cambian de mes y tocar un día abre el
   calendario de la app en ese día (MainActivity.EXTRA_OPEN_DAY).
   Mismo estilo que el de música (WidgetPainter, WidgetText). Los datos
   (CalendarStore) se pintan al momento desde lo guardado y se refrescan
   por detrás: al ponerlo, cada hora, a medianoche y al salir de la app. */
public class CalendarWidget extends AppWidgetProvider {

    static final String ACTION_MONTH = "es.melonhub.MelonHub.calendar.MONTH";
    static final String ACTION_MIDNIGHT = "es.melonhub.MelonHub.calendar.MIDNIGHT";
    static final String EXTRA_DELTA = "delta";

    private static final String[] MONTHS = {
        "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
        "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre",
    };
    private static final String[] DOW = { "L", "M", "X", "J", "V", "S", "D" };

    private static final ExecutorService IO = Executors.newSingleThreadExecutor();

    /* El mes que enseña cada widget (desplazamiento respecto al actual). */
    private static android.content.SharedPreferences offsets(Context ctx) {
        return ctx.getSharedPreferences("melon_calendar_months", Context.MODE_PRIVATE);
    }

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        for (int id : ids) mgr.updateAppWidget(id, views(ctx, mgr, id));
        refreshData(ctx, goAsync());
        scheduleMidnight(ctx);
    }

    @Override
    public void onReceive(Context ctx, Intent intent) {
        super.onReceive(ctx, intent);
        String action = intent.getAction();
        if (ACTION_MONTH.equals(action)) {
            int id = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
            int delta = intent.getIntExtra(EXTRA_DELTA, 0);
            String key = "offset_" + id;
            int offset = offsets(ctx).getInt(key, 0) + delta;
            offsets(ctx).edit().putInt(key, offset).apply();
            AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
            mgr.updateAppWidget(id, views(ctx, mgr, id));
            /* Las fotos de los momentos del mes nuevo. */
            refreshPhotos(ctx, mgr, id);
        } else if (ACTION_MIDNIGHT.equals(action)) {
            /* Cambia "hoy": todos los widgets de calendario, y vuelta al mes actual. */
            offsets(ctx).edit().clear().apply();
            refreshAll(ctx);
            refreshData(ctx, goAsync());
            scheduleMidnight(ctx);
        }
    }

    /* Repinta los dos widgets de calendario con lo guardado. */
    static void refreshAll(Context ctx) {
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        for (int id : mgr.getAppWidgetIds(new ComponentName(ctx, CalendarWidget.class))) {
            mgr.updateAppWidget(id, views(ctx, mgr, id));
        }
        int[] agenda = mgr.getAppWidgetIds(new ComponentName(ctx, CalendarAgendaWidget.class));
        for (int id : agenda) mgr.updateAppWidget(id, CalendarAgendaWidget.views(ctx, mgr, id));
        if (agenda.length > 0) mgr.notifyAppWidgetViewDataChanged(agenda, R.id.w_list);
    }

    /* Pide los datos (si no son de hace un momento) y, si han cambiado,
       repinta; luego baja las fotos que falten. pending: el goAsync del
       broadcast, o null si se llama desde la app. */
    static void refreshData(Context ctx, PendingResult pending) {
        Context app = ctx.getApplicationContext();
        IO.execute(() -> {
            try {
                boolean changed = !CalendarStore.fresh(app) && CalendarStore.fetch(app);
                if (changed) refreshAll(app);
                AppWidgetManager mgr = AppWidgetManager.getInstance(app);
                for (int id : mgr.getAppWidgetIds(new ComponentName(app, CalendarWidget.class))) {
                    if (CalendarStore.downloadPhotos(app, photosOf(app, id))) mgr.updateAppWidget(id, views(app, mgr, id));
                }
            } finally {
                if (pending != null) pending.finish();
            }
        });
    }

    /* Al salir de la app (puede haber añadido algo): datos nuevos ya. */
    static void refreshFromApp(Context ctx) {
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        boolean any = mgr.getAppWidgetIds(new ComponentName(ctx, CalendarWidget.class)).length > 0
            || mgr.getAppWidgetIds(new ComponentName(ctx, CalendarAgendaWidget.class)).length > 0;
        if (!any) return;
        Context app = ctx.getApplicationContext();
        IO.execute(() -> {
            if (CalendarStore.fetch(app)) refreshAll(app);
            AppWidgetManager m = AppWidgetManager.getInstance(app);
            for (int id : m.getAppWidgetIds(new ComponentName(app, CalendarWidget.class))) {
                if (CalendarStore.downloadPhotos(app, photosOf(app, id))) m.updateAppWidget(id, views(app, m, id));
            }
        });
    }

    private static void refreshPhotos(Context ctx, AppWidgetManager mgr, int id) {
        Context app = ctx.getApplicationContext();
        IO.execute(() -> {
            if (CalendarStore.downloadPhotos(app, photosOf(app, id))) mgr.updateAppWidget(id, views(app, mgr, id));
        });
    }

    /* A medianoche cambia "hoy" (aproximado: no despierta el móvil). */
    private static void scheduleMidnight(Context ctx) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        Calendar next = Calendar.getInstance();
        next.add(Calendar.DAY_OF_MONTH, 1);
        next.set(Calendar.HOUR_OF_DAY, 0);
        next.set(Calendar.MINUTE, 0);
        next.set(Calendar.SECOND, 5);
        Intent i = new Intent(ctx, CalendarWidget.class).setAction(ACTION_MIDNIGHT);
        am.set(AlarmManager.RTC, next.getTimeInMillis(),
            PendingIntent.getBroadcast(ctx, 0, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
    }

    /* ── Cuadrícula ── */

    /* El primer día de la cuadrícula del mes que enseña el widget. */
    private static Calendar gridStart(Context ctx, int widgetId) {
        int offset = offsets(ctx).getInt("offset_" + widgetId, 0);
        Calendar first = Calendar.getInstance();
        first.set(Calendar.DAY_OF_MONTH, 1);
        first.add(Calendar.MONTH, offset);
        return first;
    }

    /* Igual que renderGridIntoEl: 35 celdas; si el mes no cabe en 5
       semanas, se desplaza una semana (y se salta los primeros días). */
    private static int startDow(Calendar first) {
        int startDow = (first.get(Calendar.DAY_OF_WEEK) + 5) % 7;
        if (startDow + first.getActualMaximum(Calendar.DAY_OF_MONTH) > 35) startDow -= 7;
        return startDow;
    }

    private static Calendar cellDate(Calendar first, int startDow, int i) {
        Calendar d = (Calendar) first.clone();
        d.add(Calendar.DAY_OF_MONTH, i - startDow);
        return d;
    }

    private static List<String> photosOf(Context ctx, int widgetId) {
        Calendar first = gridStart(ctx, widgetId);
        int sd = startDow(first);
        String from = CalendarStore.format(cellDate(first, sd, 0));
        String to = CalendarStore.format(cellDate(first, sd, 34));
        List<String> out = new ArrayList<>();
        for (CalendarStore.Day d : CalendarStore.days(ctx, from, to).values()) {
            if (d.photo != null) out.add(d.photo);
        }
        return out;
    }

    static RemoteViews views(Context ctx, AppWidgetManager mgr, int widgetId) {
        loadIds(ctx);
        WidgetTheme t = WidgetTheme.load(ctx);
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_cal_month : R.layout.widget_w98_cal_month);
        WidgetPainter p = new WidgetPainter(ctx, rv, t);
        p.frame();
        p.titleBar();
        p.button(R.id.w_calprev_1, R.id.w_calprev, R.id.w_calprev_ic, false);
        p.button(R.id.w_calnext_1, R.id.w_calnext, R.id.w_calnext_ic, false);
        p.field(R.id.w_grid_1, R.id.w_grid);
        float w = PlayerWidget.widthDp(mgr, widgetId);

        Calendar first = gridStart(ctx, widgetId);
        int month = first.get(Calendar.MONTH);
        rv.setImageViewBitmap(R.id.w_title, WidgetText.draw(ctx, t, WidgetText.TITLE,
            MONTHS[month] + " " + first.get(Calendar.YEAR), 13, t.tbText, w - (t.kawaii ? 66 : 58), 1, true));
        for (int i = 0; i < 7; i++) {
            rv.setImageViewBitmap(DOW_IDS[i], WidgetText.draw(ctx, t, t.kawaii ? WidgetText.BODY : WidgetText.TITLE, DOW[i], 9, t.muted, 20, 1, true));
        }

        int sd = startDow(first);
        String from = CalendarStore.format(cellDate(first, sd, 0));
        String to = CalendarStore.format(cellDate(first, sd, 34));
        Map<String, CalendarStore.Day> days = CalendarStore.days(ctx, from, to);
        String today = CalendarStore.format(Calendar.getInstance());
        for (int i = 0; i < 35; i++) {
            Calendar d = cellDate(first, sd, i);
            String date = CalendarStore.format(d);
            boolean other = d.get(Calendar.MONTH) != month;
            boolean isToday = date.equals(today);
            CalendarStore.Day day = days.get(date);
            Bitmap photo = day != null && day.photo != null && !other ? CalendarStore.photo(ctx, day.photo) : null;

            int fill = isToday ? t.accent : t.inputBg;
            rv.setInt(CELL_IDS[i], "setBackgroundColor", t.kawaii ? t.border : fill);
            rv.setInt(CELL_IN_IDS[i], "setBackgroundColor", fill);
            if (photo != null) {
                rv.setImageViewBitmap(PHOTO_IDS[i], photo);
                rv.setViewVisibility(PHOTO_IDS[i], View.VISIBLE);
            } else {
                rv.setViewVisibility(PHOTO_IDS[i], View.GONE);
            }
            int color = isToday ? t.accentText : other ? ColorUtils.setAlphaComponent(t.muted, 115) : t.text;
            if (photo != null) color = 0xFFFFFFFF;
            rv.setImageViewBitmap(NUM_IDS[i], WidgetText.drawShadowed(ctx, t, isToday && !t.kawaii ? WidgetText.TITLE : WidgetText.BODY,
                String.valueOf(d.get(Calendar.DAY_OF_MONTH)), 9.5f, color, photo != null));
            if (day != null && !other && (day.moment || day.reminder)) {
                rv.setImageViewBitmap(DOT_IDS[i], dots(ctx, day.moment, day.reminder,
                    isToday ? t.accentText : t.accent, isToday ? t.accentText : t.accentDeep));
                rv.setViewVisibility(DOT_IDS[i], View.VISIBLE);
            } else {
                rv.setViewVisibility(DOT_IDS[i], View.GONE);
            }
            rv.setOnClickPendingIntent(CELL_IDS[i], openDay(ctx, 1000 + (widgetId % 1000) * 40 + i, date));
        }

        rv.setOnClickPendingIntent(R.id.w_calprev_1, month(ctx, widgetId, -1));
        rv.setOnClickPendingIntent(R.id.w_calnext_1, month(ctx, widgetId, 1));
        return rv;
    }

    /* Los puntos de un día: momento y/o recordatorio, cuadrados de 3 dp. */
    private static Bitmap dots(Context ctx, boolean moment, boolean reminder, int momColor, int recColor) {
        float dp = ctx.getResources().getDisplayMetrics().density;
        int s = Math.round(3 * dp), gap = Math.round(dp);
        int n = (moment ? 1 : 0) + (reminder ? 1 : 0);
        Bitmap b = Bitmap.createBitmap(n * s + (n - 1) * gap, s, Bitmap.Config.ARGB_8888);
        b.setDensity(ctx.getResources().getDisplayMetrics().densityDpi);
        Canvas c = new Canvas(b);
        Paint paint = new Paint();
        int x = 0;
        if (moment) {
            paint.setColor(momColor);
            c.drawRect(x, 0, x + s, s, paint);
            x += s + gap;
        }
        if (reminder) {
            paint.setColor(recColor);
            c.drawRect(x, 0, x + s, s, paint);
        }
        return b;
    }

    private static PendingIntent month(Context ctx, int widgetId, int delta) {
        Intent i = new Intent(ctx, CalendarWidget.class).setAction(ACTION_MONTH)
            .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId)
            .putExtra(EXTRA_DELTA, delta);
        return PendingIntent.getBroadcast(ctx, widgetId * 2 + (delta > 0 ? 1 : 0), i,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /* Abre la app en el calendario, en ese día ("" = sin día). */
    static PendingIntent openDay(Context ctx, int req, String date) {
        return PendingIntent.getActivity(ctx, req, openDayIntent(ctx).putExtra(MainActivity.EXTRA_OPEN_DAY, date),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    static Intent openDayIntent(Context ctx) {
        return new Intent(ctx, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    private static final int[] DOW_IDS = {
        R.id.w_dow0, R.id.w_dow1, R.id.w_dow2, R.id.w_dow3, R.id.w_dow4, R.id.w_dow5, R.id.w_dow6,
    };
    /* Los ids de las 35 celdas (w_c0…w_c34, w_ci…, w_cp…, w_cn…, w_cd…;
       generados en gen_layouts.py), buscados por nombre una vez. */
    private static int[] CELL_IDS, CELL_IN_IDS, PHOTO_IDS, NUM_IDS, DOT_IDS;

    private static synchronized void loadIds(Context ctx) {
        if (CELL_IDS != null) return;
        CELL_IDS = ids(ctx, "w_c");
        CELL_IN_IDS = ids(ctx, "w_ci");
        PHOTO_IDS = ids(ctx, "w_cp");
        NUM_IDS = ids(ctx, "w_cn");
        DOT_IDS = ids(ctx, "w_cd");
    }

    private static int[] ids(Context ctx, String prefix) {
        int[] out = new int[35];
        for (int i = 0; i < 35; i++) out[i] = ctx.getResources().getIdentifier(prefix + i, "id", ctx.getPackageName());
        return out;
    }
}
