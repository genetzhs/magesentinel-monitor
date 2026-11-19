/* MageSentinel Monitor — admin motion: health ring fill, checks settling one by one, count-up, chart hover. */
(function () {
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var C = 2 * Math.PI * 42;

  function tone(p) { return p >= 100 ? 'good' : p >= 85 ? 'watch' : 'bad'; }

  function setRing(el, pct, animateNumber) {
    el.querySelectorAll('.ms-ring-arc, .ms-ring-glow').forEach(function (c) {
      c.setAttribute('stroke', 'url(#ms-rg-' + tone(pct) + ')');
      c.style.strokeDashoffset = C * (1 - pct / 100);
    });
    var n = el.querySelector('.ms-ring-n');
    var from = parseInt(n.textContent, 10) || 0;
    if (reduce || !animateNumber) { n.textContent = pct; return; }
    var t0 = null;
    (function f(t) {
      if (!t0) t0 = t;
      var k = Math.min(1, (t - t0) / 1100), e = 1 - Math.pow(1 - k, 4);
      n.textContent = Math.round(from + (pct - from) * e);
      if (k < 1) requestAnimationFrame(f);
    })(performance.now());
  }

  function fillRing(el) {
    var pct = +el.dataset.msRing;
    if (reduce) return;
    el.querySelectorAll('.ms-ring-arc, .ms-ring-glow').forEach(function (c) { c.style.transition = 'none'; c.style.strokeDashoffset = C; });
    el.querySelector('.ms-ring-n').textContent = '0';
    el.getBoundingClientRect();
    el.querySelectorAll('.ms-ring-arc, .ms-ring-glow').forEach(function (c) { c.style.transition = ''; });
    requestAnimationFrame(function () { setRing(el, pct, true); });
  }

  function countUp(root) {
    if (reduce) return;
    root.querySelectorAll('[data-ms-count]').forEach(function (el) {
      var to = +el.dataset.msCount, t0 = null;
      if (!to) return;
      (function f(t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / 700), e = 1 - Math.pow(1 - k, 4);
        el.textContent = Math.round(to * e).toLocaleString(document.documentElement.lang || undefined);
        if (k < 1) requestAnimationFrame(f);
      })(performance.now());
    });
  }

  // The authored moment: every check starts "running" and settles in order,
  // the strip and the ring following along. The results are already on the page.
  function settle(band, list) {
    var ring = band.querySelector('.ms-ring');
    var segs = band.querySelectorAll('.ms-seg');
    // Grouped cards change the order on the page; data-i is each check's place in the strip.
    var rows = list ? Array.prototype.slice.call(list.querySelectorAll('.ms-check')).sort(function (a, b) { return (+a.getAttribute('data-i') || 0) - (+b.getAttribute('data-i') || 0); }) : [];
    var states = Array.prototype.map.call(segs, function (s) { return (s.className.match(/ms-(good|watch|bad)/) || [])[1]; });
    var btn = band.querySelector('[data-ms-recheck]');
    if (reduce) { if (ring) fillRing(ring); return; }
    if (btn) btn.setAttribute('aria-busy', 'true');
    segs.forEach(function (s) { s.classList.add('is-running'); });
    Array.prototype.forEach.call(rows, function (r) { r.querySelector('.ms-st').classList.add('is-running'); });
    if (ring) {
      ring.classList.add('is-running');
      ring.querySelectorAll('.ms-ring-arc, .ms-ring-glow').forEach(function (c) { c.style.transition = 'none'; c.style.strokeDashoffset = C; });
      ring.querySelector('.ms-ring-n').textContent = '0';
      ring.getBoundingClientRect();
      ring.querySelectorAll('.ms-ring-arc, .ms-ring-glow').forEach(function (c) { c.style.transition = ''; });
    }
    var step = 170, start = 420, good = 0, total = states.length || 1;
    states.forEach(function (s, i) {
      setTimeout(function () {
        segs[i].classList.remove('is-running');
        if (rows[i]) {
          var st = rows[i].querySelector('.ms-st');
          st.classList.remove('is-running');
          st.classList.add('is-landing');
        }
        if (s === 'good') good++;
        if (ring) setRing(ring, Math.floor(100 * good / total), true);
      }, start + i * step);
    });
    setTimeout(function () {
      if (ring) { ring.classList.remove('is-running'); setRing(ring, +ring.dataset.msRing, true); }
      if (btn) btn.removeAttribute('aria-busy');
    }, start + states.length * step + 150);
  }

  function charts(root) {
    root.querySelectorAll('[data-ms-plot]').forEach(function (plot) {
      var svg = plot.querySelector('svg'), tip = plot.querySelector('.ms-tip'), vb = svg.viewBox.baseVal;
      requestAnimationFrame(function () { plot.classList.add('ms-grow'); });
      plot.querySelectorAll('.ms-hitg').forEach(function (g) {
        var hit = g.querySelector('.ms-hit');
        g.addEventListener('mouseenter', function () {
          tip.textContent = '';
          var b = document.createElement('b'); b.textContent = hit.dataset.label;
          var v = document.createElement('span'); v.textContent = hit.dataset.value;
          tip.appendChild(b); tip.appendChild(v);
          var k = svg.getBoundingClientRect().width / vb.width;
          var x = Math.min(Math.max(+hit.dataset.x * k, tip.offsetWidth / 2), plot.clientWidth - tip.offsetWidth / 2);
          tip.style.left = x + 'px';
          tip.style.top = (+hit.dataset.y * k) + 'px';
          tip.classList.add('on');
        });
        g.addEventListener('mouseleave', function () { tip.classList.remove('on'); });
      });
    });
  }

  // "Test cart & checkout": MageSentinel runs this store's checkout from outside
  // and the page follows it step by step, the way the MageSentinel app does.
  function testPurchase(app) {
    var btn = app.querySelector('[data-ms-test-start]');
    var box = app.querySelector('[data-ms-test]');
    if (!btn || !box) return;
    var L = JSON.parse(box.dataset.labels || '{}');
    var bar = box.querySelector('[data-ms-test-bar]');
    var line = box.querySelector('[data-ms-test-line]');
    var timer = null, started = 0;

    function call(action, extra) {
      var q = 'action=' + action + '&nonce=' + encodeURIComponent(box.dataset.nonce) + (extra || '');
      return fetch(box.dataset.ajax + '?' + q, { method: action === 'magesentinel_test_start' ? 'POST' : 'GET', credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
    }
    function say(text, tone) {
      line.textContent = text;
      line.className = 'ms-test-line' + (tone ? ' ms-' + tone : '');
    }
    var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    var CROSS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>';
    var begun = {}, took = {}, lastIdx = -1;
    function secs(ms) { return ms < 10000 ? (ms / 1000).toFixed(1) + 's' : Math.round(ms / 1000) + 's'; }
    function paint(p) {
      var names = p.names || [];
      var total = Math.max(p.total || 0, names.length, (p.statuses || []).length);
      var now = Date.now();
      if (p.index !== lastIdx) {
        if (lastIdx >= 0 && begun[lastIdx] && !took[lastIdx]) took[lastIdx] = now - begun[lastIdx];
        if (!begun[p.index]) begun[p.index] = now;
        lastIdx = p.index;
      }
      if (p.phase !== 'running' && begun[p.index] && !took[p.index]) took[p.index] = now - begun[p.index];
      // MageSentinel says when the running step began; trust it over our first sight of it.
      var since = p.stepStartedAt ? Date.parse(p.stepStartedAt) : NaN;
      if (p.phase === 'running' && since > 0) begun[p.index] = Math.min(now, since);
      if (bar.children.length !== total) {
        bar.textContent = '';
        for (var i = 0; i < total; i++) {
          var li = document.createElement('li');
          li.innerHTML = '<span class="ms-flow-node"></span><span class="ms-flow-label"></span><span class="ms-flow-time"></span>';
          bar.appendChild(li);
        }
      }
      Array.prototype.forEach.call(bar.children, function (li, i) {
        var st = p.statuses && p.statuses[i];
        if (!st) st = i === p.index ? (p.phase === 'failed' ? 'failed' : p.phase === 'done' ? 'passed' : 'running') : i < p.index ? 'passed' : 'pending';
        li.className = 'ms-flow-step ms-f-' + st;
        var node = li.children[0];
        var want = st === 'passed' ? CHECK : st === 'failed' ? CROSS : '';
        if (node.dataset.icon !== st) { node.innerHTML = want; node.dataset.icon = st; }
        var n = names[i] || (i === p.index ? p.step : '');
        li.children[1].textContent = n ? ((L._short || {})[n] || L[n] || n) : '';
        li.title = n ? (L[n] || n) : '';
        var ms = p.durations && typeof p.durations[i] === 'number' && st !== 'running' ? p.durations[i] : took[i];
        if (ms) took[i] = ms;
        // The running step counts up between polls, as in MageSentinel's Active checks.
        if (st === 'running' && begun[i]) ms = now - begun[i];
        li.children[2].textContent = ms ? secs(ms) : '';
      });
      split(p, total, names);
    }
    // As in MageSentinel's Active checks: the slowest finished step gets a
    // pill, and "where the time went" splits the check into one bar per step.
    var COLORS = ['#7d93ff', '#5eead4', '#fbbf24', '#a78bfa', '#f472b6', '#60a5fa', '#34d399', '#fb923c', '#94a3b8', '#e879f9', '#2dd4bf'];
    var splitBox = box.querySelector('[data-ms-split]');
    function split(p, total, names) {
      var steps = Array.prototype.slice.call(bar.children);
      var timed = [], sum = 0;
      steps.forEach(function (li, i) {
        var st = li.className.replace(/.*ms-f-/, '');
        var old = li.querySelector('.ms-flow-slowest'); if (old) old.remove();
        if ((st === 'passed' || st === 'failed') && took[i]) { timed.push({ i: i, ms: took[i], failed: st === 'failed' }); sum += took[i]; }
        else if (st === 'running' && begun[i]) { var run = Math.max(1, Date.now() - begun[i]); timed.push({ i: i, ms: run, running: true }); sum += run; }
      });
      if (!splitBox) return;
      if (!timed.length || (timed.length === 1 && timed[0].running && timed[0].ms < 300)) { splitBox.hidden = true; return; }
      var slow = null;
      var done = timed.filter(function (x) { return !x.running; });
      if (done.length > 1) done.forEach(function (x) { if (!slow || x.ms > slow.ms) slow = x; });
      var label = function (i) { var n = names[i] || ''; return (L._short || {})[n] || L[n] || n; };
      if (slow) {
        var pill = document.createElement('span');
        pill.className = 'ms-flow-slowest'; pill.textContent = L._slowest;
        steps[slow.i].appendChild(pill);
      }
      splitBox.hidden = false;
      splitBox.querySelector('[data-ms-split-hint]').textContent =
        (slow ? L._share.replace('{step}', label(slow.i)).replace('{share}', Math.round(slow.ms / sum * 100)) + ' · ' : '') + secs(sum);
      var track = splitBox.querySelector('[data-ms-split-bar]');
      // Segments are kept per step and only resized, so widths glide instead of jumping.
      var keep = {};
      timed.forEach(function (x) {
        keep[x.i] = true;
        var seg = track.querySelector('[data-step="' + x.i + '"]');
        if (!seg) {
          seg = document.createElement('span');
          seg.setAttribute('data-step', x.i);
          seg.style.flexGrow = 0;
          track.appendChild(seg);
          seg.getBoundingClientRect();
        }
        seg.className = 'ms-split-seg' + (x.failed ? ' ms-failed' : '') + (x.running ? ' ms-running' : '');
        seg.style.flexGrow = x.ms;
        seg.style.backgroundColor = x.failed ? '' : COLORS[x.i % COLORS.length];
        // The running step has no number on it: progress can arrive late.
        seg.title = label(x.i) + (x.running ? '' : ': ' + secs(x.ms));
        seg.textContent = x.ms / sum > 0.12 ? label(x.i) + (x.running ? '' : ' ' + secs(x.ms)) : '';
      });
      Array.prototype.forEach.call(track.querySelectorAll('[data-step]'), function (seg) {
        if (!keep[seg.getAttribute('data-step')]) seg.remove();
      });
    }
    var label = btn.querySelector('span'), idle = label.textContent;
    // Between polls the running step keeps counting, so the bar never sits still.
    var last = null, tick = null;
    function finish() {
      clearInterval(tick); tick = null; last = null;
      clearInterval(timer); timer = null;
      btn.disabled = false; btn.removeAttribute('aria-busy'); label.textContent = idle;
    }
    function poll(job) {
      call('magesentinel_test_status', '&job=' + encodeURIComponent(job)).then(function (r) {
        if (!r || !r.success) return;
        var d = r.data;
        if (d.progress) {
          last = d.progress;
          paint(d.progress);
          say((d.progress.index + 1) + '/' + d.progress.total + ' · ' + (L[d.progress.step] || d.progress.step));
        } else if (d.state === 'waiting' || d.state === 'prioritized' || d.state === 'delayed') {
          say(L._queued);
        }
        if (d.state === 'completed' || d.state === 'failed') {
          finish();
          var st = d.result && d.result.status;
          var tone = st === 'PASS' ? 'good' : st === 'FAIL' ? 'bad' : 'watch';
          say(L[st] || L.UNKNOWN, tone);
          if (d.result && d.result.url) {
            var a = document.createElement('a');
            a.href = d.result.url; a.target = '_blank'; a.rel = 'noopener';
            a.textContent = L._view + ' →';
            line.appendChild(document.createTextNode(' '));
            line.appendChild(a);
          }
          if (L._ctaUrl && st !== 'PASS') {
            var cta = document.createElement('p');
            cta.className = 'ms-note ms-note-cta'; cta.setAttribute('data-ms-cta', '');
            cta.appendChild(document.createTextNode(L._cta + ' '));
            var c = document.createElement('a');
            c.href = L._ctaUrl; c.target = '_blank'; c.rel = 'noopener';
            c.textContent = L._ctaLink + ' →';
            cta.appendChild(c);
            line.parentNode.insertBefore(cta, line.nextSibling);
          }
        } else if (Date.now() - started > 6 * 60000) {
          finish();
          say(L._slow, 'watch');
        }
      }).catch(function () {});
    }
    btn.addEventListener('click', function () {
      if (timer) return;
      btn.disabled = true; btn.setAttribute('aria-busy', 'true'); label.textContent = btn.dataset.busy;
      box.hidden = false; bar.textContent = ''; took = {}; begun = {}; lastIdx = -1; last = null;
      var oldCta = box.querySelector('[data-ms-cta]');
      if (oldCta) oldCta.remove();
      clearInterval(tick);
      tick = setInterval(function () { if (last && last.phase === 'running') paint(last); }, 250);
      if (splitBox) { splitBox.hidden = true; splitBox.querySelector('[data-ms-split-bar]').textContent = ''; }
      var preview = app.querySelector('[data-ms-preview]');
      if (preview) preview.hidden = true;
      say(L._start);
      call('magesentinel_test_start').then(function (r) {
        if (!r || !r.success) { finish(); say((r && r.data && r.data.message) || '', 'bad'); return; }
        started = Date.now();
        timer = setInterval(function () { poll(r.data.jobId); }, 2500);
        poll(r.data.jobId);
      }).catch(function () { finish(); say('Could not reach MageSentinel.', 'bad'); });
    });
  }

  document.querySelectorAll('.ms-app').forEach(function (app) {
    var form = app.querySelector('[data-ms-settings]');
    if (form) {
      var row = form.querySelector('[data-ms-save-row]');
      var status = form.querySelector('[data-ms-status]');
      // Not form.action: WordPress puts an <input name="action"> in the form, which shadows it.
      var url = new URL(form.getAttribute('action'), location.href).toString();
      var say = function (text, ok) {
        if (!status) return;
        status.hidden = false;
        status.classList.toggle('ms-failed', !ok);
        status.querySelector('span').textContent = text;
        status.style.animation = 'none'; status.getBoundingClientRect(); status.style.animation = '';
      };
      // A switch saves the moment it is flipped: the same POST the form makes,
      // without leaving the page. A typed field waits for the button.
      form.querySelectorAll('input[type=checkbox]').forEach(function (box) {
        box.addEventListener('change', function () {
          box.disabled = true;
          var data = new FormData(form);
          if (box.checked) data.set(box.name, '1'); // disabled inputs are left out of FormData
          // Never the token: a switch must not replace it with whatever the browser autofilled.
          form.querySelectorAll('input[type=password]').forEach(function (f) { data.delete(f.name); });
          fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(); say(row.dataset.saved, true); })
            .catch(function () { box.checked = !box.checked; say(row.dataset.failed, false); })
            .then(function () { box.disabled = false; });
        });
      });
    }
    if (form) form.addEventListener('submit', function () {
      var b = form.querySelector('[data-ms-save]');
      if (!b) return;
      b.setAttribute('aria-busy', 'true');
      b.querySelector('span').textContent = b.dataset.busy;
    });
    var band = app.querySelector('[data-ms-verdict]');
    if (band) {
      settle(band, app.querySelector('[data-ms-checks]'));
      var btn = band.querySelector('[data-ms-recheck]');
      if (btn) btn.addEventListener('click', function (e) {
        // The settle animation also marks it busy; only a click already in flight is ignored.
        if (btn.dataset.msGoing) { e.preventDefault(); return; }
        btn.dataset.msGoing = '1';
        btn.setAttribute('aria-busy', 'true'); // navigates: the check runs on the server, then the overview reloads
      });
    } else {
      app.querySelectorAll('.ms-ring').forEach(fillRing);
    }
    countUp(app);
    charts(app);
    testPurchase(app);
  });
})();

