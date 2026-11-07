import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');

function harness(options = [{}], { frameCallback = true } = {}) {
  let now = 100000;
  let timerId = 0;
  const timers = new Map();
  const videos = [];
  const events = new Map();
  const observed = new Set();
  const canvases = options.map((option, index) => {
    const classes = new Set(['is-loading']);
    const container = {
      classList: { add: (...names) => names.forEach(n => classes.add(n)), remove: (...names) => names.forEach(n => classes.delete(n)) },
      rect: { top: option.offscreen ? 2000 : 10, bottom: option.offscreen ? 2100 : 110, left: 0, right: 200, width: 200, height: 100 },
      getBoundingClientRect() { return this.rect; },
      classes,
    };
    return {
      dataset: { previewSrc: `/viewer/preview?id=${index + 1}`, previewRefresh: option.off ? 'off' : '10', previewRefreshMs: String(option.ms || 10000) },
      isConnected: true,
      hidden: true,
      width: 640,
      height: 360,
      container,
      frames: [],
      closest: () => container,
      getContext() {
        return { drawImage: (...args) => {
          if (this.failDraw) throw new Error('Decode failed');
          this.frames.push(args);
        } };
      },
    };
  });
  const listen = (name, fn) => events.set(name, [...(events.get(name) || []), fn]);
  const document = {
    hidden: false,
    querySelectorAll: selector => selector === 'canvas[data-preview-src]' ? canvases : [],
    querySelector: () => null,
    getElementById: () => null,
    addEventListener: listen,
    createElement: tag => {
      assert.equal(tag, 'video');
      const video = {
        attributes: {},
        src: '',
        readyState: 0,
        videoWidth: 0,
        videoHeight: 0,
        duration: 0.1,
        currentTime: 0,
        pauses: 0,
        frame: null,
        requestVideoFrameCallback: frameCallback ? function (fn) { this.frame = fn; return 1; } : undefined,
        cancelVideoFrameCallback(id) { assert.equal(id, 1); this.frame = null; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { if (name === 'src') this.src = ''; },
        pause() { this.pauses++; },
        load() {},
        play() { assert.fail('A single-frame preview must not play'); },
      };
      videos.push(video);
      return video;
    },
  };
  const window = {
    innerWidth: 1440,
    innerHeight: 900,
    addEventListener: listen,
    setTimeout(fn, delay = 0) { timers.set(++timerId, { fn, at: now + delay }); return timerId; },
    clearTimeout(id) { timers.delete(id); },
    IntersectionObserver: class {
      observe(element) { observed.add(element); }
      unobserve(element) { observed.delete(element); }
    },
  };
  // Expose only lifecycle entrypoints inside the isolated test VM.
  const instrumented = source.replace(/\}\)\(\);\s*$/, 'window.previewTest = {initPreviewRefresh, disposePreviewRefresh}; })();');
  vm.runInNewContext(instrumented, { window, document, Date: { now: () => now }, Intl, URL, IntersectionObserver: window.IntersectionObserver });
  function tick(ms = 0) {
    const end = now + ms;
    let count = 0;
    while (true) {
      const next = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!next) break;
      assert.ok(++count < 1000, 'Timer loop');
      now = next[1].at;
      timers.delete(next[0]);
      next[1].fn();
    }
    now = end;
  }
  function loaded(video = videos.at(-1)) {
    video.readyState = 2;
    video.videoWidth = 1920;
    video.videoHeight = 1080;
    video.onloadeddata?.();
    video.oncanplay?.();
    tick();
  }
  function ready(video = videos.at(-1)) {
    loaded(video);
    const callback = video.frame;
    video.frame = null;
    if (callback) callback(); else video.onseeked?.();
    tick();
  }
  function emit(name) { for (const fn of events.get(name) || []) fn(); tick(); }
  return { canvases, videos, document, window, observed, tick, loaded, ready, emit };
}

test('loadeddata and canplay do not publish pixels before a frame is available', () => {
  const h = harness();
  h.tick();
  h.loaded();
  assert.equal(h.canvases[0].hidden, true);
  assert.equal(h.canvases[0].frames.length, 0);
  assert.notEqual(h.videos[0].src, '');
  h.ready();
  assert.equal(h.canvases[0].frames.length, 1);
  assert.equal(h.videos[0].src, '');
});

test('without video frame callbacks, seek inside the preview before drawing', () => {
  const h = harness([{}], { frameCallback: false });
  h.tick();
  h.loaded();
  assert.equal(h.videos[0].currentTime, 0.05);
  assert.equal(h.canvases[0].frames.length, 0);
  h.videos[0].onseeked();
  assert.equal(h.canvases[0].frames.length, 1);
  assert.equal(h.videos[0].src, '');
});

test('cancelling a pending frame callback prevents late drawing and releases the decoder', () => {
  const h = harness();
  h.tick();
  h.loaded();
  const v = h.videos[0], lateFrame = v.frame;
  h.emit('pagehide');
  assert.equal(v.frame, null);
  assert.equal(v.src, '');
  lateFrame();
  assert.equal(h.canvases[0].frames.length, 0);
});

