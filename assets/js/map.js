/* MC Stats — carte (images Pl3xMap + joueurs), avec Leaflet */
(function () {
  'use strict';

  var el = document.getElementById('map');
  if (!el || !window.L) return;
  var cfg = JSON.parse(el.getAttribute('data-map-config'));

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  var worlds = cfg.worlds;
  var byName = {};
  worlds.forEach(function (w) { byName[w.name] = w; });

  var current = null;
  var tiles = null;
  var spawnMarker = null;
  var players = cfg.players || [];
  var markers = {};
  var showOffline = !!cfg.showOffline;
  var filter = '';
  var homesCfg = cfg.homes || null;
  var homesMode = 'off';      // off | mine | player | all
  var homesData = [];
  var homesMarkers = [];
  var playerHomesLabel = '';
  var playerHomes = [];
  var list = $('[data-map-list]');
  var count = $('[data-map-count]');
  var coordsBox = $('[data-map-coords]');

  // Colonne des joueurs repliable, état mémorisé dans le navigateur.
  // Appliqué avant la création de la carte pour qu'elle démarre directement à la bonne taille.
  var layout = el.closest('.map-layout');
  var PANEL_KEY = 'mcstats.mapPanelCollapsed';
  try {
    if (localStorage.getItem(PANEL_KEY) === '1') layout.classList.add('is-collapsed');
  } catch (e) {}

  // Repère identique à Pl3xMap : axe Z vers le bas, 1 pixel = 1 bloc au zoom natif
  var crs = L.extend({}, L.CRS.Simple, { transformation: new L.Transformation(1, 0, 1, 0) });
  // Dézoom au-delà des images générées par Pl3xMap : Leaflet réduit les images du dernier niveau.
  var EXTRA_OUT = Math.max(0, Math.min(6, cfg.extraZoomOut === undefined ? 3 : cfg.extraZoomOut));
  var map = L.map(el, { crs: crs, center: [0, 0], zoom: 0, minZoom: -EXTRA_OUT, zoomSnap: 1, zoomDelta: 1, attributionControl: true, zoomControl: false });
  map.attributionControl.setPrefix('<a href="https://modrinth.com/plugin/pl3xmap" target="_blank" rel="noopener">Pl3xMap</a> · <a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');

  var ICON_HIDE = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M10 3 5 8l5 5M13 3v10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_SHOW = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="m6 3 5 5-5 5M3 3v10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var panelButton = null;

  function setPanelCollapsed(collapsed) {
    layout.classList.toggle('is-collapsed', collapsed);
    if (panelButton) {
      var label = collapsed ? 'Afficher la liste des joueurs' : 'Masquer la liste des joueurs';
      panelButton.innerHTML = collapsed ? ICON_SHOW : ICON_HIDE;
      panelButton.title = label;
      panelButton.setAttribute('aria-label', label);
      panelButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
    // la carte change de taille : Leaflet recalcule en gardant le même centre
    map.invalidateSize();
    try { localStorage.setItem(PANEL_KEY, collapsed ? '1' : '0'); } catch (e) {}
  }

  var PanelToggle = L.Control.extend({
    options: { position: 'topleft' },
    onAdd: function () {
      var bar = L.DomUtil.create('div', 'leaflet-bar');
      panelButton = L.DomUtil.create('button', 'map-panel-toggle', bar);
      panelButton.type = 'button';
      panelButton.setAttribute('aria-controls', 'map-panel');
      L.DomEvent.disableClickPropagation(bar);
      L.DomEvent.on(panelButton, 'click', function () {
        setPanelCollapsed(!layout.classList.contains('is-collapsed'));
      });
      return bar;
    }
  });
  new PanelToggle().addTo(map);
  L.control.zoom({ zoomInTitle: 'Zoomer', zoomOutTitle: 'Dézoomer' }).addTo(map);
  setPanelCollapsed(layout.classList.contains('is-collapsed'));

  function scale() { return 1 / Math.pow(2, current.maxOut); }
  function latLngIn(w, x, z) { var s = 1 / Math.pow(2, w.maxOut); return L.latLng(z * s, x * s); }
  function toLatLng(x, z) { return latLngIn(current, x, z); }
  function toBlock(latlng) { return [Math.floor(latlng.lng / scale()), Math.floor(latlng.lat / scale())]; }
  function nativeZoom() { return current.maxOut; }

  // Dossier de zoom Pl3xMap : 0 = 1 pixel par bloc, 1 = 2 blocs par pixel, etc.
  var PlTiles = L.TileLayer.extend({
    getTileUrl: function (c) {
      var w = this.options.world;
      var folder = Math.min(w.maxOut, Math.max(0, w.maxOut - c.z));
      var path = w.name + '/' + folder + '/' + w.renderer + '/' + c.x + '_' + c.y + '.' + w.format;
      // sans adresse publique configurée, les images passent par le site (réservées aux joueurs connectés)
      return cfg.tilesUrl ? cfg.tilesUrl + '/' + path.split('/').map(encodeURIComponent).join('/') : 'api/tile.php?t=' + encodeURIComponent(path);
    }
  });

  // view : {x, z, zoom} pour une vue précise, sinon le point d'apparition du monde.
  // La vue est posée avant d'ajouter les images pour ne charger que celles qui seront affichées.
  function setWorld(name, view) {
    var w = byName[name] || worlds[0];
    if (current === w) {
      if (view) map.setView(toLatLng(view.x, view.z), view.zoom);
      return;
    }
    current = w;
    var v = view || { x: w.spawn.x, z: w.spawn.z, zoom: Math.max(0, w.maxOut - w.zoom) };
    if (tiles) map.removeLayer(tiles);
    if (spawnMarker) map.removeLayer(spawnMarker);
    map.setMinZoom(-EXTRA_OUT);
    map.setMaxZoom(w.maxOut + w.maxIn);
    map.setView(toLatLng(v.x, v.z), v.zoom, { animate: false });
    tiles = new PlTiles('', {
      world: w,
      tileSize: 512,
      noWrap: true,
      minNativeZoom: 0,
      maxNativeZoom: w.maxOut,
      minZoom: -EXTRA_OUT,
      maxZoom: w.maxOut + w.maxIn,
      className: 'map-tiles',
      attribution: ''
    }).addTo(map);
    el.setAttribute('data-dimension', w.type);
    farZoom();
    if (cfg.spawnIcon) {
      spawnMarker = L.marker(toLatLng(w.spawn.x + 0.5, w.spawn.z + 0.5), {
        icon: L.divIcon({ className: 'map-spawn', html: '<span style="background-image:url(\'' + esc(cfg.spawnIcon) + '\')"></span>', iconSize: [20, 20], iconAnchor: [10, 10] }),
        title: "Point d'apparition", keyboard: false, zIndexOffset: -100
      }).addTo(map);
    }
    $$('[data-map-world]').forEach(function (b) {
      var on = b.getAttribute('data-map-world') === w.name;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    Object.keys(markers).forEach(function (k) { map.removeLayer(markers[k].m); });
    markers = {};
    refresh();
    renderHomes();
  }

  // ----------------------------------------------------------------- Homes
  function homeIcon(h) {
    return L.divIcon({
      className: 'map-home' + (homesMode === 'all' ? '' : ' is-named'),
      html: '<span class="map-home__icon" style="background-image:url(\'' + esc(homesCfg.icon) + '\')"></span>' +
        '<span class="map-home__name">' + esc(h.name) + '</span>',
      iconSize: [22, 22],
      iconAnchor: [11, 11],
      popupAnchor: [0, -12]
    });
  }

  function homePopup(h) {
    return '<div class="map-pop map-pop--home"><span class="map-home__icon" style="background-image:url(\'' + esc(homesCfg.icon) + '\')"></span>' +
      '<div><strong>' + esc(h.name) + '</strong>' +
      (homesMode === 'mine' ? '' : '<span class="muted">' + esc(h.player) + '</span>') +
      '<span class="muted map-pop__xyz">X ' + h.x + ' · Y ' + h.y + ' · Z ' + h.z + '</span></div></div>';
  }

  function renderHomes() {
    homesMarkers.forEach(function (m) { map.removeLayer(m); });
    homesMarkers = [];
    if (!homesCfg || homesMode === 'off' || !current) return;
    homesData.forEach(function (h) {
      if (h.world !== current.name) return;
      var m = L.marker(toLatLng(h.x + 0.5, h.z + 0.5), { icon: homeIcon(h), title: h.name, riseOnHover: true, zIndexOffset: 500 });
      m.bindPopup(homePopup(h), { className: 'map-popup', minWidth: 160, maxWidth: 260 });
      m.addTo(map);
      homesMarkers.push(m);
    });
  }

  function homeChip(mode) { return $('[data-homes="' + mode + '"]'); }

  function setHomes(mode, data, label) {
    homesMode = mode;
    homesData = mode === 'off' ? [] : (data || []);
    if (mode === 'player') playerHomes = homesData;
    var chip = homeChip('player');
    if (chip) {
      if (mode === 'player') playerHomesLabel = label || playerHomesLabel;
      chip.hidden = !playerHomesLabel;
      if (playerHomesLabel) chip.innerHTML = '<span class="mc-icon" style="background-image:url(\'' + esc(homesCfg.icon) + '\')"></span>' + esc(playerHomesLabel);
    }
    $$('[data-homes]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-homes') === mode);
    });
    renderHomes();
  }

  function loadHomes(query, mode) {
    fetch(homesCfg.api + query, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && Array.isArray(d.homes)) setHomes(mode, d.homes, d.label);
      })
      .catch(function () {});
  }

  // Admin : cliquer un joueur affiche ses homes
  function showHomesOf(p) {
    if (!homesCfg || !homesCfg.admin || !p) return;
    loadHomes('?p=' + encodeURIComponent(p.uuid), 'player');
  }

  if (homesCfg && homesCfg.mine && homesCfg.mine.length) setHomes('mine', homesCfg.mine);

  $$('[data-homes]').forEach(function (b) {
    b.addEventListener('click', function () {
      var mode = b.getAttribute('data-homes');
      if (homesMode === mode) { setHomes('off'); return; }
      if (mode === 'mine') setHomes('mine', homesCfg.mine);
      else if (mode === 'all') loadHomes('?all=1', 'all');
      else if (playerHomesLabel) setHomes('player', playerHomes, playerHomesLabel);
    });
  });

  // Sous le zoom 0, les images sont réduites : lissage plutôt que pixels, plus lisible de loin
  function farZoom() {
    el.classList.toggle('is-far', map.getZoom() < 0);
  }
  map.on('zoomend', farZoom);

  function visible(p) {
    return p.online || showOffline;
  }

  function popupHtml(p) {
    return '<div class="map-pop"><img src="' + esc(p.head) + '" alt="" width="40" height="40">' +
      '<div><strong>' + esc(p.name) + '</strong>' +
      '<span class="' + (p.online ? 'text-online' : 'muted') + '">' + esc(p.seen) + '</span>' +
      (cfg.showCoords && !p.hideCoords ? '<span class="muted map-pop__xyz">X ' + p.x + ' · Z ' + p.z + '</span>' : '') +
      '<a href="' + esc(p.url) + '">Voir le profil →</a></div></div>';
  }

  function icon(p) {
    return L.divIcon({
      className: 'map-player' + (p.online ? ' is-online' : ''),
      html: '<img src="' + esc(p.head) + '" alt=""><span class="map-player__name">' + esc(p.name) + '</span>',
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      popupAnchor: [0, -16]
    });
  }

  function renderMarkers() {
    var keep = {};
    players.forEach(function (p) {
      if (p.world !== current.name || !visible(p)) return;
      keep[p.uuid] = true;
      var ll = toLatLng(p.x + 0.5, p.z + 0.5);
      var entry = markers[p.uuid];
      if (!entry) {
        var m = L.marker(ll, { icon: icon(p), title: p.name, riseOnHover: true, zIndexOffset: p.online ? 1000 : 0 });
        m.bindPopup(popupHtml(p), { className: 'map-popup', minWidth: 190, maxWidth: 280 });
        m.addTo(map);
        markers[p.uuid] = { m: m, online: p.online };
      } else {
        entry.m.setLatLng(ll);
        if (entry.online !== p.online) {
          entry.m.setIcon(icon(p));
          entry.m.setZIndexOffset(p.online ? 1000 : 0);
          entry.online = p.online;
        }
        entry.m.setPopupContent(popupHtml(p));
      }
    });
    Object.keys(markers).forEach(function (k) {
      if (!keep[k]) {
        map.removeLayer(markers[k].m);
        delete markers[k];
      }
    });
  }

  function renderList() {
    var q = filter.toLowerCase();
    var items = players.filter(function (p) {
      return visible(p) && (!q || p.name.toLowerCase().indexOf(q) !== -1);
    });
    var online = players.filter(function (p) { return p.online; }).length;
    if (count) count.textContent = online + ' en ligne';
    list.innerHTML = items.length
      ? items.map(function (p) {
          var w = byName[p.world];
          return '<li><button type="button" class="map-item' + (p.online ? ' is-online' : '') + '" data-uuid="' + esc(p.uuid) + '">' +
            '<img src="' + esc(p.head) + '" alt="" width="32" height="32" loading="lazy">' +
            '<span class="map-item__text"><strong>' + esc(p.name) + '</strong>' +
            '<span class="' + (p.online ? 'text-online' : 'muted') + '">' + esc(p.seen) + (w ? ' · ' + esc(w.label) : '') + '</span></span></button></li>';
        }).join('')
      : '<li class="muted empty-note">' + (q ? 'Aucun joueur ne correspond.' : 'Aucun joueur à afficher.') + '</li>';
  }

  function refresh() {
    renderMarkers();
    renderList();
  }

  function findPlayer(q) {
    q = String(q || '').toLowerCase();
    if (!q) return null;
    var exact = null, partial = null;
    players.forEach(function (p) {
      if (p.uuid === q || p.name.toLowerCase() === q) exact = exact || p;
      else if (p.name.toLowerCase().indexOf(q) !== -1) partial = partial || p;
    });
    return exact || partial;
  }

  function focusPlayer(p) {
    if (!p || !visible(p)) return;
    showHomesOf(p);
    var w = byName[p.world];
    if (!w) return;
    var zoom = current === w ? Math.max(map.getZoom(), w.maxOut) : w.maxOut;
    setWorld(p.world, { x: p.x + 0.5, z: p.z + 0.5, zoom: zoom });
    var entry = markers[p.uuid];
    if (entry) entry.m.openPopup();
    if (window.matchMedia('(max-width: 820px)').matches) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Panneau : dimensions, recherche, liste, joueurs hors ligne
  $$('[data-map-world]').forEach(function (b) {
    b.addEventListener('click', function () { setWorld(b.getAttribute('data-map-world')); });
  });
  var search = $('[data-map-search]');
  if (search) {
    search.addEventListener('input', function () { filter = search.value.trim(); renderList(); });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); focusPlayer(findPlayer(search.value.trim())); }
    });
  }
  var offline = $('[data-map-offline]');
  if (offline) {
    offline.addEventListener('change', function () { showOffline = offline.checked; refresh(); });
  }
  list.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-uuid]') : null;
    if (!b) return;
    var uuid = b.getAttribute('data-uuid');
    focusPlayer(players.filter(function (p) { return p.uuid === uuid; })[0]);
  });

  // Coordonnées sous le curseur
  map.on('mousemove', function (e) {
    var b = toBlock(e.latlng);
    coordsBox.textContent = 'X ' + b[0] + ' · Z ' + b[1];
  });
  map.on('mouseout', function () { coordsBox.textContent = 'X – · Z –'; });

  // Rafraîchissement des positions
  function poll() {
    fetch(cfg.api, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && Array.isArray(d.players)) {
          players = d.players;
          refresh();
        }
      })
      .catch(function () {});
  }
  setInterval(poll, cfg.refresh || 5000);

  // Zone la plus peuplée : joueurs connectés en priorité, sinon dernières positions connues.
  // Pour chaque joueur, on compte ceux à moins de CLUSTER blocs ; le groupe le plus nombreux l'emporte
  // (à égalité : la dimension qui vient en premier, Surface avant Nether et End).
  var CLUSTER = 300;
  function busiestView() {
    var known = players.filter(function (p) { return byName[p.world]; });
    var online = known.filter(function (p) { return p.online; });
    var pool = online.length ? online : (showOffline ? known : []);
    if (!pool.length) return null;
    var best = null;
    pool.forEach(function (p) {
      var group = pool.filter(function (q) {
        return q.world === p.world && Math.abs(q.x - p.x) <= CLUSTER && Math.abs(q.z - p.z) <= CLUSTER;
      });
      if (!best || group.length > best.length || (group.length === best.length && byName[p.world].order < byName[best[0].world].order)) {
        best = group;
      }
    });
    var w = byName[best[0].world];
    var xs = best.map(function (q) { return q.x; }), zs = best.map(function (q) { return q.z; });
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minZ = Math.min.apply(null, zs), maxZ = Math.max.apply(null, zs);
    // un joueur seul : un cran de dézoom pour voir les alentours ; un groupe : tout le groupe à l'écran
    var zoom = w.maxOut - 1;
    if (best.length > 1) {
      var bounds = L.latLngBounds(latLngIn(w, minX, minZ), latLngIn(w, maxX + 1, maxZ + 1));
      zoom = map.getBoundsZoom(bounds, false, L.point(160, 160));
    }
    return { world: w.name, x: (minX + maxX) / 2 + 0.5, z: (minZ + maxZ) / 2 + 0.5, zoom: Math.max(-EXTRA_OUT, Math.min(zoom, w.maxOut)) };
  }

  // Vue de départ : joueur demandé (?p=), coordonnées (?x=&z=&w=), zone la plus peuplée ou point d'apparition
  var f = cfg.focus || {};
  var target = findPlayer(f.p);
  var busiest;
  if (target && byName[target.world]) {
    focusPlayer(target);
  } else if (f.x !== null && f.z !== null && f.x !== undefined) {
    var fw = worlds.filter(function (w) { return w.name === f.w || w.type === f.w; })[0] || worlds[0];
    setWorld(fw.name, { x: f.x, z: f.z, zoom: fw.maxOut });
  } else if (cfg.centerOnPlayers && (busiest = busiestView())) {
    setWorld(busiest.world, busiest);
  } else {
    setWorld(worlds[0].name);
  }
})();
