const { app, BrowserWindow } = require('electron');
require('/app/main.js');
const tray = require('/app/tray.js');
const settings = require('/app/settings.js');
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let ok = true; const t = (l, c) => { if (!c) ok = false; console.log((c ? 'OK   ' : 'FAIL ') + l); };
const orders = [];
app.on('browser-window-created', (_e, w) => w.webContents.on('console-message', (e) => { if (String(e.message).startsWith('ORDEN')) orders.push(String(e.message).slice(6)); }));
const wins = () => BrowserWindow.getAllWindows();
const main = () => wins().find((w) => w.webContents.getURL().endsWith('index.html'));
const miniW = () => wins().find((w) => w.webContents.getURL().includes('mini-player'));
app.whenReady().then(async () => {
  settings.set('floatingPlayer', true); settings.set('miniPosition', null);
  await wait(4000);
  main().close(); await wait(2500);
  t('Cerrar con música → la ventana principal se esconde', !main().isVisible());
  const m = miniW();
  t('…y sale el reproductor flotante', !!m && m.isVisible());
  t('…siempre encima y fuera de la barra de tareas', m.isAlwaysOnTop());
  const [w, h] = m.getContentSize(); const [x, y] = m.getPosition();
  console.log(`     tamaño ${w}×${h}, posición ${x},${y} (pantalla 1400×900)`);
  t('…ajustado a la ventana Win98 (290 + 3 px de borde a cada lado) y abajo a la derecha', w === 296 && h > 100 && h < 260 && x > 1000 && y > 550);
  const dom = await m.webContents.executeJavaScript(`({ pl: document.getElementById('player-pl-name').textContent, title: document.getElementById('player-title').textContent,
      artist: document.getElementById('player-artist').textContent, play: document.getElementById('btn-play').textContent,
      cur: document.getElementById('player-current').textContent, dur: document.getElementById('player-duration').textContent,
      cover: document.getElementById('player-cover').src.split('/').pop(), body: document.body.className,
      look: document.querySelectorAll('link[data-look]').length })`);
  t(`…enseña lo que suena: «${dom.title}» · ${dom.artist} · ${dom.pl} · ${dom.cur}/${dom.dur} · ${dom.play}`, dom.title === 'Kill Or Be Killed' && dom.artist === 'Muse' && dom.play === '⏸' && dom.dur === '5:00' && dom.cover === 'cover.jpg');
  t('…con el tema de la ventana principal copiado', dom.body === 'tema-prueba' && dom.look === 2);
  require('fs').writeFileSync('/app/.e2e/mini.png', (await m.webContents.capturePage()).toPNG());
  await m.webContents.executeJavaScript(`document.getElementById('btn-next').click(); document.getElementById('btn-prev').click(); document.getElementById('btn-play').click();
      const p = document.getElementById('player-progress'); p.value = 50; p.dispatchEvent(new Event('change'));`);
  await wait(800);
  t('Botones del flotante → órdenes a la ventana principal: ' + orders.join(', '), orders.join() === 'nexttrack,previoustrack,toggle,seekto 150');
  await m.webContents.executeJavaScript(`document.getElementById('btn-play').click()`); await wait(800);
  orders.length = 0;
  await m.webContents.executeJavaScript(`document.getElementById('mini-close').click()`); await wait(800);
  t('X del flotante → se cierra y la música se pausa (' + orders.join() + ')', !m.isVisible() && orders.join() === 'pause' && !main().isVisible());
  tray.openMain(); await wait(500);
  t('"Abrir Melon Hub" → vuelve la ventana principal', main().isVisible());
  await main().webContents.executeJavaScript(`st.playing = true`); await wait(800);
  main().close(); await wait(1500);
  t('Volver a cerrar con música → sale otra vez el flotante', miniW().isVisible());
  await miniW().webContents.executeJavaScript(`document.getElementById('mini-open').click()`); await wait(500);
  t('Botón de abrir del flotante → ventana principal y el flotante se va', main().isVisible() && !miniW().isVisible());
  const item = tray.menuTemplate().find((i) => i.label === 'Reproductor flotante');
  t('Menú del icono: ' + tray.menuTemplate().filter((i) => i.label).map((i) => i.label + (i.type === 'checkbox' ? (i.checked ? ' [✓]' : ' [ ]') : '')).join(' · '), item.checked === true);
  item.click({ checked: false });
  orders.length = 0; main().close(); await wait(1500);
  t('Flotante desactivado → cerrar con música la pausa y no sale nada (' + orders.join() + ')', !main().isVisible() && !miniW().isVisible() && orders.join() === 'pause');
  t('…y se recuerda en los ajustes', settings.get('floatingPlayer') === false);
  tray.menuTemplate().find((i) => i.label === 'Reproductor flotante').click({ checked: true });
  tray.openMain(); await wait(300);
  orders.length = 0; main().close(); await wait(1500);
  t('Flotante activo pero música en pausa → no sale el flotante', !miniW().isVisible() && !main().isVisible());
  app.on('will-quit', () => console.log(ok ? '\nTodo OK (y "Cerrar" sale de verdad)' : '\nHAY FALLOS'));
  tray.menuTemplate().find((i) => i.label === 'Cerrar').click();
});
