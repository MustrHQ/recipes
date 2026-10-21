<?php
/**
 * Service worker: makes the kitchen screens an installable app and keeps them quick on weak wifi.
 *
 * - Pages are never cached (they hold security tokens and change when recipes are edited).
 * - Stylesheets and scripts are requested with a version stamp (?v=), and are cached under that
 *   exact address, so a new release can never be shown with an old stylesheet.
 * - Photos are cached by name; a new photo always gets a new name.
 *
 * The file's content changes whenever any app file changes (the fingerprint below), so browsers
 * notice an update even when files were copied up by FTP.
 */
require_once __DIR__ . '/version.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Service-Worker-Allowed: ./');

/** The address the pages themselves use for an asset (see asset() in inc/bootstrap.php). */
$stamp = fn(string $f) => 'assets/' . $f . '?v=' . (@filemtime(__DIR__ . '/assets/' . $f) ?: MUSTR_VERSION);
$shell = [$stamp('app.css'), $stamp('app.js'), $stamp('printer.js'), $stamp('labels.js'),
          'assets/fonts/archivo-var.woff2', 'assets/icons/icon-192.png', 'assets/icons/favicon-32.png', 'offline.html'];
$fingerprint = substr(sha1(MUSTR_VERSION . implode('|', $shell)), 0, 12);
?>
'use strict';
const CACHE = 'mustr-recipes-<?= $fingerprint ?>';
const PHOTOS = 'mustr-recipes-photos';
const SHELL = <?= json_encode($shell, JSON_UNESCAPED_SLASHES) ?>;
// Pages someone may be typing into: never reloaded under them after an update.
const KEEP_OPEN = ['/admin/recipe-edit.php', '/admin/labels.php', '/admin/transfer.php', '/admin/settings.php', '/admin/brands.php'];

self.addEventListener('install', (e) => {
  // cache: 'reload' skips the browser's own cache, which may hold last year's copy of a file.
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => Promise.all(SHELL.map((u) => fetch(new Request(u, { cache: 'reload' })).then((r) => r.ok ? c.put(u, r) : null).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const old = (await caches.keys()).filter((k) => k.startsWith('mustr-recipes-') && k !== CACHE && k !== PHOTOS);
    await Promise.all(old.map((k) => caches.delete(k)));
    await self.clients.claim();
    if (!old.length) return;
    // An update: reload open screens once so none keeps showing the old look.
    const wins = await self.clients.matchAll({ type: 'window' });
    for (const w of wins) {
      const path = new URL(w.url).pathname;
      if (!KEEP_OPEN.some((p) => path.endsWith(p))) w.navigate(w.url).catch(() => {});
    }
  })());
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
  if (url.pathname.includes('/uploads/') || url.pathname.endsWith('/thumb.php')) {
    e.respondWith(caches.open(PHOTOS).then((c) => c.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) c.put(req, res.clone());
      return res;
    }))));
    return;
  }
  if (url.pathname.includes('/assets/')) {
    e.respondWith(caches.open(CACHE).then(async (c) => {
      // Exact address only: a stylesheet stamped ?v=123 is never answered with ?v=100.
      const hit = await c.match(req);
      if (hit) return hit;
      try {
        const res = await fetch(req);
        if (res.ok) c.put(req, res.clone());
        return res;
      } catch (err) {
        // Offline and never seen this exact version: any copy is better than none.
        return (await c.match(req, { ignoreSearch: true })) || Response.error();
      }
    }));
  }
});
