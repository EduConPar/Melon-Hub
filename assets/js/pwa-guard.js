/* ──────────────────────────────────────────────────────────────────────
   PWA GUARD — Enforcer client-side de "Melon Hub solo desde icono"
   ──────────────────────────────────────────────────────────────────────
   Si la página se está cargando en un dispositivo MÓVIL pero NO en
   modo standalone (es decir, dentro del navegador con barra de URL),
   redirigimos a la landing. Esto plug el agujero del cookie-sharing
   en Android, donde session.is_pwa server-side puede haberse colado
   al navegador normal.

   Desktop / tablet → return temprano, no toca nada.
   PWA standalone   → return temprano, deja seguir.
   Móvil + navegador → redirect a mobile-landing.php.

   Inline en <head> antes de cualquier otra cosa para evitar flash y
   evitar carrera con scripts de la app.
   ────────────────────────────────────────────────────────────────────── */
(function(){
    var ua = navigator.userAgent;
    /* Solo aplicamos a móviles. Tablets (iPad, Android no-Mobile) e
       PC pasan sin tocar nada. Mismo regex que isMobileDevice() server-side. */
    if (!/Mobi|Android.*Mobile|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/.test(ua)) return;
    if (/iPad|Tablet/i.test(ua)) return;

    /* La app de Android (Capacitor) añade "MelonHubApp" al
       User-Agent: cuenta como PWA instalada. */
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                  || window.navigator.standalone === true
                  || /MelonHubApp/.test(ua);
    if (standalone) return;

    /* La landing está en la raíz del proyecto, dos carpetas por encima de
       este script (assets/js/): se calcula desde su propia URL, así vale
       llamado desde cualquier profundidad (apps/mobile/X.php o mobile.php)
       y en cualquier dominio. Antes se buscaba "/scrapbookOnline/" (la
       carpeta de XAMPP) y en el hosting acababa en un 404. */
    var me = document.currentScript && document.currentScript.src;
    var landing = me ? new URL('../../mobile-landing.php', me).href : '/mobile-landing.php';
    window.location.replace(landing);
})();