test('refreshes a static canvas on the personal interval without clearing the old frame', () => {
  const h = harness();
  h.tick();
  assert.equal(h.videos.length, 1);
  const first = h.videos[0];
  const firstUrl = first.src;
  assert.equal(firstUrl, '/viewer/preview?id=1&_=100000');
  assert.equal(first.muted, true);
  assert.equal(first.playsInline, true);
  assert.equal(first.preload, 'auto');
  assert.equal(first.loop, undefined);
  assert.equal(first.controls, undefined);
  h.ready();
  const canvas = h.canvases[0];
  assert.equal(canvas.hidden, false);
  assert.equal(canvas.frames.length, 1);
  assert.equal(first.src, '');
  assert.equal(first.pauses, 1);
  h.tick(9999);
  assert.equal(h.videos.length, 1);
  h.tick(1);
  assert.equal(h.videos.length, 2);
  assert.notEqual(h.videos[1].src, firstUrl);
  assert.equal(canvas.frames.length, 1);
  assert.equal(canvas.container.classes.has('is-loading'), false);
  h.videos[1].onerror();
  h.tick();
  assert.equal(canvas.hidden, false);
  assert.equal(canvas.frames.length, 1);
  h.tick(10000);
  h.ready();
  assert.equal(canvas.frames.length, 2);
  assert.deepEqual([canvas.width, canvas.height], [640, 360]);
});

test('off loads once when first visible, not once per scroll or visibility change', () => {
  const h = harness([{ off: true, offscreen: true }]);
  h.tick(30000);
  assert.equal(h.videos.length, 0);
  Object.assign(h.canvases[0].container.rect, { top: 10, bottom: 110 });
  h.emit('scroll');
  h.ready();
  h.document.hidden = true;
  h.emit('visibilitychange');
  h.tick(300000);
  h.document.hidden = false;
  h.emit('visibilitychange');
  h.emit('scroll');
  assert.equal(h.videos.length, 1);
});

test('honors longer intervals and clamps invalid short intervals', () => {
  const h = harness([{ ms: 60000 }, { ms: 1 }]);
  h.tick();
  h.ready(h.videos[0]);
  h.ready(h.videos[1]);
  h.tick(10000);
  assert.equal(h.videos.length, 3);
  assert.ok(h.videos[2].src.includes('id=2'));
});

test('hidden tab cancels in-flight requests and resumes once, with no catch-up burst', () => {
  const h = harness();
  h.tick();
  const old = h.videos[0];
  const lateEvent = old.frame;
  h.document.hidden = true;
  h.emit('visibilitychange');
  assert.equal(old.src, '');
  h.tick(300000);
  assert.equal(h.videos.length, 1);
  h.document.hidden = false;
  h.emit('visibilitychange');
  assert.equal(h.videos.length, 2);
  h.ready();
  old.readyState = 2;
  old.videoWidth = 1920;
  old.videoHeight = 1080;
  lateEvent();
  assert.equal(h.canvases[0].frames.length, 1);
});

test('pagehide cancels requests and pageshow restores refresh after bfcache', () => {
  const h = harness();
  h.tick();
  h.emit('pagehide');
  h.tick(60000);
  assert.equal(h.videos.length, 1);
  assert.equal(h.videos[0].src, '');
  h.emit('pageshow');
  assert.equal(h.videos.length, 2);
  h.ready();
});

test('at most four decoders are active; completed requests release queued cards', () => {
  const h = harness(Array.from({ length: 9 }, () => ({ off: true })));
  h.tick();
  assert.equal(h.videos.length, 4);
  for (let i = 0; i < 9; i++) {
    h.ready(h.videos[i]);
    assert.ok(h.videos.filter(v => v.src).length <= 4);
  }
  assert.equal(h.videos.length, 9);
  assert.ok(h.canvases.every(c => c.frames.length === 1));
  assert.ok(h.videos.every(v => v.src === ''));
});

test('offscreen and removed cards release their decoder and never draw late frames', () => {
  const h = harness([{}, {}]);
  h.tick();
  Object.assign(h.canvases[0].container.rect, { top: 2000, bottom: 2100 });
  h.canvases[1].isConnected = false;
  h.emit('scroll');
  h.tick(60000);
  assert.equal(h.videos.length, 2);
  assert.ok(h.videos.every(v => v.src === ''));
  assert.equal(h.observed.size, 1);
  Object.assign(h.canvases[0].container.rect, { top: 10, bottom: 110 });
  h.emit('scroll');
  assert.equal(h.videos.length, 3);
});

test('slow or timed-out cards cannot starve the remaining visible cards', () => {
  const h = harness(Array.from({ length: 8 }, () => ({})));
  h.tick();
  h.tick(20000);
  assert.equal(h.videos.length, 8);
  for (let i = 4; i < 8; i++) assert.ok(h.videos[i].src.includes(`id=${i + 1}&`));
});

test('failed first frame shows unavailable; timeouts release requests and permit recovery', () => {
  const h = harness([{ ms: 30000 }]);
  h.tick();
  h.tick(20000);
  assert.equal(h.canvases[0].container.classes.has('no-preview'), true);
  assert.equal(h.canvases[0].dataset.previewLoading, undefined);
  assert.equal(h.videos[0].src, '');
  h.tick(10000);
  h.ready();
  assert.equal(h.canvases[0].container.classes.has('no-preview'), false);
  assert.equal(h.canvases[0].hidden, false);
});

test('canvas draw failures preserve the previous frame', () => {
  const h = harness();
  h.tick();
  h.ready();
  h.tick(10000);
  h.canvases[0].failDraw = true;
  h.ready();
  assert.equal(h.canvases[0].frames.length, 1);
  assert.equal(h.canvases[0].hidden, false);
  assert.equal(h.canvases[0].dataset.previewLoading, undefined);
});

test('map popup disposal cancels off requests and permits a fresh frame on reopening', () => {
  const h = harness([{ off: true }]);
  h.tick();
  const root = { contains: c => c === h.canvases[0], querySelectorAll: () => h.canvases };
  h.window.previewTest.disposePreviewRefresh(root);
  h.tick(60000);
  assert.equal(h.observed.size, 0);
  assert.equal(h.videos[0].src, '');
  h.window.previewTest.initPreviewRefresh(root);
  h.tick();
  assert.equal(h.videos.length, 2);
  h.ready();
});
