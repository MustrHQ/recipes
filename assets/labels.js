/* MustrHQ Recipes — the use-by labels screen. Needs printer.js. */
(function () {
  'use strict';
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  var D = JSON.parse($('#labelData').textContent);
  var MP = window.MustrPrinter;
  MP.configure(D.cfg);
  MP.reconnect();

  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var dayStr = function (d) { return DAYS[d.getDay()] + ' ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1); };
  var timeStr = function (d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
  var isoDate = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var byId = {};
  D.items.forEach(function (i) { byId[i.id] = i; });

  /* ---------- toast ---------- */
  var toastT = null;
  function toast(msg, bad) {
    var t = $('#toast');
    t.textContent = msg; t.className = 'toast' + (bad ? ' bad' : ''); t.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, bad ? 6000 : 3500);
  }

  /* ---------- modals ---------- */
  var lastFocus = null;
  function openModal(m) {
    lastFocus = document.activeElement;
    m.hidden = false; document.body.classList.add('modal-open');
    var f = $('[data-autofocus]', m) || $('.modal-box', m).querySelector('button, input');
    if (f) setTimeout(function () { f.focus(); }, 30);
  }
  function closeModal(m) {
    m.hidden = true;
    if (!$$('.modal:not([hidden])').length) document.body.classList.remove('modal-open');
    if (lastFocus) lastFocus.focus();
  }
  $$('.modal').forEach(function (m) {
    $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = $$('.modal:not([hidden])');
    if (open.length) closeModal(open[open.length - 1]);
  });

  /* ---------- search and brand filter ---------- */
  var q = $('[data-label-search]'), brand = '';
  function filter() {
    var terms = (q ? q.value : '').toLowerCase().trim().split(/\s+/).filter(Boolean), shown = 0;
    $$('#grid .lcard').forEach(function (c) {
      var ok = (!brand || c.getAttribute('data-brand-id') === brand) && terms.every(function (w) { return c.getAttribute('data-search').indexOf(w) !== -1; });
      c.hidden = !ok; if (ok) shown++;
    });
    var cnt = $('#count'); if (cnt) cnt.textContent = shown + (shown === 1 ? ' item' : ' items');
    var none = $('#noMatch'); if (none) none.hidden = shown > 0;
  }
  if (q) q.addEventListener('input', filter);
  $$('[data-brand-filter] [data-brand]').forEach(function (b) {
    b.addEventListener('click', function () {
      brand = b.getAttribute('data-brand');
      $$('[data-brand-filter] [data-brand]').forEach(function (o) { o.setAttribute('aria-current', o === b ? 'true' : 'false'); });
      filter();
    });
  });

  /* ---------- printer chip and panel ---------- */
  function printerLine(st) {
    if (st.lang === 'browser') return 'Using the print dialog';
    if (st.connected) return 'Connected to ' + st.name;
    if (st.paired) return st.name + ', not connected';
    return st.supported ? 'No printer connected. Labels use the print dialog.' : 'Labels use the print dialog on this device.';
  }
  function chip() {
    var st = MP.status(), b = $('[data-printer-open]');
    if (!b) return;
    b.classList.toggle('ok', st.connected || st.lang === 'browser');
    b.classList.toggle('warn', st.paired && !st.connected && st.lang !== 'browser');
    $('[data-printer-name]', b).textContent = st.lang === 'browser' ? 'Print dialog' : st.connected ? st.name : st.paired ? 'Reconnect printer' : 'Connect printer';
    var via = $('#lmVia'); if (via) via.textContent = printerLine(st);
  }
  function drawPanel() {
    var st = MP.status(), cfg = MP.cfg(), box = $('#pmState');
    box.className = 'pm-state' + (st.connected ? ' ok' : '');
    box.innerHTML = '';
    var b = document.createElement('b');
    b.textContent = st.connected ? st.name : st.paired ? st.name + ' (not connected)' : 'No printer paired on this device';
    var sm = document.createElement('small');
    sm.textContent = 'Labels ' + cfg.w + ' × ' + cfg.h + ' mm, printed ' + cfg.print_w + ' mm wide at ' + cfg.dpi + ' dpi';
    box.appendChild(b); box.appendChild(sm);
    var note = $('#pmNote');
    if (!st.supported) {
      note.hidden = false;
      note.textContent = st.secure
        ? 'This browser has no Bluetooth. Use Chrome or Edge on Android, Windows, Mac or a Chromebook. On an iPad, labels print through the print dialog (AirPrint).'
        : 'Bluetooth printing needs the site on a secure https:// address. Until then, labels print through the print dialog.';
    } else note.hidden = true;
    $('#pmConnect').hidden = !st.supported;
    $('#pmConnect span').textContent = st.paired ? 'Pair a different printer' : 'Connect Bluetooth printer';
    $('#pmReconnect').hidden = !(st.supported && st.paired && !st.connected);
    $('#pmForget').hidden = !st.paired;
    $$('#pmLang [data-lang]').forEach(function (x) { x.setAttribute('aria-pressed', x.getAttribute('data-lang') === st.lang ? 'true' : 'false'); });
    $('#pmInvert').checked = st.invert;
  }
  function busy(btn, fn) {
    if (btn.disabled) return;
    btn.disabled = true; btn.classList.add('busy');
    Promise.resolve().then(fn).catch(function (e) {
      if (e && e.name === 'NotFoundError') return; // pairing window closed
      toast(e && e.message ? e.message : 'Something went wrong. Try again.', true);
    }).then(function () { btn.disabled = false; btn.classList.remove('busy'); drawPanel(); chip(); });
  }
  MP.onChange(function () { chip(); if (!$('#printerModal').hidden) drawPanel(); });
  $$('[data-printer-open]').forEach(function (b) { b.addEventListener('click', function () { drawPanel(); openModal($('#printerModal')); }); });
  $('#pmConnect').addEventListener('click', function () { busy(this, function () { return MP.connect().then(function () { toast('Connected to ' + MP.status().name); }); }); });
  $('#pmReconnect').addEventListener('click', function () {
    busy(this, function () { return MP.reconnect().then(function (ok) { return ok || MP.connect(); }).then(function () { toast('Printer connected'); }); });
  });
  $('#pmForget').addEventListener('click', function () { MP.forget(); toast('Printer forgotten on this device'); });
  $('#pmTest').addEventListener('click', function () {
    busy(this, function () {
      var d = new Date(), cfg = MP.cfg();
      return MP.print({ brand: D.kitchen, title: 'Test label', rows: [['Size', cfg.w + ' × ' + cfg.h + ' mm'], ['Printed', dayStr(d) + ' ' + timeStr(d)]], useBy: 'OK', useByTime: '' }, 1)
        .then(function (via) { toast(via === 'bluetooth' ? 'Test label sent to the printer' : 'Opened the print dialog'); });
    });
  });
  $$('#pmLang [data-lang]').forEach(function (x) { x.addEventListener('click', function () { MP.setLang(x.getAttribute('data-lang')); drawPanel(); }); });
  $('#pmInvert').addEventListener('change', function (e) { MP.setInvert(e.target.checked); });
  chip();

  /* ---------- print one label ---------- */
  var cur = null, qty = 1, previewT = null;
  var initialsKey = 'mustrhq_initials';
  var dateIn = $('#lmDate'), timeIn = $('#lmTime'), supIn = $('#lmSupplier'), iniIn = $('#lmInitials');
  try { iniIn.value = localStorage.getItem(initialsKey) || ''; } catch (e) { /* ignore */ }
  $('#lmInitialsRow').hidden = !D.cfg.ask_initials;

  function madeAt() {
    var p = (dateIn.value || isoDate(new Date())).split('-'), t = (timeIn.value || '00:00').split(':');
    return new Date(+p[0], +p[1] - 1, +p[2], +t[0], +t[1]);
  }
  /** Days: the day it was made counts as day 1. Hours: from the time it was made. */
  function useBy() {
    var m = madeAt(), u, timed = cur.unit === 'hours';
    if (timed) u = new Date(m.getTime() + cur.value * 3600000);
    else { u = new Date(m.getFullYear(), m.getMonth(), m.getDate()); u.setDate(u.getDate() + Math.max(0, cur.value - 1)); }
    var note = '', sup = supIn.value;
    if (cur.supplier && sup) {
      var p = sup.split('-'), s = new Date(+p[0], +p[1] - 1, +p[2]);
      if (s < new Date(u.getFullYear(), u.getMonth(), u.getDate())) { u = s; timed = false; note = "The supplier's date is sooner, so it is used."; }
    }
    return { date: u, timed: timed, note: note };
  }
  function labelData() {
    var m = madeAt(), ub = useBy(), ini = (iniIn.value || '').trim().toUpperCase();
    var rows = [[cur.kindLabel, dayStr(m) + ' ' + timeStr(m) + (D.cfg.ask_initials && ini ? '  ' + ini : '')]];
    if (cur.supplier && supIn.value) { var p = supIn.value.split('-'); rows.push(['Supplier date', dayStr(new Date(+p[0], +p[1] - 1, +p[2]))]); }
    if (D.cfg.show_allergens) rows.push(['Allergens', cur.allergens.length ? cur.allergens.join(', ') : 'None declared']);
    if (cur.storage && rows.length < 3) rows.push(['Keep', cur.storage]);
    return { brand: cur.brand, title: cur.name, rows: rows, useBy: dayStr(ub.date), useByTime: ub.timed ? timeStr(ub.date) : '' };
  }
  function refresh() {
    var ub = useBy();
    $('#lmUseBy').textContent = dayStr(ub.date) + (ub.timed ? ' ' + timeStr(ub.date) : '');
    var ago = Math.round((ub.date - new Date()) / 3600000);
    $('#lmUseByNote').textContent = ub.note || (ub.date < new Date() ? 'Already past. Do not use.' : (cur.unit === 'hours' ? 'In about ' + Math.max(0, ago) + (ago === 1 ? ' hour' : ' hours') : 'End of that day'));
    $('.useby').classList.toggle('past', ub.date < new Date());
    clearTimeout(previewT);
    previewT = setTimeout(function () {
      MP.render(labelData()).then(function (cv) { $('#lmImg').src = cv.toDataURL('image/png'); });
    }, 80);
  }
  function setWhen(kind) {
    var n = new Date();
    if (kind === 'yesterday') n.setDate(n.getDate() - 1);
    dateIn.value = isoDate(n);
    timeIn.value = kind === 'now' ? timeStr(n) : (kind === 'yesterday' ? timeStr(n) : '06:00');
    $$('.lm-when [data-when]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-when') === kind ? 'true' : 'false'); });
    refresh();
  }
  function openLabel(id) {
    cur = byId[id];
    if (!cur) return;
    qty = 1; $('#lmQty').textContent = '1';
    $('#lmTitle').textContent = cur.name;
    var ph = $('#lmPhoto');
    ph.innerHTML = '';
    if (cur.photo) { var im = document.createElement('img'); im.src = cur.photo; im.alt = ''; ph.appendChild(im); }
    else { ph.textContent = (cur.name.match(/[A-Za-z0-9]+/g) || ['?']).filter(function (w) { return ['and', 'of', 'the', 'with', 'in'].indexOf(w.toLowerCase()) < 0; }).slice(0, 3).map(function (w) { return w[0]; }).join('').toUpperCase(); }
    ph.style.setProperty('--c', cur.colour || '#01216C');
    var k = $('#lmKind'); k.textContent = cur.kindLabel; k.className = 'kind kind-' + cur.kind;
    $('#lmShelf').textContent = cur.brand + ' · use within ' + cur.value + (cur.unit === 'hours' ? (cur.value === 1 ? ' hour' : ' hours') : (cur.value === 1 ? ' day' : ' days')) + (cur.storage ? ' · ' + cur.storage : '');
    $('#lmWhenLegend').textContent = 'When was it ' + cur.kindLabel.toLowerCase() + '?';
    $('#lmSupplierRow').hidden = !cur.supplier; supIn.value = '';
    var cfg = MP.cfg();
    $('#lmPaper').style.aspectRatio = cfg.w + ' / ' + cfg.h;
    $('#lmSize').textContent = 'Actual size ' + cfg.w + ' × ' + cfg.h + ' mm';
    setWhen('now');
    chip();
    openModal($('#labelModal'));
  }
  $$('.lm-when [data-when]').forEach(function (b) { b.addEventListener('click', function () { setWhen(b.getAttribute('data-when')); }); });
  [dateIn, timeIn].forEach(function (i) {
    i.addEventListener('change', function () { $$('.lm-when [data-when]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); }); refresh(); });
  });
  supIn.addEventListener('change', refresh);
  iniIn.addEventListener('input', function () {
    iniIn.value = iniIn.value.toUpperCase().replace(/[^A-Z]/g, '');
    try { localStorage.setItem(initialsKey, iniIn.value); } catch (e) { /* ignore */ }
    refresh();
  });
  $$('#labelModal [data-q]').forEach(function (b) {
    b.addEventListener('click', function () { qty = Math.max(1, Math.min(20, qty + Number(b.getAttribute('data-q')))); $('#lmQty').textContent = qty; });
  });
  $('#lmPrint').addEventListener('click', function () {
    var btn = this, data = labelData(), ub = useBy();
    if (ub.date < new Date() && !confirm('This use-by is already past. Print it anyway?')) return;
    btn.disabled = true; btn.classList.add('busy');
    MP.print(data, qty).then(function (via) {
      if (via === 'bluetooth') { closeModal($('#labelModal')); toast('Printed ' + qty + (qty === 1 ? ' label' : ' labels') + ' for ' + cur.name + '. Use by ' + data.useBy + (data.useByTime ? ' ' + data.useByTime : '') + '.'); }
    }).catch(function (e) {
      if (e.code === 'reconnect') { toast(e.message, true); drawPanel(); openModal($('#printerModal')); }
      else toast(e.message || 'The label did not print. Try again.', true);
    }).then(function () { btn.disabled = false; btn.classList.remove('busy'); });
  });
  $$('[data-label]').forEach(function (b) { b.addEventListener('click', function () { openLabel(+b.getAttribute('data-label')); }); });
  if (D.open && byId[D.open]) openLabel(D.open);
})();
