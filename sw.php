<?php
/**
 * Service worker. Served from PHP so its cache name follows the release version: after an
 * update, tablets pick up the new CSS and scripts on their own.
 *
 * Pages are never cached (they hold security tokens and change when recipes are edited).
 * The app's own files and recipe photos are, so screens open fast on weak kitchen wifi
 * and show a proper offline screen instead of the browser's error page.
 */
require_once __DIR__ . '/version.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ./');
$v = preg_replace('/[^0-9A-Za-z.\-]/', '', MUSTR_VERSION);
$shell = ['assets/app.css', 'assets/app.js', 'assets/fonts/archivo-var.woff2', 'assets/icons/icon-192.png', 'assets/icons/favicon-32.png', 'offline.html'];
?>
'use strict';
const CACHE = 'mustr-recipes-<?= $v ?>';
const PHOTOS = 'mustr-recipes-photos';
const SHELL = <?= json_encode($shell) ?>;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('mustr-recipes-') && k !== CACHE && k !== PHOTOS).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  // Photos never change once uploaded (a new photo gets a new name), so cache-first is safe.
  if (url.pathname.includes('/uploads/')) {
    e.respondWith(caches.open(PHOTOS).then((c) => c.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) c.put(req, res.clone());
      return res;
    }))));
    return;
  }
  if (url.pathname.includes('/assets/')) {
    e.respondWith(caches.open(CACHE).then((c) => c.match(req, { ignoreSearch: true }).then((hit) => {
      const live = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || live;
    })));
  }
});
