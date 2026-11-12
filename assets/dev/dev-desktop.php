<?php
/* ════════════════════════════════════════════════════════════════════
   SECCIÓN EXCLUSIVA DE DEV (escritorio)
   --------------------------------------------------------------------
   Todo lo que haya aquí solo se ve en la build de desarrollo. Se incluye
   desde desktop-base.php únicamente cuando MELON_DEV_BUILD es TRUE, así
   que en producción (main, flag FALSE) nada de esto aparece.

   Contiene:
     - Banner para abrir el Melon Wrapped (modo dev → todas las plays).
     - Marca de agua "Melon Hub Dev Build" abajo, con poca opacidad.
     - Notificación de prueba del haro (botón "Probar" de Temas → Haros).

   ¿Quieres añadir MÁS cosas solo-dev? Añádelas aquí (o en otro archivo
   de assets/dev/ incluido igual). Mientras MELON_DEV_BUILD siga en FALSE
   en main, no se "pasarán" al hacer dev→main.
   ════════════════════════════════════════════════════════════════════ */
if (!defined('MELON_DEV_BUILD') || !MELON_DEV_BUILD) return;
?>
<style>
    /* Banner flotante para abrir el Wrapped. */
    #dev-wrapped-banner {
        position: fixed;
        top: 10px;
        right: 10px;                  /* pegado a la parte derecha */
        z-index: 480;                 /* sobre los iconos, bajo las ventanas */
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: bold;
        color: #fff;
        cursor: pointer;
        background: linear-gradient(90deg, #6a0dad, #b0309e);
        border: 1px solid #3a0050;
        box-shadow: 0 2px 8px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.25);
        border-radius: 3px;
        text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        user-select: none;
        transition: transform .08s ease, filter .08s ease;
    }
    #dev-wrapped-banner:hover  { filter: brightness(1.1); }
    #dev-wrapped-banner:active { transform: translateY(1px); }
    #dev-wrapped-banner .dev-wrapped-emoji { font-size: 15px; line-height: 1; }

    /* Marca de agua "Melon Hub Dev Build" abajo centrada, con poca
       opacidad. */
    #dev-build-watermark {
        position: fixed;
        bottom: 38px;                 /* por encima de la taskbar */
        left: 0;
        right: 0;
        text-align: center;
        z-index: 460;
        pointer-events: none;
        font-size: 11px;
        letter-spacing: 1px;
        color: #ffffff;
        opacity: 0.18;
        text-shadow: 0 1px 2px rgba(0,0,0,0.6);
        user-select: none;
    }
    body.is-tablet #dev-build-watermark { bottom: 56px; }
</style>

<div id="dev-wrapped-banner" title="Abrir Melon Wrapped (build dev)">
    <span class="dev-wrapped-emoji">&#127873;</span>
    <span>Abrir Melon Wrapped</span>
</div>

<div id="dev-build-watermark">Melon Hub Dev Build</div>

<script>
(function(){
    /* Banner → abre el Wrapped en modo dev (todas las reproducciones). */
    var banner = document.getElementById('dev-wrapped-banner');
    if (banner) {
        banner.addEventListener('click', function(){
            if (typeof window.openWrappedWindow === 'function') window.openWrappedWindow(true);
        });
    }
    /* Icono de mascotas del escritorio (estático, solo-dev) → SPAWNEA la
       mascota en pantalla (huevo o bicho) y muestra el botón flotante ☰
       (desde el que se abre la gestión). Antes esto lo hacía el icono que
       se retiró; sin él la mascota nunca aparecía. El doble-tap en tablet
       ya se sintetiza globalmente como dblclick sobre .desktop-icon. */
    var pet = document.getElementById('mascota-desktop-icon');
    if (pet) {
        pet.addEventListener('dblclick', function(){
            if (window.MascotaEngine && typeof window.MascotaEngine.spawn === 'function') {
                window.MascotaEngine.spawn();
            } else if (typeof window.openMascotaWindow === 'function') {
                /* Fallback: si el engine no cargó, al menos abre la gestión. */
                window.openMascotaWindow();
            }
        });
    }
    /* Botón "Probar" de Temas → Haros: Temas va en un iframe y pide aquí
       una notificación informativa con el haro activo. Id distinto en
       cada pulsación: notifSystem no repite un id ya enseñado. */
    window.addEventListener('message', function(e){
        if (e.origin !== location.origin) return;
        if (!e.data || e.data.type !== 'dev-haro-test') return;
        if (!window.notifSystem) return;
        window.notifSystem.show({
            id:      'dev-haro-test-' + Date.now(),
            type:    'info',
            title:   'Notificación de prueba',
            message: 'Esto es una notificación de prueba del haro.',
            sentAt:  Math.floor(Date.now() / 1000)
        });
    });
})();
</script>
