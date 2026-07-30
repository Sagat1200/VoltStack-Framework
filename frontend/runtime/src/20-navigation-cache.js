  function navigationCacheAliases(entryOrUrl, finalUrl) {
    const aliases = [];

    function pushAlias(value) {
      const normalized = normalizeNavigationUrl(value);

      if (!normalized || aliases.indexOf(normalized) !== -1) {
        return;
      }

      aliases.push(normalized);
    }

    if (entryOrUrl && typeof entryOrUrl === "object") {
      if (Array.isArray(entryOrUrl.aliases)) {
        entryOrUrl.aliases.forEach(pushAlias);
      }

      pushAlias(entryOrUrl.url);
      pushAlias(entryOrUrl.finalUrl);
      return aliases;
    }

    pushAlias(entryOrUrl);
    pushAlias(finalUrl);
    return aliases;
  }

  function uniqueNavigationCacheEntries() {
    const entries = [];
    const seen = new Set();

    runtime.navigationCache.forEach(function (entry) {
      if (!entry || !entry.cacheKey || seen.has(entry.cacheKey)) {
        return;
      }

      seen.add(entry.cacheKey);
      entries.push(entry);
    });

    return entries;
  }

  function deleteNavigationCacheEntry(entry) {
    if (!entry) {
      return 0;
    }

    let removed = 0;

    navigationCacheAliases(entry).forEach(function (alias) {
      if (runtime.navigationCache.get(alias) === entry) {
        runtime.navigationCache.delete(alias);
        removed += 1;
      }
    });

    return removed;
  }

  function emitNavigationCacheEvent(name, detail) {
    emitRuntimeHook(
      name,
      Object.assign(
        {
          cacheEntries: uniqueNavigationCacheEntries().length,
        },
        detail || {},
      ),
      document,
    );
  }

  function invalidateNavigationCache(url, reason, extra) {
    if (typeof url !== "string" || url === "") {
      return 0;
    }

    const normalizedUrl = normalizeNavigationUrl(url);
    const details = extra && typeof extra === "object" ? extra : {};
    const entry = runtime.navigationCache.get(normalizedUrl);
    const aliases = entry ? navigationCacheAliases(entry) : [normalizedUrl];
    const removed = entry
      ? deleteNavigationCacheEntry(entry)
      : runtime.navigationCache.delete(normalizedUrl)
        ? 1
        : 0;

    if (removed > 0 && details.silent !== true) {
      emitNavigationCacheEvent(
        "volt:cache-invalidate",
        Object.assign(
          {
            url: normalizedUrl,
            aliases: aliases,
            reason: reason || "manual",
            removed: removed,
          },
          details,
        ),
      );
    }

    return removed;
  }

  function clearNavigationCache(reason, extra) {
    const removed = uniqueNavigationCacheEntries().length;

    if (removed === 0) {
      return 0;
    }

    runtime.navigationCache.clear();
    emitNavigationCacheEvent(
      "volt:cache-clear",
      Object.assign(
        {
          reason: reason || "manual",
          removed: removed,
        },
        extra || {},
      ),
    );

    return removed;
  }

  function cloneNavigationPayload(entry) {
    if (!entry || typeof entry !== "object" || typeof entry.html !== "string") {
      return null;
    }

    const documentPayload = parseNavigationDocument(entry.html);
    const cacheControl =
      entry.cacheControl || navigationCacheControlForDocument(documentPayload);
    const navigationMode =
      entry.navigationMode || navigationModeForDocument(documentPayload);
    const documentContract =
      entry.documentContract || documentContractForDocument(documentPayload);
    const pageTransition =
      entry.pageTransition ||
      pageTransitionForPayload({
        html: entry.html,
        document: documentPayload,
      });

    return {
      url: entry.url,
      target: entry.target || entry.url,
      finalUrl: entry.finalUrl,
      html: entry.html,
      document: documentPayload,
      fetchedAt: entry.fetchedAt,
      lastAccessedAt: entry.lastAccessedAt,
      expiresAt: entry.expiresAt,
      source: entry.source || "cache",
      cacheKey: entry.cacheKey || null,
      aliases: navigationCacheAliases(entry),
      cacheControl: cacheControl,
      navigationMode: navigationMode,
      documentContract: documentContract,
      pageTransition: pageTransition,
      spaNavigation:
        entry.spaNavigation && typeof entry.spaNavigation === "object"
          ? entry.spaNavigation
          : null,
    };
  }

  function frontendRouteManifestEndpoint() {
    return normalizeNavigationUrl("/_volt/routes-manifest.json");
  }

  function isValidFrontendRouteManifest(payload) {
    if (!payload || typeof payload !== "object") {
      return false;
    }

    const protocol =
      payload.protocol && typeof payload.protocol === "object"
        ? payload.protocol
        : null;

    return (
      !!protocol &&
      protocol.name === "VoltStack Frontend Manifest" &&
      typeof protocol.version === "string" &&
      Array.isArray(payload.routes)
    );
  }

  async function loadFrontendRouteManifest(options) {
    const settings = options && typeof options === "object" ? options : {};
    const state = runtime.frontendRouteManifest;

    if (
      settings.reload !== true &&
      state &&
      Array.isArray(state.routes) &&
      state.routes.length >= 0
    ) {
      return state;
    }

    if (state && state.promise) {
      return state.promise;
    }

    const request = fetch(frontendRouteManifestEndpoint(), {
      method: "GET",
      headers: {
        "X-Requested-With": "VoltStack",
      },
      credentials: "same-origin",
      cache: "no-store",
    })
      .then(function (response) {
        if (!response.ok) {
          return null;
        }

        return response.json().catch(function () {
          return null;
        });
      })
      .then(function (payload) {
        if (!isValidFrontendRouteManifest(payload)) {
          return null;
        }

        state.loadedAt = Date.now();
        state.checksum =
          payload.version && typeof payload.version === "object"
            ? payload.version.checksum || null
            : null;
        state.routes = payload.routes;
        return state;
      })
      .catch(function () {
        return null;
      })
      .finally(function () {
        if (state) {
          state.promise = null;
        }
      });

    if (state) {
      state.promise = request;
    }

    return request;
  }

  function escapeFrontendRoutePatternSegment(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function frontendRoutePatternToRegExp(path) {
    if (typeof path !== "string" || path.trim() === "") {
      return null;
    }

    const normalizedPath = path.trim();

    if (normalizedPath === "/") {
      return /^\/$/;
    }

    const pattern = normalizedPath
      .split("/")
      .map(function (segment, index) {
        if (index === 0) {
          return "";
        }

        if (/^\{[^/]+\}$/.test(segment)) {
          return "[^/]+";
        }

        return escapeFrontendRoutePatternSegment(segment);
      })
      .join("/");

    return new RegExp("^" + pattern + "/?$");
  }

  function frontendManifestRouteForUrl(manifestState, url, method) {
    if (
      !manifestState ||
      !Array.isArray(manifestState.routes) ||
      typeof url !== "string" ||
      url === ""
    ) {
      return null;
    }

    const targetUrl = new URL(url, window.location.href);
    const normalizedMethod =
      typeof method === "string" && method.trim() !== ""
        ? method.trim().toUpperCase()
        : "GET";

    for (let index = 0; index < manifestState.routes.length; index += 1) {
      const route = manifestState.routes[index];

      if (!route || typeof route !== "object") {
        continue;
      }

      const methods = Array.isArray(route.methods) ? route.methods : [];

      if (methods.indexOf(normalizedMethod) === -1) {
        continue;
      }

      const matcher = frontendRoutePatternToRegExp(route.path || "");

      if (!matcher) {
        continue;
      }

      if (matcher.test(targetUrl.pathname)) {
        return route;
      }
    }

    return null;
  }

  async function resolveFrontendManifestRoute(url, method) {
    const manifestState = await loadFrontendRouteManifest();

    if (!manifestState) {
      return null;
    }

    return frontendManifestRouteForUrl(manifestState, url, method || "GET");
  }

  function frontendManifestRouteAllowsPrefetch(route) {
    if (!route || typeof route !== "object") {
      return true;
    }

    const capabilities = Array.isArray(route.capabilities) ? route.capabilities : [];
    return capabilities.indexOf("prefetch") !== -1;
  }

  function frontendManifestRouteNavigationMode(route) {
    const policy =
      route && route.policy && typeof route.policy === "object" ? route.policy : null;

    if (!policy || typeof policy.navigation !== "string") {
      return null;
    }

    return parseNavigationMode(policy.navigation, "manifest");
  }

  function frontendManifestRouteDocumentContract(route) {
    const policy =
      route && route.policy && typeof route.policy === "object" ? route.policy : null;

    if (!policy || typeof policy.document !== "string") {
      return null;
    }

    return parseDocumentContract(policy.document, "manifest");
  }

  function parseSpaNavigationHeader(value) {
    if (typeof value !== "string" || value.trim() === "") {
      return null;
    }

    try {
      const payload = JSON.parse(value);

      if (!payload || typeof payload !== "object") {
        return null;
      }

      const protocol =
        payload.protocol && typeof payload.protocol === "object"
          ? payload.protocol
          : null;

      if (
        !protocol ||
        protocol.name !== "VoltStack SPA Routing" ||
        typeof protocol.version !== "string" ||
        protocol.version.trim() === ""
      ) {
        return null;
      }

      return payload;
    } catch (error) {
      return null;
    }
  }

  function spaNavigationLayout(spaNavigation) {
    const runtime =
      spaNavigation &&
      spaNavigation.runtime &&
      typeof spaNavigation.runtime === "object"
        ? spaNavigation.runtime
        : null;

    if (!runtime || typeof runtime.layout !== "string" || runtime.layout === "") {
      return null;
    }

    return runtime.layout;
  }

  function spaNavigationHydrate(spaNavigation) {
    const runtime =
      spaNavigation &&
      spaNavigation.runtime &&
      typeof spaNavigation.runtime === "object"
        ? spaNavigation.runtime
        : null;

    if (!runtime || typeof runtime.hydrate !== "boolean") {
      return null;
    }

    return {
      enabled: runtime.hydrate,
      strategy: null,
      dirtyState: null,
      source: "protocol",
      declared: true,
    };
  }

  function spaNavigationPageTransition(spaNavigation) {
    const runtime =
      spaNavigation &&
      spaNavigation.runtime &&
      typeof spaNavigation.runtime === "object"
        ? spaNavigation.runtime
        : null;

    if (
      !runtime ||
      typeof runtime.transition !== "string" ||
      runtime.transition === ""
    ) {
      return null;
    }

    return createPageTransition(runtime.transition, null, null, "protocol", null);
  }

  function spaNavigationNavigationMode(spaNavigation) {
    const policy =
      spaNavigation &&
      spaNavigation.policy &&
      typeof spaNavigation.policy === "object"
        ? spaNavigation.policy
        : null;

    if (!policy || typeof policy.navigation !== "string") {
      return null;
    }

    return parseNavigationMode(policy.navigation, "protocol");
  }

  function spaNavigationDocumentContract(spaNavigation) {
    const policy =
      spaNavigation &&
      spaNavigation.policy &&
      typeof spaNavigation.policy === "object"
        ? spaNavigation.policy
        : null;

    if (!policy || typeof policy.document !== "string") {
      return null;
    }

    return parseDocumentContract(policy.document, "protocol");
  }

  function pruneNavigationCache() {
    const now = Date.now();

    uniqueNavigationCacheEntries().forEach(function (entry) {
      if (
        !entry ||
        typeof entry.expiresAt !== "number" ||
        entry.expiresAt <= now
      ) {
        deleteNavigationCacheEntry(entry);
      }
    });

    const entries = uniqueNavigationCacheEntries().sort(function (left, right) {
      const leftStamp =
        typeof left.lastAccessedAt === "number"
          ? left.lastAccessedAt
          : left.fetchedAt;
      const rightStamp =
        typeof right.lastAccessedAt === "number"
          ? right.lastAccessedAt
          : right.fetchedAt;
      return leftStamp - rightStamp;
    });

    while (entries.length > NAVIGATION_CACHE_MAX_ENTRIES) {
      const oldestEntry = entries.shift();

      if (!oldestEntry) {
        break;
      }

      deleteNavigationCacheEntry(oldestEntry);
    }
  }

  function getCachedNavigation(url) {
    const normalizedUrl = normalizeNavigationUrl(url);
    const entry = runtime.navigationCache.get(normalizedUrl);

    if (!entry) {
      return null;
    }

    if (typeof entry.expiresAt !== "number" || entry.expiresAt <= Date.now()) {
      deleteNavigationCacheEntry(entry);
      emitNavigationCacheEvent("volt:cache-invalidate", {
        url: normalizedUrl,
        aliases: navigationCacheAliases(entry),
        reason: "ttl",
      });
      return null;
    }

    entry.lastAccessedAt = Date.now();

    return cloneNavigationPayload(entry);
  }

  function setCachedNavigation(url, payload, source, control) {
    if (!payload || typeof payload.html !== "string" || payload.html === "") {
      return null;
    }

    const normalizedUrl = normalizeNavigationUrl(url);
    const payloadTarget =
      typeof payload.target === "string" && payload.target !== ""
        ? normalizeNavigationUrl(payload.target)
        : normalizedUrl;
    const finalUrl = normalizeNavigationUrl(payload.finalUrl || normalizedUrl);
    const redirectTarget =
      typeof payload.redirect === "string" && payload.redirect !== ""
        ? normalizeNavigationUrl(payload.redirect)
        : finalUrl !== normalizedUrl
          ? finalUrl
          : null;
    const cacheControl = mergeNavigationCacheControl(
      control,
      payload.cacheControl && typeof payload.cacheControl === "object"
        ? payload.cacheControl
        : null,
    );
    const ttl = navigationCacheTtlForControl(cacheControl);

    if (!shouldStoreNavigationCache(cacheControl) || ttl <= 0) {
      invalidateNavigationCache(normalizedUrl, "no-store", {
        finalUrl: finalUrl,
      });

      if (finalUrl !== normalizedUrl) {
        invalidateNavigationCache(finalUrl, "no-store", {
          requestedUrl: normalizedUrl,
        });
      }

      return null;
    }

    const now = Date.now();
    const aliases = navigationCacheAliases(normalizedUrl, finalUrl);

    aliases.forEach(function (alias) {
      invalidateNavigationCache(alias, "replace", {
        requestedUrl: normalizedUrl,
        finalUrl: finalUrl,
        silent: true,
      });
    });

    const entry = {
      cacheKey: aliases.join("::"),
      aliases: aliases,
      url: normalizedUrl,
      target: payloadTarget,
      finalUrl: finalUrl,
      html: payload.html,
      fetchedAt: now,
      lastAccessedAt: now,
      expiresAt: now + ttl,
      source: source || "prefetch",
      cacheControl: cacheControl,
      redirect: redirectTarget,
      layout:
        typeof payload.layout === "string" && payload.layout !== ""
          ? payload.layout
          : documentLayoutIdentity(parseNavigationDocument(payload.html)),
      navigationMode:
        payload.navigationMode && typeof payload.navigationMode === "object"
          ? payload.navigationMode
          : navigationModeForDocument(parseNavigationDocument(payload.html)),
      documentContract:
        payload.documentContract && typeof payload.documentContract === "object"
          ? payload.documentContract
          : documentContractForDocument(parseNavigationDocument(payload.html)),
      hydrate:
        payload.hydrate && typeof payload.hydrate === "object"
          ? payload.hydrate
          : hydrationForDocument(parseNavigationDocument(payload.html)),
      pageTransition:
        payload.pageTransition && typeof payload.pageTransition === "object"
          ? payload.pageTransition
          : pageTransitionForPayload({
              html: payload.html,
              document: parseNavigationDocument(payload.html),
            }),
      spaNavigation:
        payload.spaNavigation && typeof payload.spaNavigation === "object"
          ? payload.spaNavigation
          : null,
    };

    aliases.forEach(function (alias) {
      runtime.navigationCache.set(alias, entry);
    });
    pruneNavigationCache();
    emitNavigationCacheEvent("volt:cache-store", {
      url: normalizedUrl,
      finalUrl: finalUrl,
      aliases: aliases,
      source: entry.source,
      ttl: ttl,
      mode: cacheControl.mode,
    });

    return cloneNavigationPayload(entry);
  }

  function preloadDescriptorFromHeadNode(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }

    const tag = node.tagName.toLowerCase();

    if (tag === "link") {
      const rel = (node.getAttribute("rel") || "").toLowerCase();
      const href = normalizeHeadAssetUrl(node.getAttribute("href") || "");

      if (!href) {
        return null;
      }

      if (rel === "stylesheet") {
        return {
          key: "style:" + href,
          rel: "preload",
          href: href,
          as: "style",
          crossOrigin: node.getAttribute("crossorigin") || null,
        };
      }

      if (rel === "modulepreload") {
        return {
          key: "module:" + href,
          rel: "modulepreload",
          href: href,
          crossOrigin: node.getAttribute("crossorigin") || null,
        };
      }

      if (rel === "preload") {
        const as = (node.getAttribute("as") || "").toLowerCase();

        if (!as) {
          return null;
        }

        return {
          key: "preload:" + as + ":" + href,
          rel: "preload",
          href: href,
          as: as,
          crossOrigin: node.getAttribute("crossorigin") || null,
        };
      }

      return null;
    }

    if (tag === "script") {
      const src = normalizeHeadAssetUrl(node.getAttribute("src") || "");
      const type = (node.getAttribute("type") || "").toLowerCase();

      if (!src || type !== "module") {
        return null;
      }

      return {
        key: "module:" + src,
        rel: "modulepreload",
        href: src,
        crossOrigin: node.getAttribute("crossorigin") || null,
      };
    }

    return null;
  }

  function documentAlreadyHasHeadAsset(descriptor) {
    if (!descriptor || !descriptor.href || !document.head) {
      return false;
    }

    const href = descriptor.href;

    if (descriptor.as === "style") {
      const styles = Array.from(
        document.head.querySelectorAll('link[rel="stylesheet"]'),
      ).some(function (node) {
        return normalizeHeadAssetUrl(node.getAttribute("href") || "") === href;
      });

      if (styles) {
        return true;
      }

      return Array.from(
        document.head.querySelectorAll('link[rel="preload"][as="style"]'),
      ).some(function (node) {
        return normalizeHeadAssetUrl(node.getAttribute("href") || "") === href;
      });
    }

    if (descriptor.rel === "modulepreload") {
      const preloads = Array.from(
        document.head.querySelectorAll('link[rel="modulepreload"]'),
      ).some(function (node) {
        return normalizeHeadAssetUrl(node.getAttribute("href") || "") === href;
      });

      if (preloads) {
        return true;
      }

      return Array.from(
        document.head.querySelectorAll('script[type="module"][src]'),
      ).some(function (node) {
        return normalizeHeadAssetUrl(node.getAttribute("src") || "") === href;
      });
    }

    if (descriptor.rel === "preload") {
      const desiredAs = descriptor.as || "";

      if (!desiredAs) {
        return false;
      }

      return Array.from(document.head.querySelectorAll('link[rel="preload"]')).some(
        function (node) {
          return (
            normalizeHeadAssetUrl(node.getAttribute("href") || "") === href &&
            (node.getAttribute("as") || "").toLowerCase() === desiredAs
          );
        },
      );
    }

    return false;
  }

  function ensurePreloadHint(descriptor) {
    if (!descriptor || !descriptor.key || !descriptor.href || !document.head) {
      return;
    }

    if (
      runtime.navigationPreloadHints.has(descriptor.key) ||
      documentAlreadyHasHeadAsset(descriptor)
    ) {
      return;
    }

    const link = document.createElement("link");
    link.setAttribute("href", descriptor.href);
    link.setAttribute("rel", descriptor.rel);
    link.setAttribute("data-volt-prefetch-preload", descriptor.key);

    if (descriptor.as) {
      link.setAttribute("as", descriptor.as);
    }

    if (descriptor.crossOrigin) {
      link.setAttribute("crossorigin", descriptor.crossOrigin);
    }

    document.head.appendChild(link);
    runtime.navigationPreloadHints.add(descriptor.key);
  }

  function trackPrefetchInterest(element, url) {
    if (!element) {
      return normalizeNavigationUrl(url);
    }

    const normalizedUrl = normalizeNavigationUrl(url);
    const previousUrl = runtime.navigationPrefetchElements.get(element);

    if (previousUrl === normalizedUrl) {
      return normalizedUrl;
    }

    if (previousUrl) {
      releasePrefetchInterest(element);
    }

    runtime.navigationPrefetchElements.set(element, normalizedUrl);
    runtime.navigationPrefetchTrackedElements.add(element);
    runtime.navigationPrefetchInterest.set(
      normalizedUrl,
      (runtime.navigationPrefetchInterest.get(normalizedUrl) || 0) + 1,
    );

    return normalizedUrl;
  }

  function cancelPrefetch(url) {
    const normalizedUrl = normalizeNavigationUrl(url);
    const entry = runtime.navigationInFlight.get(normalizedUrl);

    if (
      !entry ||
      entry.source !== "prefetch" ||
      entry.retained ||
      !entry.controller
    ) {
      return false;
    }

    entry.controller.abort();
    return true;
  }

  function releasePrefetchInterest(element) {
    if (!element) {
      return;
    }

    const url = runtime.navigationPrefetchElements.get(element);

    if (!url) {
      return;
    }

    runtime.navigationPrefetchElements.delete(element);
    runtime.navigationPrefetchTrackedElements.delete(element);

    const nextCount = (runtime.navigationPrefetchInterest.get(url) || 0) - 1;

    if (nextCount > 0) {
      runtime.navigationPrefetchInterest.set(url, nextCount);
      return;
    }

    runtime.navigationPrefetchInterest.delete(url);
    cancelPrefetch(url);
  }

  function preloadCriticalHeadAssets(nextHead) {
    if (!nextHead || !nextHead.children) {
      return;
    }

    Array.from(nextHead.children).forEach(function (node) {
      const descriptor = preloadDescriptorFromHeadNode(node);

      if (descriptor) {
        ensurePreloadHint(descriptor);
      }
    });
  }

  function requestNavigationPayload(url, signal, source, options) {
    const normalizedUrl = normalizeNavigationUrl(url);
    const settings = options && typeof options === "object" ? options : {};
    const requestedControl =
      settings.cacheControl && typeof settings.cacheControl === "object"
        ? settings.cacheControl
        : parseNavigationCacheControl("", "default");
    const requestedMode =
      settings.navigationMode && typeof settings.navigationMode === "object"
        ? settings.navigationMode
        : parseNavigationMode("", "default");

    if (runtime.navigationInFlight.has(normalizedUrl)) {
      const existing = runtime.navigationInFlight.get(normalizedUrl);

      if (
        existing &&
        existing.source === "prefetch" &&
        existing.controller &&
        (requestedControl.mode === "reload" ||
          requestedControl.mode === "invalidate" ||
          requestedControl.mode === "no-store")
      ) {
        existing.controller.abort();
        runtime.navigationInFlight.delete(normalizedUrl);
      } else {
        if (source !== "prefetch") {
          existing.retained = true;
          existing.source = "navigate";
        }

        return existing.promise.then(function (payload) {
          if (!shouldStoreNavigationCache(requestedControl)) {
            invalidateNavigationCache(normalizedUrl, "no-store", {
              finalUrl:
                payload && payload.finalUrl ? payload.finalUrl : normalizedUrl,
            });
          }

          return payload;
        });
      }
    }

    const prefetchController =
      source === "prefetch" && typeof AbortController === "function"
        ? new AbortController()
        : null;
    const requestSignal = prefetchController
      ? prefetchController.signal
      : signal;
    const entry = {
      promise: null,
      controller: prefetchController,
      source: source || "navigate",
      retained: source !== "prefetch",
    };
    const promise = requestPage(normalizedUrl, requestSignal)
      .then(function (payload) {
        if (payload && payload.error && typeof payload.error === "object") {
          return payload;
        }

        const responseControl = navigationCacheControlForDocument(
          payload.document,
        );
        const responseMode = navigationModeForDocument(payload.document);
        const responseDocumentContract = documentContractForDocument(
          payload.document,
        );
        const responseHydrate = hydrationForDocument(payload.document);
        const responsePageTransition = pageTransitionForDocument(
          payload.document,
        );
        const responseLayout = documentLayoutIdentity(payload.document);
        const spaNavigation =
          payload.spaNavigation && typeof payload.spaNavigation === "object"
            ? payload.spaNavigation
            : null;
        const spaTransition = spaNavigationPageTransition(spaNavigation);
        const spaHydrate = spaNavigationHydrate(spaNavigation);
        const spaLayout = spaNavigationLayout(spaNavigation);
        const spaNavigationMode = spaNavigationNavigationMode(spaNavigation);
        const spaDocumentContract =
          spaNavigationDocumentContract(spaNavigation);
        const responseRedirect =
          spaNavigation &&
          spaNavigation.redirect &&
          typeof spaNavigation.redirect === "object" &&
          typeof spaNavigation.redirect.location === "string" &&
          spaNavigation.redirect.location !== ""
            ? normalizeNavigationUrl(spaNavigation.redirect.location)
            : payload.finalUrl && payload.finalUrl !== normalizedUrl
            ? payload.finalUrl
            : null;
        const effectiveControl = mergeNavigationCacheControl(
          requestedControl,
          responseControl,
        );
        const enrichedPayload = Object.assign({}, payload, {
          target:
            spaNavigation &&
            spaNavigation.navigation &&
            typeof spaNavigation.navigation === "object" &&
            typeof spaNavigation.navigation.target === "string" &&
            spaNavigation.navigation.target !== ""
              ? normalizeNavigationUrl(spaNavigation.navigation.target)
              : normalizedUrl,
          cacheControl: effectiveControl,
          redirect: responseRedirect,
          layout: responseLayout || spaLayout,
          navigationMode:
            responseMode.mode !== "auto"
              ? responseMode
              : spaNavigationMode && spaNavigationMode.mode !== "auto"
                ? spaNavigationMode
                : requestedMode,
          documentContract:
            responseDocumentContract.mode !== "auto"
              ? responseDocumentContract
              : spaDocumentContract && spaDocumentContract.mode !== "auto"
                ? spaDocumentContract
                : responseDocumentContract,
          hydrate:
            responseHydrate && responseHydrate.declared ? responseHydrate : spaHydrate || responseHydrate,
          pageTransition:
            responsePageTransition && responsePageTransition.declared
              ? responsePageTransition
              : spaTransition || responsePageTransition,
          spaNavigation: spaNavigation,
        });

        if (
          source === "prefetch" &&
          enrichedPayload.document &&
          enrichedPayload.document.head
        ) {
          preloadCriticalHeadAssets(enrichedPayload.document.head);
        }

        const cached = setCachedNavigation(
          normalizedUrl,
          enrichedPayload,
          source || "navigate",
          effectiveControl,
        );
        return cached || enrichedPayload;
      })
      .finally(function () {
        runtime.navigationInFlight.delete(normalizedUrl);
      });

    entry.promise = promise;
    runtime.navigationInFlight.set(normalizedUrl, entry);

    return entry.promise;
  }

  function prefetchPage(url, options) {
    const normalizedUrl = normalizeNavigationUrl(url);
    const settings = options && typeof options === "object" ? options : {};
    const cacheControl =
      settings.cacheControl && typeof settings.cacheControl === "object"
        ? settings.cacheControl
        : parseNavigationCacheControl("", "default");
    const navigationMode =
      settings.navigationMode && typeof settings.navigationMode === "object"
        ? settings.navigationMode
        : parseNavigationMode("", "default");

    if (
      !shouldPrefetchNavigation(cacheControl) ||
      !shouldPrefetchForNavigationMode(navigationMode)
    ) {
      return Promise.resolve(null);
    }

    if (cacheControl.mode === "reload" || cacheControl.mode === "invalidate") {
      invalidateNavigationCache(normalizedUrl, cacheControl.mode, {
        source: "prefetch",
      });
    }

    if (shouldReadNavigationCache(cacheControl)) {
      const cached = getCachedNavigation(normalizedUrl);

      if (cached) {
        emitNavigationCacheEvent("volt:cache-hit", {
          url: normalizedUrl,
          finalUrl: cached.finalUrl,
          source: "prefetch",
          mode: cacheControl.mode,
        });

        return Promise.resolve(cached);
      }
    }

    emitNavigationCacheEvent("volt:cache-miss", {
      url: normalizedUrl,
      source: "prefetch",
      mode: cacheControl.mode,
    });

    return resolveFrontendManifestRoute(normalizedUrl, "GET").then(function (route) {
      if (route && !frontendManifestRouteAllowsPrefetch(route)) {
        emitNavigationCacheEvent("volt:cache-skip", {
          url: normalizedUrl,
          source: "prefetch",
          reason: "manifest-prefetch-disabled",
        });
        return null;
      }

      return requestNavigationPayload(normalizedUrl, undefined, "prefetch", {
        cacheControl: cacheControl,
        navigationMode: navigationMode,
      });
    });
  }

  function hasNavigationCacheOrFlight(url) {
    const normalizedUrl = normalizeNavigationUrl(url);
    return (
      !!getCachedNavigation(normalizedUrl) ||
      runtime.navigationInFlight.has(normalizedUrl)
    );
  }

  function navigationVisitCacheControl(options) {
    const settings = options && typeof options === "object" ? options : {};
    const optionControl =
      settings.cacheControl && typeof settings.cacheControl === "object"
        ? settings.cacheControl
        : null;
    const triggerControl = navigationCacheControlForElement(
      settings.trigger || null,
    );

    return mergeNavigationCacheControl(triggerControl, optionControl);
  }

