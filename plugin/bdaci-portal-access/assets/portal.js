/* BDACI portal: menu toggle, sign-off progress, profile picture filters, document viewer and signatures. */
(function () {
  'use strict';
  var btn = document.getElementById('bp-menubtn'), side = document.getElementById('bp-side');
  if (btn && side) {
    btn.addEventListener('click', function () {
      var open = side.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Sign-off: once every document has been opened, unlock the checkbox (the server checks again).
  var docs = document.getElementById('bp-so-docs');
  if (docs) {
    var ack = document.querySelector('[data-so-ack]'), sbtn = document.querySelector('[data-so-btn]');
    var update = function () {
      var pills = docs.querySelectorAll('[data-so-pill]'), done = 0;
      pills.forEach(function (p) { if (p.classList.contains('pill-ok')) done++; });
      var c = document.querySelector('[data-so-count]');
      if (c) c.textContent = c.textContent.replace(/^\d+/, String(done));
      var bar = document.querySelector('.signoff .progress i');
      if (bar && pills.length) bar.style.width = Math.round(done / pills.length * 100) + '%';
      if (ack && pills.length && done === pills.length) {
        ack.disabled = false;
        var hint = document.querySelector('[data-so-hint]');
        if (hint) hint.hidden = true;
      }
      if (sbtn && ack) sbtn.disabled = !ack.checked || ack.disabled;
    };
    docs.addEventListener('click', function (e) {
      var a = e.target.closest('[data-so-open]');
      if (!a) return;
      var pill = a.parentNode.querySelector('[data-so-pill]');
      if (pill) { pill.className = 'pill pill-ok'; pill.textContent = pill.getAttribute('data-opened') || 'Opened'; }
      setTimeout(update, 50);
    });
    if (ack) ack.addEventListener('change', update);
    update();
  }

  // Profile: upload as soon as a file is picked; filter preset pictures.
  document.querySelectorAll('[data-autosubmit]').forEach(function (inp) {
    inp.addEventListener('change', function () { if (inp.files && inp.files.length) inp.form.submit(); });
  });
  var grid = document.querySelector('.pf-grid');
  if (grid) {
    var f = { skin: 'all', head: 'all', age: 'all' };
    document.querySelectorAll('[data-pff]').forEach(function (b) {
      b.addEventListener('click', function () {
        var kv = b.getAttribute('data-pff').split('|');
        f[kv[0]] = kv[1];
        document.querySelectorAll('[data-pff^="' + kv[0] + '|"]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        var shown = 0;
        grid.querySelectorAll('.pf-opt').forEach(function (o) {
          var ok = (f.skin === 'all' || o.dataset.skin === f.skin) && (f.head === 'all' || o.dataset.head === f.head) && (f.age === 'all' || o.dataset.age === f.age);
          o.hidden = !ok; if (ok) shown++;
        });
        var none = document.querySelector('[data-pf-none]'); if (none) none.hidden = shown > 0;
        var cnt = document.querySelector('[data-pf-count]'); if (cnt) cnt.textContent = shown + ' / ' + grid.children.length;
      });
    });
  }
  // ---------------------------------------------------------------------
  // Signatures: type your name or draw it (sign-off page and document viewer).
  function initSig(box) {
    if (!box || box.getAttribute('data-sig-ready')) return;
    box.setAttribute('data-sig-ready', '1');
    var how = box.querySelector('input[name="sig_how"]'), img = box.querySelector('input[name="sig_img"]');
    var name = box.querySelector('input[name="sig_name"]'), prev = box.querySelector('.sig-preview');
    var cv = box.querySelector('canvas'), ctx = cv ? cv.getContext('2d') : null, drawn = false, down = false;
    function size() {
      if (!cv || drawn) return;
      var r = cv.getBoundingClientRect(), d = window.devicePixelRatio || 1;
      if (!r.width) return;
      cv.width = Math.round(r.width * d); cv.height = Math.round(r.height * d);
      ctx.setTransform(d, 0, 0, d, 0, 0);
      ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#13315c';
    }
    function mode(m) {
      how.value = m;
      box.querySelectorAll('[data-sig-mode]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-sig-mode') === m ? 'true' : 'false'); });
      box.querySelectorAll('[data-sig-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-sig-pane') !== m; });
      if (m === 'drawn') size();
      clearErr();
    }
    function clearErr() { var e = box.querySelector('.sig-err'); if (e) e.remove(); }
    box.querySelectorAll('[data-sig-mode]').forEach(function (b) { b.addEventListener('click', function () { mode(b.getAttribute('data-sig-mode')); }); });
    if (name && prev) name.addEventListener('input', function () { prev.textContent = name.value; clearErr(); });
    if (cv) {
      var pos = function (e) { var r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
      cv.addEventListener('pointerdown', function (e) { e.preventDefault(); size(); down = true; cv.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.lineTo(p[0] + 0.1, p[1] + 0.1); ctx.stroke(); });
      cv.addEventListener('pointermove', function (e) { if (!down) return; var p = pos(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); });
      var up = function () { if (!down) return; down = false; drawn = true; img.value = cv.toDataURL('image/png'); clearErr(); };
      cv.addEventListener('pointerup', up); cv.addEventListener('pointercancel', up);
      var clr = box.querySelector('[data-sig-clear]');
      if (clr) clr.addEventListener('click', function () { drawn = false; img.value = ''; ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, cv.width, cv.height); size(); });
    }
    var form = box.closest('form');
    if (form) form.addEventListener('submit', function (e) {
      var ok = how.value === 'drawn' ? !!img.value : (name && name.value.trim().length >= 2);
      if (!ok) {
        e.preventDefault(); clearErr();
        var m = document.createElement('p'); m.className = 'small sig-err'; m.setAttribute('role', 'alert');
        m.textContent = (window.bpView && bpView.i18n && bpView.i18n.needSig) || 'Type your name or draw your signature.';
        box.appendChild(m);
        if (how.value === 'drawn') { if (cv) cv.focus(); } else if (name) name.focus();
      }
    });
  }
  document.querySelectorAll('[data-sig]').forEach(initSig);

  // ---------------------------------------------------------------------
  // Document viewer: opens documents in a pop-up with expand and full screen,
  // and asks for the sign-off there when the document (or its sign-off pack) needs one.
  var V = window.bpView;
  if (V && window.HTMLDialogElement) {
    var T = function (k) { return (V.i18n && V.i18n[k]) || k; };
    var ICON = {
      dl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>',
      print: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z"/></svg>',
      tab: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/></svg>',
      expand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
      full: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 10V8h2M16 14v2h-2"/></svg>'
    };
    var dlg, body, sign, titleEl, metaEl, cur = null, warned = false;
    var btn = function (key, icon, label, tag) {
      var b = document.createElement(tag || 'button');
      b.className = 'btn'; b.setAttribute('data-vw', key);
      if (!tag) b.type = 'button';
      b.innerHTML = icon + '<span class="lbl"></span>';
      b.querySelector('.lbl').textContent = label;
      b.setAttribute('title', label); b.setAttribute('aria-label', label);
      return b;
    };
    var build = function () {
      dlg = document.createElement('dialog');
      dlg.className = 'bp bp-viewer'; dlg.setAttribute('aria-labelledby', 'bpv-title');
      var head = document.createElement('div'); head.className = 'vw-head';
      var tt = document.createElement('div'); tt.className = 'vw-title';
      titleEl = document.createElement('b'); titleEl.id = 'bpv-title';
      metaEl = document.createElement('span');
      tt.appendChild(titleEl); tt.appendChild(metaEl);
      var tools = document.createElement('div'); tools.className = 'vw-tools';
      var bTab = btn('newtab', ICON.tab, T('newtab'), 'a'); bTab.target = '_blank'; bTab.rel = 'noopener';
      var bEx = btn('expand', ICON.expand, T('expand'));
      var bFs = btn('full', ICON.full, T('full'));
      var bX = document.createElement('button'); bX.type = 'button'; bX.className = 'btn vw-x'; bX.setAttribute('data-vw', 'close'); bX.setAttribute('aria-label', T('close')); bX.title = T('close'); bX.textContent = '✕';
      [bTab, bEx, bFs, bX].forEach(function (b) { tools.appendChild(b); });
      var root = document.documentElement;
      if (!root.requestFullscreen || document.fullscreenEnabled === false) bFs.hidden = true;
      head.appendChild(tt); head.appendChild(tools);
      body = document.createElement('div'); body.className = 'vw-body';
      sign = document.createElement('div'); sign.className = 'vw-sign'; sign.hidden = true;
      dlg.appendChild(head); dlg.appendChild(body); dlg.appendChild(sign);
      document.body.appendChild(dlg);
      bX.addEventListener('click', tryClose);
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); if (document.fullscreenElement) return; tryClose(); });
      // A modal <dialog> can't be made full screen itself (browsers refuse it), so the page goes full screen
      // and the viewer fills it.
      dlg.addEventListener('click', function (e) { if (e.target === dlg) tryClose(); });
      bEx.addEventListener('click', function () {
        var on = dlg.classList.toggle('max');
        bEx.querySelector('.lbl').textContent = on ? T('shrink') : T('expand'); bEx.title = bEx.querySelector('.lbl').textContent; bEx.setAttribute('aria-label', bEx.title);
      });
      bFs.addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen().catch(function () {}); return; }
        dlg.classList.add('fs');
        root.requestFullscreen().catch(function () { dlg.classList.remove('fs'); });
      });
      document.addEventListener('fullscreenchange', function () {
        var on = !!document.fullscreenElement;
        if (!on) dlg.classList.remove('fs');
        bFs.querySelector('.lbl').textContent = on ? T('exitfull') : T('full'); bFs.title = bFs.querySelector('.lbl').textContent; bFs.setAttribute('aria-label', bFs.title);
      });
    };
    var tool = function (k) { return dlg.querySelector('[data-vw="' + k + '"]'); };
    var msg = function (text, withDl) {
      var m = document.createElement('div'); m.className = 'vw-msg';
      if (cur.ext) { var x = document.createElement('span'); x.className = 'ext'; x.textContent = cur.ext; m.appendChild(x); }
      var p = document.createElement('p'); p.textContent = text; m.appendChild(p);
      if (withDl) { var a = document.createElement('a'); a.className = 'btn btn-primary'; a.href = cur.dl; a.textContent = T('download'); a.addEventListener('click', function () { cur.got = true; markOpened(); }); m.appendChild(a); }
      return m;
    };
    var load = function () {
      body.innerHTML = '';
      if (cur.type === 'pdf') {
        // #toolbar=0 hides the browser's own PDF toolbar (download and print) where the browser supports it.
        var f = document.createElement('iframe'); f.src = cur.url + '#toolbar=0&navpanes=0'; f.title = cur.title; body.appendChild(f);
      } else if (cur.type === 'img') {
        var sc = document.createElement('div'); sc.className = 'vw-scroll';
        var i = document.createElement('img'); i.className = 'vw-img'; i.src = cur.url; i.alt = cur.title;
        i.addEventListener('error', function () { body.innerHTML = ''; body.appendChild(msg(T('loadFail'), true)); });
        sc.appendChild(i); body.appendChild(sc);
      } else if (cur.type === 'text') {
        var l = msg(T('loading'), false); body.appendChild(l);
        fetch(cur.url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.blob(); }).then(function (b) {
          body.innerHTML = '';
          if (b.size > 2 * 1024 * 1024) { body.appendChild(msg(T('tooBig'), true)); return; }
          return b.text().then(function (s) { var w = document.createElement('div'); w.className = 'vw-scroll'; var pre = document.createElement('pre'); pre.textContent = s; w.appendChild(pre); body.appendChild(w); });
        }).catch(function () { body.innerHTML = ''; body.appendChild(msg(T('loadFail'), true)); });
      } else {
        body.appendChild(msg(T('noPreview'), true));
      }
    };
    var markOpened = function () {
      if (!cur) return;
      cur.packs.forEach(function (k) { var p = V.packs[k]; if (p && p.opened.indexOf(cur.id) === -1) p.opened.push(cur.id); });
      renderSign();
    };
    var hidden = function (f, n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
    var signForm = function (heading, statement, hint, fields) {
      var f = document.createElement('form'); f.method = 'post'; f.action = V.post;
      hidden(f, 'action', 'bdaci_pa_portal');
      Object.keys(fields).forEach(function (k) { hidden(f, k, fields[k]); });
      var wrap = document.createElement('div'); wrap.className = 'vw-sign-in';
      var hh = document.createElement('div'); hh.className = 'vw-sign-h'; var h2 = document.createElement('h2'); h2.textContent = heading; hh.appendChild(h2);
      var more = document.createElement('button'); more.type = 'button'; more.className = 'btn btn-primary btn-sm vw-open-sign'; more.textContent = T('signNow');
      more.addEventListener('click', function () { expand(); var c = f.querySelector('input[name="ack"]'); if (c) c.focus(); });
      hh.appendChild(more);
      var p = document.createElement('p'); p.className = 'small muted'; p.textContent = hint;
      var lab = document.createElement('label'); lab.className = 'check'; var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'ack'; cb.value = '1'; cb.required = true; lab.appendChild(cb); lab.appendChild(document.createTextNode(' ' + statement));
      var sig = document.createElement('div'); sig.innerHTML = V.sig; // Built and escaped on the server.
      var go = document.createElement('div'); var b = document.createElement('button'); b.type = 'submit'; b.className = 'btn btn-primary'; b.textContent = T('signBtn'); go.appendChild(b);
      [hh, p, lab, sig.firstElementChild, go].forEach(function (x) { if (x) wrap.appendChild(x); });
      f.appendChild(wrap);
      f.addEventListener('submit', function () { cur.sent = true; });
      return f;
    };
    var expand = function () { sign.classList.remove('compact'); };
    var pending = function () {
      if (!cur || cur.sent) return false;
      if (cur.sign) return true;
      return cur.packs.some(function (k) { var p = V.packs[k]; return p && p.docs.length && p.docs.every(function (d) { return p.opened.indexOf(d.id) !== -1; }); });
    };
    var renderSign = function () {
      if (!cur) return;
      sign.innerHTML = ''; sign.classList.remove('attn');
      var any = false;
      if (cur.signed) {
        var d = document.createElement('div'); d.className = 'vw-sign-in'; var p = document.createElement('p');
        p.innerHTML = '<span class="pill pill-ok"></span> '; p.firstChild.textContent = T('signed');
        p.appendChild(document.createTextNode(T('signedOn').replace('%s', cur.signed)));
        if (cur.receipt) { var r = document.createElement('a'); r.href = cur.receipt; r.target = '_blank'; r.rel = 'noopener'; r.textContent = T('receipt'); r.style.marginLeft = '10px'; p.appendChild(r); }
        d.appendChild(p); sign.appendChild(d); any = true;
      } else if (cur.sign) {
        sign.appendChild(signForm(T('signTitle'), cur.statement, cur.type === 'file' && !cur.got ? T('signFile') : T('signHint'), { 'do': 'docsign', _bdaci_nonce: V.nonce.docsign, doc: cur.id }));
        any = true;
      }
      cur.packs.forEach(function (k) {
        var pk = V.packs[k]; if (!pk) return;
        var total = pk.docs.length, done = pk.docs.filter(function (d) { return pk.opened.indexOf(d.id) !== -1; }).length;
        if (total && done === total) {
          sign.appendChild(signForm(T('packTitle').replace('%s', pk.title), pk.statement, T('packReady'), { 'do': 'signoff', _bdaci_nonce: V.nonce.signoff, pack: k }));
        } else {
          var w = document.createElement('div'); w.className = 'vw-sign-in';
          var h = document.createElement('div'); h.className = 'vw-sign-h'; var h2 = document.createElement('h2'); h2.textContent = T('packTitle').replace('%s', pk.title); h.appendChild(h2);
          var pr = document.createElement('p'); pr.className = 'small muted'; pr.textContent = T('packProg').replace('%1$d', done).replace('%2$d', total);
          var nx = document.createElement('div'); nx.className = 'vw-pack-next';
          pk.docs.forEach(function (d) {
            if (pk.opened.indexOf(d.id) !== -1) return;
            var a = document.querySelector('a[data-view="' + d.id + '"]');
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = T('next') + ' ' + d.title;
            b.addEventListener('click', function () { if (a) { open(a); } });
            if (a) nx.appendChild(b);
          });
          w.appendChild(h); w.appendChild(pr); if (nx.children.length) w.appendChild(nx); sign.appendChild(w);
        }
        any = true;
      });
      sign.hidden = !any;
      // Keep the document readable: the sign-off form starts folded to one line until "Sign now".
      sign.classList.toggle('compact', !!sign.querySelector('form'));
      sign.querySelectorAll('[data-sig]').forEach(initSig);
    };
    var reallyClose = function () {
      if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
      dlg.close(); body.innerHTML = ''; dlg.classList.remove('max', 'fs'); cur = null;
    };
    var tryClose = function () {
      if (pending() && !warned) {
        warned = true;
        var box = document.createElement('div'); box.className = 'vw-leave'; box.setAttribute('role', 'alert');
        var b = document.createElement('b'); b.textContent = T('leaveTitle');
        var p = document.createElement('p'); p.className = 'small'; p.textContent = T('leaveText');
        var row = document.createElement('div'); row.className = 'row-btns';
        var now = document.createElement('button'); now.type = 'button'; now.className = 'btn btn-primary btn-sm'; now.textContent = T('signNow');
        var later = document.createElement('button'); later.type = 'button'; later.className = 'btn btn-sm'; later.textContent = T('later');
        row.appendChild(now); row.appendChild(later); box.appendChild(b); box.appendChild(p); box.appendChild(row);
        var first = sign.querySelector('form .vw-sign-in') || sign;
        first.insertBefore(box, first.firstChild);
        expand(); sign.hidden = false; sign.classList.add('attn'); sign.scrollTop = 0;
        now.addEventListener('click', function () { box.remove(); var cb = sign.querySelector('input[name="ack"]'); if (cb) cb.focus(); });
        later.addEventListener('click', reallyClose);
        now.focus();
        return;
      }
      reallyClose();
    };
    var open = function (a) {
      if (!dlg) build();
      warned = false;
      cur = {
        id: parseInt(a.getAttribute('data-view'), 10), title: a.getAttribute('data-title') || a.textContent, type: a.getAttribute('data-type') || 'file',
        ext: a.getAttribute('data-ext') || '', url: a.href, dl: a.getAttribute('data-dl') || a.href, meta: a.getAttribute('data-meta') || '',
        sign: a.getAttribute('data-sign') === '1', signed: a.getAttribute('data-signed') || '', receipt: a.getAttribute('data-receipt') || '',
        statement: a.getAttribute('data-statement') || '', packs: (a.getAttribute('data-packs') || '').split(',').filter(function (k) { return k && V.packs[k]; })
      };
      titleEl.textContent = cur.title; metaEl.textContent = cur.meta;
      tool('newtab').href = cur.url;
      load();
      if (cur.type !== 'file') markOpened(); else renderSign();
      if (!dlg.open) dlg.showModal();
      body.scrollTop = 0;
    };
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[data-view]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      open(a);
    });
  }
})();
