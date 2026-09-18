/* MC Stats — interactions (recherche, statut serveur, onglets, infobulles, graphiques) */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  /* ---------------------------------------------------------------- Recherche */
  function initSearch(form) {
    var input = $('input', form);
    var box = $('.search__results', form);
    if (!input || !box) return;
    var timer = null, ctrl = null, active = -1;

    function close() { box.hidden = true; active = -1; }
    function render(list) {
      box.innerHTML = list.length
        ? list.map(function (p) {
            return '<a class="search__item" href="' + esc(p.url) + '">' +
              '<img src="' + esc(p.head) + '" alt="" width="28" height="28" loading="lazy">' +
              '<span>' + esc(p.name) + '</span><span class="muted">' + esc(p.play_time) + '</span></a>';
          }).join('')
        : '<div class="search__empty">Aucun joueur trouvé</div>';
      box.hidden = false;
      active = -1;
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (!q) { close(); return; }
      timer = setTimeout(function () {
        if (ctrl) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;
        fetch('api/search.php?q=' + encodeURIComponent(q), ctrl ? { signal: ctrl.signal } : {})
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (list) { if (input.value.trim() === q) render(Array.isArray(list) ? list : []); })
          .catch(function () {});
      }, 150);
    });
    input.addEventListener('keydown', function (e) {
      var links = $$('.search__item', box);
      if (e.key === 'Escape') { close(); return; }
      if (box.hidden || !links.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach(function (l, i) { l.classList.toggle('is-active', i === active); });
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        location.href = links[active].href;
      }
    });
    input.addEventListener('focus', function () {
      if (input.value.trim() && box.innerHTML) box.hidden = false;
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
  }

  /* ---------------------------------------------------------- Statut serveur */
  function initStatus() {
    var pill = $('[data-status-pill]');
    var card = $('[data-server]');
    var dots = $$('[data-online-uuid]');
    var badges = $$('[data-online-badge]');
    if (!pill && !card && !dots.length && !badges.length) return;

    function apply(s) {
      if (!s || s.enabled === false) {
        if (pill) pill.hidden = true;
        return;
      }
      var online = !!s.online;
      var players = online && s.players ? s.players : { online: 0, max: 0, sample: [] };
      var sample = players.sample || [];

      if (pill) {
        pill.classList.toggle('is-online', online);
        pill.classList.toggle('is-offline', !online);
        $('[data-status-pill-text]', pill).textContent = online ? players.online + ' / ' + players.max + ' en ligne' : 'Serveur hors ligne';
      }

      if (card) {
        var badge = $('[data-s-badge]', card);
        badge.classList.toggle('is-online', online);
        badge.classList.toggle('is-offline', !online);
        $('[data-s-badge-text]', card).textContent = online ? 'En ligne' : 'Hors ligne';
        var fav = $('[data-s-favicon]', card);
        if (s.favicon) { fav.src = s.favicon; fav.hidden = false; }
        // motd_html est déjà nettoyé côté serveur
        $('[data-s-motd]', card).innerHTML = online ? (s.motd_html || '') : '<span class="muted">Le serveur ne répond pas pour le moment.</span>';
        $('[data-s-players]', card).textContent = online ? players.online + ' / ' + players.max : '–';
        $('[data-s-version]', card).textContent = online ? (s.version || '–') : '–';
        $('[data-s-latency]', card).textContent = online && s.latency != null ? s.latency + ' ms' : '–';
        $('[data-s-meter]', card).style.width = online && players.max ? Math.min(100, players.online / players.max * 100) + '%' : '0';
        var list = $('[data-s-list]', card);
        if (!online) {
          list.innerHTML = '';
        } else if (!sample.length) {
          list.innerHTML = players.online ? '' : '<span class="muted">Personne en ligne pour le moment.</span>';
        } else {
          list.innerHTML = sample.map(function (p) {
            var inner = '<img src="' + esc(p.head) + '" alt="" width="22" height="22" loading="lazy">' + esc(p.name);
            return p.url ? '<a class="server__player" href="' + esc(p.url) + '">' + inner + '</a>' : '<span class="server__player">' + inner + '</span>';
          }).join('') + (players.online > sample.length ? '<span class="muted server__more">+' + (players.online - sample.length) + '</span>' : '');
        }
      }

      var ids = {};
      sample.forEach(function (p) { ids[p.id] = true; });
      // La liste envoyée par le serveur est limitée : on ne déclare "hors ligne" que si elle est complète
      var complete = !online || sample.length >= players.online;
      dots.forEach(function (d) {
        var on = !!ids[d.getAttribute('data-online-uuid')];
        if (on) { d.classList.add('is-on'); d.title = 'En ligne'; }
        else if (complete) { d.classList.remove('is-on'); d.title = ''; }
      });
      badges.forEach(function (b) {
        var on = !!ids[b.getAttribute('data-online-badge')];
        var text = $('[data-badge-text]', b);
        if (on) {
          // on garde "En ligne depuis …" rendu par le serveur s'il est déjà affiché
          if (!b.classList.contains('is-online')) text.textContent = 'En ligne';
          b.classList.add('is-online');
          b.classList.remove('is-offline');
        } else if (complete) {
          b.classList.remove('is-online');
          b.classList.add('is-offline');
          text.textContent = 'Hors ligne';
        }
      });
    }

    function load() {
      fetch('api/status.php', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(apply)
        .catch(function () {
          // erreur réseau passagère : on ne touche pas à l'état affiché
          if (pill) $('[data-status-pill-text]', pill).textContent = 'Statut indisponible';
        });
    }
    load();
    setInterval(load, 30000);
  }

  /* ------------------------------------------------------------------ Onglets */
  function initTabs(root) {
    var tabs = $$('[role="tab"]', root);
    if (!tabs.length) return;
    function show(id, focus) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById('panel-' + t.getAttribute('data-tab'));
        if (panel) panel.hidden = !on;
        if (on && focus) t.focus();
      });
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () {
        show(t.getAttribute('data-tab'));
        history.replaceState(null, '', '#' + t.getAttribute('data-tab'));
      });
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
        show(next.getAttribute('data-tab'), true);
        history.replaceState(null, '', '#' + next.getAttribute('data-tab'));
      });
    });
    var initial = location.hash.slice(1);
    var exists = tabs.some(function (t) { return t.getAttribute('data-tab') === initial; });
    show(exists ? initial : tabs[0].getAttribute('data-tab'));
  }

  /* -------------------------------------------------------------- Infobulles */
  function initTooltips() {
    var tip = document.getElementById('tooltip');
    if (!tip) return;
    var current = null;
    function place(el) {
      var r = el.getBoundingClientRect();
      var tw = tip.offsetWidth, th = tip.offsetHeight;
      var x = r.right + 10, y = r.top;
      if (x + tw > window.innerWidth - 8) x = r.left - tw - 10;
      if (x < 8) {
        x = Math.max(8, Math.min(window.innerWidth - tw - 8, r.left + r.width / 2 - tw / 2));
        y = r.bottom + 8;
        if (y + th > window.innerHeight - 8) y = r.top - th - 8;
      }
      y = Math.max(8, Math.min(y, window.innerHeight - th - 8));
      tip.style.left = x + 'px';
      tip.style.top = y + 'px';
    }
    function show(el) {
      current = el;
      // contenu généré et échappé côté serveur
      tip.innerHTML = el.getAttribute('data-tip');
      tip.hidden = false;
      place(el);
    }
    function hide() { current = null; tip.hidden = true; }
    document.addEventListener('mouseover', function (e) {
      var el = e.target.closest ? e.target.closest('[data-tip]') : null;
      if (el && el !== current) show(el);
      else if (!el && current) hide();
    });
    document.addEventListener('focusin', function (e) {
      var el = e.target.closest ? e.target.closest('[data-tip]') : null;
      if (el) show(el); else if (current) hide();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    window.addEventListener('scroll', function () { if (current) hide(); }, { passive: true });
  }

  /* ------------------------------------- Contenu d'un shulker (fenêtre) */
  function initBoxes() {
    var modal = null;
    var last = null;

    function build() {
      modal = document.createElement('div');
      modal.className = 'modal';
      modal.hidden = true;
      modal.innerHTML = '<div class="modal__backdrop" data-close></div>'
        + '<div class="modal__card" role="dialog" aria-modal="true" aria-labelledby="modal-title">'
        + '<div class="modal__head"><strong class="modal__title" id="modal-title"></strong>'
        + '<button type="button" class="modal__close" data-close aria-label="Fermer">×</button></div>'
        + '<div class="modal__body"></div></div>';
      document.body.appendChild(modal);
      modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-close]')) close();
      });
    }

    function open(slot) {
      var box = $('.slot__box', slot);
      if (!box) return;
      if (!modal) build();
      last = slot;
      $('.modal__title', modal).textContent = slot.getAttribute('data-box-name') || 'Contenu';
      $('.modal__body', modal).innerHTML = box.innerHTML;
      modal.hidden = false;
      var tip = document.getElementById('tooltip');
      if (tip) tip.hidden = true;
      $('.modal__close', modal).focus();
    }

    function close() {
      if (!modal || modal.hidden) return;
      modal.hidden = true;
      $('.modal__body', modal).innerHTML = '';
      if (last) last.focus();
      last = null;
    }

    document.addEventListener('click', function (e) {
      var slot = e.target.closest ? e.target.closest('.slot--box') : null;
      if (slot && (!modal || !modal.contains(slot))) open(slot);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var slot = e.target.closest ? e.target.closest('.slot--box') : null;
      if (slot) { e.preventDefault(); open(slot); }
    });
  }

  /* --------------------------------------------------------------- Divers */
  function initCopy() {
    $$('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var text = b.getAttribute('data-copy');
        var target = $('.server__copy', b) || b;
        var done = function () {
          var old = target.textContent;
          target.textContent = 'Copié !';
          setTimeout(function () { target.textContent = old; }, 1400);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    });
  }

  function initFilters() {
    $$('[data-filter-input]').forEach(function (input) {
      var target = $(input.getAttribute('data-filter-input'));
      if (!target) return;
      var empty = $('[data-filter-empty]', target.closest('.panel') || document);
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var n = 0;
        $$('[data-filter-text]', target).forEach(function (el) {
          var on = el.getAttribute('data-filter-text').indexOf(q) !== -1;
          el.hidden = !on;
          if (on) n++;
        });
        if (empty) empty.hidden = n > 0;
      });
    });
    $$('[data-autosubmit]').forEach(function (s) {
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }

  /* ------------------------------------------------------------ Graphiques */
  function initCharts() {
    var canvases = $$('canvas[data-chart]');
    if (!canvases.length || !window.Chart) return;
    var css = getComputedStyle(document.documentElement);
    var v = function (n) { return css.getPropertyValue(n).trim(); };
    var color = v('--chart'), grid = v('--grid'), muted = v('--muted'), text = v('--text'), surface = v('--surface');
    var nf = new Intl.NumberFormat('fr-FR');
    Chart.defaults.font.family = v('--font');
    Chart.defaults.color = muted;

    function fmtHours(h) {
      var m = Math.round(h * 60);
      return m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min' : m + ' min';
    }
    // Ligne verticale sous le curseur (graphiques en courbe)
    var crosshair = {
      id: 'crosshair',
      afterDatasetsDraw: function (chart) {
        var active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
        if (!active.length) return;
        var x = active[0].element.x, area = chart.chartArea, ctx = chart.ctx;
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,.18)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, area.top);
        ctx.lineTo(x, area.bottom);
        ctx.stroke();
        ctx.restore();
      }
    };

    canvases.forEach(function (el) {
      var d = JSON.parse(el.getAttribute('data-chart'));
      var bar = d.type === 'bar';
      var fmt = function (y) { return d.unit === 'h' ? fmtHours(y) : nf.format(Math.round(y)) + ' pts'; };
      new Chart(el, {
        type: d.type,
        data: {
          labels: d.labels,
          datasets: [{
            data: d.values,
            backgroundColor: color,
            borderColor: color,
            borderWidth: bar ? 0 : 2,
            borderRadius: bar ? 4 : 0,
            borderSkipped: 'start',
            maxBarThickness: 16,
            pointRadius: 0,
            pointHitRadius: 12,
            pointHoverRadius: 5,
            pointHoverBackgroundColor: color,
            pointHoverBorderColor: surface,
            pointHoverBorderWidth: 2,
            tension: 0.3,
            spanGaps: true
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 300 },
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(11,14,19,.96)',
              borderColor: 'rgba(255,255,255,.14)',
              borderWidth: 1,
              titleColor: muted,
              bodyColor: text,
              bodyFont: { weight: '700' },
              displayColors: false,
              padding: 10,
              callbacks: { label: function (c) { return c.parsed.y == null ? '' : fmt(c.parsed.y); } }
            }
          },
          scales: {
            x: { grid: { display: false }, border: { color: grid }, ticks: { maxRotation: 0, autoSkipPadding: 14 } },
            y: {
              beginAtZero: bar,
              grace: '5%',
              grid: { color: grid },
              border: { display: false },
              ticks: {
                maxTicksLimit: 5,
                callback: function (val) { return d.unit === 'h' ? val + ' h' : nf.format(val); }
              }
            }
          }
        },
        plugins: bar ? [] : [crosshair]
      });
    });
  }

  /* ------------------------------------------------------------ Formulaires */
  function initForms() {
    // Demande de whitelist : libellés selon l'édition (Java / Bedrock)
    var sw = $('[data-edition-switch]');
    if (sw) {
      var update = function () {
        var checked = $('input[name="edition"]:checked', sw);
        var ed = checked ? checked.value : 'java';
        $$('[data-java][data-bedrock]').forEach(function (el) { el.textContent = el.getAttribute('data-' + ed); });
      };
      $$('input[name="edition"]', sw).forEach(function (r) { r.addEventListener('change', update); });
      update();
    }
    // Confirmation avant les actions sensibles (refuser, supprimer…)
    $$('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
      });
    });
    // Motif demandé avant l'envoi (expulsion, bannissement) : annuler abandonne l'action
    $$('form[data-prompt]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var reason = window.prompt(form.getAttribute('data-prompt'), '');
        if (reason === null) { e.preventDefault(); return; }
        var field = $('input[name="reason"]', form);
        if (field) field.value = reason;
      });
    });
  }

  /* ------------------------------------------------- Menus « Actions » (admin) */
  function initMenus() {
    var menus = $$('details.menu');
    if (!menus.length) return;
    menus.forEach(function (m) {
      m.addEventListener('toggle', function () {
        if (m.open) menus.forEach(function (o) { if (o !== m) o.open = false; });
      });
    });
    document.addEventListener('click', function (e) {
      menus.forEach(function (m) { if (m.open && !m.contains(e.target)) m.open = false; });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') menus.forEach(function (m) { m.open = false; });
    });
  }

  function init() {
    $$('[data-search]').forEach(initSearch);
    $$('[data-tabs]').forEach(initTabs);
    initStatus();
    initTooltips();
    initCopy();
    initFilters();
    initForms();
    initMenus();
    initBoxes();
    initCharts();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