/* 4.2: overview tabs. The hash picks the tab, so links like #ms-js-errors land on the right one. */
(function () {
  var nav = document.querySelector('[data-ms-ptabs]');
  if (!nav) return;
  var app = nav.closest('.ms-app');
  var tabs = nav.querySelectorAll('[data-ms-ptab]');
  var known = {}; tabs.forEach(function (t) { known[t.dataset.msPtab] = 1; });
  function show(id, scrollTo) {
    if (!known[id]) id = 'overview';
    app.setAttribute('data-ms-active', id);
    tabs.forEach(function (t) { var on = t.dataset.msPtab === id; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    try { localStorage.setItem('msTab', id); } catch (e) {}
    if (scrollTo) { var el = document.getElementById(scrollTo); if (el) el.scrollIntoView({ block: 'start' }); }
  }
  function fromHash() {
    var h = location.hash.replace('#', '');
    if (h.indexOf('tab-') === 0) return show(h.slice(4));
    var el = h && document.getElementById(h);
    if (el) { var p = el.closest('[data-ms-tab]'); if (p) return show(p.getAttribute('data-ms-tab'), h); }
    var saved = null; try { saved = localStorage.getItem('msTab'); } catch (e) {}
    show(saved || 'overview');
  }
  nav.addEventListener('click', function (e) {
    var t = e.target.closest('[data-ms-ptab]'); if (!t) return;
    e.preventDefault();
    history.replaceState(null, '', '#tab-' + t.dataset.msPtab);
    show(t.dataset.msPtab);
  });
  window.addEventListener('hashchange', fromHash);
  fromHash();
})();
