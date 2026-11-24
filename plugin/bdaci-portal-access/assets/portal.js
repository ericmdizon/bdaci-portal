/* BDACI portal: menu toggle, sign-off progress, profile picture filters. */
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
})();
