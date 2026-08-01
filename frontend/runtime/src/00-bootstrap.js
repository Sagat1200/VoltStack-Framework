(function () {
  if (typeof window !== "undefined" && window.__voltRuntimeBooted === true) {
    return;
  }

  if (typeof window !== "undefined") {
    window.__voltRuntimeBooted = true;
  }

  const runtime = {
    navigationRequestId: 0,
    navigationController: null,
    queuedNavigationVisit: null,
    busyState: {
      active: false,
      kind: "idle",
      phase: "idle",
      source: "idle",
      requestId: null,
      component: null,
      action: null,
      target: null,
      updatedAt: null,
    },
    busyUi: {
      shell: null,
      indicator: null,
      label: null,
      liveRegion: null,
      linksDisabled: false,
    },
    navigationCache: new Map(),
    navigationInFlight: new Map(),
    frontendRouteManifest: {
      loadedAt: null,
      checksum: null,
      routes: null,
      promise: null,
    },
    persistentFragments: new Map(),
    navigationPreloadHints: new Set(),
    navigationPrefetchInterest: new Map(),
    navigationPrefetchElements: new WeakMap(),
    navigationViewportObserver: null,
    navigationViewportObserved: new WeakSet(),
    navigationHeuristicHandle: null,
    componentRequestStates: new Map(),
    statePolicies: new Map(),
    loadingDelays: new Map(),
    loadingActivatedAt: new Map(),
    loadingMinClearDelays: new Map(),
    successTimeouts: new Map(),
    successActivatedAt: new Map(),
    successMinClearDelays: new Map(),
    errorTimeouts: new Map(),
    dirtyDebounces: new Map(),
    modelSyncDebounces: new WeakMap(),
    modelSyncTrackedElements: new Set(),
    clientStateScope: null,
    clientStateValues: new Map(),
    sharedStateValues: new Map(),
    clientStateSubscribers: new Map(),
    sharedStateSubscribers: new Map(),
    clientStateGlobalSubscribers: new Set(),
    sharedStateGlobalSubscribers: new Set(),
    directiveSequence: 0,
    onDirectiveOnce: new WeakMap(),
    onDirectiveTrackedElements: new Set(),
    activeComponents: new Map(),
    activeComponentsMeta: {
      refreshedAt: null,
      reason: "boot",
      count: 0,
    },
    navigationPrefetchTrackedElements: new Set(),
    navigationViewportTrackedElements: new Set(),
    telemetryEntries: [],
    telemetryMaxEntries: 60,
    telemetrySequence: 0,
    bootInvocationCount: 0,
    bootMetrics: null,
  };
  const VOLT_RUNTIME_PUBLIC_CONTRACT_VERSION = 2;

  const NAVIGATION_CACHE_TTL = 5000;
  const NAVIGATION_CACHE_MAX_ENTRIES = 10;
  const NAVIGATION_REQUEST_TIMEOUT = 8000;
  const NAVIGATION_REQUEST_RETRY_ATTEMPTS = 1;
  const NAVIGATION_REQUEST_RETRY_DELAY = 180;
  const NAVIGATION_HEURISTIC_DELAY = 180;
  const NAVIGATION_HEURISTIC_VIEWPORT_MARGIN = 240;
  const ACTION_REQUEST_TIMEOUT = 8000;
  const MODEL_SYNC_INTERNAL_ACTION = "__volt_sync__";
  const MODEL_SYNC_DEBOUNCE = 220;
  const NAVIGATION_PREFETCH_SELECTOR =
    "a[volt-navigate], a[volt\\:navigate], a[volt-prefetch], a[volt\\:prefetch]";
  const NAVIGATION_CACHE_CONTROL_META_NAMES = [
    "volt-cache-control",
    "volt:navigation-cache",
  ];
  const NAVIGATION_MODE_META_NAMES = [
    "volt-navigation-mode",
    "volt:navigation-mode",
  ];
  const DOCUMENT_CONTRACT_META_NAMES = ["volt-document", "volt:document"];
  const NAVIGATION_PAGE_TRANSITION_META_NAMES = [
    "volt-page-transition",
    "volt:page-transition",
  ];
  const NAVIGATION_PAGE_TRANSITION_PROFILE_META_NAMES = [
    "volt-page-transition-profile",
    "volt:page-transition-profile",
  ];
  const NAVIGATION_PAGE_TRANSITION_DURATION_META_NAMES = [
    "volt-page-transition-duration",
    "volt:page-transition-duration",
  ];
  const NAVIGATION_PAGE_TRANSITION_MODE_META_NAMES = [
    "volt-page-transition-mode",
    "volt:page-transition-mode",
  ];
  const NAVIGATION_FRAGMENT_CONTROL_META_NAMES = [
    "volt-fragment-control",
    "volt:fragment-cache",
  ];
  const NAVIGATION_FRAGMENT_SELECTOR =
    "[data-volt-preserve], [volt-preserve], [volt\\:preserve]";
  const NAVIGATION_PERSIST_SELECTOR =
    "[data-volt-persist], [volt-persist], [volt\\:persist]";
  const NAVIGATION_RETAINED_SELECTOR =
    NAVIGATION_FRAGMENT_SELECTOR + ", " + NAVIGATION_PERSIST_SELECTOR;
  const PAGE_TRANSITION_PROFILES = Object.freeze({
    soft: Object.freeze({
      name: "fade",
      duration: 220,
      mode: "out-in",
    }),
    gentle: Object.freeze({
      name: "fade",
      duration: 320,
      mode: "out-in",
    }),
    crisp: Object.freeze({
      name: "fade",
      duration: 160,
      mode: "out-in",
    }),
    classic: Object.freeze({
      name: "default",
      duration: 180,
      mode: "out-in",
    }),
  });

  function componentRequestState(component) {
    if (!component) {
      return null;
    }

    if (!runtime.componentRequestStates.has(component)) {
      runtime.componentRequestStates.set(component, {
        requestId: 0,
        controller: null,
      });
    }

    return runtime.componentRequestStates.get(component);
  }

  function findRoot(element) {
    return element.closest('[data-volt-root="true"]');
  }

  function findRootByComponent(componentName) {
    const roots = document.querySelectorAll('[data-volt-root="true"]');

    for (let index = 0; index < roots.length; index += 1) {
      if (roots[index].getAttribute("data-volt-component") === componentName) {
        return roots[index];
      }
    }

    return null;
  }

  function readSnapshot(root) {
    const snapshot = root.getAttribute("data-volt-snapshot");

    return snapshot ? JSON.parse(snapshot) : null;
  }

  function collectModelUpdates(root) {
    const updates = {};

    root
      .querySelectorAll("[volt-model], [volt\\:model]")
      .forEach(function (element) {
        const key = directiveValue(element, ["volt-model", "volt:model"]);

        if (!key) {
          return;
        }

        if (element.type === "checkbox") {
          updates[key] = !!element.checked;
          return;
        }

        updates[key] = element.value;
      });

    return updates;
  }

  function collectFormData(form) {
    const data = {};
    const formData = new FormData(form);

    formData.forEach(function (value, key) {
      if (typeof value === "string") {
        data[key] = value;
      }
    });

    return data;
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }

    return String(value).replace(/[^a-zA-Z0-9\-_]/g, "\\$&");
  }

  function directiveValue(element, names) {
    for (let index = 0; index < names.length; index += 1) {
      const value = element.getAttribute(names[index]);

      if (value !== null && value !== "") {
        return value;
      }
    }

    return null;
  }

  function closestFromEventTarget(event, selector) {
    return event && event.target instanceof Element
      ? event.target.closest(selector)
      : null;
  }

  function directiveAttribute(element, names) {
    for (let index = 0; index < names.length; index += 1) {
      if (element.hasAttribute(names[index])) {
        return {
          name: names[index],
          value: element.getAttribute(names[index]) || "",
        };
      }
    }

    return null;
  }

  function busyUiDirectiveValue(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    return (
      element.getAttribute("data-volt-busy-ui") ||
      element.getAttribute("volt-busy-ui") ||
      element.getAttribute("volt:busy-ui") ||
      null
    );
  }

  function runtimeBusyUiEnabled() {
    const bodyValue = document.body ? busyUiDirectiveValue(document.body) : null;

    if (typeof bodyValue === "string" && bodyValue.trim() !== "") {
      return !/^(off|false|none|0)$/i.test(bodyValue.trim());
    }

    const meta = document.head
      ? document.head.querySelector('meta[name="volt-busy-ui"], meta[name="volt:busy-ui"]')
      : null;
    const metaValue =
      meta && typeof meta.getAttribute === "function"
        ? meta.getAttribute("content") || ""
        : "";

    if (metaValue.trim() !== "") {
      return !/^(off|false|none|0)$/i.test(metaValue.trim());
    }

    return true;
  }

  function activeLoadingRoots() {
    return Array.from(
      document.querySelectorAll('[data-volt-root="true"][data-volt-loading="true"]'),
    );
  }

  function activeLoadingRootSummary(root) {
    if (!root || typeof root.getAttribute !== "function") {
      return {
        requestId: null,
        component: null,
        action: null,
        target: null,
      };
    }

    return {
      requestId: root.getAttribute("data-volt-request-id") || null,
      component: root.getAttribute("data-volt-component") || null,
      action: root.getAttribute("data-volt-loading-action") || null,
      target: root.getAttribute("data-volt-loading-target") || null,
    };
  }

  function activeActionRequestState() {
    const entries = Array.from(runtime.componentRequestStates.entries());

    for (let index = 0; index < entries.length; index += 1) {
      const component = entries[index][0];
      const state = entries[index][1];

      if (state && state.controller) {
        return {
          component: component,
          requestId:
            typeof state.requestId === "number" ? String(state.requestId) : null,
        };
      }
    }

    return {
      component: null,
      requestId: null,
    };
  }

  function cloneBusyState(state) {
    const source = state && typeof state === "object" ? state : runtime.busyState;

    return {
      active: source.active === true,
      kind: typeof source.kind === "string" ? source.kind : "idle",
      phase: typeof source.phase === "string" ? source.phase : "idle",
      source: typeof source.source === "string" ? source.source : "idle",
      requestId:
        source.requestId === null || typeof source.requestId === "undefined"
          ? null
          : String(source.requestId),
      component: typeof source.component === "string" ? source.component : null,
      action: typeof source.action === "string" ? source.action : null,
      target: typeof source.target === "string" ? source.target : null,
      updatedAt: typeof source.updatedAt === "string" ? source.updatedAt : null,
    };
  }

  function busyStateEquals(left, right) {
    const first = cloneBusyState(left);
    const second = cloneBusyState(right);

    return (
      first.active === second.active &&
      first.kind === second.kind &&
      first.phase === second.phase &&
      first.source === second.source &&
      first.requestId === second.requestId &&
      first.component === second.component &&
      first.action === second.action &&
      first.target === second.target
    );
  }

  function applyBusyStateAttributes(element, state) {
    if (!element || typeof element.setAttribute !== "function") {
      return;
    }

    element.setAttribute("data-volt-busy", state.active ? "true" : "false");
    element.setAttribute("data-volt-busy-kind", state.kind);
    element.setAttribute("data-volt-busy-phase", state.phase);
    element.setAttribute("data-volt-busy-source", state.source);
    element.setAttribute("aria-busy", state.active ? "true" : "false");

    if (state.requestId !== null) {
      element.setAttribute("data-volt-busy-request-id", state.requestId);
    } else {
      element.removeAttribute("data-volt-busy-request-id");
    }

    if (state.component) {
      element.setAttribute("data-volt-busy-component", state.component);
    } else {
      element.removeAttribute("data-volt-busy-component");
    }

    if (state.action) {
      element.setAttribute("data-volt-busy-action", state.action);
    } else {
      element.removeAttribute("data-volt-busy-action");
    }

    if (state.target) {
      element.setAttribute("data-volt-busy-target", state.target);
    } else {
      element.removeAttribute("data-volt-busy-target");
    }
  }

  function ensureRuntimeBusyUiShell() {
    if (!document.body || runtimeBusyUiEnabled() !== true) {
      return null;
    }

    let shell = document.body.querySelector("[data-volt-runtime-busy-shell]");

    if (!shell) {
      shell = document.createElement("div");
      shell.setAttribute("data-volt-runtime-busy-shell", "true");
      shell.setAttribute("aria-hidden", "true");
      shell.style.position = "fixed";
      shell.style.inset = "0 auto auto 0";
      shell.style.width = "100%";
      shell.style.pointerEvents = "none";
      shell.style.zIndex = "2147483000";
      shell.style.opacity = "0";
      shell.style.transition = "opacity 120ms ease";

      const indicator = document.createElement("div");
      indicator.setAttribute("data-volt-runtime-busy-indicator", "true");
      indicator.style.height = "3px";
      indicator.style.width = "100%";
      indicator.style.transformOrigin = "left center";
      indicator.style.transform = "scaleX(0)";
      indicator.style.transition =
        "transform 160ms ease, opacity 120ms ease, background-color 160ms ease";
      indicator.style.background = "linear-gradient(90deg, #22d3ee 0%, #38bdf8 100%)";
      indicator.style.opacity = "0";
      indicator.style.boxShadow = "0 0 18px rgba(56, 189, 248, 0.42)";
      shell.appendChild(indicator);
      document.body.appendChild(shell);
    }

    let liveRegion = document.body.querySelector("[data-volt-runtime-busy-live]");

    if (!liveRegion) {
      liveRegion = document.createElement("div");
      liveRegion.setAttribute("data-volt-runtime-busy-live", "true");
      liveRegion.setAttribute("role", "status");
      liveRegion.setAttribute("aria-live", "polite");
      liveRegion.style.position = "absolute";
      liveRegion.style.width = "1px";
      liveRegion.style.height = "1px";
      liveRegion.style.margin = "-1px";
      liveRegion.style.padding = "0";
      liveRegion.style.overflow = "hidden";
      liveRegion.style.clip = "rect(0 0 0 0)";
      liveRegion.style.whiteSpace = "nowrap";
      liveRegion.style.border = "0";
      document.body.appendChild(liveRegion);
    }

    runtime.busyUi.shell = shell;
    runtime.busyUi.indicator = shell.querySelector("[data-volt-runtime-busy-indicator]");
    runtime.busyUi.liveRegion = liveRegion;
    return shell;
  }

  function syncRuntimeBusyLinks(active) {
    const links = Array.from(
      document.querySelectorAll("a[volt-navigate], a[volt\\:navigate], a[data-volt-navigate]"),
    );

    links.forEach(function (link) {
      if (!(link instanceof HTMLElement)) {
        return;
      }

      if (active) {
        if (
          !link.hasAttribute("data-volt-busy-prev-tabindex") &&
          link.hasAttribute("tabindex")
        ) {
          link.setAttribute(
            "data-volt-busy-prev-tabindex",
            link.getAttribute("tabindex") || "",
          );
        }

        if (
          !link.hasAttribute("data-volt-busy-prev-aria-disabled") &&
          link.hasAttribute("aria-disabled")
        ) {
          link.setAttribute(
            "data-volt-busy-prev-aria-disabled",
            link.getAttribute("aria-disabled") || "",
          );
        }

        link.setAttribute("aria-disabled", "true");
        link.setAttribute("tabindex", "-1");
        link.setAttribute("data-volt-busy-link-disabled", "true");
        link.style.pointerEvents = "none";
        return;
      }

      if (link.hasAttribute("data-volt-busy-prev-tabindex")) {
        const previousTabIndex = link.getAttribute("data-volt-busy-prev-tabindex");

        if (previousTabIndex === "") {
          link.removeAttribute("tabindex");
        } else {
          link.setAttribute("tabindex", previousTabIndex);
        }

        link.removeAttribute("data-volt-busy-prev-tabindex");
      } else if (link.getAttribute("data-volt-busy-link-disabled") === "true") {
        link.removeAttribute("tabindex");
      }

      if (link.hasAttribute("data-volt-busy-prev-aria-disabled")) {
        const previousAriaDisabled = link.getAttribute(
          "data-volt-busy-prev-aria-disabled",
        );

        if (previousAriaDisabled === "") {
          link.removeAttribute("aria-disabled");
        } else {
          link.setAttribute("aria-disabled", previousAriaDisabled);
        }

        link.removeAttribute("data-volt-busy-prev-aria-disabled");
      } else if (link.getAttribute("data-volt-busy-link-disabled") === "true") {
        link.removeAttribute("aria-disabled");
      }

      link.removeAttribute("data-volt-busy-link-disabled");
      link.style.pointerEvents = "";
    });

    runtime.busyUi.linksDisabled = active === true;
  }

  function syncRuntimeBusyUi(state) {
    const nextState = cloneBusyState(state);
    const uiEnabled = runtimeBusyUiEnabled();
    const shell = uiEnabled ? ensureRuntimeBusyUiShell() : null;
    const indicator = runtime.busyUi.indicator;
    const liveRegion = runtime.busyUi.liveRegion;

    if (document.body) {
      document.body.style.cursor = nextState.active ? "progress" : "";
    }

    syncRuntimeBusyLinks(nextState.active);

    if (!shell || !indicator) {
      return;
    }

    shell.style.opacity = nextState.active ? "1" : "0";
    indicator.style.opacity = nextState.active ? "1" : "0";
    indicator.style.transform = nextState.active ? "scaleX(1)" : "scaleX(0)";
    indicator.style.background =
      nextState.kind === "action"
        ? "linear-gradient(90deg, #a78bfa 0%, #c084fc 100%)"
        : nextState.kind === "mixed"
          ? "linear-gradient(90deg, #22d3ee 0%, #c084fc 100%)"
          : "linear-gradient(90deg, #22d3ee 0%, #38bdf8 100%)";

    if (liveRegion) {
      liveRegion.textContent = nextState.active
        ? "Volt busy " + nextState.kind + " en fase " + nextState.phase + "."
        : "Volt busy idle.";
    }
  }

  function resolveGlobalBusyState(meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const previous = cloneBusyState(runtime.busyState);
    const navigationActive =
      !!runtime.navigationController ||
      document.documentElement.getAttribute("data-volt-navigating") === "true";
    const loadingRoots = activeLoadingRoots();
    const actionRequestState = activeActionRequestState();
    const actionActive =
      loadingRoots.length > 0 || actionRequestState.requestId !== null;
    const primaryLoadingRoot = activeLoadingRootSummary(loadingRoots[0] || null);
    const active = navigationActive || actionActive;
    const kind = active
      ? navigationActive && actionActive
        ? "mixed"
        : navigationActive
          ? "navigation"
          : "action"
      : "idle";
    const phase =
      typeof detail.phase === "string" && detail.phase !== ""
        ? detail.phase
        : active
          ? kind === "action"
            ? "loading"
            : previous.phase !== "idle"
              ? previous.phase
              : "request-start"
          : "idle";
    const requestId =
      detail.requestId !== null && typeof detail.requestId !== "undefined"
        ? String(detail.requestId)
        : navigationActive
          ? String(runtime.navigationRequestId || "")
          : primaryLoadingRoot.requestId || actionRequestState.requestId;
    const next = {
      active: active,
      kind: kind,
      phase: phase,
      source:
        typeof detail.source === "string" && detail.source !== ""
          ? detail.source
          : kind,
      requestId: requestId || null,
      component:
        typeof detail.component === "string" && detail.component !== ""
          ? detail.component
          : primaryLoadingRoot.component || actionRequestState.component,
      action:
        typeof detail.action === "string" && detail.action !== ""
          ? detail.action
          : primaryLoadingRoot.action,
      target:
        typeof detail.target === "string" && detail.target !== ""
          ? detail.target
          : primaryLoadingRoot.target,
      updatedAt: new Date().toISOString(),
    };

    applyBusyStateAttributes(document.documentElement, next);
    applyBusyStateAttributes(document.body, next);
    syncRuntimeBusyUi(next);

    if (busyStateEquals(previous, next)) {
      runtime.busyState = next;
      return next;
    }

    runtime.busyState = next;
    emitRuntimeHook("volt:busy-change", cloneBusyState(next), document);

    if (!previous.active && next.active) {
      emitRuntimeHook("volt:busy-start", cloneBusyState(next), document);
    } else if (previous.active && !next.active) {
      emitRuntimeHook("volt:busy-end", cloneBusyState(next), document);
    }

    return next;
  }

  function dispatchDirectiveNames() {
    return ["volt-dispatch", "volt:dispatch"];
  }

  function onDirectiveNames() {
    return ["volt-on", "volt:on"];
  }

  function parseDispatchDirectiveEvents(value) {
    if (typeof value !== "string") {
      return [];
    }

    return value
      .split("|")
      .map(function (entry) {
        return entry.trim();
      })
      .filter(function (entry) {
        return /^[A-Za-z][A-Za-z0-9:._-]*$/.test(entry);
      });
  }

  function onDirectiveSelector() {
    return "[volt-on], [volt\\:on]";
  }

  function normalizeOnDirectiveKeyFilter(key) {
    const normalized = String(key || "").trim().toLowerCase();

    if (normalized === "esc") {
      return "escape";
    }

    if (normalized === "spacebar" || normalized === " ") {
      return "space";
    }

    return normalized;
  }

  function onDirectiveOnceStore(element) {
    if (!runtime.onDirectiveOnce.has(element)) {
      runtime.onDirectiveOnce.set(element, new Set());
    }

    runtime.onDirectiveTrackedElements.add(element);

    return runtime.onDirectiveOnce.get(element);
  }

  function onDirectiveActionValue(rawValue, event) {
    const trimmed = typeof rawValue === "string" ? rawValue.trim() : "";

    if (trimmed === "") {
      return {
        valid: false,
        value: null,
      };
    }

    if (trimmed === "$event.target.value") {
      return {
        valid: true,
        value:
          event && event.target && "value" in event.target
            ? event.target.value
            : undefined,
      };
    }

    if (trimmed === "$event.target.checked") {
      return {
        valid: true,
        value:
          event && event.target && "checked" in event.target
            ? !!event.target.checked
            : undefined,
      };
    }

    const stringLiteral = parseDirectiveStringLiteral(trimmed);

    if (stringLiteral !== null) {
      return {
        valid: true,
        value: stringLiteral,
      };
    }

    if (/^(true|false)$/i.test(trimmed)) {
      return {
        valid: true,
        value: trimmed.toLowerCase() === "true",
      };
    }

    if (/^null$/i.test(trimmed)) {
      return {
        valid: true,
        value: null,
      };
    }

    if (/^-?\d+(?:\.\d+)?$/.test(trimmed)) {
      return {
        valid: true,
        value: Number(trimmed),
      };
    }

    return {
      valid: false,
      value: null,
    };
  }

  function parseOnDirectiveEventSpec(rawValue) {
    if (typeof rawValue !== "string" || rawValue.trim() === "") {
      return null;
    }

    const segments = rawValue
      .trim()
      .split(".")
      .map(function (segment) {
        return segment.trim().toLowerCase();
      })
      .filter(function (segment) {
        return segment !== "";
      });

    if (segments.length === 0) {
      return null;
    }

    const eventName = segments[0];
    const supportedEvents = [
      "click",
      "input",
      "change",
      "submit",
      "focus",
      "blur",
      "keydown",
      "keyup",
    ];

    if (supportedEvents.indexOf(eventName) === -1) {
      return null;
    }

    const modifiers = {
      prevent: false,
      stop: false,
      once: false,
      self: false,
    };
    let keyFilter = null;

    for (let index = 1; index < segments.length; index += 1) {
      const segment = segments[index];

      if (Object.prototype.hasOwnProperty.call(modifiers, segment)) {
        modifiers[segment] = true;
        continue;
      }

      if ((eventName === "keydown" || eventName === "keyup") && !keyFilter) {
        keyFilter = normalizeOnDirectiveKeyFilter(segment);
        continue;
      }

      return null;
    }

    return {
      name: eventName,
      modifiers: modifiers,
      keyFilter: keyFilter,
      raw: rawValue.trim(),
    };
  }

  function parseOnDirectiveAction(rawValue) {
    if (typeof rawValue !== "string" || rawValue.trim() === "") {
      return null;
    }

    const trimmed = rawValue.trim();
    const dispatchMatches = trimmed.match(/^dispatch:([A-Za-z][A-Za-z0-9:._-]*)$/);

    if (dispatchMatches) {
      return {
        type: "dispatch",
        name: dispatchMatches[1],
        raw: trimmed,
      };
    }

    const toggleMatches = trimmed.match(
      /^state:toggle\s+(client|shared):([A-Za-z0-9_.-]+)$/i,
    );

    if (toggleMatches) {
      return {
        type: "toggle",
        scope: normalizeRuntimeStateScope(toggleMatches[1]),
        path: toggleMatches[2],
        raw: trimmed,
      };
    }

    const deleteMatches = trimmed.match(
      /^state:delete\s+(client|shared):([A-Za-z0-9_.-]+)$/i,
    );

    if (deleteMatches) {
      return {
        type: "delete",
        scope: normalizeRuntimeStateScope(deleteMatches[1]),
        path: deleteMatches[2],
        raw: trimmed,
      };
    }

    const setMatches = trimmed.match(
      /^state:set\s+(client|shared):([A-Za-z0-9_.-]+)\s*=\s*(.+)$/i,
    );

    if (setMatches) {
      return {
        type: "set",
        scope: normalizeRuntimeStateScope(setMatches[1]),
        path: setMatches[2],
        valueExpression: setMatches[3].trim(),
        raw: trimmed,
      };
    }

    return null;
  }

  function parseOnDirectiveRule(value, index) {
    if (typeof value !== "string" || value.trim() === "") {
      return null;
    }

    const separator = findTopLevelArrow(value);

    if (separator === -1) {
      return null;
    }

    const eventSpec = parseOnDirectiveEventSpec(value.slice(0, separator).trim());
    const action = parseOnDirectiveAction(value.slice(separator + 2).trim());

    if (!eventSpec || !action) {
      return null;
    }

    return {
      key: String(index) + ":" + value.trim(),
      event: eventSpec,
      action: action,
      raw: value.trim(),
    };
  }

  function parseOnDirectiveRules(value) {
    return splitTopLevelDirectiveEntries(value, "|")
      .map(function (entry, index) {
        return parseOnDirectiveRule(entry, index);
      })
      .filter(function (entry) {
        return !!entry;
      });
  }

  function dispatchDirectiveDisabled(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return true;
    }

    if ("disabled" in element && !!element.disabled) {
      return true;
    }

    return (
      (element.getAttribute("aria-disabled") || "").toLowerCase() === "true"
    );
  }

  function dispatchDirectiveScopeId(root) {
    if (!root || typeof root.getAttribute !== "function") {
      return currentClientStateScope();
    }

    return (
      root.getAttribute("data-volt-component") ||
      root.getAttribute("data-volt-scope-id") ||
      currentClientStateScope()
    );
  }

  function dispatchDirectiveDetail(root, trigger, directive, originalEvent) {
    return {
      sourceElement: trigger,
      directive: directive,
      scopeId: dispatchDirectiveScopeId(root),
      clientScope: currentClientStateScope(),
      sharedScope: "shared",
      component:
        root && typeof root.getAttribute === "function"
          ? root.getAttribute("data-volt-component") || null
          : null,
      originalEvent: originalEvent,
    };
  }

  function emitNamedDispatchDirectiveEvent(
    trigger,
    root,
    name,
    directive,
    originalEvent,
  ) {
    if (!trigger || !name || dispatchDirectiveDisabled(trigger)) {
      return false;
    }

    trigger.dispatchEvent(
      new CustomEvent(name, {
        detail: dispatchDirectiveDetail(
          root,
          trigger,
          directive || "dispatch:" + name,
          originalEvent,
        ),
        bubbles: true,
        cancelable: true,
      }),
    );

    return true;
  }

  function emitDispatchDirective(trigger, root, directiveAttributeValue, event) {
    const events = parseDispatchDirectiveEvents(directiveAttributeValue);

    if (!trigger || events.length === 0 || dispatchDirectiveDisabled(trigger)) {
      return false;
    }

    const detail = dispatchDirectiveDetail(
      root,
      trigger,
      directiveAttributeValue,
      event,
    );

    events.forEach(function (name) {
      trigger.dispatchEvent(
        new CustomEvent(name, {
          detail: detail,
          bubbles: true,
          cancelable: true,
        }),
      );
    });

    return true;
  }

  function matchesOnDirectiveEvent(rule, eventName, event) {
    if (!rule || !rule.event || rule.event.name !== eventName) {
      return false;
    }

    if (
      (eventName === "keydown" || eventName === "keyup") &&
      rule.event.keyFilter
    ) {
      return (
        normalizeOnDirectiveKeyFilter(event && event.key) ===
        rule.event.keyFilter
      );
    }

    return true;
  }

  function runOnDirectiveAction(rule, trigger, root, event) {
    if (!rule || !rule.action) {
      return false;
    }

    if (rule.action.type === "dispatch") {
      return emitNamedDispatchDirectiveEvent(
        trigger,
        root,
        rule.action.name,
        "volt:on=" + rule.raw,
        event,
      );
    }

    if (rule.action.type === "set") {
      const resolved = onDirectiveActionValue(rule.action.valueExpression, event);

      if (!resolved.valid) {
        return false;
      }

      setRuntimeStateValue(rule.action.path, resolved.value, {
        scope: rule.action.scope,
        action: "directive:on:set",
      });
      return true;
    }

    if (rule.action.type === "toggle") {
      const current = getRuntimeStateValue(rule.action.path, {
        scope: rule.action.scope,
        fallback: false,
      });
      setRuntimeStateValue(rule.action.path, !current, {
        scope: rule.action.scope,
        action: "directive:on:toggle",
      });
      return true;
    }

    if (rule.action.type === "delete") {
      return deleteRuntimeStateValue(rule.action.path, {
        scope: rule.action.scope,
      });
    }

    return false;
  }

  function handleOnDirectiveEvent(eventName, event) {
    const trigger = closestFromEventTarget(event, onDirectiveSelector());

    if (!trigger) {
      return false;
    }

    const rules = parseOnDirectiveRules(directiveValue(trigger, onDirectiveNames()));

    if (rules.length === 0) {
      return false;
    }

    const root = findRoot(trigger);
    let handled = false;

    rules.forEach(function (rule) {
      if (!matchesOnDirectiveEvent(rule, eventName, event)) {
        return;
      }

      if (rule.event.modifiers.self && event.target !== trigger) {
        return;
      }

      const onceStore = onDirectiveOnceStore(trigger);

      if (rule.event.modifiers.once && onceStore.has(rule.key)) {
        return;
      }

      if (rule.event.modifiers.prevent && event.cancelable) {
        event.preventDefault();
      }

      if (rule.event.modifiers.stop) {
        event.stopPropagation();
      }

      if (runOnDirectiveAction(rule, trigger, root, event)) {
        handled = true;

        if (rule.event.modifiers.once) {
          onceStore.add(rule.key);
        }
      }
    });

    return handled;
  }

  function normalizeNavigationUrl(url) {
    try {
      return new URL(url, window.location.href).toString();
    } catch (error) {
      return String(url || "");
    }
  }

  function cloneStateValue(value) {
    if (typeof structuredClone === "function") {
      try {
        return structuredClone(value);
      } catch (error) {}
    }

    if (value === null || typeof value !== "object") {
      return value;
    }

    try {
      return JSON.parse(JSON.stringify(value));
    } catch (error) {
      return value;
    }
  }

  function runtimeNow() {
    if (
      typeof performance !== "undefined" &&
      performance &&
      typeof performance.now === "function"
    ) {
      return performance.now();
    }

    return Date.now();
  }

  function roundedMetricValue(value) {
    return typeof value === "number" && isFinite(value)
      ? Math.round(value * 100) / 100
      : null;
  }

  function serializedTextBytes(value) {
    if (typeof value !== "string" || value === "") {
      return 0;
    }

    if (typeof TextEncoder === "function") {
      return new TextEncoder().encode(value).length;
    }

    try {
      let bytes = 0;

      for (const character of value) {
        const codePoint = character.codePointAt(0);

        if (typeof codePoint !== "number") {
          continue;
        }

        if (codePoint <= 0x7f) {
          bytes += 1;
        } else if (codePoint <= 0x7ff) {
          bytes += 2;
        } else if (codePoint <= 0xffff) {
          bytes += 3;
        } else {
          bytes += 4;
        }
      }

      return bytes;
    } catch (error) {
      return value.length;
    }
  }

  function serializedPayloadBytes(value) {
    if (typeof value === "undefined") {
      return 0;
    }

    if (typeof value === "string") {
      return serializedTextBytes(value);
    }

    try {
      return serializedTextBytes(JSON.stringify(value));
    } catch (error) {
      return 0;
    }
  }

  function normalizeTelemetryKinds(options) {
    const settings = options && typeof options === "object" ? options : {};
    const candidates = Array.isArray(settings.kinds)
      ? settings.kinds
      : settings.kind
        ? [settings.kind]
        : [];

    return candidates
      .map(function (kind) {
        return typeof kind === "string" ? kind.trim() : "";
      })
      .filter(function (kind) {
        return kind !== "";
      });
  }

  function filteredTelemetryEntries(options) {
    const settings = options && typeof options === "object" ? options : {};
    const kinds = normalizeTelemetryKinds(settings);
    const filtered =
      kinds.length === 0
        ? runtime.telemetryEntries
        : runtime.telemetryEntries.filter(function (entry) {
            return kinds.indexOf(entry.kind) !== -1;
          });
    const limit =
      typeof settings.limit === "number" && settings.limit > 0
        ? Math.floor(settings.limit)
        : null;
    const entries = limit ? filtered.slice(0, limit) : filtered.slice();

    return entries.map(function (entry) {
      return cloneStateValue(entry);
    });
  }

  function summarizeTelemetryKind(entries, kind) {
    const filtered = entries.filter(function (entry) {
      return entry.kind === kind;
    });
    const outcomes = {};
    let totalDurationMs = 0;
    let durationSamples = 0;
    let maxDurationMs = 0;
    let totalRequestPayloadBytes = 0;
    let requestPayloadSamples = 0;
    let maxRequestPayloadBytes = 0;
    let totalResponsePayloadBytes = 0;
    let responsePayloadSamples = 0;
    let maxResponsePayloadBytes = 0;
    let totalPatchDurationMs = 0;
    let patchSamples = 0;
    let maxPatchDurationMs = 0;

    filtered.forEach(function (entry) {
      const outcome =
        entry && typeof entry.outcome === "string" ? entry.outcome : "unknown";
      const totalDuration =
        typeof entry.totalDurationMs === "number" ? entry.totalDurationMs : null;
      const requestPayload =
        typeof entry.requestPayloadBytes === "number"
          ? entry.requestPayloadBytes
          : null;
      const responsePayload =
        typeof entry.responsePayloadBytes === "number"
          ? entry.responsePayloadBytes
          : null;
      const patchDuration =
        typeof entry.patchDurationMs === "number" ? entry.patchDurationMs : null;

      outcomes[outcome] = (outcomes[outcome] || 0) + 1;

      if (totalDuration !== null) {
        totalDurationMs += totalDuration;
        durationSamples += 1;
        maxDurationMs = Math.max(maxDurationMs, totalDuration);
      }

      if (requestPayload !== null) {
        totalRequestPayloadBytes += requestPayload;
        requestPayloadSamples += 1;
        maxRequestPayloadBytes = Math.max(maxRequestPayloadBytes, requestPayload);
      }

      if (responsePayload !== null) {
        totalResponsePayloadBytes += responsePayload;
        responsePayloadSamples += 1;
        maxResponsePayloadBytes = Math.max(
          maxResponsePayloadBytes,
          responsePayload,
        );
      }

      if (patchDuration !== null) {
        totalPatchDurationMs += patchDuration;
        patchSamples += 1;
        maxPatchDurationMs = Math.max(maxPatchDurationMs, patchDuration);
      }
    });

    return {
      kind: kind,
      count: filtered.length,
      outcomes: outcomes,
      averageDurationMs:
        durationSamples > 0
          ? roundedMetricValue(totalDurationMs / durationSamples)
          : null,
      maxDurationMs:
        durationSamples > 0 ? roundedMetricValue(maxDurationMs) : null,
      averageRequestPayloadBytes:
        requestPayloadSamples > 0
          ? Math.round(totalRequestPayloadBytes / requestPayloadSamples)
          : null,
      maxRequestPayloadBytes:
        requestPayloadSamples > 0 ? maxRequestPayloadBytes : null,
      averageResponsePayloadBytes:
        responsePayloadSamples > 0
          ? Math.round(totalResponsePayloadBytes / responsePayloadSamples)
          : null,
      maxResponsePayloadBytes:
        responsePayloadSamples > 0 ? maxResponsePayloadBytes : null,
      averagePatchDurationMs:
        patchSamples > 0
          ? roundedMetricValue(totalPatchDurationMs / patchSamples)
          : null,
      maxPatchDurationMs:
        patchSamples > 0 ? roundedMetricValue(maxPatchDurationMs) : null,
      latest:
        filtered.length > 0 ? cloneStateValue(filtered[0]) : null,
    };
  }

  function telemetrySummary(options) {
    const entries = filteredTelemetryEntries(options);
    const summary = {
      totalEntries: entries.length,
      maxEntries: runtime.telemetryMaxEntries,
      navigation: summarizeTelemetryKind(entries, "navigation"),
      action: summarizeTelemetryKind(entries, "action"),
      patch: summarizeTelemetryKind(entries, "patch"),
    };

    return cloneStateValue(summary);
  }

  function runtimeBootSnapshot() {
    return runtime.bootMetrics ? cloneStateValue(runtime.bootMetrics) : null;
  }

  function recordRuntimeTelemetry(kind, detail) {
    const entry = Object.assign(
      {
        kind: kind,
        sequence: runtime.telemetrySequence + 1,
        recordedAt: new Date().toISOString(),
      },
      cloneStateValue(detail || {}),
    );

    runtime.telemetrySequence = entry.sequence;
    runtime.telemetryEntries.unshift(entry);

    while (runtime.telemetryEntries.length > runtime.telemetryMaxEntries) {
      runtime.telemetryEntries.pop();
    }

    return cloneStateValue(entry);
  }

  function resetRuntimeTelemetry() {
    const cleared = runtime.telemetryEntries.length;

    runtime.telemetryEntries = [];
    runtime.telemetrySequence = 0;

    return cleared;
  }

  function activeComponentRoots() {
    return Array.prototype.slice.call(
      document.querySelectorAll('[data-volt-root="true"]'),
    );
  }

  function activeComponentKey(root, index) {
    const component =
      root && typeof root.getAttribute === "function"
        ? root.getAttribute("data-volt-component") || "anonymous"
        : "anonymous";
    const descriptor = buildStableElementDescriptor(root);

    if (descriptor && descriptor.value) {
      return (
        component +
        "::" +
        descriptor.strategy +
        ":" +
        String(descriptor.value)
      );
    }

    return component + "::index:" + String(index);
  }

  function publicActiveComponentEntry(entry) {
    if (!entry || typeof entry !== "object") {
      return null;
    }

    const sanitized = Object.assign({}, entry);
    delete sanitized.rootRef;

    return cloneStateValue(sanitized);
  }

  function describeActiveComponent(root, index, previousEntry) {
    const snapshotAttribute =
      root && typeof root.getAttribute === "function"
        ? root.getAttribute("data-volt-snapshot")
        : null;
    const snapshot = root ? readSnapshot(root) : null;
    const component =
      root && typeof root.getAttribute === "function"
        ? root.getAttribute("data-volt-component")
        : null;
    const descriptor = buildStableElementDescriptor(root);

    return {
      id: activeComponentKey(root, index),
      index: index,
      component: component,
      endpoint:
        root && typeof root.getAttribute === "function"
          ? root.getAttribute("data-volt-endpoint") || "/_volt/action"
          : "/_volt/action",
      renderMode:
        root && typeof root.getAttribute === "function"
          ? root.getAttribute("data-volt-render-mode")
          : null,
      descriptor: descriptor,
      hasSnapshot: !!snapshotAttribute,
      snapshotBytes: serializedPayloadBytes(snapshotAttribute || ""),
      snapshotStateKeys:
        snapshot && snapshot.state && typeof snapshot.state === "object"
          ? Object.keys(snapshot.state)
          : [],
      isConnected: !!(root && root.isConnected),
      flags: {
        loading:
          !!(
            root &&
            typeof root.getAttribute === "function" &&
            root.getAttribute("data-volt-loading") === "true"
          ),
        dirty:
          !!(
            root &&
            typeof root.getAttribute === "function" &&
            root.getAttribute("data-volt-dirty") === "true"
          ),
        error:
          !!(
            root &&
            typeof root.getAttribute === "function" &&
            root.getAttribute("data-volt-error") === "true"
          ),
        success:
          !!(
            root &&
            typeof root.getAttribute === "function" &&
            root.getAttribute("data-volt-success") === "true"
          ),
      },
      firstSeenAt:
        previousEntry && previousEntry.firstSeenAt
          ? previousEntry.firstSeenAt
          : new Date().toISOString(),
      lastSeenAt: new Date().toISOString(),
      rootRef: root || null,
    };
  }

  function remainingComponentCount(entries, component) {
    if (!component) {
      return 0;
    }

    let count = 0;

    entries.forEach(function (entry) {
      if (entry && entry.component === component) {
        count += 1;
      }
    });

    return count;
  }

  function clearRuntimeRootState(root) {
    if (!root) {
      return;
    }

    clearLoadingDelay(root);
    clearLoadingMinDuration(root);
    clearSuccessTimeout(root);
    clearSuccessMinDuration(root);
    clearErrorTimeout(root);
    clearDirtyDebounce(root);
    runtime.loadingActivatedAt.delete(root);
    runtime.successActivatedAt.delete(root);
  }

  function destroyUnmountedComponent(entry, remainingEntries, reason) {
    if (!entry || typeof entry !== "object") {
      return false;
    }

    const root = entry.rootRef || null;
    const component = entry.component || null;

    clearRuntimeRootState(root);

    if (component && remainingComponentCount(remainingEntries, component) === 0) {
      const requestState = runtime.componentRequestStates.get(component);

      if (requestState && requestState.controller) {
        requestState.controller.abort();
      }

      runtime.componentRequestStates.delete(component);
      runtime.statePolicies.delete(component);
    }

    emitRuntimeHook(
      "volt:component-destroyed",
      {
        id: entry.id || null,
        component: component,
        reason: reason || "unmounted",
        descriptor: entry.descriptor || null,
        remainingComponentRoots: remainingComponentCount(
          remainingEntries,
          component,
        ),
        activeComponentCount: remainingEntries.size,
      },
      document,
    );

    return true;
  }

  function destroyRemovedActiveComponents(previousEntries, nextEntries, reason) {
    previousEntries.forEach(function (entry, key) {
      if (!entry || typeof entry !== "object") {
        return;
      }

      const previousRoot = entry.rootRef || null;
      const nextEntry = nextEntries.get(key);
      const nextRoot =
        nextEntry && typeof nextEntry === "object" ? nextEntry.rootRef || null : null;

      if (previousRoot && nextRoot === previousRoot) {
        return;
      }

      destroyUnmountedComponent(entry, nextEntries, reason || "refresh");
    });
  }

  function refreshActiveComponentsRegistry(reason) {
    const previous = runtime.activeComponents;
    const next = new Map();
    const roots = activeComponentRoots();

    roots.forEach(function (root, index) {
      const key = activeComponentKey(root, index);
      next.set(key, describeActiveComponent(root, index, previous.get(key)));
    });

    destroyRemovedActiveComponents(previous, next, reason || "refresh");
    runtime.activeComponents = next;
    runtime.activeComponentsMeta = {
      refreshedAt: new Date().toISOString(),
      reason: reason || "manual",
      count: next.size,
    };

    return activeComponentsSnapshot();
  }

  function activeComponentsEntries(options) {
    const settings = options && typeof options === "object" ? options : {};
    const componentFilter =
      typeof settings.component === "string" && settings.component.trim() !== ""
        ? settings.component.trim()
        : null;
    const entries = Array.from(runtime.activeComponents.values()).filter(
      function (entry) {
        return componentFilter ? entry.component === componentFilter : true;
      },
    );

    return entries.map(function (entry) {
      return publicActiveComponentEntry(entry);
    });
  }

  function activeComponentsSummary(options) {
    const entries = activeComponentsEntries(options);
    const components = {};

    entries.forEach(function (entry) {
      const name = entry.component || "anonymous";

      if (!components[name]) {
        components[name] = {
          component: name,
          count: 0,
          totalSnapshotBytes: 0,
          loading: 0,
          dirty: 0,
          error: 0,
          success: 0,
        };
      }

      components[name].count += 1;
      components[name].totalSnapshotBytes += entry.snapshotBytes || 0;
      components[name].loading += entry.flags && entry.flags.loading ? 1 : 0;
      components[name].dirty += entry.flags && entry.flags.dirty ? 1 : 0;
      components[name].error += entry.flags && entry.flags.error ? 1 : 0;
      components[name].success += entry.flags && entry.flags.success ? 1 : 0;
    });

    return {
      totalRoots: entries.length,
      uniqueComponents: Object.keys(components).length,
      refreshedAt: runtime.activeComponentsMeta.refreshedAt,
      reason: runtime.activeComponentsMeta.reason,
      components: Object.keys(components)
        .sort()
        .map(function (name) {
          return cloneStateValue(components[name]);
        }),
    };
  }

  function activeComponentsSnapshot(options) {
    return {
      entries: activeComponentsEntries(options),
      summary: activeComponentsSummary(options),
    };
  }

  function normalizeRuntimeStateScope(scope) {
    return scope === "shared" ? "shared" : "client";
  }

  function normalizeRuntimeStateKey(key) {
    if (typeof key !== "string") {
      return null;
    }

    const normalized = key.trim();
    return normalized !== "" ? normalized : null;
  }

  function currentClientStateScope() {
    if (!runtime.clientStateScope) {
      runtime.clientStateScope = normalizeNavigationUrl(window.location.href);
    }

    return runtime.clientStateScope;
  }

  function runtimeStateStore(scope) {
    return normalizeRuntimeStateScope(scope) === "shared"
      ? runtime.sharedStateValues
      : runtime.clientStateValues;
  }

  function runtimeStateSubscriberStore(scope) {
    return normalizeRuntimeStateScope(scope) === "shared"
      ? runtime.sharedStateSubscribers
      : runtime.clientStateSubscribers;
  }

  function runtimeStateGlobalSubscribers(scope) {
    return normalizeRuntimeStateScope(scope) === "shared"
      ? runtime.sharedStateGlobalSubscribers
      : runtime.clientStateGlobalSubscribers;
  }

  function runtimeStateSnapshot(scope) {
    const snapshot = {};

    runtimeStateStore(scope).forEach(function (value, key) {
      snapshot[key] = cloneStateValue(value);
    });

    return snapshot;
  }

  function hasRuntimeStateValue(key, options) {
    const normalizedKey = normalizeRuntimeStateKey(key);

    if (!normalizedKey) {
      return false;
    }

    return runtimeStateStore(options && options.scope).has(normalizedKey);
  }

  function normalizeStatePathSegments(path) {
    if (typeof path !== "string" || path.trim() === "") {
      return [];
    }

    return path
      .split(".")
      .map(function (segment) {
        return segment.trim();
      })
      .filter(function (segment) {
        return segment !== "";
      });
  }

  function resolveValueBySegments(value, segments) {
    let current = value;

    for (let index = 0; index < segments.length; index += 1) {
      const segment = segments[index];

      if (Array.isArray(current)) {
        const numericIndex = Number(segment);

        if (
          !Number.isInteger(numericIndex) ||
          numericIndex < 0 ||
          numericIndex >= current.length
        ) {
          return {
            found: false,
            value: null,
          };
        }

        current = current[numericIndex];
        continue;
      }

      if (
        current === null ||
        typeof current !== "object" ||
        !Object.prototype.hasOwnProperty.call(current, segment)
      ) {
        return {
          found: false,
          value: null,
        };
      }

      current = current[segment];
    }

    return {
      found: true,
      value: current,
    };
  }

  function runtimeStateValueByPath(scope, path) {
    const segments = normalizeStatePathSegments(path);

    if (segments.length === 0) {
      return {
        found: false,
        value: null,
      };
    }

    for (let length = segments.length; length >= 1; length -= 1) {
      const stateKey = segments.slice(0, length).join(".");

      if (
        !hasRuntimeStateValue(stateKey, {
          scope: scope,
        })
      ) {
        continue;
      }

      const value = getRuntimeStateValue(stateKey, {
        scope: scope,
      });
      const nested = resolveValueBySegments(value, segments.slice(length));

      return {
        found: nested.found,
        value: nested.found ? cloneStateValue(nested.value) : null,
      };
    }

    return {
      found: false,
      value: null,
    };
  }

  function notifyRuntimeStateSubscribers(detail) {
    const scope = normalizeRuntimeStateScope(detail && detail.scope);
    const key = detail && detail.key ? detail.key : null;
    const subscriberStore = runtimeStateSubscriberStore(scope);
    const globalSubscribers = runtimeStateGlobalSubscribers(scope);

    if (key && subscriberStore.has(key)) {
      subscriberStore.get(key).forEach(function (listener) {
        try {
          listener(detail);
        } catch (error) {
          console.error("VoltStack state subscriber error:", error);
        }
      });
    }

    globalSubscribers.forEach(function (listener) {
      try {
        listener(detail);
      } catch (error) {
        console.error("VoltStack state subscriber error:", error);
      }
    });
  }

  function emitRuntimeStateChanged(
    scope,
    key,
    value,
    previousValue,
    action,
    extra,
  ) {
    const detail = Object.assign(
      {
        scope: normalizeRuntimeStateScope(scope),
        scopeId:
          normalizeRuntimeStateScope(scope) === "client"
            ? currentClientStateScope()
            : "shared",
        key: key,
        value: cloneStateValue(value),
        previousValue: cloneStateValue(previousValue),
        action: action || "set",
        snapshot: runtimeStateSnapshot(scope),
      },
      extra || {},
    );

    emitRuntimeHook("volt:state-changed", detail, document);
    notifyRuntimeStateSubscribers(detail);
  }

  function emitRuntimeStateCleared(scope, keys, reason, extra) {
    const detail = Object.assign(
      {
        scope: normalizeRuntimeStateScope(scope),
        scopeId:
          normalizeRuntimeStateScope(scope) === "client"
            ? currentClientStateScope()
            : "shared",
        keys: Array.isArray(keys) ? keys.slice() : [],
        reason: reason || "manual",
        snapshot: runtimeStateSnapshot(scope),
      },
      extra || {},
    );

    emitRuntimeHook("volt:state-cleared", detail, document);
    notifyRuntimeStateSubscribers(detail);
  }

  function getRuntimeStateValue(key, options) {
    const normalizedKey = normalizeRuntimeStateKey(key);

    if (!normalizedKey) {
      return null;
    }

    const settings = options && typeof options === "object" ? options : {};
    const store = runtimeStateStore(settings.scope);
    const fallback = Object.prototype.hasOwnProperty.call(settings, "fallback")
      ? settings.fallback
      : null;

    return store.has(normalizedKey)
      ? cloneStateValue(store.get(normalizedKey))
      : fallback;
  }

  function setRuntimeStateValue(key, value, options) {
    const normalizedKey = normalizeRuntimeStateKey(key);

    if (!normalizedKey) {
      return null;
    }

    const settings = options && typeof options === "object" ? options : {};
    const scope = normalizeRuntimeStateScope(settings.scope);
    const store = runtimeStateStore(scope);
    const previousValue = store.has(normalizedKey)
      ? store.get(normalizedKey)
      : null;

    store.set(normalizedKey, value);
    syncAllRuntimeStateDirectives();
    emitRuntimeStateChanged(
      scope,
      normalizedKey,
      value,
      previousValue,
      settings.action || "set",
    );
    return cloneStateValue(value);
  }

  function mergeRuntimeStateValue(key, value, options) {
    const current = getRuntimeStateValue(key, options);
    const nextValue = Object.assign(
      {},
      current && typeof current === "object" && !Array.isArray(current)
        ? current
        : {},
      value && typeof value === "object" && !Array.isArray(value) ? value : {},
    );

    return setRuntimeStateValue(
      key,
      nextValue,
      Object.assign({}, options || {}, {
        action: "merge",
      }),
    );
  }

  function updateRuntimeStateValue(key, updater, options) {
    const current = getRuntimeStateValue(key, options);
    const nextValue =
      typeof updater === "function" ? updater(current) : updater;

    return setRuntimeStateValue(
      key,
      nextValue,
      Object.assign({}, options || {}, {
        action: "update",
      }),
    );
  }

  function deleteRuntimeStateValue(key, options) {
    const normalizedKey = normalizeRuntimeStateKey(key);

    if (!normalizedKey) {
      return false;
    }

    const settings = options && typeof options === "object" ? options : {};
    const scope = normalizeRuntimeStateScope(settings.scope);
    const store = runtimeStateStore(scope);

    if (!store.has(normalizedKey)) {
      return false;
    }

    const previousValue = store.get(normalizedKey);
    store.delete(normalizedKey);
    syncAllRuntimeStateDirectives();
    emitRuntimeStateChanged(
      scope,
      normalizedKey,
      null,
      previousValue,
      "delete",
    );
    return true;
  }

  function clearRuntimeState(scope, reason, extra) {
    const normalizedScope = normalizeRuntimeStateScope(scope);
    const store = runtimeStateStore(normalizedScope);
    const keys = Array.from(store.keys());

    if (keys.length === 0) {
      return false;
    }

    store.clear();
    syncAllRuntimeStateDirectives();
    emitRuntimeStateCleared(normalizedScope, keys, reason, extra);
    return true;
  }

  function transitionClientStateScope(nextUrl, reason) {
    const nextScope = normalizeNavigationUrl(nextUrl || window.location.href);
    const previousScope = currentClientStateScope();

    if (previousScope === nextScope) {
      return false;
    }

    const hadValues = clearRuntimeState("client", reason || "navigation", {
      previousScopeId: previousScope,
      nextScopeId: nextScope,
    });

    runtime.clientStateScope = nextScope;

    emitRuntimeHook(
      "volt:state-scope-changed",
      {
        scope: "client",
        previousScopeId: previousScope,
        nextScopeId: nextScope,
        cleared: hadValues,
        reason: reason || "navigation",
      },
      document,
    );

    return true;
  }

  function subscribeRuntimeState(key, listener, options) {
    if (typeof listener !== "function") {
      return function () {
        return false;
      };
    }

    const settings = options && typeof options === "object" ? options : {};
    const scope = normalizeRuntimeStateScope(settings.scope);
    const normalizedKey = normalizeRuntimeStateKey(key);

    if (!normalizedKey) {
      const listeners = runtimeStateGlobalSubscribers(scope);
      listeners.add(listener);

      return function () {
        return listeners.delete(listener);
      };
    }

    const subscriberStore = runtimeStateSubscriberStore(scope);

    if (!subscriberStore.has(normalizedKey)) {
      subscriberStore.set(normalizedKey, new Set());
    }

    subscriberStore.get(normalizedKey).add(listener);

    return function () {
      const listeners = subscriberStore.get(normalizedKey);

      if (!listeners) {
        return false;
      }

      const deleted = listeners.delete(listener);

      if (listeners.size === 0) {
        subscriberStore.delete(normalizedKey);
      }

      return deleted;
    };
  }

  function createPublicStateApi() {
    return {
      get: getRuntimeStateValue,
      set: setRuntimeStateValue,
      merge: mergeRuntimeStateValue,
      update: updateRuntimeStateValue,
      delete: deleteRuntimeStateValue,
      clear: function (options) {
        const settings = options && typeof options === "object" ? options : {};
        return clearRuntimeState(settings.scope, settings.reason || "manual");
      },
      snapshot: function (options) {
        const settings = options && typeof options === "object" ? options : {};
        return runtimeStateSnapshot(settings.scope);
      },
      subscribe: subscribeRuntimeState,
      currentScope: currentClientStateScope,
    };
  }

  function createPublicBusyApi() {
    return {
      active: function () {
        return cloneBusyState(resolveGlobalBusyState()).active;
      },
      current: function () {
        return cloneBusyState(resolveGlobalBusyState());
      },
      snapshot: function () {
        return cloneBusyState(resolveGlobalBusyState());
      },
    };
  }

  function createPublicOnFunction() {
    function resolvePublicOnTarget(settings) {
      if (settings && settings.target && typeof settings.target.addEventListener === "function") {
        return settings.target;
      }

      return document;
    }

    function resolvePublicOnRoot(settings) {
      if (!settings || typeof settings !== "object") {
        return null;
      }

      if (
        settings.root &&
        typeof settings.root === "object" &&
        settings.root.isConnected
      ) {
        return settings.root;
      }

      if (typeof settings.component === "string" && settings.component.trim() !== "") {
        return findRootByComponent(settings.component.trim());
      }

      return null;
    }

    function normalizePublicOnOptions(settings) {
      const resolved = settings && typeof settings === "object" ? settings : {};

      return {
        capture: resolved.capture === true,
        once: resolved.once === true,
        passive: resolved.passive === true,
        selector:
          typeof resolved.selector === "string" && resolved.selector.trim() !== ""
            ? resolved.selector.trim()
            : null,
        component:
          typeof resolved.component === "string" && resolved.component.trim() !== ""
            ? resolved.component.trim()
            : null,
        root: resolvePublicOnRoot(resolved),
        target: resolvePublicOnTarget(resolved),
      };
    }

    function matchesPublicOnFilter(event, settings) {
      if (!settings) {
        return true;
      }

      const detail =
        event && event.detail && typeof event.detail === "object"
          ? event.detail
          : {};
      const target = event && event.target ? event.target : null;

      if (settings.component) {
        const component =
          typeof detail.component === "string" && detail.component !== ""
            ? detail.component
            : null;

        if (component && component !== settings.component) {
          return false;
        }
      }

      if (settings.root) {
        if (target && settings.root === target) {
          return true;
        }

        if (
          target &&
          typeof target === "object" &&
          typeof target.nodeType === "number" &&
          typeof settings.root.contains === "function" &&
          settings.root.contains(target)
        ) {
          return true;
        }

        const componentName =
          typeof detail.component === "string" && detail.component !== ""
            ? detail.component
            : null;

        if (
          componentName &&
          settings.root.getAttribute &&
          settings.root.getAttribute("data-volt-component") === componentName
        ) {
          return true;
        }

        return false;
      }

      if (settings.selector) {
        if (!(target instanceof Element)) {
          return false;
        }

        return !!target.closest(settings.selector);
      }

      return true;
    }

    return function (name, listener, options) {
      if (typeof name !== "string" || name.trim() === "") {
        return function () {
          return false;
        };
      }

      if (typeof listener !== "function") {
        return function () {
          return false;
        };
      }

      const settings = normalizePublicOnOptions(options);
      const eventName = name.trim();

      function wrapper(event) {
        if (!matchesPublicOnFilter(event, settings)) {
          return;
        }

        listener(event);
      }

      settings.target.addEventListener(eventName, wrapper, {
        capture: settings.capture,
        passive: settings.passive,
        once: settings.once,
      });

      return function () {
        settings.target.removeEventListener(eventName, wrapper, settings.capture);
        return true;
      };
    };
  }

  function safeLocalStorage() {
    try {
      return typeof window !== "undefined" ? window.localStorage : null;
    } catch (error) {
      return null;
    }
  }

  function offlineSnapshotStorageKey(component) {
    const key = typeof component === "string" ? component.trim() : "";
    return key !== "" ? "volt:offline:snapshot:" + key : null;
  }

  function persistOfflineSnapshot(root) {
    if (!root || typeof root.getAttribute !== "function") {
      return false;
    }

    const component = root.getAttribute("data-volt-component") || "";
    const key = offlineSnapshotStorageKey(component);

    if (!key) {
      return false;
    }

    const snapshot = root.getAttribute("data-volt-snapshot");

    if (!snapshot || typeof snapshot !== "string" || snapshot.trim() === "") {
      return false;
    }

    const storage = safeLocalStorage();

    if (!storage || typeof storage.setItem !== "function") {
      return false;
    }

    try {
      storage.setItem(
        key,
        JSON.stringify({
          component: component,
          snapshot: snapshot,
          url:
            typeof window !== "undefined" && window.location
              ? String(window.location.href)
              : null,
          storedAt: new Date().toISOString(),
        }),
      );
      return true;
    } catch (error) {
      return false;
    }
  }

  function readOfflineSnapshot(component) {
    const key = offlineSnapshotStorageKey(component);

    if (!key) {
      return null;
    }

    const storage = safeLocalStorage();

    if (!storage || typeof storage.getItem !== "function") {
      return null;
    }

    try {
      const raw = storage.getItem(key);

      if (!raw || typeof raw !== "string") {
        return null;
      }

      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function clearOfflineSnapshot(component) {
    const key = offlineSnapshotStorageKey(component);

    if (!key) {
      return false;
    }

    const storage = safeLocalStorage();

    if (!storage || typeof storage.removeItem !== "function") {
      return false;
    }

    try {
      storage.removeItem(key);
      return true;
    } catch (error) {
      return false;
    }
  }

  function ensureQueuedActions() {
    if (Array.isArray(runtime.queuedActions)) {
      return;
    }

    runtime.queuedActions = [];
    runtime.queuedActionsFlushInProgress = false;
  }

  function enqueueOfflineAction(component, action, params, updates, trigger) {
    ensureQueuedActions();

    const componentName = typeof component === "string" ? component.trim() : "";
    const actionName = typeof action === "string" ? action.trim() : "";

    if (componentName === "" || actionName === "") {
      return false;
    }

    runtime.queuedActions.push({
      component: componentName,
      action: actionName,
      params: params && typeof params === "object" ? params : {},
      updates: updates && typeof updates === "object" ? updates : {},
      trigger: trigger && typeof trigger === "object" ? trigger : null,
      queuedAt: new Date().toISOString(),
    });

    emitRuntimeHook(
      "volt:action-queued",
      {
        component: componentName,
        action: actionName,
        trigger: trigger && typeof trigger === "object" ? trigger : null,
        queueSize: runtime.queuedActions.length,
      },
      document,
    );

    return true;
  }

  async function flushQueuedActions(options) {
    ensureQueuedActions();

    const settings = options && typeof options === "object" ? options : {};
    const max = typeof settings.max === "number" && settings.max > 0 ? settings.max : null;

    if (runtime.queuedActionsFlushInProgress === true) {
      return {
        flushed: 0,
        remaining: runtime.queuedActions.length,
        busy: true,
      };
    }

    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      return {
        flushed: 0,
        remaining: runtime.queuedActions.length,
        offline: true,
      };
    }

    runtime.queuedActionsFlushInProgress = true;

    emitRuntimeHook(
      "volt:queue-flush-start",
      {
        queueSize: runtime.queuedActions.length,
      },
      document,
    );

    let flushed = 0;

    try {
      while (runtime.queuedActions.length > 0) {
        if (max !== null && flushed >= max) {
          break;
        }

        const entry = runtime.queuedActions.shift();

        if (!entry) {
          continue;
        }

        const root = findRootByComponent(entry.component);

        if (!root) {
          continue;
        }

        try {
          await dispatchAction(
            root,
            entry.action,
            entry.params || {},
            entry.updates || {},
            null,
          );
          flushed += 1;
        } catch (error) {
          runtime.queuedActions.unshift(entry);
          break;
        }
      }
    } finally {
      runtime.queuedActionsFlushInProgress = false;

      emitRuntimeHook(
        "volt:queue-flush-finish",
        {
          flushed: flushed,
          remaining: runtime.queuedActions.length,
        },
        document,
      );
    }

    return {
      flushed: flushed,
      remaining: runtime.queuedActions.length,
    };
  }

  function createPublicQueueApi() {
    return {
      list: function () {
        ensureQueuedActions();
        return runtime.queuedActions.slice();
      },
      flush: function (options) {
        return flushQueuedActions(options || {});
      },
      clear: function () {
        ensureQueuedActions();
        const size = runtime.queuedActions.length;
        runtime.queuedActions = [];
        return {
          cleared: size,
        };
      },
    };
  }

  function createPublicSnapshotsApi() {
    return {
      get: function (component) {
        return readOfflineSnapshot(component);
      },
      clear: function (component) {
        return clearOfflineSnapshot(component);
      },
    };
  }

  function runtimeOnlineValue() {
    return typeof navigator === "undefined" ? true : navigator.onLine !== false;
  }

  function setRuntimeOnlineState(online, meta) {
    const active = online === true;
    const previous = runtime.networkOnline !== false;
    runtime.networkOnline = active;

    if (document && document.documentElement && document.documentElement.setAttribute) {
      document.documentElement.setAttribute(
        "data-volt-offline",
        active ? "false" : "true",
      );
      document.documentElement.setAttribute(
        "data-volt-online",
        active ? "true" : "false",
      );
    }

    if (previous !== active) {
      emitRuntimeHook(active ? "volt:online" : "volt:offline", meta || {}, document);
    }
  }

  function ensureRuntimeNetworkBindings() {
    if (runtime.networkBindingsInstalled === true) {
      return;
    }

    runtime.networkBindingsInstalled = true;

    if (typeof window !== "undefined" && window.addEventListener) {
      setRuntimeOnlineState(runtimeOnlineValue(), {
        source: "boot",
      });

      window.addEventListener("online", function () {
        setRuntimeOnlineState(true, {
          source: "browser",
        });
        flushQueuedActions({
          max: null,
        }).catch(function () {
          return null;
        });
      });

      window.addEventListener("offline", function () {
        setRuntimeOnlineState(false, {
          source: "browser",
        });
      });
    }
  }

  function createPublicNetworkApi() {
    return {
      online: function () {
        return runtime.networkOnline !== false;
      },
      offline: function () {
        return runtime.networkOnline === false;
      },
      current: function () {
        return {
          online: runtime.networkOnline !== false,
        };
      },
    };
  }

  function createPublicRuntimeContract() {
    return Object.freeze({
      version: VOLT_RUNTIME_PUBLIC_CONTRACT_VERSION,
      apis: Object.freeze({
        visit: Object.freeze({
          type: "function",
        }),
        prefetch: Object.freeze({
          type: "function",
        }),
        on: Object.freeze({
          type: "function",
        }),
        state: Object.freeze({
          type: "api",
          factory: "createPublicStateApi",
        }),
        directives: Object.freeze({
          type: "api",
          factory: "createPublicDirectivesApi",
        }),
        effects: Object.freeze({
          type: "api",
          factory: "createPublicEffectsApi",
        }),
        plugins: Object.freeze({
          type: "api",
          factory: "createPublicPluginsApi",
        }),
        middleware: Object.freeze({
          type: "api",
          factory: "createPublicMiddlewareApi",
        }),
        queue: Object.freeze({
          type: "api",
          factory: "createPublicQueueApi",
        }),
        snapshots: Object.freeze({
          type: "api",
          factory: "createPublicSnapshotsApi",
        }),
        network: Object.freeze({
          type: "api",
          factory: "createPublicNetworkApi",
        }),
        components: Object.freeze({
          type: "api",
          factory: "createPublicComponentsApi",
        }),
        telemetry: Object.freeze({
          type: "api",
          factory: "createPublicTelemetryApi",
        }),
        busy: Object.freeze({
          type: "api",
          factory: "createPublicBusyApi",
        }),
      }),
    });
  }

  function createPublicTelemetryApi() {
    return {
      entries: filteredTelemetryEntries,
      latest: function (options) {
        const entries = filteredTelemetryEntries(
          Object.assign({}, options || {}, {
            limit: 1,
          }),
        );

        return entries.length > 0 ? entries[0] : null;
      },
      summary: telemetrySummary,
      boot: runtimeBootSnapshot,
      snapshot: function (options) {
        return {
          entries: filteredTelemetryEntries(options),
          summary: telemetrySummary(options),
          maxEntries: runtime.telemetryMaxEntries,
        };
      },
      reset: resetRuntimeTelemetry,
      size: function () {
        return runtime.telemetryEntries.length;
      },
      config: function () {
        return {
          maxEntries: runtime.telemetryMaxEntries,
        };
      },
    };
  }

  function createPublicComponentsApi() {
    return {
      entries: activeComponentsEntries,
      all: activeComponentsEntries,
      summary: activeComponentsSummary,
      snapshot: activeComponentsSnapshot,
      count: function (options) {
        return activeComponentsEntries(options).length;
      },
      names: function () {
        return activeComponentsSummary().components.map(function (entry) {
          return entry.component;
        });
      },
      find: function (componentName) {
        const entries = activeComponentsEntries({
          component: componentName,
        });

        return entries.length > 0 ? entries[0] : null;
      },
      refresh: function (reason) {
        return refreshActiveComponentsRegistry(reason || "manual");
      },
    };
  }
