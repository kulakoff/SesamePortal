// Run with node tests/preview_browser_fixture.mjs; requires ffmpeg, uses no Portal DB.
// Add --cross-origin to reproduce the Portal -> DVR redirect in iOS Safari.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';

const frames = ['testsrc2', 'smptebars'].map(pattern => execFileSync('ffmpeg', [
  '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', `${pattern}=size=640x360:rate=25`,
  '-frames:v', '1', '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
  '-movflags', 'frag_keyframe+empty_moov+default_base_moof', '-f', 'mp4', 'pipe:1',
]));
const requests = [];
const imageKeys = new Map();
const counts = new Map();
const assets = new Map([
  ['/assets/app.js', ['application/javascript', new URL('../public/assets/app.js', import.meta.url)]],
  ['/assets/styles.css', ['text/css', new URL('../public/assets/styles.css', import.meta.url)]],
]);
const card = (id, off = false) => `<article class="camera-card">
  <a class="preview is-loading" href="/player?id=${id}" aria-label="Open player">
    <canvas data-preview-src="/viewer/preview?id=${id}" data-preview-refresh="${off ? 'off' : '10'}" data-preview-refresh-ms="10000" width="640" height="360" aria-hidden="true" hidden></canvas>
    <span class="preview-spinner" aria-hidden="true"></span><span class="preview-state">Preview unavailable</span><span class="preview-play" aria-hidden="true"></span>
  </a><div class="camera-meta"><strong>Camera ${id}</strong><span>${off ? 'Refresh off' : 'Refresh 10s'}</span></div>
</article>`;
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/styles.css"><title>MP4 preview test</title></head><body>
  <main style="max-width:1200px;margin:auto;padding:24px"><h1>MP4 preview test</h1>
    <section class="camera-grid cols-3">${card(1)}${card(2, true)}${card(4)}</section>
    <div style="height:1200px"></div><section class="camera-grid cols-3">${card(3)}</section>
  </main><output id="diagnostics" hidden></output><script src="/assets/app.js"></script>
  <script>setInterval(() => {
    document.getElementById('diagnostics').textContent = JSON.stringify(Array.from(document.querySelectorAll('canvas')).map(c => {
      let pixels;
      try { pixels = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; }
      catch { return {id:c.dataset.previewSrc, hidden:c.hidden, tainted:true}; }
      let checksum = 0;
      for (let i = 0; i < pixels.length; i += 16) checksum = (checksum + pixels[i] * (i + 1)) >>> 0;
      return {id:c.dataset.previewSrc, hidden:c.hidden, checksum};
    }));
  }, 500);</script></body></html>`;
let mediaOrigin = '';
const handleRequest = (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  res.setHeader('Cache-Control', 'no-store');
  if (assets.has(url.pathname)) {
    const [type, path] = assets.get(url.pathname);
    res.setHeader('Content-Type', type);
    res.end(readFileSync(path));
  } else if (url.pathname === '/viewer/preview') {
    requests.push({ id: url.searchParams.get('id'), at: Date.now(), cacheBust: url.searchParams.get('_') });
    res.writeHead(302, { Location: `${mediaOrigin}/dvr/preview.mp4${url.search}&token=local-fixture` });
    res.end();
  } else if (url.pathname === '/dvr/preview.mp4') {
    const id = url.searchParams.get('id');
    const key = id + ':' + url.searchParams.get('_');
    if (!imageKeys.has(key)) {
      counts.set(id, (counts.get(id) || 0) + 1);
      imageKeys.set(key, counts.get(id));
    }
    const ordinal = imageKeys.get(key);
    if (id === '4' || (id === '1' && ordinal % 3 === 0)) {
      res.writeHead(404);
      res.end('Preview not found');
      return;
    }
    const data = frames[(ordinal - 1) % frames.length];
    const range = /^bytes=(\d+)-(\d*)$/.exec(req.headers.range || '');
    res.setHeader('Content-Type', 'video/mp4');
    res.setHeader('Accept-Ranges', 'bytes');
    if (range) {
      const start = Number(range[1]);
      const end = Math.min(range[2] ? Number(range[2]) : data.length - 1, data.length - 1);
      if (start > end) {
        res.writeHead(416, { 'Content-Range': `bytes */${data.length}` });
        res.end();
        return;
      }
      res.writeHead(206, { 'Content-Range': `bytes ${start}-${end}/${data.length}`, 'Content-Length': end - start + 1 });
      res.end(data.subarray(start, end + 1));
    } else {
      res.writeHead(200, { 'Content-Length': data.length });
      res.end(data);
    }
  } else if (url.pathname === '/stats') {
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify({ requests, counts: Object.fromEntries(counts), mp4Bytes: frames.map(f => f.length) }));
  } else {
    res.setHeader('Content-Type', 'text/html');
    res.end(url.pathname === '/' ? html : '<h1>Player link works</h1>');
  }
};
if (process.argv.includes('--cross-origin')) {
  const mediaServer = createServer(handleRequest);
  await new Promise(resolve => mediaServer.listen(0, '127.0.0.1', resolve));
  mediaOrigin = `http://127.0.0.1:${mediaServer.address().port}`;
}
const server = createServer(handleRequest);
server.listen(0, '127.0.0.1', () => console.log(`Preview fixture: http://127.0.0.1:${server.address().port}/`));
