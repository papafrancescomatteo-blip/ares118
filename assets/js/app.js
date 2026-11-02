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
  function afterLive() { if (window.aresScanCalls) window.aresScanCalls(); }
  if (live.length && window.fetch && window.DOMParser) {
    var every = Math.max(3, parseInt(live[0].getAttribute('data-live'), 10) || 10) * 1000;
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
          afterLive();
        })
        .catch(function () {});
    }, every);
  }

  // ---- Tablet di bordo: orologio e suono delle chiamate ----
  var clock = document.getElementById('clock');
  if (clock) {
    var tick = function () { var d = new Date(); clock.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2); };
    tick(); setInterval(tick, 1000);
  }

  var alertBar = document.getElementById('alert-bar');
  if (alertBar) {
    var AC = window.AudioContext || window.webkitAudioContext;
    var ctx = null, timer = null, blink = null, baseTitle = document.title;
    var audioBar = document.getElementById('audio-bar');
    var audioBtn = document.getElementById('audio-btn');
    var store = function (k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; };
    var getAck = function () { try { return JSON.parse(sessionStorage.getItem('ares_ack') || '[]'); } catch (e) { return []; } };
    var setAck = function (a) { try { sessionStorage.setItem('ares_ack', JSON.stringify(a)); } catch (e) {} };
    var enabled = function () { return store('ares_audio') !== 'off'; };

    function unlock() {
      if (!AC) return false;
      if (!ctx) ctx = new AC();
      if (ctx.state === 'suspended') ctx.resume();
      return ctx.state === 'running' || ctx.state === 'suspended';
    }
    function running() { return ctx && ctx.state === 'running'; }
    function tone(f, t0, dur) {
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'square'; o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, ctx.currentTime + t0);
      g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + t0 + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + t0 + dur);
      o.connect(g); g.connect(ctx.destination);
      o.start(ctx.currentTime + t0); o.stop(ctx.currentTime + t0 + dur + 0.02);
    }
    // segnale a due toni alternati (stile cercapersone / tablet di bordo), ripetuto tre volte
    function pattern() {
      if (!running() || !enabled()) return;
      for (var i = 0; i < 3; i++) { tone(1320, i * 0.5, 0.22); tone(990, i * 0.5 + 0.25, 0.22); }
      if (navigator.vibrate) navigator.vibrate([250, 120, 250]);
    }
    function refreshAudioUi() {
      if (audioBtn) audioBtn.textContent = enabled() ? 'Audio attivo' : 'Audio spento';
      if (audioBar) audioBar.hidden = !(enabled() && AC && !running());
    }
    function stopAlarm() {
      if (timer) { clearInterval(timer); timer = null; }
      if (blink) { clearInterval(blink); blink = null; document.title = baseTitle; }
    }
    function startAlarm() {
      if (!timer) { pattern(); timer = setInterval(pattern, 3500); }
      if (!blink) { var on = false; blink = setInterval(function () { on = !on; document.title = on ? 'NUOVA CHIAMATA' : baseTitle; }, 800); }
    }
    window.aresScanCalls = function () {
      var ack = getAck(), pend = [], txt = '';
      document.querySelectorAll('[data-alert-id]').forEach(function (el) {
        var id = el.getAttribute('data-alert-id');
        if (ack.indexOf(id) < 0) { pend.push(id); txt = txt || el.getAttribute('data-alert-text') || ''; }
      });
      if (pend.length) {
        alertBar.hidden = false;
        document.getElementById('alert-text').textContent = txt ? ' · ' + txt : '';
        startAlarm();
      } else {
        alertBar.hidden = true;
        stopAlarm();
      }
      refreshAudioUi();
    };
    document.getElementById('alert-silence').addEventListener('click', function () {
      var ack = getAck();
      document.querySelectorAll('[data-alert-id]').forEach(function (el) { var id = el.getAttribute('data-alert-id'); if (ack.indexOf(id) < 0) ack.push(id); });
      setAck(ack);
      window.aresScanCalls();
    });
    document.getElementById('audio-unlock').addEventListener('click', function () { unlock(); refreshAudioUi(); window.aresScanCalls(); });
    audioBtn.addEventListener('click', function () {
      if (enabled()) { store('ares_audio', 'off'); stopAlarm(); }
      else { store('ares_audio', 'on'); unlock(); pattern(); }
      refreshAudioUi();
      window.aresScanCalls();
    });
    // il primo tocco in qualunque punto della pagina sblocca l'audio del browser
    ['click', 'touchstart', 'keydown'].forEach(function (ev) {
      document.addEventListener(ev, function () { if (enabled() && !running()) { unlock(); setTimeout(function () { refreshAudioUi(); window.aresScanCalls(); }, 50); } }, { passive: true });
    });
    window.aresScanCalls();
  }

  // ---- Scheda di soccorso a schermate (se JavaScript manca, resta tutta in fila) ----
  var wform = document.querySelector('form[data-wizard]');
  if (wform) {
    var secs = [].slice.call(wform.querySelectorAll(':scope > .sec'));
    var actions = wform.querySelector('.actions');
    var groups = secs.length === 11 ? [[0, 1], [2, 3], [4], [5], [6, 7], [8, 9], [10]] : secs.map(function (_, i) { return [i]; });
    var names = secs.length === 11 ? ['Missione', 'Paziente', 'Valutazione', 'Prestazioni', 'Eventi critici e rifiuti', 'Esito e note', 'Equipaggio e firme']
      : secs.map(function (x) { return x.querySelector('h2').textContent.replace(/^\d+\.\s*/, ''); });
    var steps = groups.map(function (g, i) { return { name: names[i], secs: g.map(function (k) { return secs[k]; }) }; });
    var cur = 0;

    wform.classList.add('wiz');
    var nav = document.createElement('div');
    nav.className = 'wiz-nav';
    nav.innerHTML = '<div class="wiz-bar"><i></i></div><div class="wiz-pills"></div><div class="wiz-count"></div>';
    wform.insertBefore(nav, secs[0]);
    var pills = nav.querySelector('.wiz-pills'), bar = nav.querySelector('.wiz-bar i'), count = nav.querySelector('.wiz-count');
    steps.forEach(function (st, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = st.name;
      b.addEventListener('click', function () { show(i); });
      pills.appendChild(b);
    });

    var foot = document.createElement('div');
    foot.className = 'wiz-foot';
    foot.innerHTML = '<button type="button" class="btn slate" data-w="prev">Indietro</button><button type="button" class="btn teal" data-w="next">Avanti</button>';
    wform.insertBefore(foot, actions || null);
    foot.addEventListener('click', function (ev) {
      var w = ev.target.getAttribute && ev.target.getAttribute('data-w');
      if (w === 'next') show(cur + 1); else if (w === 'prev') show(cur - 1);
    });

    function show(i, noScroll) {
      cur = Math.max(0, Math.min(steps.length - 1, i));
      steps.forEach(function (st, k) { st.secs.forEach(function (x) { x.style.display = k === cur ? '' : 'none'; }); });
      [].forEach.call(pills.children, function (b, k) { b.className = (k === cur ? 'on' : '') + (k < cur ? ' done' : ''); });
      bar.style.width = ((cur + 1) / steps.length * 100) + '%';
      count.textContent = 'Passo ' + (cur + 1) + ' di ' + steps.length + ' · ' + steps[cur].name;
      foot.querySelector('[data-w=prev]').style.visibility = cur === 0 ? 'hidden' : 'visible';
      foot.querySelector('[data-w=next]').style.display = cur === steps.length - 1 ? 'none' : '';
      if (actions) actions.style.display = cur === steps.length - 1 ? '' : 'none';
      if (!noScroll) nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    // un campo obbligatorio vuoto in una schermata nascosta: ci porta li'
    wform.addEventListener('invalid', function (ev) {
      var el = ev.target;
      steps.forEach(function (st, k) { if (st.secs.some(function (x) { return x.contains(el); })) { if (k !== cur) show(k, true); } });
    }, true);
    show(0, true);
  }
})();
