(function () {
  'use strict';

  // ---- Campi con filtro "scrivi per filtrare" ----
  document.querySelectorAll('.combo').forEach(function (box) {
    var input = box.querySelector('.combo-input');
    var list = box.querySelector('.combo-list');
    var opts = [];
    try { opts = JSON.parse(box.getAttribute('data-options') || '[]'); } catch (e) {}

    function render(q) {
      q = (q || '').toLowerCase();
      list.innerHTML = '';
      var n = 0;
      opts.forEach(function (o) {
        if (!q || o.toLowerCase().indexOf(q) > -1) {
          var d = document.createElement('div');
          d.textContent = o;
          d.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            input.value = o;
            list.style.display = 'none';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
          list.appendChild(d);
          n++;
        }
      });
      list.style.display = n ? 'block' : 'none';
    }
    input.addEventListener('focus', function () { render(input.value); });
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('blur', function () { list.style.display = 'none'; });
  });

  // ---- Trattamenti (elenco con + Aggiungi) ----
  var addBtn = document.getElementById('add-tratt');
  var trInput = document.getElementById('tratt-input');
  var chips = document.getElementById('tratt-chips');
  function addChip(text) {
    text = (text || '').trim();
    if (!text) return;
    var exists = false;
    chips.querySelectorAll('input').forEach(function (i) { if (i.value === text) exists = true; });
    if (exists) return;
    var c = document.createElement('div');
    c.className = 'chip';
    var s = document.createElement('span');
    s.textContent = text;
    var h = document.createElement('input');
    h.type = 'hidden'; h.name = 'trattamenti[]'; h.value = text;
    var x = document.createElement('button');
    x.type = 'button'; x.textContent = '×'; x.title = 'Rimuovi';
    x.addEventListener('click', function () { c.remove(); });
    c.appendChild(s); c.appendChild(h); c.appendChild(x);
    chips.appendChild(c);
  }
  if (addBtn && trInput && chips) {
    addBtn.addEventListener('click', function () { addChip(trInput.value); trInput.value = ''; trInput.focus(); });
    trInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); addChip(trInput.value); trInput.value = ''; }
    });
    try { JSON.parse(chips.getAttribute('data-selected') || '[]').forEach(addChip); } catch (e) {}
  }

  // ---- Glasgow totale ----
  var ge = document.getElementById('gcs_e'), gv = document.getElementById('gcs_v'),
      gm = document.getElementById('gcs_m'), gt = document.getElementById('gcs_tot');
  function gcs() {
    if (!gt) return;
    var e = parseInt(ge.value, 10), v = parseInt(gv.value, 10), m = parseInt(gm.value, 10);
    gt.value = (e && v && m) ? (e + v + m) : '';
  }
  [ge, gv, gm].forEach(function (el) { if (el) el.addEventListener('change', gcs); });
  gcs();

  // ---- Eta' dalla data di nascita ----
  var dn = document.getElementById('pz_data_nascita'), eta = document.getElementById('pz_eta');
  if (dn && eta) {
    dn.addEventListener('change', function () {
      if (!dn.value) return;
      var b = new Date(dn.value), t = new Date();
      var a = t.getFullYear() - b.getFullYear();
      var m = t.getMonth() - b.getMonth();
      if (m < 0 || (m === 0 && t.getDate() < b.getDate())) a--;
      if (a >= 0 && a < 131) eta.value = a;
    });
  }

  // ---- Contatore parole note ----
  var note = document.getElementById('note'), cnt = document.getElementById('note-count');
  if (note && cnt) {
    var min = parseInt(note.getAttribute('data-min') || '0', 10);
    var upd = function () {
      var n = (note.value.trim().match(/\S+/g) || []).length;
      cnt.textContent = n + ' / ' + min + ' parole';
      cnt.className = 'hint' + (n >= min ? ' ok' : '');
    };
    note.addEventListener('input', upd);
    upd();
  }

  // ---- Evita doppio invio ----
  document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.sent) { ev.preventDefault(); return; }
      f.dataset.sent = '1';
      setTimeout(function () { f.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  });
  // ---- Aggiornamento automatico dei pannelli [data-live] (centralino, interventi in dashboard) ----
  var live = document.querySelectorAll('[data-live]');
  if (live.length && window.fetch && window.DOMParser) {
    var every = Math.max(5, parseInt(live[0].getAttribute('data-live'), 10) || 10) * 1000;
    setInterval(function () {
      if (document.hidden) return;
      fetch(location.href, { credentials: 'same-origin' })
        .then(function (r) {
          if (r.redirected && /index\.php/.test(r.url)) { location.reload(); return null; }
          return r.ok ? r.text() : null;
        })
        .then(function (t) {
          if (!t) return;
          var doc = new DOMParser().parseFromString(t, 'text/html');
          live.forEach(function (el) {
            var fresh = el.id && doc.getElementById(el.id);
            var a = document.activeElement;
            if (!fresh || (a && el.contains(a) && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName))) return;
            if (el.innerHTML !== fresh.innerHTML) el.innerHTML = fresh.innerHTML;
          });
        })
        .catch(function () {});
    }, every);
  }
})();
