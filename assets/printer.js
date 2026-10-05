/*
 * MustrHQ Recipes — label printing (shared with MustrHQ Station).
 *
 * Draws each label at the printer's real resolution (default 48 mm print width on a 50 × 30 mm
 * label at 203 dpi), then sends it to a Bluetooth label printer (TSPL or ESC/POS) through Web
 * Bluetooth, or to the browser's print dialog at the exact label size.
 *
 * Licence: GNU AGPL-3.0-or-later.
 */
(function () {
  'use strict';
  var KEY = 'mustrhq_printer';
  // Services used by common Bluetooth thermal label and receipt printers (Xprinter, HPRT, Munbyn, iDPRT, generic modules).
  var SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb'
  ];
  var FONT = 'Archivo, "Helvetica Neue", Arial, sans-serif';
  var mm = function (v, dpi) { return Math.round(v * dpi / 25.4); };
  var saved = function () { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } };
  var save = function (o) {
    try { var s = saved(); Object.keys(o).forEach(function (k) { s[k] = o[k]; }); localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* private mode */ }
  };

  var P = { cfg: { w: 50, h: 30, print_w: 48, gap: 2, dpi: 203, lang: 'tspl' }, device: null, ch: null, listeners: [] };
  var emit = function () { P.listeners.forEach(function (f) { try { f(status()); } catch (e) { /* ignore */ } }); };

  function status() {
    var s = saved();
    return {
      supported: !!(navigator.bluetooth && window.isSecureContext),
      secure: !!window.isSecureContext,
      connected: !!(P.device && P.device.gatt && P.device.gatt.connected && P.ch),
      name: (P.device && P.device.name) || s.name || '',
      paired: !!s.id,
      lang: s.lang || P.cfg.lang,
      invert: !!s.invert
    };
  }

  /* ---------------- drawing ---------------- */
  function setFont(ctx, size, weight, stretch) {
    ctx.font = weight + ' ' + size + 'px ' + FONT;
    if ('fontStretch' in ctx) ctx.fontStretch = stretch || 'normal';
  }
  function fit(ctx, text, maxW, size, min, weight, stretch) {
    var s = size;
    for (; s > min; s -= 1) { setFont(ctx, s, weight, stretch); if (ctx.measureText(text).width <= maxW) break; }
    setFont(ctx, s, weight, stretch);
    return s;
  }
  function wrap(ctx, text, maxW, maxLines) {
    var words = String(text).split(/\s+/), lines = [], cur = '';
    words.forEach(function (w) {
      var t = cur ? cur + ' ' + w : w;
      if (ctx.measureText(t).width <= maxW || !cur) cur = t; else { lines.push(cur); cur = w; }
    });
    if (cur) lines.push(cur);
    if (lines.length > maxLines) {
      lines.length = maxLines;
      var last = lines[maxLines - 1];
      while (last.length > 1 && ctx.measureText(last + '…').width > maxW) last = last.slice(0, -1);
      lines[maxLines - 1] = last + '…';
    }
    return lines;
  }

  /**
   * data: { brand, title, rows: [[label, value], ...], useBy, useByTime, foot }
   * Returns a canvas exactly print_w × h at the printer's dpi, black on white.
   */
  function render(data, cfg) {
    cfg = cfg || P.cfg;
    var ready = (document.fonts && document.fonts.load)
      ? Promise.all(['700 20px Archivo', '600 20px Archivo', '500 20px Archivo'].map(function (f) { return document.fonts.load(f); })).catch(function () {})
      : Promise.resolve();
    return ready.then(function () {
      var W = Math.floor(mm(cfg.print_w, cfg.dpi) / 8) * 8, H = mm(cfg.h, cfg.dpi);
      var c = document.createElement('canvas'); c.width = W; c.height = H;
      var x = c.getContext('2d');
      x.fillStyle = '#fff'; x.fillRect(0, 0, W, H); x.fillStyle = '#000'; x.textBaseline = 'alphabetic';
      var pad = Math.round(H * 0.04), inner = W - pad * 2;

      // Header bar: brand or kitchen, white on black.
      var hb = Math.round(H * 0.15);
      x.fillRect(0, 0, W, hb);
      x.fillStyle = '#fff'; x.textAlign = 'center';
      fit(x, data.brand || '', inner, Math.round(hb * 0.7), 8, 700, 'condensed');
      x.fillText(data.brand || '', W / 2, Math.round(hb * 0.75));
      x.fillStyle = '#000';
      var y = hb;

      // Title: up to 2 lines.
      var ts = Math.round(H * 0.15);
      setFont(x, ts, 700, 'condensed');
      var lines = wrap(x, data.title, inner, 2);
      if (lines.length === 2) { ts = Math.round(H * 0.122); setFont(x, ts, 700, 'condensed'); lines = wrap(x, data.title, inner, 2); }
      lines.forEach(function (l) { y += Math.round(ts * 1.0); x.fillText(l, W / 2, y); });

      // Info rows: label left, value right.
      var rows = data.rows || [];
      var spaceLeft = H - Math.round(H * 0.24) - y;
      var rs = Math.min(Math.round(H * 0.092), Math.floor(spaceLeft / Math.max(1, rows.length) / 1.15));
      y += Math.round(rs * 0.3);
      x.textAlign = 'left';
      rows.forEach(function (r) {
        y += Math.round(rs * 1.12);
        setFont(x, rs, 500, 'normal'); x.fillText(r[0], pad, y);
        var kw = x.measureText(r[0] + '  ').width;
        x.textAlign = 'right'; fit(x, String(r[1]), inner - kw, rs, 8, 700, 'condensed'); x.fillText(String(r[1]), W - pad, y); x.textAlign = 'left';
      });

      // Use-by band at the bottom: a rule, then USE BY and the date, as large as fits.
      var ub = Math.round(H * 0.21), top = H - ub - Math.round(pad * 0.5);
      x.fillRect(pad, top - Math.round(pad * 0.4), inner, Math.max(2, Math.round(H * 0.01)));
      setFont(x, Math.round(ub * 0.56), 700, 'condensed');
      x.fillText('USE BY', pad, top + Math.round(ub * 0.74));
      var val = data.useBy + (data.useByTime ? ' ' + data.useByTime : '');
      var labelW = x.measureText('USE BY  ').width;
      x.textAlign = 'right'; fit(x, val, inner - labelW, Math.round(ub * 0.82), 10, 800, 'condensed');
      x.fillText(val, W - pad, top + Math.round(ub * 0.78));
      return c;
    });
  }

  /** 1-bit image, rows padded to whole bytes; bit 1 = black dot. */
  function mono(canvas) {
    var W = canvas.width, H = canvas.height, bpr = W / 8;
    var px = canvas.getContext('2d').getImageData(0, 0, W, H).data, out = new Uint8Array(bpr * H);
    for (var y = 0; y < H; y++) for (var x = 0; x < W; x++) {
      var i = (y * W + x) * 4, lum = px[i] * 0.299 + px[i + 1] * 0.587 + px[i + 2] * 0.114;
      if (lum < 150) out[y * bpr + (x >> 3)] |= 0x80 >> (x & 7);
    }
    return { bpr: bpr, H: H, bits: out };
  }
  var ascii = function (s) { return new TextEncoder().encode(s); };
  function concat(parts) {
    var n = parts.reduce(function (a, p) { return a + p.length; }, 0), out = new Uint8Array(n), o = 0;
    parts.forEach(function (p) { out.set(p, o); o += p.length; });
    return out;
  }
  /** TSPL (TSC-style label printers): bitmap centred on the label. */
  function tspl(m, cfg, qty, invert) {
    var xOff = Math.max(0, Math.round(mm((cfg.w - cfg.print_w) / 2, cfg.dpi)));
    // In TSPL bitmaps a 0 bit prints black, so the image is inverted unless the screen says otherwise.
    var data = invert ? m.bits : m.bits.map(function (b) { return ~b & 0xff; });
    return concat([
      ascii('SIZE ' + cfg.w + ' mm,' + cfg.h + ' mm\r\nGAP ' + cfg.gap + ' mm,0 mm\r\nDIRECTION 1,0\r\nREFERENCE 0,0\r\nDENSITY 8\r\nSPEED 3\r\nCLS\r\nBITMAP ' + xOff + ',0,' + m.bpr + ',' + m.H + ',0,'),
      data, ascii('\r\nPRINT ' + qty + ',1\r\n')
    ]);
  }
  /** ESC/POS raster (GS v 0), one image per label with a feed between. */
  function escpos(m, cfg, qty, invert) {
    var data = invert ? m.bits.map(function (b) { return ~b & 0xff; }) : m.bits;
    var head = new Uint8Array([0x1b, 0x40, 0x1b, 0x61, 0x01, 0x1d, 0x76, 0x30, 0x00, m.bpr & 0xff, m.bpr >> 8, m.H & 0xff, m.H >> 8]);
    var feed = new Uint8Array([0x1b, 0x4a, Math.min(255, mm(cfg.gap + 1, cfg.dpi))]);
    var parts = [];
    for (var i = 0; i < qty; i++) parts.push(head, data, feed);
    return concat(parts);
  }

  /* ---------------- Bluetooth ---------------- */
  function findWritable(server) {
    return server.getPrimaryServices().then(function (services) {
      var i = 0;
      var next = function () {
        if (i >= services.length) throw new Error('This printer has no Bluetooth channel the app can write to.');
        var sv = services[i++];
        return sv.getCharacteristics().then(function (chars) {
          var c = chars.find(function (ch) { return ch.properties.writeWithoutResponse; }) || chars.find(function (ch) { return ch.properties.write; });
          return c || next();
        }, next);
      };
      return next();
    });
  }
  function attach(device) {
    device.addEventListener('gattserverdisconnected', function () { P.ch = null; emit(); });
    var conn = device.gatt.connected ? Promise.resolve(device.gatt) : device.gatt.connect();
    return conn.then(function (server) {
      P.device = device;
      return findWritable(server);
    }).then(function (ch) {
      P.ch = ch;
      save({ id: device.id, name: device.name || 'Bluetooth printer' });
      emit();
    });
  }
  function connect() {
    if (!navigator.bluetooth) return Promise.reject(new Error('This browser cannot use Bluetooth. Use Chrome or Edge on Android, Windows, Mac or a Chromebook.'));
    if (!window.isSecureContext) return Promise.reject(new Error('Bluetooth needs a secure (https://) address.'));
    return navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES }).then(attach).then(status);
  }
  /** Reconnect to the remembered printer without a new pairing prompt, where the browser allows it. */
  function reconnect() {
    if (status().connected) return Promise.resolve(true);
    var s = saved();
    if (!s.id || !navigator.bluetooth || !navigator.bluetooth.getDevices) return Promise.resolve(false);
    return navigator.bluetooth.getDevices().then(function (list) {
      var d = list.find(function (x) { return x.id === s.id; });
      return d ? attach(d).then(function () { return true; }) : false;
    }).catch(function () { return false; });
  }
  function write(bytes) {
    var ch = P.ch, noResp = ch.properties.writeWithoutResponse, size = noResp ? 128 : 180, i = 0, n = 0;
    var step = function () {
      if (i >= bytes.length) return Promise.resolve();
      var chunk = bytes.slice(i, i + size); i += size; n++;
      var w = (noResp && ch.writeValueWithoutResponse) ? ch.writeValueWithoutResponse(chunk) : ch.writeValue(chunk);
      return w.then(function () {
        // Let small printers' buffers drain.
        return (noResp && n % 8 === 0) ? new Promise(function (r) { setTimeout(r, 12); }) : null;
      }).then(step);
    };
    return step();
  }
  function forget() {
    try { if (P.device && P.device.gatt.connected) P.device.gatt.disconnect(); } catch (e) { /* ignore */ }
    P.device = null; P.ch = null;
    try { localStorage.removeItem(KEY); } catch (e) { /* ignore */ }
    emit();
  }

  /* ---------------- browser print fallback ---------------- */
  function browserPrint(canvas, qty, cfg) {
    var st = document.getElementById('labelPageSize');
    if (!st) { st = document.createElement('style'); st.id = 'labelPageSize'; document.head.appendChild(st); }
    st.textContent = '@media print { @page { size: ' + cfg.w + 'mm ' + cfg.h + 'mm; margin: 0; } '
      + 'body > *:not(#labelSheet) { display: none !important; } #labelSheet { display: block !important; } '
      + '#labelSheet .lab { width: ' + cfg.w + 'mm; height: ' + cfg.h + 'mm; display: flex; align-items: center; justify-content: center; break-after: page; overflow: hidden; } '
      + '#labelSheet .lab img { width: ' + cfg.print_w + 'mm; height: ' + cfg.h + 'mm; } }';
    var sheet = document.getElementById('labelSheet');
    if (!sheet) { sheet = document.createElement('div'); sheet.id = 'labelSheet'; sheet.hidden = true; document.body.appendChild(sheet); }
    var url = canvas.toDataURL('image/png');
    sheet.innerHTML = '';
    for (var i = 0; i < qty; i++) {
      var d = document.createElement('div'); d.className = 'lab';
      var im = document.createElement('img'); im.src = url; im.alt = '';
      d.appendChild(im); sheet.appendChild(d);
    }
    var clear = function () { st.textContent = ''; sheet.innerHTML = ''; window.removeEventListener('afterprint', clear); };
    window.addEventListener('afterprint', clear);
    setTimeout(function () { window.print(); }, 150);
  }

  /** Print a label: Bluetooth when a printer is paired (and the language is not "browser"), otherwise the print dialog. */
  function print(data, qty) {
    qty = qty || 1;
    return render(data).then(function (cv) {
      var st = status();
      if (st.lang !== 'browser' && st.paired && st.supported) {
        return (st.connected ? Promise.resolve(true) : reconnect()).then(function (ok) {
          if (!ok) { var e = new Error('The label printer is not connected. Open the printer panel and tap Reconnect.'); e.code = 'reconnect'; throw e; }
          var m = mono(cv);
          return write(st.lang === 'escpos' ? escpos(m, P.cfg, qty, st.invert) : tspl(m, P.cfg, qty, st.invert)).then(function () { return 'bluetooth'; });
        });
      }
      browserPrint(cv, qty, P.cfg);
      return 'browser';
    });
  }

  window.MustrPrinter = {
    configure: function (cfg) { if (cfg) Object.keys(cfg).forEach(function (k) { P.cfg[k] = cfg[k]; }); },
    cfg: function () { return JSON.parse(JSON.stringify(P.cfg)); },
    status: status, connect: connect, reconnect: reconnect, forget: forget, print: print, render: render,
    setLang: function (lang) { save({ lang: lang }); emit(); },
    setInvert: function (v) { save({ invert: !!v }); emit(); },
    onChange: function (f) { P.listeners.push(f); },
    _encode: { mono: mono, tspl: tspl, escpos: escpos } // exposed for tests
  };
})();
