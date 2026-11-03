package es.melonhub.MelonHub;

import android.content.Context;
import android.content.Intent;
import android.widget.RemoteViews;
import android.widget.RemoteViewsService;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;

/* Filas de "Próximos" (CalendarAgendaWidget): los recordatorios de hoy en
   adelante (un año como mucho, 30 filas), con los repetidos expandidos.
   Con nada que enseñar, una fila con el aviso (sin sesión, sin pareja…). */
public class CalendarAgendaService extends RemoteViewsService {

    @Override
    public RemoteViewsFactory onGetViewFactory(Intent intent) {
        return new Factory(getApplicationContext(), intent);
    }

    private static final String[] MONTHS = { "ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic" };

    private static class Factory implements RemoteViewsFactory {

        private final Context ctx;
        private final float rowRoomDp;
        private WidgetTheme theme;
        private List<CalendarStore.Occurrence> rows = new ArrayList<>();
        private String message;
        private String today;

        Factory(Context ctx, Intent intent) {
            this.ctx = ctx;
            this.rowRoomDp = intent.getFloatExtra("rowRoomDp", 90);
        }

        @Override
        public void onCreate() {}

        @Override
        public void onDataSetChanged() {
            theme = WidgetTheme.load(ctx);
            Calendar now = Calendar.getInstance();
            today = CalendarStore.format(now);
            Calendar limit = (Calendar) now.clone();
            limit.add(Calendar.YEAR, 1);
            List<CalendarStore.Occurrence> all = CalendarStore.occurrences(ctx, today, CalendarStore.format(limit));
            rows = all.size() > 30 ? new ArrayList<>(all.subList(0, 30)) : all;
            message = null;
            if (rows.isEmpty()) {
                String status = CalendarStore.status(ctx);
                if (status.equals(CalendarStore.STATUS_LOGIN)) message = ctx.getString(R.string.widget_login);
                else if (!CalendarStore.hasCouple(ctx) && status.equals(CalendarStore.STATUS_OK)) message = ctx.getString(R.string.cal_no_couple);
                else message = ctx.getString(R.string.cal_nothing);
            }
        }

        @Override
        public void onDestroy() {}

        @Override
        public int getCount() {
            return message != null ? 1 : rows.size();
        }

        @Override
        public RemoteViews getViewAt(int position) {
            WidgetTheme t = theme != null ? theme : WidgetTheme.load(ctx);
            if (message != null) {
                /* Sin extras: tocarla no hace nada. */
                RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_item_song : R.layout.widget_w98_item_song);
                rv.setImageViewBitmap(R.id.w_item_title, WidgetText.draw(ctx, t, WidgetText.BODY, message, 12, t.muted, rowRoomDp + 34, 3, true));
                rv.setInt(R.id.w_item, "setBackgroundColor", 0x00000000);
                return rv;
            }
            CalendarStore.Occurrence o = rows.get(Math.min(position, rows.size() - 1));
            RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_cal_item : R.layout.widget_w98_cal_item);
            boolean isToday = o.date.equals(today);
            boolean event = o.title.startsWith("Evento:");
            Calendar d = CalendarStore.parse(o.date);

            rv.setImageViewBitmap(R.id.w_ag_day, WidgetText.draw(ctx, t, t.kawaii ? WidgetText.BODY : WidgetText.TITLE,
                String.valueOf(d.get(Calendar.DAY_OF_MONTH)), 12, isToday ? t.accent : t.text, 26, 1, true));
            rv.setImageViewBitmap(R.id.w_ag_mon, WidgetText.draw(ctx, t, WidgetText.BODY,
                MONTHS[d.get(Calendar.MONTH)], 9, t.muted, 26, 1, true));
            rv.setImageViewBitmap(R.id.w_item_title, WidgetText.draw(ctx, t, WidgetText.BODY, o.title, 12, t.text, rowRoomDp, 1, false));
            rv.setImageViewBitmap(R.id.w_item_sub, WidgetText.draw(ctx, t, WidgetText.BODY, subtitle(isToday, event, o.period), 10, t.muted, rowRoomDp, 1, false));
            rv.setInt(R.id.w_ag_kind, "setBackgroundColor", event ? t.muted : t.accentDeep);
            WidgetPainter.paintRow(rv, t, 0);
            rv.setOnClickFillInIntent(R.id.w_item, new Intent().putExtra(MainActivity.EXTRA_OPEN_DAY, o.date));
            return rv;
        }

        /* "Hoy · Recordatorio · cada año" */
        private String subtitle(boolean isToday, boolean event, String period) {
            StringBuilder s = new StringBuilder();
            if (isToday) s.append(ctx.getString(R.string.cal_today)).append(" · ");
            s.append(ctx.getString(event ? R.string.cal_event : R.string.cal_reminder));
            int every = period.equals("semanal") ? R.string.cal_every_week
                : period.equals("mensual") ? R.string.cal_every_month
                : period.equals("anual") ? R.string.cal_every_year : 0;
            if (every != 0) s.append(" · ").append(ctx.getString(every));
            return s.toString();
        }

        @Override
        public RemoteViews getLoadingView() {
            return null;
        }

        @Override
        public int getViewTypeCount() {
            return 2;
        }

        @Override
        public long getItemId(int position) {
            return position;
        }

        @Override
        public boolean hasStableIds() {
            return false;
        }
    }
}
