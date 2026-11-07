'use strict';

const OFFLINE_CACHE = 'sesame-portal-offline-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', event => {
  event.waitUntil(caches.open(OFFLINE_CACHE).then(cache => cache.add(new Request(OFFLINE_URL, {cache: 'reload'})))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(names => Promise.all(names
    .filter(name => name.startsWith('sesame-portal-offline-') && name !== OFFLINE_CACHE)
    .map(name => caches.delete(name)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  // Only document navigations get an offline explanation. API, previews, media,
  // playback tokens and authenticated HTML are never stored in Cache Storage.
  const page = ['/', '/login', '/logout', '/settings', '/viewer/map', '/viewer/player',
    '/video-walls', '/video-walls/edit', '/video-walls/view'].includes(url.pathname)
    || url.pathname.startsWith('/admin/');
  if (request.method !== 'GET' || request.mode !== 'navigate' || url.origin !== self.location.origin || !page) return;
  event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL, {cacheName: OFFLINE_CACHE})));
});
