(function () {
  const messages = window.SESAME_I18N || {};
  const previewStates = new Map();
  let previewObserver = null;
  let previewTimer = null;
  let previewLifecycleBound = false;
  let previewPageSuspended = false;
  initMapProviderSettings();
  initMap();
  initCameraPositionEditor();
  initPlayer();
  initPreviewRefresh();
  initDensitySwitch();
  initGroupTreePickers();
  initCameraFormVisibility();
  initDvrStreamOptions();
  initSubmitProgress();
  initAssignmentPickers();
  initDvrStreamImport();
  initLocalTimes();
  initPlayerBackBridge();

  function initMap() {
    if (!window.L || !window.SESAME_CAMERAS || !document.getElementById("map")) return;

    const cameras = window.SESAME_CAMERAS || [];
    const visibleCameras = cameras.filter((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
    const container = document.getElementById("map");
    const map = L.map(container, { zoomControl: true });
    setPlainLeafletAttribution(map);
    const center = configuredMapCenter();
    map.setView([center.lat, center.lng], configuredMapZoom());
    void addBaseMapLayer(map, container);

    const markerLayer = cameraMarkerLayer();
    visibleCameras.forEach((camera) => {
      const marker = L.marker([camera.lat, camera.lng], { icon: cameraIcon(camera.direction, camera.viewAngle, 42) });
      marker.bindPopup(cameraPopupHtml(camera), { className: "camera-popup", maxWidth: 280 });
      markerLayer.addLayer(marker);
    });
    markerLayer.addTo(map);

    fitMapToCameras(map, visibleCameras);
    map.on("popupopen", (event) => initPreviewRefresh(event.popup.getElement()));
    map.on("popupclose", (event) => disposePreviewRefresh(event.popup.getElement()));
  }

  function initCameraPositionEditor() {
    const container = document.getElementById("camera-position-map");
    if (!window.L || !container) return;

    const panel = container.closest("[data-camera-location-options]");
    const init = () => {
      if (container.dataset.cameraPositionEditorBound === "1") return;
      if (panel && !panel.open) return;
      container.dataset.cameraPositionEditorBound = "1";
      initCameraPositionEditorMap(container);
    };
    if (panel) {
      panel.addEventListener("toggle", init);
    }
    init();
  }

  function initCameraPositionEditorMap(container) {
    const latInput = document.getElementById("camera-latitude") || document.querySelector('input[name="latitude"]');
    const lngInput = document.getElementById("camera-longitude") || document.querySelector('input[name="longitude"]');
    const directionInput = document.getElementById("camera-direction") || document.querySelector('input[name="direction_deg"]');
    if (!latInput || !lngInput) return;

    let committed = readEditorState();
    let pending = null;
    const defaultLatitude = mapDefaultCoordinate(container.dataset.defaultLat, 25.2048, -90, 90);
    const defaultLongitude = mapDefaultCoordinate(container.dataset.defaultLng, 55.2708, -180, 180);
    const start = hasPoint(committed) ? [committed.lat, committed.lng] : [defaultLatitude, defaultLongitude];
    const editorMap = L.map(container, { zoomControl: true }).setView(
      start,
      hasPoint(committed) ? 16 : configuredMapZoom()
    );
    setPlainLeafletAttribution(editorMap);
    const confirmBar = createMapConfirmBar(container);
    let cameraMarker = null;
    let directionMarker = null;
    let directionLine = null;

    void addBaseMapLayer(editorMap, container);

    const stageChange = (next, pan = false) => {
      pending = normalizeEditorState(next);
      renderEditorState(pending);
      showMapConfirm(confirmBar, true);
      if (pan && hasPoint(pending)) {
        editorMap.setView([pending.lat, pending.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    const syncFromInputs = () => {
      committed = readEditorState();
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
      if (hasPoint(committed)) {
        editorMap.setView([committed.lat, committed.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    renderEditorState(committed);
    editorMap.on("click", (event) => {
      const base = pending || committed;
      stageChange({ ...base, lat: event.latlng.lat, lng: event.latlng.lng });
    });
    editorMap.on("zoomend", () => renderEditorState(pending || committed));
    latInput.addEventListener("change", syncFromInputs);
    lngInput.addEventListener("change", syncFromInputs);
    directionInput?.addEventListener("change", syncFromInputs);
    confirmBar.querySelector(".camera-map-apply")?.addEventListener("click", () => {
      if (!pending) return;
      committed = pending;
      pending = null;
      writeEditorState(committed);
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    confirmBar.querySelector(".camera-map-cancel")?.addEventListener("click", () => {
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    document.querySelector(".camera-map-clear")?.addEventListener("click", () => {
      stageChange({ ...committed, lat: NaN, lng: NaN });
    });
    container.closest("form")?.addEventListener("submit", (event) => {
      if (!pending) return;
      event.preventDefault();
      showMapConfirm(confirmBar, true, true);
    });
    window.setTimeout(() => editorMap.invalidateSize(), 0);

    function readEditorState() {
      return normalizeEditorState({
        lat: toNumber(latInput.value),
        lng: toNumber(lngInput.value),
        direction: directionInput ? toNumber(directionInput.value) : 0
      });
    }

    function writeEditorState(state) {
      if (hasPoint(state)) {
        latInput.value = state.lat.toFixed(7);
        lngInput.value = state.lng.toFixed(7);
      } else {
        latInput.value = "";
        lngInput.value = "";
      }
      if (directionInput) {
        directionInput.value = String(state.direction);
      }
    }

    function renderEditorState(state) {
      if (!hasPoint(state)) {
        removeLayer(cameraMarker);
        removeLayer(directionMarker);
        removeLayer(directionLine);
        cameraMarker = null;
        directionMarker = null;
        directionLine = null;
        return;
      }

      const center = L.latLng(state.lat, state.lng);
      const target = directionTarget(center, state.direction, editorMap);
      if (!cameraMarker) {
        cameraMarker = L.marker(center, { draggable: true, icon: cameraIcon(state.direction, 60, 54) }).addTo(editorMap);
        cameraMarker.on("dragend", () => {
          const latlng = cameraMarker.getLatLng();
          const base = pending || committed;
          stageChange({ ...base, lat: latlng.lat, lng: latlng.lng });
        });
      } else {
        cameraMarker.setLatLng(center);
        cameraMarker.setIcon(cameraIcon(state.direction, 60, 54));
      }

      if (!directionMarker) {
        directionMarker = L.marker(target, { draggable: true, icon: directionHandleIcon(), zIndexOffset: 1000 }).addTo(editorMap);
        directionMarker.on("dragend", () => {
          const base = pending || committed;
          if (!hasPoint(base)) return;
          stageChange({ ...base, direction: bearingFromTarget(L.latLng(base.lat, base.lng), directionMarker.getLatLng(), editorMap) });
        });
      } else {
        directionMarker.setLatLng(target);
      }

      if (!directionLine) {
        directionLine = L.polyline([center, target], {
          color: "#C1964E",
          weight: 3,
          opacity: 0.9,
          dashArray: "6 6",
          className: "camera-direction-line"
        }).addTo(editorMap);
      } else {
        directionLine.setLatLngs([center, target]);
      }
    }
  }

  function initPlayer() {
    const page = document.querySelector(".player-page");
    if (!page) return;

    const stage = page.querySelector(".player-stage") || page;
    const frame = page.querySelector(".player-frame");
    const button = page.querySelector(".player-fullscreen");
    const backUrl = page.dataset.backUrl || "/";
    const fullscreenTarget = (stage.requestFullscreen || stage.webkitRequestFullscreen) ? stage : frame;
    const requestFullscreen = fullscreenTarget?.requestFullscreen || fullscreenTarget?.webkitRequestFullscreen;
    const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
    const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

    if (button && requestFullscreen) {
      button.addEventListener("click", async () => {
        try {
          if (fullscreenElement() && exitFullscreen) {
            await exitFullscreen.call(document);
          } else {
            await requestFullscreen.call(fullscreenTarget);
          }
        } catch (_error) {
          button.blur();
        }
      });

      const syncFullscreenState = () => {
        const active = fullscreenElement() === fullscreenTarget;
        const label = active ? tr("collapse", "Свернуть") : tr("fullscreen", "На весь экран");
        page.classList.toggle("is-fullscreen", active);
        button.textContent = label;
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
      };
      document.addEventListener("fullscreenchange", syncFullscreenState);
      document.addEventListener("webkitfullscreenchange", syncFullscreenState);
    } else if (button) {
      button.hidden = true;
    }

    [page.querySelector(".player-edge-swipe"), page.querySelector(".player-toolbar")]
      .filter(Boolean)
      .forEach((zone) => bindBackSwipe(zone, backUrl));
  }

  function bindBackSwipe(element, backUrl) {
    let startX = 0;
    let startY = 0;
    let startAt = 0;

    element.addEventListener("touchstart", (event) => {
      const touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
      startAt = Date.now();
    }, { passive: true });

    element.addEventListener("touchend", (event) => {
      const touch = event.changedTouches[0];
      const dx = touch.clientX - startX;
      const dy = touch.clientY - startY;
      const elapsed = Date.now() - startAt;
      if (elapsed < 700 && dx > 72 && Math.abs(dy) < 55) {
        window.location.assign(backUrl);
      }
    }, { passive: true });
  }

  function createMapConfirmBar(container) {
    const field = container.closest(".camera-position-field") || container.parentElement;
    const bar = document.createElement("div");
    bar.className = "camera-map-confirm";
    bar.hidden = true;
    bar.innerHTML = `
      <span>${escapeHtml(tr("mapChangePending", "Подтвердите изменение на карте"))}</span>
      <div>
        <button type="button" class="primary camera-map-apply">${escapeHtml(tr("apply", "Применить"))}</button>
        <button type="button" class="camera-map-cancel">${escapeHtml(tr("cancel", "Отменить"))}</button>
      </div>
    `;
    field.insertBefore(bar, container);
    return bar;
  }

  function showMapConfirm(bar, visible, attention = false) {
    bar.hidden = !visible;
    bar.classList.toggle("attention", Boolean(visible && attention));
    if (visible && attention) {
      bar.scrollIntoView({ block: "nearest" });
    }
  }

  function fitMapToCameras(map, cameras) {
    if (!cameras.length) return;

    if (cameras.length === 1) {
      map.setView([cameras[0].lat, cameras[0].lng], 16);
      return;
    }

    const bounds = L.latLngBounds(cameras.map((camera) => [camera.lat, camera.lng]));
    const padding = map.getSize().x < 520 ? [28, 28] : [52, 52];
    map.fitBounds(bounds, {
      paddingTopLeft: padding,
      paddingBottomRight: padding,
      maxZoom: 16
    });
  }

  function setPlainLeafletAttribution(map) {
    map.attributionControl?.setPrefix('<a href="https://leafletjs.com" title="A JavaScript library for interactive maps">Leaflet</a>');
  }

  function initMapProviderSettings() {
    document.querySelectorAll("[data-map-provider-settings]").forEach((form) => {
      const select = form.querySelector("[data-map-provider-select]");
      if (!select) return;

      const sync = () => {
        form.querySelectorAll("[data-map-provider-key]").forEach((field) => {
          field.hidden = field.dataset.mapProviderKey !== select.value;
        });
      };
      select.addEventListener("change", sync);
      sync();
    });
  }

  function mapProviderConfig() {
    return window.SESAME_MAP_CONFIG && typeof window.SESAME_MAP_CONFIG === "object"
      ? window.SESAME_MAP_CONFIG
      : { provider: "osm" };
  }

  function configuredMapCenter() {
    const center = mapProviderConfig().defaultCenter || {};
    return {
      lat: mapDefaultCoordinate(center.lat, 25.2048, -90, 90),
      lng: mapDefaultCoordinate(center.lng, 55.2708, -180, 180)
    };
  }

  function configuredMapZoom() {
    const zoom = Number(mapProviderConfig().defaultZoom);
    return Number.isInteger(zoom) && zoom >= 0 && zoom <= 19 ? zoom : 10;
  }

  async function addBaseMapLayer(map, container) {
    const config = mapProviderConfig();
    if (config.provider === "yandex" && config.tileUrl) {
      const layer = L.tileLayer(config.tileUrl, {
        maxZoom: Number(config.maxZoom) || 20
      }).addTo(map);
      const logo = addYandexMapLogo(map, config);
      layer.once("tileerror", () => fallbackToOsm(map, container, layer, logo));
      return;
    }

    if (config.provider === "google" && config.sessionUrl) {
      try {
        const response = await fetch(config.sessionUrl, {
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const session = await response.json();
        if (!session.tileUrl) throw new Error("Missing tile URL");

        const layer = L.tileLayer(session.tileUrl, {
          maxZoom: Number(session.maxZoom) || 22,
          attribution: '<a href="https://maps.google.com/" target="_blank" rel="noopener">Google Maps</a>'
        }).addTo(map);
        const stopAttribution = installGoogleMapAttribution(map, config.attributionUrl);
        layer.once("tileerror", () => {
          stopAttribution();
          fallbackToOsm(map, container, layer);
        });
        return;
      } catch (_error) {
        fallbackToOsm(map, container);
        return;
      }
    }

    addOsmBaseLayer(map);
    if (config.provider && config.provider !== "osm") {
      showMapProviderWarning(container);
    }
  }

  function addOsmBaseLayer(map) {
    return L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
    }).addTo(map);
  }

  function fallbackToOsm(map, container, providerLayer = null, providerControl = null) {
    if (container.dataset.mapProviderFallback === "1") return;
    container.dataset.mapProviderFallback = "1";
    if (providerLayer && map.hasLayer(providerLayer)) {
      map.removeLayer(providerLayer);
    }
    if (providerControl) {
      map.removeControl(providerControl);
    }
    addOsmBaseLayer(map);
    showMapProviderWarning(container);
  }

  function showMapProviderWarning(container) {
    if (container.querySelector(".map-provider-warning")) return;
    const warning = document.createElement("div");
    warning.className = "map-provider-warning";
    warning.setAttribute("role", "status");
    warning.textContent = tr("mapProviderUnavailable", "Провайдер карт недоступен. Используется OpenStreetMap.");
    container.appendChild(warning);
  }

  function addYandexMapLogo(map, config) {
    const control = L.control({ position: "bottomleft" });
    control.onAdd = () => {
      const wrapper = L.DomUtil.create("div", "map-provider-logo yandex-map-logo");
      const link = document.createElement("a");
      link.href = config.mapsUrl || "https://yandex.com/maps/";
      link.target = "_blank";
      link.rel = "noopener";
      const logo = document.createElement("img");
      logo.src = config.logoUrl;
      logo.alt = "Yandex Maps";
      link.appendChild(logo);
      wrapper.appendChild(link);
      L.DomEvent.disableClickPropagation(wrapper);
      return wrapper;
    };
    control.addTo(map);
    return control;
  }

  function installGoogleMapAttribution(map, endpoint) {
    if (!endpoint) return () => {};
    let timer = null;
    let activeAttribution = "";
    let activeAttributionMarkup = "";
    let stopped = false;

    const update = async () => {
      if (stopped) return;
      const bounds = map.getBounds();
      const params = new URLSearchParams({
        north: String(bounds.getNorth()),
        south: String(bounds.getSouth()),
        east: String(bounds.getEast()),
        west: String(bounds.getWest()),
        zoom: String(Math.round(map.getZoom()))
      });
      try {
        const response = await fetch(`${endpoint}?${params}`, {
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        if (!response.ok) return;
        const payload = await response.json();
        const nextAttribution = String(payload.copyright || "").trim();
        if (nextAttribution === activeAttribution) return;
        if (activeAttributionMarkup) map.attributionControl?.removeAttribution(activeAttributionMarkup);
        activeAttribution = nextAttribution;
        activeAttributionMarkup = nextAttribution ? escapeHtml(nextAttribution) : "";
        if (activeAttributionMarkup) map.attributionControl?.addAttribution(activeAttributionMarkup);
      } catch (_error) {
        // The permanent Google Maps attribution remains visible on network errors.
      }
    };

    const schedule = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(update, 250);
    };
    map.on("moveend zoomend", schedule);
    schedule();

    return () => {
      stopped = true;
      window.clearTimeout(timer);
      map.off("moveend zoomend", schedule);
      if (activeAttributionMarkup) map.attributionControl?.removeAttribution(activeAttributionMarkup);
    };
  }

  function cameraMarkerLayer() {
    if (typeof L.markerClusterGroup !== "function") {
      return L.layerGroup();
    }

    return L.markerClusterGroup({
      showCoverageOnHover: false,
      removeOutsideVisibleBounds: true,
      spiderfyOnMaxZoom: true,
      disableClusteringAtZoom: 18,
      maxClusterRadius: 56,
      iconCreateFunction(cluster) {
        const count = cluster.getChildCount();
        return L.divIcon({
          className: "camera-cluster-icon",
          html: `<div class="camera-cluster" data-digits="${String(count).length}"><span>${escapeHtml(count)}</span></div>`,
          iconSize: [52, 52],
          iconAnchor: [26, 26]
        });
      }
    });
  }

  function normalizeEditorState(state) {
    return {
      lat: toNumber(state.lat),
      lng: toNumber(state.lng),
      direction: normalizeDirection(state.direction ?? state.dir ?? 0)
    };
  }

  function hasPoint(state) {
    return Number.isFinite(state.lat) && Number.isFinite(state.lng);
  }

  function mapDefaultCoordinate(value, fallback, min, max) {
    const raw = String(value ?? "").trim();
    const coordinate = raw === "" ? Number.NaN : Number(raw);
    return Number.isFinite(coordinate) && coordinate >= min && coordinate <= max ? coordinate : fallback;
  }

  function directionTarget(center, direction, map) {
    const point = map.latLngToLayerPoint(center);
    const radians = normalizeDirection(direction) * Math.PI / 180;
    return map.layerPointToLatLng(L.point(
      point.x + Math.sin(radians) * 72,
      point.y - Math.cos(radians) * 72
    ));
  }

  function bearingFromTarget(center, target, map) {
    const a = map.latLngToLayerPoint(center);
    const b = map.latLngToLayerPoint(target);
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return 0;
    return normalizeDirection(Math.atan2(dx, -dy) * 180 / Math.PI);
  }

  function removeLayer(layer) {
    if (layer) {
      layer.remove();
    }
  }

  function normalizeDirection(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return ((Math.round(number) % 360) + 360) % 360;
  }

  function initPlayerBackBridge() {
    const frames = Array.from(document.querySelectorAll(".player-frame"));
    if (!frames.length) return;

    window.addEventListener("message", (event) => {
      const data = event.data || {};
      if (!data || data.type !== "sesame-dvr:player-back") return;
      if (!frames.some((frame) => frame.contentWindow === event.source)) return;

      const targetUrl = safeSameOriginUrl(data.url);
      if (!targetUrl) return;

      event.source?.postMessage({ type: "sesame-dvr:player-back-ack", id: data.id || "" }, event.origin || "*");
      if (history.length > 1 && referrerMatches(targetUrl)) {
        history.back();
        return;
      }

      window.location.assign(relativeUrl(targetUrl));
    });
  }

  function safeSameOriginUrl(value) {
    try {
      const url = new URL(String(value || ""), window.location.href);
      return url.origin === window.location.origin ? url : null;
    } catch (_error) {
      return null;
    }
  }

  function referrerMatches(targetUrl) {
    if (!document.referrer) return false;
    const referrerUrl = safeSameOriginUrl(document.referrer);
    return Boolean(referrerUrl && relativeUrl(referrerUrl) === relativeUrl(targetUrl));
  }

  function relativeUrl(url) {
    return `${url.pathname}${url.search}${url.hash}`;
  }

  function cameraIcon(direction, viewAngle = 60, markerHitSize = 42) {
    const angle = normalizeDirection(direction);
    const fov = clamp(Number(viewAngle) || 60, 12, 170);
    const coneLength = 72;
    const coneWidth = Math.round(clamp(2 * Math.tan(fov * Math.PI / 360) * coneLength, 34, 132));
    const hitSize = Math.round(clamp(Number(markerHitSize) || 42, 30, 72));
    return L.divIcon({
      className: "camera-marker-icon",
      html: `<div class="camera-marker" style="--camera-direction:${angle}deg;--camera-cone-width:${coneWidth}px;--camera-cone-length:${coneLength}px"><span class="camera-view-cone"></span><span class="camera-marker-dot">●</span></div>`,
      iconSize: [hitSize, hitSize],
      iconAnchor: [hitSize / 2, hitSize / 2],
      popupAnchor: [0, -Math.round(hitSize / 2)]
    });
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function directionHandleIcon() {
    return L.divIcon({
      className: "",
      html: '<div class="camera-direction-handle"></div>',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
  }

  function cameraPopupHtml(camera) {
    const stateText = camera.streamUnavailable
      ? tr("streamUnavailable", "Поток недоступен")
      : tr("previewUnavailable", "Превью недоступно");
    const unavailableClass = camera.streamUnavailable ? " stream-unavailable" : "";
    const previewLabel = tr("openPlayer", tr("openVideo", "Открыть видео"));
    const preview = camera.preview
      ? `<a class="map-popup-preview is-loading${unavailableClass}" href="${escapeHtml(camera.player)}" aria-label="${escapeHtml(previewLabel)}"><canvas data-preview-src="${escapeHtml(camera.preview)}" data-preview-refresh="off" width="640" height="360" aria-hidden="true" hidden></canvas><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span><span class="preview-play" aria-hidden="true"></span></a>`
      : `<div class="map-popup-preview no-preview${unavailableClass}"><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span></div>`;
    const favoriteTitle = camera.favorite
      ? tr("removeFavorite", "Удалить из избранного")
      : tr("addFavorite", "Добавить в избранное");
    const favoriteClass = camera.favorite ? "favorite active" : "favorite";
    const favoriteIcon = camera.favorite ? "★" : "☆";

    return `
      <div class="map-popup-card">
        ${preview}
        <strong>${escapeHtml(camera.name)}</strong>
        <span>${escapeHtml(camera.server || "")}</span>
        <div class="map-popup-actions">
          <a href="${escapeHtml(camera.player)}">${escapeHtml(tr("openVideo", "Открыть видео"))}</a>
          <form method="post" action="/favorite/toggle" class="favorite-form">
            <input type="hidden" name="csrf" value="${escapeHtml(window.SESAME_CSRF || "")}">
            <input type="hidden" name="camera_id" value="${escapeHtml(camera.id)}">
            <button title="${escapeHtml(favoriteTitle)}" aria-label="${escapeHtml(favoriteTitle)}" class="${favoriteClass}">${favoriteIcon}</button>
          </form>
        </div>
      </div>
    `;
  }

  function initPreviewRefresh(root = document) {
    const canvases = Array.from(root?.querySelectorAll("canvas[data-preview-src]") || []);
    if (!canvases.length) return;

    if (!previewLifecycleBound) {
      previewLifecycleBound = true;
      document.addEventListener("visibilitychange", runPreviewRefresh);
      window.addEventListener("pagehide", () => {
        previewPageSuspended = true;
        runPreviewRefresh();
      });
      window.addEventListener("pageshow", () => {
        previewPageSuspended = false;
        queuePreviewRefresh();
      });
      window.addEventListener("resize", () => queuePreviewRefresh());
      document.addEventListener("scroll", () => queuePreviewRefresh(), { capture: true, passive: true });
      if (window.IntersectionObserver) {
        previewObserver = new IntersectionObserver(() => queuePreviewRefresh());
      }
    }
    canvases.forEach((canvas) => {
      if (previewStates.has(canvas)) return;
      const container = canvas.closest(".preview, .map-popup-preview");
      if (!container) return;
      previewStates.set(canvas, {
        canvas,
        container,
        intervalMs: canvas.dataset.previewRefresh === "off"
          ? Infinity : Math.max(10000, Number(canvas.dataset.previewRefreshMs) || 30000),
        nextAt: 0,
        cancel: null,
      });
      previewObserver?.observe(container);
    });
    queuePreviewRefresh();
  }

  function disposePreviewRefresh(root) {
    for (const [canvas, state] of previewStates) {
      if (!canvas.isConnected || root?.contains(canvas)) {
        previewStates.delete(canvas);
        previewObserver?.unobserve(state.container);
        state.cancel?.();
      }
    }
    queuePreviewRefresh();
  }

  function previewVisible(state) {
    if (document.hidden || previewPageSuspended || !state.canvas.isConnected) return false;
    const rect = state.container.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0
      && rect.top < window.innerHeight && rect.left < window.innerWidth;
  }

  function queuePreviewRefresh(delay = 0) {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(runPreviewRefresh, delay);
  }

  function runPreviewRefresh() {
    window.clearTimeout(previewTimer);
    let active = 0;
    let nextAt = Infinity;
    for (const [canvas, state] of previewStates) {
      if (!canvas.isConnected) {
        previewStates.delete(canvas);
        previewObserver?.unobserve(state.container);
        state.cancel?.();
      } else if (!previewVisible(state)) {
        state.cancel?.();
      } else if (state.cancel) {
        active += 1;
      }
    }
    for (const state of [...previewStates.values()].sort((a, b) => a.nextAt - b.nextAt)) {
      if (!previewVisible(state) || state.cancel) continue;
      if (state.nextAt <= Date.now()) {
        // Bound simultaneous decoders/requests even on dense camera grids.
        if (active >= 4) continue;
        loadPreviewFrame(state);
        active += 1;
      } else {
        nextAt = Math.min(nextAt, state.nextAt);
      }
    }
    if (Number.isFinite(nextAt)) queuePreviewRefresh(Math.max(0, nextAt - Date.now()));
  }

  function initAssignmentPickers() {
    document.querySelectorAll("[data-assignment-picker]").forEach((picker) => {
      const search = picker.querySelector(".assignment-search");
      const selectedOnly = picker.querySelector(".assignment-selected-only");
      const count = picker.querySelector(".assignment-count");
      const empty = picker.querySelector(".assignment-empty");
      const rows = Array.from(picker.querySelectorAll(".assignment-row"));
      const update = () => {
        const query = String(search?.value || "").trim().toLowerCase();
        const selectedOnlyMode = selectedOnly?.getAttribute("aria-pressed") === "true";
        let selected = 0;
        let visible = 0;
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          const checked = Boolean(checkbox?.checked);
          const matches = row.textContent.toLowerCase().includes(query);
          const show = matches && (!selectedOnlyMode || checked);
          if (checked) selected += 1;
          if (show) visible += 1;
          row.hidden = !show;
        });
        if (count) {
          count.textContent = `${tr("selectedCount", "Выбрано")}: ${selected} / ${rows.length}`;
        }
        if (empty) {
          empty.hidden = visible > 0;
        }
      };

      search?.addEventListener("input", update);
      selectedOnly?.addEventListener("click", () => {
        const active = selectedOnly.getAttribute("aria-pressed") === "true";
        selectedOnly.setAttribute("aria-pressed", active ? "false" : "true");
        selectedOnly.classList.toggle("active", !active);
        update();
      });
      rows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener("change", update));
      update();
    });
  }

  function initDvrStreamImport(root = document) {
    root.querySelectorAll("[data-dvr-import-form]").forEach((form) => {
      if (form.dataset.dvrImportBound === "1") return;
      form.dataset.dvrImportBound = "1";
      const rows = Array.from(form.querySelectorAll("[data-dvr-import-row]"));
      const search = form.querySelector("[data-dvr-import-search]");
      const count = form.querySelector("[data-dvr-import-count]");
      const submit = form.querySelector("[data-dvr-import-submit]");
      const empty = form.querySelector("[data-dvr-import-filter-empty]");

      const update = () => {
        const query = String(search?.value || "").trim().toLocaleLowerCase();
        let selected = 0;
        let visible = 0;
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          const haystack = String(row.dataset.search || row.textContent || "").toLocaleLowerCase();
          const show = query === "" || haystack.includes(query);
          row.hidden = !show;
          if (show) visible += 1;
          if (checkbox?.checked) selected += 1;
        });
        if (count) {
          count.textContent = `${tr("selectedCount", "Выбрано")}: ${selected} / ${rows.length}`;
        }
        if (submit) submit.disabled = selected === 0;
        if (empty) empty.hidden = visible > 0;
      };

      search?.addEventListener("input", update);
      rows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener("change", update));
      form.querySelector("[data-dvr-import-select-all]")?.addEventListener("click", () => {
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          if (checkbox) checkbox.checked = true;
        });
        update();
      });
      form.querySelector("[data-dvr-import-clear-all]")?.addEventListener("click", () => {
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          if (checkbox) checkbox.checked = false;
        });
        update();
      });
      update();
    });
  }

  function initDensitySwitch() {
    const switcher = document.querySelector(".density-switch");
    const grid = document.querySelector(".camera-grid");
    if (!switcher || !grid) return;

    const setColumns = (cols) => {
      const normalized = String(clamp(Math.round(Number(cols) || 3), 2, 6));
      grid.classList.remove("cols-2", "cols-3", "cols-4", "cols-5", "cols-6");
      grid.classList.add(`cols-${normalized}`);

      switcher.querySelectorAll("[data-cols]").forEach((link) => {
        const active = link.dataset.cols === normalized;
        link.classList.toggle("active", active);
        link.setAttribute("aria-current", active ? "true" : "false");
      });

      document.querySelectorAll('input[name="cols"]').forEach((input) => {
        input.value = normalized;
      });
      updateViewerLinks(normalized);
    };

    const current = switcher.querySelector("[data-cols].active")?.dataset.cols;
    if (current) {
      setColumns(current);
    }
  }

  function updateViewerLinks(cols) {
    document.querySelectorAll(".viewer-filters a[href^='/'], .pager a[href^='/']").forEach((link) => {
      if (link.closest(".density-switch")) return;
      const href = link.getAttribute("href");
      if (!href) return;
      const url = new URL(href, window.location.origin);
      if (url.pathname !== "/") return;
      url.searchParams.set("cols", cols);
      link.setAttribute("href", url.pathname + url.search + url.hash);
    });
  }

  function initGroupTreePickers(root = document) {
    const pickers = Array.from(root.querySelectorAll("[data-group-tree-picker]"));
    const toggles = Array.from(root.querySelectorAll("[data-group-tree-toggle]"));
    const jsonInputs = Array.from(root.querySelectorAll("[data-group-tree-json]"));
    if (!pickers.length && !toggles.length && !jsonInputs.length) return;

    const closePicker = (picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    };

    const openPicker = (picker) => {
      pickers.forEach((other) => {
        if (other !== picker) closePicker(other);
      });
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
    };

    const toggleNode = (toggle) => {
      const node = toggle.closest("[data-group-tree-node]");
      const children = Array.from(node?.children || []).find((child) => child.matches("[data-group-tree-children]"));
      if (!children) return;
      const expanded = toggle.getAttribute("aria-expanded") === "true";
      const nextExpanded = !expanded;
      children.hidden = !nextExpanded;
      toggle.setAttribute("aria-expanded", nextExpanded ? "true" : "false");
      toggle.textContent = nextExpanded ? "-" : "+";
      toggle.setAttribute("aria-label", nextExpanded ? (toggle.dataset.collapseLabel || "") : (toggle.dataset.expandLabel || ""));
      node?.classList.toggle("is-expanded", nextExpanded);
    };

    const syncGroupTreeJson = (field) => {
      const input = field?.querySelector("[data-group-tree-json]");
      if (!field || !input) return;
      const ids = [];
      const seen = new Set();
      field.querySelectorAll('.group-tree-checkbox-list input[type="checkbox"]:checked').forEach((checkbox) => {
        const id = Number.parseInt(checkbox.value, 10);
        if (Number.isFinite(id) && id > 0 && !seen.has(id)) {
          seen.add(id);
          ids.push(id);
        }
      });
      input.value = JSON.stringify(ids);
    };

    jsonInputs.forEach((input) => {
      const field = input.closest(".group-tree-field");
      if (!field || field.dataset.groupTreeJsonBound === "1") return;
      field.dataset.groupTreeJsonBound = "1";
      field.addEventListener("change", (event) => {
        if (event.target?.matches?.('.group-tree-checkbox-list input[type="checkbox"]')) {
          syncGroupTreeJson(field);
        }
      });
      field.closest("form")?.addEventListener("submit", () => syncGroupTreeJson(field), { capture: true });
      syncGroupTreeJson(field);
    });

    toggles.forEach((toggle) => {
      toggle.addEventListener("click", (event) => {
        event.preventDefault();
        toggleNode(toggle);
      });
    });

    root.querySelectorAll("[data-group-tree-check-all], [data-group-tree-clear-all]").forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        const field = button.closest(".group-tree-field");
        const checked = button.hasAttribute("data-group-tree-check-all");
        field?.querySelectorAll('.group-tree-checkbox-list input[type="checkbox"]').forEach((checkbox) => {
          checkbox.checked = checked;
          checkbox.dispatchEvent(new Event("change", { bubbles: true }));
        });
        syncGroupTreeJson(field);
      });
    });

    pickers.forEach((picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        if (menu.hidden) {
          openPicker(picker);
        } else {
          closePicker(picker);
        }
      });

      picker.addEventListener("click", (event) => {
        const option = event.target.closest("[data-group-tree-select-value]");
        if (!option || !picker.contains(option)) return;
        event.preventDefault();
        const input = picker.querySelector('input[type="hidden"]');
        const label = picker.querySelector("[data-group-tree-trigger-label]");
        if (input) input.value = option.dataset.groupTreeSelectValue || "";
        if (label) label.textContent = option.dataset.groupTreeSelectLabel || option.textContent.trim();
        picker.querySelectorAll("[data-group-tree-select-value]").forEach((candidate) => {
          const active = candidate === option;
          candidate.classList.toggle("active", active);
          if (active) {
            candidate.setAttribute("aria-current", "true");
          } else {
            candidate.removeAttribute("aria-current");
          }
        });
        closePicker(picker);
      });
    });

    document.addEventListener("click", (event) => {
      pickers.forEach((picker) => {
        if (!picker.contains(event.target)) {
          closePicker(picker);
        }
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      pickers.forEach(closePicker);
    });
  }

  function initSubmitProgress(root = document) {
    root.querySelectorAll("form[data-submit-progress]").forEach((form) => {
      if (form.dataset.submitProgressBound === "1") return;
      form.dataset.submitProgressBound = "1";
      form.addEventListener("submit", () => {
        const label = form.dataset.submitProgress || tr("js.saving", "Saving...");
        form.classList.add("is-submitting");
        form.setAttribute("aria-busy", "true");
        form.querySelectorAll("[data-submit-status]").forEach((status) => {
          status.textContent = label;
          status.hidden = false;
        });
        form.querySelectorAll("[data-submit-button]").forEach((button) => {
          if ("disabled" in button) button.disabled = true;
          button.setAttribute("aria-disabled", "true");
        });
      });
    });
  }

  function initCameraFormVisibility(root = document) {
    root.querySelectorAll("form").forEach((form) => {
      if (form.dataset.cameraFormVisibilityBound === "1") return;
      const modeSelect = form.querySelector("[data-camera-mode-select]");
      const watermarkToggle = form.querySelector("[data-watermark-toggle]");
      if (!modeSelect && !watermarkToggle) return;
      form.dataset.cameraFormVisibilityBound = "1";

      const sync = () => {
        const agentVisible = modeSelect?.value === "edge_agent";
        form.querySelectorAll("[data-camera-agent-field]").forEach((field) => {
          field.hidden = !agentVisible;
        });

        const watermarkVisible = !!watermarkToggle?.checked;
        form.querySelectorAll("[data-watermark-dependent]").forEach((field) => {
          field.hidden = !watermarkVisible;
        });
      };

      modeSelect?.addEventListener("change", sync);
      watermarkToggle?.addEventListener("change", sync);
      sync();
    });
  }

  function initDvrStreamOptions(root = document) {
    root.querySelectorAll("[data-dvr-stream-options]").forEach((container) => {
      if (container.dataset.dvrStreamOptionsBound === "1") return;
      container.dataset.dvrStreamOptionsBound = "1";

      const sync = () => {
        container.querySelectorAll("[data-dvr-dependent]").forEach((section) => {
          const key = section.dataset.dvrDependent || "";
          const toggle = Array.from(container.querySelectorAll("[data-dvr-toggle]")).find((candidate) => {
            return (candidate.dataset.dvrToggle || "") === key;
          });
          section.hidden = !toggle?.checked;
        });
      };

      container.querySelectorAll("[data-dvr-toggle]").forEach((toggle) => {
        toggle.addEventListener("change", sync);
      });
      sync();
    });
  }

  function initLocalTimes(root = document) {
    const times = Array.from(root.querySelectorAll("time.local-time[datetime]"));
    if (!times.length) return;

    const locale = document.documentElement.lang || undefined;
    const formatter = new Intl.DateTimeFormat(locale, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    });
    const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || "";

    times.forEach((time) => {
      if (time.dataset.localTimeBound === "1") return;
      const date = new Date(time.dateTime || time.getAttribute("datetime") || "");
      if (Number.isNaN(date.getTime())) return;
      time.dataset.localTimeBound = "1";
      time.textContent = formatter.format(date);
      if (timeZone) {
        time.title = `${time.getAttribute("datetime")} -> ${timeZone}`;
      }
    });
  }

  function loadPreviewFrame(state) {
    const { canvas, container } = state;
    const startedAt = Date.now();
    const video = document.createElement("video");
    video.muted = true;
    video.defaultMuted = true;
    video.playsInline = true;
    video.preload = "auto";
    video.setAttribute("muted", "");
    video.setAttribute("playsinline", "");
    canvas.dataset.previewLoading = "1";
    if (canvas.hidden) container.classList.add("is-loading");
    let finished = false;
    let timeout;
    let frameCallback = null;
    const finish = (outcome) => {
      if (finished) return;
      finished = true;
      window.clearTimeout(timeout);
      if (frameCallback !== null) video.cancelVideoFrameCallback(frameCallback);
      video.onloadeddata = video.onseeked = video.onerror = null;
      video.pause();
      video.removeAttribute("src");
      video.load();
      state.cancel = null;
      delete canvas.dataset.previewLoading;
      container.classList.remove("is-loading");
      if (outcome !== "cancelled") state.nextAt = startedAt + state.intervalMs;
      if (outcome === "error" && canvas.hidden && canvas.isConnected) markPreviewMissing(canvas);
      queuePreviewRefresh();
    };
    state.cancel = () => finish("cancelled");
    const drawFrame = () => {
      if (finished || video.readyState < 2 || !video.videoWidth || !video.videoHeight) return;
      if (!previewVisible(state)) {
        finish("cancelled");
        return;
      }
      try {
        // Keep the canvas and its old pixels until a complete new frame is available.
        const scale = Math.max(canvas.width / video.videoWidth, canvas.height / video.videoHeight);
        const width = canvas.width / scale;
        const height = canvas.height / scale;
        canvas.getContext("2d").drawImage(video,
          (video.videoWidth - width) / 2, (video.videoHeight - height) / 2, width, height,
          0, 0, canvas.width, canvas.height);
        markPreviewReady(canvas);
        finish("ready");
      } catch {
        finish("error");
      }
    };
    // Safari can fire loadeddata/canplay before drawImage has usable pixels,
    // especially after the redirect to the DVR. Wait for the decoded frame.
    if (typeof video.requestVideoFrameCallback === "function") {
      frameCallback = video.requestVideoFrameCallback(() => {
        frameCallback = null;
        drawFrame();
      });
    } else {
      // Older browsers: seeking inside the single-frame clip forces a frame
      // to become available without playing every camera in the mosaic.
      video.onloadeddata = () => {
        if (finished) return;
        if (!Number.isFinite(video.duration) || video.duration <= 0) {
          finish("error");
          return;
        }
        video.currentTime = Math.min(0.05, video.duration / 2);
      };
      video.onseeked = drawFrame;
    }
    video.onerror = () => finish("error");
    timeout = window.setTimeout(() => finish("error"), 20000);
    const source = canvas.dataset.previewSrc;
    video.src = `${source}${source.includes("?") ? "&" : "?"}_=${startedAt}`;
    video.load();
  }

  function markPreviewReady(canvas) {
    canvas.hidden = false;
    const container = canvas.closest(".preview, .map-popup-preview");
    container?.classList.remove("no-preview", "is-loading");
    container?.classList.add("has-preview");
  }

  function markPreviewMissing(canvas) {
    canvas.hidden = true;
    const container = canvas.closest(".preview, .map-popup-preview");
    container?.classList.remove("is-loading");
    container?.classList.remove("has-preview");
    container?.classList.add("no-preview");
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === "") return NaN;
    return Number(String(value).replace(",", "."));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function tr(key, fallback) {
    return messages[key] || fallback;
  }
})();
