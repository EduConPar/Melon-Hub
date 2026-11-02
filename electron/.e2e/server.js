/* Sirve la web de prueba y hace de assets/music/api.php. */
const http = require('http'), fs = require('fs'), path = require('path');
const root = path.join(__dirname, 'site');
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const playlists = [
  { id: 7, name: 'Playlist para bombardear peru', tracks: [
    { videoId: 'abc', title: 'Kill Or Be Killed', artist: 'Muse' }, { videoId: 'def', title: 'Uprising', artist: 'Muse' },
    { videoId: 'ghi', title: 'Psycho', artist: 'Muse' } ] },
  { id: 9, name: 'Chill de domingo', tracks: [ { videoId: 'jkl', title: 'Bloom', artist: 'The Paper Kites' } ] },
];
const lrc = '[00:25.00]You better run\n[00:29.00]You better hide\n[00:33.00]Kill or be killed\n[00:37.00]Now we are free';
http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  if (u.pathname.endsWith('/api.php')) {
    res.setHeader('Content-Type', 'application/json');
    if (u.searchParams.get('action') === 'get-playlists') return res.end(JSON.stringify(playlists));
    if (u.searchParams.get('action') === 'get-lyrics') return res.end(JSON.stringify({ ok: true, found: true, synced: lrc, plain: '' }));
    return res.end('{}');
  }
  const f = path.join(root, decodeURIComponent(u.pathname));
  fs.readFile(f, (err, data) => {
    if (err) { res.statusCode = 404; return res.end('404'); }
    res.setHeader('Content-Type', types[path.extname(f)] || 'application/octet-stream');
    res.end(data);
  });
}).listen(8099, '127.0.0.1');
