/* MustrHQ Station Recipes — front-end behaviour. No libraries. */
(function () {
  'use strict';
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  /* ---------- Image preview (lightbox) ---------- */
  var lb = $('#lb');
  if (lb) {
    var img = $('#lbImg'), cap = $('#lbCap'), pos = $('#lbPos'), stage = $('#lbStage');
    var list = [], idx = 0, zoom = 1, tx = 0, ty = 0, lastFocus = null;

    var items = function () {
      return $$('[data-lb]').filter(function (el) { return el.getAttribute('data-lb') && !el.closest('.drawer:not(.on)'); });
    };
    var apply = function () { img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + zoom + ')'; img.classList.toggle('zoomed', zoom > 1); };
    var resetZoom = function () { zoom = 1; tx = 0; ty = 0; apply(); };
    var show = function (i) {
      idx = (i + list.length) % list.length;
      var el = list[idx];
      img.src = el.getAttribute('data-lb');
      img.alt = el.getAttribute('data-cap') || '';
      cap.textContent = el.getAttribute('data-cap') || '';
      pos.textContent = list.length > 1 ? (idx + 1) + ' of ' + list.length : '';
      $('#lbPrev').hidden = $('#lbNext').hidden = list.length < 2;
      resetZoom();
    };
    var open = function (el) {
      list = items();
      if (!list.length) return;
      lastFocus = document.activeElement;
      lb.hidden = false; lb.classList.add('on');
      show(Math.max(0, list.indexOf(el)));
      $('#lbClose').focus();
    };
    var close = function () {
      lb.classList.remove('on'); lb.hidden = true; img.removeAttribute('src');
      if (lastFocus) lastFocus.focus();
    };
    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-lb]');
      if (el && el.getAttribute('data-lb') && !lb.contains(el)) { e.preventDefault(); open(el); }
    });
    $('#lbClose').addEventListener('click', close);
    $('#lbPrev').addEventListener('click', function () { show(idx - 1); });
    $('#lbNext').addEventListener('click', function () { show(idx + 1); });
    stage.addEventListener('click', function (e) { if (e.target === stage) close(); });

    // Double-tap / double-click to zoom; drag to pan when zoomed; swipe to change picture.
    var lastTap = 0, sx = 0, sy = 0, ox = 0, oy = 0, dragging = false, moved = false;
    var toggleZoom = function (cx, cy) {
      if (zoom > 1) { resetZoom(); return; }
      var r = img.getBoundingClientRect();
      zoom = 2.5;
      tx = (r.left + r.width / 2 - cx) * (zoom - 1);
      ty = (r.top + r.height / 2 - cy) * (zoom - 1);
      apply();
    };
    img.addEventListener('dblclick', function (e) { toggleZoom(e.clientX, e.clientY); });
    stage.addEventListener('pointerdown', function (e) {
      if (e.target !== img) return;
      dragging = true; moved = false; sx = e.clientX; sy = e.clientY; ox = tx; oy = ty;
      img.setPointerCapture(e.pointerId);
    });
    stage.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var dx = e.clientX - sx, dy = e.clientY - sy;
      if (Math.abs(dx) > 6 || Math.abs(dy) > 6) moved = true;
      if (zoom > 1) { tx = ox + dx; ty = oy + dy; apply(); }
    });
    stage.addEventListener('pointerup', function (e) {
      if (!dragging) return;
      dragging = false;
      var dx = e.clientX - sx;
      if (zoom === 1 && moved && Math.abs(dx) > 60 && list.length > 1) { show(idx + (dx < 0 ? 1 : -1)); return; }
      if (!moved && e.pointerType !== 'mouse') {
        var now = Date.now();
        if (now - lastTap < 320) { toggleZoom(e.clientX, e.clientY); lastTap = 0; } else lastTap = now;
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('on')) return;
      if (e.key === 'Escape') { close(); e.stopImmediatePropagation(); }
      else if (e.key === 'ArrowLeft') { show(idx - 1); e.stopImmediatePropagation(); }
      else if (e.key === 'ArrowRight') { show(idx + 1); e.stopImmediatePropagation(); }
    }, true);
  }

  /* ---------- Live search on the library ---------- */
  var q = $('[data-live-search]');
  var grid = $('#grid');
  if (q && grid) {
    var tiles = $$('[data-search]', grid), count = $('#count'), none = $('#noMatch');
    q.addEventListener('input', function () {
      var terms = q.value.toLowerCase().trim().split(/\s+/).filter(Boolean), shown = 0;
      tiles.forEach(function (t) {
        var hay = t.getAttribute('data-search');
        var ok = terms.every(function (w) { return hay.indexOf(w) !== -1; });
        t.hidden = !ok; if (ok) shown++;
      });
      $$('[data-group]', grid).forEach(function (g) { g.hidden = !$$('[data-search]:not([hidden])', g).length; });
      if (count) count.textContent = shown + (shown === 1 ? ' recipe' : ' recipes');
      if (none) none.hidden = shown > 0;
    });
  }

  /* ---------- Batch scaling and ticking off ingredients ---------- */
  var scale = $('[data-scale]');
  if (scale) {
    var fmt = function (n) {
      if (Math.abs(n - Math.round(n)) < 0.01) return String(Math.round(n));
      return (Math.round(n * 10) / 10).toString();
    };
    scale.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-x]'); if (!b) return;
      var x = parseFloat(b.getAttribute('data-x'));
      $$('button', scale).forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      $$('[data-qty]').forEach(function (el) {
        var raw = el.getAttribute('data-qty');
        var v = parseFloat(String(raw).replace(',', '.'));
        el.textContent = (/^\s*[\d.,]+\s*$/.test(raw) && !isNaN(v)) ? fmt(v * x) : raw;
      });
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-got]')) e.target.closest('.ing').classList.toggle('got', e.target.checked);
  });

  /* ---------- Cook mode ---------- */
  var cook = $('[data-cook]');
  if (cook) {
    var total = parseInt(cook.getAttribute('data-total'), 10);
    var slides = $$('[data-slide]'), segs = $$('.segs [data-go]'), finish = $('[data-finish]');
    var prev = $('[data-prev]'), next = $('[data-next]'), nextLabel = $('[data-next-label]');
    var cur = 0;
    var go = function (i) {
      cur = Math.max(0, Math.min(total, i));
      slides.forEach(function (s, n) { s.classList.toggle('on', n === cur); });
      segs.forEach(function (s, n) {
        s.classList.toggle('done', n < cur); s.classList.toggle('now', n === cur);
        if (n === cur) s.setAttribute('aria-current', 'step'); else s.removeAttribute('aria-current');
      });
      finish.classList.toggle('on', cur === total);
      prev.disabled = cur === 0;
      next.hidden = cur === total;
      nextLabel.textContent = cur === total - 1 ? 'Finish' : 'Next step';
      history.replaceState(null, '', '#step-' + (cur + 1));
      var t = slides[cur] && $('.ctext', slides[cur]); if (t) t.scrollTop = 0;
    };
    var m = /#step-(\d+)/.exec(location.hash);
    go(m ? parseInt(m[1], 10) - 1 : 0);

    prev.addEventListener('click', function () { go(cur - 1); });
    next.addEventListener('click', function () { go(cur + 1); });
    document.addEventListener('click', function (e) {
      var g = e.target.closest('[data-go]'); if (g) go(parseInt(g.getAttribute('data-go'), 10));
    });
    document.addEventListener('keydown', function (e) {
      if ($('#drawer').classList.contains('on')) { if (e.key === 'Escape') closeDrawer(); return; }
      if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') { e.preventDefault(); go(cur + 1); }
      if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); go(cur - 1); }
    });
    // Swipe between steps (ignored when swiping inside long text).
    var stg = $('#stage'), tsx = 0, tsy = 0;
    stg.addEventListener('touchstart', function (e) { tsx = e.touches[0].clientX; tsy = e.touches[0].clientY; }, { passive: true });
    stg.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - tsx, dy = e.changedTouches[0].clientY - tsy;
      if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) go(cur + (dx < 0 ? 1 : -1));
    }, { passive: true });

    // Ingredient drawer
    var drawer = $('#drawer');
    var openDrawer = function () { drawer.classList.add('on'); drawer.setAttribute('aria-hidden', 'false'); $('[data-close-drawer].btn', drawer).focus(); };
    var closeDrawer = function () { drawer.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); $('[data-open-drawer]').focus(); };
    $('[data-open-drawer]').addEventListener('click', openDrawer);
    $$('[data-close-drawer]').forEach(function (b) { b.addEventListener('click', closeDrawer); });

    // Step timers with an alarm tone.
    var actx = null;
    var beep = function () {
      try {
        actx = actx || new (window.AudioContext || window.webkitAudioContext)();
        [0, .35, .7].forEach(function (d) {
          var o = actx.createOscillator(), g = actx.createGain();
          o.frequency.value = 880; o.type = 'square'; g.gain.value = .15;
          o.connect(g); g.connect(actx.destination);
          o.start(actx.currentTime + d); o.stop(actx.currentTime + d + .22);
        });
      } catch (err) { /* sound not available */ }
      if (navigator.vibrate) navigator.vibrate([300, 150, 300]);
    };
    $$('[data-timer]').forEach(function (box) {
      var full = parseInt(box.getAttribute('data-timer'), 10), left = full, tick = null, ringer = null;
      var clock = $('.clock', box), startBtn = $('[data-t-start]', box), label = $('span', startBtn);
      var draw = function () { var s = Math.max(0, left); clock.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
      var stopRing = function () { clearInterval(ringer); ringer = null; box.classList.remove('ring'); };
      var pause = function () { clearInterval(tick); tick = null; box.classList.remove('run'); label.textContent = 'Resume'; };
      var end = Date.now();
      startBtn.addEventListener('click', function () {
        if (actx === null) { try { actx = new (window.AudioContext || window.webkitAudioContext)(); } catch (err) {} }
        if (ringer) { stopRing(); left = full; draw(); label.textContent = 'Start timer'; return; }
        if (tick) { pause(); return; }
        end = Date.now() + left * 1000;
        box.classList.add('run'); label.textContent = 'Pause';
        tick = setInterval(function () {
          left = Math.round((end - Date.now()) / 1000); draw();
          if (left <= 0) {
            clearInterval(tick); tick = null; box.classList.remove('run'); box.classList.add('ring');
            label.textContent = 'Stop alarm'; beep(); ringer = setInterval(beep, 2500);
          }
        }, 250);
      });
      $('[data-t-reset]', box).addEventListener('click', function () { clearInterval(tick); tick = null; stopRing(); box.classList.remove('run'); left = full; draw(); label.textContent = 'Start timer'; });
    });

    // Keep the screen awake while cooking (supported browsers only).
    var lock = null;
    var wake = function () { if ('wakeLock' in navigator) navigator.wakeLock.request('screen').then(function (l) { lock = l; }).catch(function () {}); };
    wake();
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') wake(); });
  }

  /* ---------- Small helpers used across pages (no inline scripts: the CSP forbids them) ---------- */
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-confirm]');
    if (c && !confirm(c.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    if (e.target.closest('[data-print]')) window.print();
  }, true);

  // "Select all" boxes in tables.
  document.addEventListener('change', function (e) {
    var all = e.target.closest('[data-check-all]');
    if (all) $$('input[name="' + all.getAttribute('data-check-all') + '"]').forEach(function (b) { b.checked = all.checked; });
  });

  // "/" jumps to search, like most web apps.
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || e.target.isContentEditable) return;
    var s = $('[data-live-search]');
    if (s) { e.preventDefault(); s.focus(); s.select(); }
  });

  // Installable app: register the service worker (it only caches static files).
  if ('serviceWorker' in navigator && document.querySelector('link[rel=manifest]')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(document.body.getAttribute('data-base') + 'sw.php').catch(function () {});
    });
  }

  /* ---------- Admin: repeatable rows (ingredients, steps) ---------- */
  $$('[data-rows]').forEach(function (wrap) {
    var tpl = document.getElementById(wrap.getAttribute('data-rows'));
    var renumber = function () { $$('.row', wrap).forEach(function (r, n) { var el = $('.num', r); if (el) el.textContent = n + 1; }); };
    var add = function (focus) {
      var key = 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
      var div = document.createElement('div');
      div.innerHTML = tpl.innerHTML.replace(/__KEY__/g, key).trim();
      var row = div.firstElementChild; wrap.appendChild(row); renumber();
      if (focus !== false) { var first = $('input[type=text], textarea', row); if (first) first.focus(); }
    };
    wrap.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]'); if (!b) return;
      var row = b.closest('.row'), act = b.getAttribute('data-act');
      if (act === 'up' && row.previousElementSibling) wrap.insertBefore(row, row.previousElementSibling);
      if (act === 'down' && row.nextElementSibling) wrap.insertBefore(row.nextElementSibling, row);
      if (act === 'del') {
        var name = ($('input[type=text], textarea', row) || {}).value;
        if (!name || confirm('Remove "' + name.slice(0, 60) + '"?')) row.remove();
      }
      renumber();
    });
    var addBtn = document.querySelector('[data-add="' + wrap.id + '"]');
    if (addBtn) addBtn.addEventListener('click', function () { add(true); });
    // A new recipe starts with one blank row to fill in.
    if (!$('.row', wrap) && wrap.hasAttribute('data-start-empty')) add(false);
    renumber();
  });

  // Admin: preview a picked image, and shrink big photos in the browser so they
  // fit shared-hosting upload limits (often 2 MB per file).
  var MAX_SIDE = 1800;
  var shrink = function (input, file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.DataTransfer || !window.createImageBitmap) return Promise.resolve();
    return createImageBitmap(file).then(function (bmp) {
      var big = Math.max(bmp.width, bmp.height) > MAX_SIDE;
      if (!big && file.size < 1.5 * 1024 * 1024) return;
      var r = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * r); c.height = Math.round(bmp.height * r);
      var cx = c.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, c.width, c.height);
      cx.drawImage(bmp, 0, 0, c.width, c.height);
      return new Promise(function (res) {
        c.toBlob(function (blob) {
          if (blob && blob.size < file.size) {
            var dt = new DataTransfer();
            dt.items.add(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
            input.files = dt.files;
          }
          res();
        }, 'image/jpeg', 0.85);
      });
    }).catch(function () {});
  };
  var pending = 0;
  document.addEventListener('change', function (e) {
    var f = e.target;
    if (!f.matches('input[type=file][data-preview]') || !f.files || !f.files[0]) return;
    var pv = f.closest('.imgpick').querySelector('.pv');
    pv.innerHTML = '<img alt="" src="' + URL.createObjectURL(f.files[0]) + '">';
    var lbl = f.closest('.imgpick').querySelector('[data-file-label]');
    if (lbl) lbl.textContent = 'Photo ready to save';
    pending++;
    shrink(f, f.files[0]).then(function () { pending--; });
  });
  var upForm = $('form[data-max-files]');
  if (upForm) {
    upForm.addEventListener('submit', function (e) {
      if (pending > 0) { e.preventDefault(); setTimeout(function () { upForm.requestSubmit ? upForm.requestSubmit() : upForm.submit(); }, 300); return; }
      var files = $$('input[type=file]', upForm).filter(function (i) { return i.files && i.files.length; });
      var bytes = files.reduce(function (t, i) { return t + i.files[0].size; }, 0);
      var maxFiles = parseInt(upForm.getAttribute('data-max-files'), 10), maxPost = parseInt(upForm.getAttribute('data-max-post'), 10);
      var note = $('[data-upload-note]');
      var maxFile = parseInt(upForm.getAttribute('data-max-file'), 10);
      var tooBig = files.filter(function (i) { return maxFile && i.files[0].size > maxFile; });
      if (tooBig.length) {
        e.preventDefault();
        alert('"' + tooBig[0].files[0].name + '" is ' + (tooBig[0].files[0].size / 1048576).toFixed(1) + ' MB. Your server accepts up to ' + (maxFile / 1048576).toFixed(0) + ' MB per photo. Pick a smaller photo.');
      } else if (files.length > maxFiles) {
        e.preventDefault();
        alert('Your server accepts ' + maxFiles + ' photos per save and you picked ' + files.length + '. Save now with fewer new photos, then add the rest.');
      } else if (bytes > maxPost * 0.95) {
        e.preventDefault();
        alert('These photos add up to ' + (bytes / 1048576).toFixed(1) + ' MB, more than your server accepts in one save (' + (maxPost / 1048576).toFixed(0) + ' MB). Save with fewer new photos, then add the rest.');
      } else if (note) {
        note.textContent = files.length ? 'Uploading ' + files.length + ' photo' + (files.length === 1 ? '' : 's') + '…' : 'Saving…';
      }
    });
  }

  // Admin: warn before leaving a form with unsaved changes.
  var dirtyForm = $('form[data-dirty]');
  if (dirtyForm) {
    var dirty = false;
    dirtyForm.addEventListener('input', function () { dirty = true; });
    dirtyForm.addEventListener('change', function () { dirty = true; });
    dirtyForm.addEventListener('click', function (e) { if (e.isTrusted && e.target.closest('[data-act], [data-add]')) dirty = true; });
    dirtyForm.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }
})();
