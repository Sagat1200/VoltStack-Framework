
  document.addEventListener("input", function (event) {
    handleOnDirectiveEvent("input", event);

    const element = closestFromEventTarget(event, "input, textarea, select");

    if (!element) {
      return;
    }

    const root = findRoot(element);

    if (!root) {
      return;
    }

    const component = root.getAttribute("data-volt-component");
    const snapshot = readSnapshot(root);
    const key = directiveValue(element, ["volt-model", "volt:model"]);

    if (snapshot && snapshot.state && key) {
      snapshot.state[key] =
        element.type === "checkbox" ? !!element.checked : element.value;
      root.setAttribute("data-volt-snapshot", JSON.stringify(snapshot));
    }

    updateModelLocalDirectiveFromElement(element, "directive:model.local:input");
    updateModelSyncDirectiveFromElement(element, root, "directive:model.sync:input");

    scheduleDirtyDebounce(root, {
      component: component,
      target: fieldStateTarget(element),
    });
    setSuccessState(root, false, {
      component: component,
      target: fieldStateTarget(element),
    });
  });

  document.addEventListener("click", function (event) {
    handleOnDirectiveEvent("click", event);

    const actionTrigger = closestFromEventTarget(
      event,
      "[volt-click], [volt\\:click]",
    );

    if (actionTrigger) {
      const root = findRoot(actionTrigger);

      if (!root) {
        return;
      }

      event.preventDefault();

      const params = directiveValue(actionTrigger, [
        "volt-params",
        "volt:params",
      ]);
      dispatchAction(
        root,
        directiveValue(actionTrigger, ["volt-click", "volt:click"]),
        params ? JSON.parse(params) : {},
        collectModelUpdates(root),
        actionTrigger,
      ).catch(function (error) {
        console.error("VoltStack runtime error:", error);
      });

      return;
    }

    const dispatchTrigger = closestFromEventTarget(
      event,
      "[volt-dispatch], [volt\\:dispatch]",
    );

    if (dispatchTrigger) {
      emitDispatchDirective(
        dispatchTrigger,
        findRoot(dispatchTrigger),
        directiveValue(dispatchTrigger, dispatchDirectiveNames()),
        event,
      );
    }

    const navigationTrigger = closestFromEventTarget(
      event,
      "a[volt-navigate], a[volt\\:navigate]",
    );

    if (!shouldHandleNavigation(event, navigationTrigger)) {
      return;
    }

    const url = new URL(navigationTrigger.href, window.location.href);
    const navigationMode = navigationModeForElement(navigationTrigger);

    if (navigationMode.mode === "reload") {
      return;
    }

    event.preventDefault();

    const preserveScroll =
      navigationTrigger.hasAttribute("volt-preserve-scroll") ||
      navigationTrigger.hasAttribute("volt:preserve-scroll");
    const replace =
      navigationTrigger.hasAttribute("volt-replace") ||
      navigationTrigger.hasAttribute("volt:replace");

    visit(url.toString(), {
      trigger: navigationTrigger,
      queueIfBusy: true,
      preserveScroll: preserveScroll,
      historyMode: replace ? "replace" : "push",
      navigationMode: navigationMode,
      pageTransition: pageTransitionForElement(navigationTrigger),
    }).catch(function (error) {
      console.error("VoltStack navigation error:", error);
    });
  });

  document.addEventListener(
    "pointerenter",
    function (event) {
      const navigationTrigger = closestFromEventTarget(
        event,
        NAVIGATION_PREFETCH_SELECTOR,
      );

      if (
        !navigationTrigger ||
        !navigationTrigger.href ||
        !linkAllowsPrefetchSource(navigationTrigger, "intent") ||
        !shouldPrefetchForNavigationMode(
          navigationModeForElement(navigationTrigger),
        )
      ) {
        return;
      }

      const url = trackPrefetchInterest(
        navigationTrigger,
        navigationTrigger.href,
      );

      prefetchPage(url, {
        cacheControl: navigationCacheControlForElement(navigationTrigger),
        navigationMode: navigationModeForElement(navigationTrigger),
      }).catch(function () {
        return null;
      });
    },
    true,
  );

  document.addEventListener(
    "pointerleave",
    function (event) {
      const navigationTrigger = closestFromEventTarget(
        event,
        NAVIGATION_PREFETCH_SELECTOR,
      );

      if (!navigationTrigger) {
        return;
      }

      releasePrefetchInterest(navigationTrigger);
    },
    true,
  );

  document.addEventListener("focusin", function (event) {
    handleOnDirectiveEvent("focus", event);

    const navigationTrigger = closestFromEventTarget(
      event,
      NAVIGATION_PREFETCH_SELECTOR,
    );

    if (
      !navigationTrigger ||
      !navigationTrigger.href ||
      !linkAllowsPrefetchSource(navigationTrigger, "intent") ||
      !shouldPrefetchForNavigationMode(
        navigationModeForElement(navigationTrigger),
      )
    ) {
      return;
    }

    const url = trackPrefetchInterest(
      navigationTrigger,
      navigationTrigger.href,
    );

    prefetchPage(url, {
      cacheControl: navigationCacheControlForElement(navigationTrigger),
      navigationMode: navigationModeForElement(navigationTrigger),
    }).catch(function () {
      return null;
    });
  });

  document.addEventListener(
    "volt:navigation-cache-invalidate",
    function (event) {
      const detail =
        event && event.detail && typeof event.detail === "object"
          ? event.detail
          : {};

      if (typeof detail.url === "string" && detail.url !== "") {
        invalidateNavigationCache(detail.url, detail.reason || "event", {
          source: detail.source || "event",
        });
        return;
      }

      clearNavigationCache(detail.reason || "event", {
        source: detail.source || "event",
      });
    },
  );

  document.addEventListener("focusout", function (event) {
    handleOnDirectiveEvent("blur", event);

    const navigationTrigger = closestFromEventTarget(
      event,
      NAVIGATION_PREFETCH_SELECTOR,
    );

    if (!navigationTrigger) {
      return;
    }

    releasePrefetchInterest(navigationTrigger);
  });

  document.addEventListener("submit", function (event) {
    handleOnDirectiveEvent("submit", event);

    const form = closestFromEventTarget(
      event,
      "form[volt-submit], form[volt\\:submit]",
    );

    if (!form) {
      return;
    }

    const root = findRoot(form);

    if (!root) {
      return;
    }

    event.preventDefault();

    dispatchAction(
      root,
      directiveValue(form, ["volt-submit", "volt:submit"]),
      collectFormData(form),
      collectModelUpdates(root),
      form,
    ).catch(function (error) {
      console.error("VoltStack runtime error:", error);
    });
  });

  document.addEventListener("change", function (event) {
    handleOnDirectiveEvent("change", event);

    const element = closestFromEventTarget(event, "input, textarea, select");

    if (!element) {
      return;
    }

    const root = findRoot(element);

    updateModelLocalDirectiveFromElement(element, "directive:model.local:change");
    updateModelSyncDirectiveFromElement(element, root, "directive:model.sync:change");
  });

  document.addEventListener("keydown", function (event) {
    handleOnDirectiveEvent("keydown", event);
  });

  document.addEventListener("keyup", function (event) {
    handleOnDirectiveEvent("keyup", event);
  });

  window.addEventListener("popstate", function () {
    visit(window.location.href, {
      updateHistory: false,
      historyMode: "replace",
      preserveScroll: false,
      fallback: false,
    }).catch(function (error) {
      console.error("VoltStack navigation error:", error);
      window.location.reload();
    });
  });

  runtime.clientStateScope = normalizeNavigationUrl(window.location.href);
  window.Volt =
    window.Volt && typeof window.Volt === "object" ? window.Volt : {};
  window.Volt.contract = createPublicRuntimeContract();
  window.Volt.visit = function (url, options) {
    return visit(url, options || {});
  };
  window.Volt.prefetch = function (url, options) {
    return prefetchPage(url, options || {});
  };
  window.Volt.on = createPublicOnFunction();
  window.Volt.busy = createPublicBusyApi();
  window.Volt.state = createPublicStateApi();
  window.Volt.directives = createPublicDirectivesApi();
  window.Volt.components = createPublicComponentsApi();
  window.Volt.telemetry = createPublicTelemetryApi();

  function bootRuntimeDocumentFeatures() {
    const measureNow =
      typeof performance !== "undefined" &&
      typeof performance.now === "function"
        ? () => performance.now()
        : () => Date.now();
    const bootStartedAt = measureNow();
    runtime.bootInvocationCount += 1;
    const busyStartedAt = measureNow();
    resolveGlobalBusyState({
      source: "boot",
      phase: "idle",
      requestId: null,
    });
    const busyDurationMs = measureNow() - busyStartedAt;
    const syncStartedAt = measureNow();
    syncAllRuntimeStateDirectives();
    const syncDurationMs = measureNow() - syncStartedAt;
    const refreshStartedAt = measureNow();
    refreshActiveComponentsRegistry("boot");
    const refreshDurationMs = measureNow() - refreshStartedAt;
    const viewportPrefetchStartedAt = measureNow();
    registerViewportPrefetchTargets(document);
    const viewportPrefetchDurationMs = measureNow() - viewportPrefetchStartedAt;
    const heuristicPrefetchStartedAt = measureNow();
    scheduleHeuristicPrefetch(document);
    const heuristicPrefetchDurationMs = measureNow() - heuristicPrefetchStartedAt;
    const totalDurationMs = measureNow() - bootStartedAt;
    const rootCount = document.querySelectorAll('[data-volt-root="true"]').length;
    runtime.bootMetrics = {
      invocationCount: runtime.bootInvocationCount,
      readyState: document.readyState,
      busyDurationMs: Number(busyDurationMs.toFixed(2)),
      syncDurationMs: Number(syncDurationMs.toFixed(2)),
      refreshDurationMs: Number(refreshDurationMs.toFixed(2)),
      viewportPrefetchDurationMs: Number(
        viewportPrefetchDurationMs.toFixed(2),
      ),
      heuristicPrefetchDurationMs: Number(
        heuristicPrefetchDurationMs.toFixed(2),
      ),
      totalDurationMs: Number(totalDurationMs.toFixed(2)),
      rootCount: rootCount,
      recordedAt: new Date().toISOString(),
    };
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootRuntimeDocumentFeatures, {
      once: true,
    });
  }

  bootRuntimeDocumentFeatures();
})();
