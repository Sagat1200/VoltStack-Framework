  function sameOrigin(url) {
    return url.origin === window.location.origin;
  }

  function shouldHandleNavigation(event, link) {
    if (!link) {
      return false;
    }

    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return false;
    }

    if (link.hasAttribute("download")) {
      return false;
    }

    const target = link.getAttribute("target");

    if (target && target !== "" && target.toLowerCase() !== "_self") {
      return false;
    }

    const href = link.getAttribute("href");

    if (!href || href.startsWith("#")) {
      return false;
    }

    const url = new URL(href, window.location.href);

    if (!sameOrigin(url)) {
      return false;
    }

    if (documentContractForDocument(document).mode === "reload") {
      return false;
    }

    return true;
  }

  function setNavigationState(active, trigger) {
    document.documentElement.setAttribute(
      "data-volt-navigating",
      active ? "true" : "false",
    );
    document.documentElement.setAttribute(
      "aria-busy",
      active ? "true" : "false",
    );

    if (document.body) {
      document.body.setAttribute(
        "data-volt-navigating",
        active ? "true" : "false",
      );
      document.body.setAttribute("aria-busy", active ? "true" : "false");
    }

    if (trigger && "disabled" in trigger) {
      trigger.disabled = active;
    }

    resolveGlobalBusyState({
      source: "navigation",
      phase: active ? "request-start" : "request-finish",
      requestId: active ? runtime.navigationRequestId : null,
    });
  }

  function replaceBodyAttributes(nextBody) {
    const currentBody = document.body;
    const attributeNames = currentBody.getAttributeNames();

    attributeNames.forEach(function (name) {
      currentBody.removeAttribute(name);
    });

    nextBody.getAttributeNames().forEach(function (name) {
      currentBody.setAttribute(name, nextBody.getAttribute(name) || "");
    });
  }

  function applyHydrationFallbackToBody(payloadHydrate) {
    if (!document.body || !payloadHydrate || typeof payloadHydrate !== "object") {
      return;
    }

    const currentHydration = hydrationForDocument(document);

    if (currentHydration && currentHydration.declared) {
      return;
    }

    if (typeof payloadHydrate.enabled === "boolean") {
      document.body.setAttribute(
        "data-volt-hydrate",
        payloadHydrate.enabled ? "true" : "false",
      );
    }

    if (
      typeof payloadHydrate.strategy === "string" &&
      payloadHydrate.strategy !== ""
    ) {
      document.body.setAttribute(
        "data-volt-hydrate-strategy",
        payloadHydrate.strategy,
      );
    } else {
      document.body.removeAttribute("data-volt-hydrate-strategy");
    }

    if (
      typeof payloadHydrate.dirtyState === "string" &&
      payloadHydrate.dirtyState !== ""
    ) {
      document.body.setAttribute(
        "data-volt-hydrate-dirty-state",
        payloadHydrate.dirtyState,
      );
    } else {
      document.body.removeAttribute("data-volt-hydrate-dirty-state");
    }
  }

  function preservedFragmentAttribute(element) {
    return directiveAttribute(element, [
      "data-volt-preserve",
      "volt-preserve",
      "volt:preserve",
    ]);
  }

  function persistedFragmentAttribute(element) {
    return directiveAttribute(element, [
      "data-volt-persist",
      "volt-persist",
      "volt:persist",
    ]);
  }

  function preservedFragmentKey(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    const attribute = preservedFragmentAttribute(element);

    if (!attribute) {
      return null;
    }

    const explicitKey = (attribute.value || "").trim();

    if (explicitKey !== "") {
      return explicitKey;
    }

    const id = (element.getAttribute("id") || "").trim();

    if (id !== "") {
      return id;
    }

    const target = (element.getAttribute("data-volt-target") || "").trim();

    return target !== "" ? target : null;
  }

  function persistedFragmentKey(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    const attribute = persistedFragmentAttribute(element);

    if (!attribute) {
      return null;
    }

    const explicitKey = (attribute.value || "").trim();

    if (explicitKey !== "") {
      return explicitKey;
    }

    const id = (element.getAttribute("id") || "").trim();

    if (id !== "") {
      return id;
    }

    const target = (element.getAttribute("data-volt-target") || "").trim();

    return target !== "" ? target : null;
  }

  function retainedFragmentCandidates(root, selector) {
    if (!root || typeof root.querySelectorAll !== "function") {
      return [];
    }

    const candidates = [];

    if (typeof root.matches === "function" && root.matches(selector)) {
      candidates.push(root);
    }

    root.querySelectorAll(selector).forEach(function (element) {
      candidates.push(element);
    });

    return candidates.filter(function (element) {
      const parent = element.parentElement;

      return !parent || !parent.closest(NAVIGATION_RETAINED_SELECTOR);
    });
  }

  function preservedFragmentCandidates(root) {
    return retainedFragmentCandidates(root, NAVIGATION_FRAGMENT_SELECTOR).filter(
      function (element) {
        return !persistedFragmentAttribute(element);
      },
    );
  }

  function persistedFragmentCandidates(root) {
    return retainedFragmentCandidates(root, NAVIGATION_PERSIST_SELECTOR);
  }

  function fragmentNavigationDetail(meta, extra) {
    return Object.assign(
      {
        source: meta && meta.source ? meta.source : "navigate",
        url: meta && meta.url ? meta.url : window.location.href,
        finalUrl: meta && meta.finalUrl ? meta.finalUrl : null,
      },
      extra || {},
    );
  }

  function discardPreservedFragments(fragments, meta, reason, extra) {
    if (!(fragments instanceof Map) || fragments.size === 0) {
      return {
        preservedCount: 0,
        discardedCount: 0,
      };
    }

    let discardedCount = 0;

    fragments.forEach(function (fragment) {
      discardedCount += 1;
      emitRuntimeHook(
        "volt:fragment-discard",
        fragmentNavigationDetail(
          meta,
          Object.assign(
            {
              key: fragment.key,
              tagName: fragment.tagName,
              reason: reason,
            },
            extra || {},
          ),
        ),
        document,
      );
    });

    return {
      preservedCount: 0,
      discardedCount: discardedCount,
    };
  }

  function shouldRestorePreservedFragments(control, meta) {
    const fragmentMode = control && control.mode ? control.mode : "preserve";

    if (fragmentMode === "reset") {
      return false;
    }

    const cacheControl =
      meta && meta.cacheControl && typeof meta.cacheControl === "object"
        ? meta.cacheControl
        : null;
    const cacheMode =
      cacheControl && cacheControl.mode ? cacheControl.mode : "default";

    return cacheMode !== "no-store";
  }

  function capturePreservedFragments(root, meta) {
    const fragments = new Map();

    preservedFragmentCandidates(root).forEach(function (element) {
      const key = preservedFragmentKey(element);

      if (!key) {
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            reason: "missing-key",
            tagName: element.tagName ? element.tagName.toLowerCase() : null,
          }),
          document,
        );
        return;
      }

      if (fragments.has(key)) {
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            key: key,
            reason: "duplicate-source",
            tagName: element.tagName ? element.tagName.toLowerCase() : null,
          }),
          document,
        );
        return;
      }

      fragments.set(key, {
        key: key,
        tagName: element.tagName ? element.tagName.toLowerCase() : null,
        element: element,
      });
    });

    return fragments;
  }

  function preservedFragmentTargets(root, meta) {
    const targets = new Map();

    preservedFragmentCandidates(root).forEach(function (element) {
      const key = preservedFragmentKey(element);

      if (!key) {
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            reason: "missing-target-key",
            tagName: element.tagName ? element.tagName.toLowerCase() : null,
          }),
          document,
        );
        return;
      }

      if (targets.has(key)) {
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            key: key,
            reason: "duplicate-target",
            tagName: element.tagName ? element.tagName.toLowerCase() : null,
          }),
          document,
        );
        return;
      }

      targets.set(key, element);
    });

    return targets;
  }

  function restorePreservedFragments(root, fragments, meta) {
    if (!root || !(fragments instanceof Map) || fragments.size === 0) {
      return {
        preservedCount: 0,
        discardedCount: 0,
      };
    }

    const targets = preservedFragmentTargets(root, meta);
    let preservedCount = 0;
    let discardedCount = 0;

    fragments.forEach(function (fragment) {
      const target = targets.get(fragment.key);

      if (!target) {
        discardedCount += 1;
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            key: fragment.key,
            tagName: fragment.tagName,
            reason: "missing-target",
          }),
          document,
        );
        return;
      }

      const targetTagName = target.tagName
        ? target.tagName.toLowerCase()
        : null;

      if (targetTagName !== fragment.tagName) {
        discardedCount += 1;
        emitRuntimeHook(
          "volt:fragment-discard",
          fragmentNavigationDetail(meta, {
            key: fragment.key,
            tagName: fragment.tagName,
            targetTagName: targetTagName,
            reason: "tag-mismatch",
          }),
          document,
        );
        return;
      }

      target.replaceWith(fragment.element);
      preservedCount += 1;

      emitRuntimeHook(
        "volt:fragment-preserve",
        fragmentNavigationDetail(meta, {
          key: fragment.key,
          tagName: fragment.tagName,
        }),
        document,
      );
    });

    return {
      preservedCount: preservedCount,
      discardedCount: discardedCount,
    };
  }

  function capturePersistentFragments(root) {
    const captured = new Map();
    let discardedCount = 0;

    persistedFragmentCandidates(root).forEach(function (element) {
      const key = persistedFragmentKey(element);

      if (!key) {
        discardedCount += 1;
        return;
      }

      if (captured.has(key)) {
        discardedCount += 1;
        return;
      }

      captured.set(key, {
        key: key,
        tagName: element.tagName ? element.tagName.toLowerCase() : null,
        element: element,
      });
    });

    captured.forEach(function (fragment, key) {
      runtime.persistentFragments.set(key, fragment);
    });

    return {
      capturedCount: captured.size,
      discardedCount: discardedCount,
      registrySize: runtime.persistentFragments.size,
    };
  }

  function persistentFragmentTargets(root) {
    const targets = new Map();

    persistedFragmentCandidates(root).forEach(function (element) {
      const key = persistedFragmentKey(element);

      if (!key || targets.has(key)) {
        return;
      }

      targets.set(key, element);
    });

    return targets;
  }

  function discardPersistentFragments() {
    const discardedCount = runtime.persistentFragments.size;

    runtime.persistentFragments.clear();

    return {
      persistedCount: 0,
      discardedCount: discardedCount,
      registrySize: 0,
    };
  }

  function restorePersistentFragments(root) {
    if (
      !root ||
      !(runtime.persistentFragments instanceof Map) ||
      runtime.persistentFragments.size === 0
    ) {
      return {
        persistedCount: 0,
        discardedCount: 0,
        registrySize:
          runtime.persistentFragments instanceof Map
            ? runtime.persistentFragments.size
            : 0,
      };
    }

    const targets = persistentFragmentTargets(root);
    let persistedCount = 0;
    let discardedCount = 0;

    targets.forEach(function (target, key) {
      const fragment = runtime.persistentFragments.get(key);

      if (!fragment) {
        return;
      }

      const targetTagName = target.tagName
        ? target.tagName.toLowerCase()
        : null;

      if (targetTagName !== fragment.tagName) {
        runtime.persistentFragments.delete(key);
        discardedCount += 1;
        return;
      }

      if (fragment.element !== target) {
        target.replaceWith(fragment.element);
      }

      runtime.persistentFragments.set(key, {
        key: key,
        tagName: fragment.tagName,
        element: fragment.element,
      });
      persistedCount += 1;
    });

    return {
      persistedCount: persistedCount,
      discardedCount: discardedCount,
      registrySize: runtime.persistentFragments.size,
    };
  }

  function currentLayoutIdentity() {
    if (document.body) {
      const bodyLayout = document.body.getAttribute("data-volt-layout");

      if (bodyLayout) {
        return bodyLayout;
      }
    }

    if (document.documentElement) {
      const documentLayout =
        document.documentElement.getAttribute("data-volt-layout");

      if (documentLayout) {
        return documentLayout;
      }
    }

    return null;
  }

  function documentLayoutIdentity(doc) {
    if (!doc || typeof doc !== "object") {
      return null;
    }

    if (doc.body) {
      const bodyLayout = doc.body.getAttribute("data-volt-layout");

      if (bodyLayout) {
        return bodyLayout;
      }
    }

    if (doc.documentElement) {
      const documentLayout =
        doc.documentElement.getAttribute("data-volt-layout");

      if (documentLayout) {
        return documentLayout;
      }
    }

    return null;
  }

  function shouldFallbackForLayoutChange(doc, nextLayoutHint) {
    const currentLayout = currentLayoutIdentity();
    const hintedLayout =
      typeof nextLayoutHint === "string" && nextLayoutHint !== ""
        ? nextLayoutHint
        : null;
    const nextLayout = documentLayoutIdentity(doc) || hintedLayout;

    // Un layout ausente ya no invalida por si solo la navegacion SPA.
    // Solo hacemos fallback cuando ambos documentos declaran una identidad
    // explicita y realmente cambian entre si.
    if (!currentLayout || !nextLayout) {
      return false;
    }

    return currentLayout !== nextLayout;
  }

  function setElementAttributes(target, source) {
    const nextAttributes = {};

    source.getAttributeNames().forEach(function (name) {
      nextAttributes[name] = source.getAttribute(name) || "";
    });

    target.getAttributeNames().forEach(function (name) {
      if (!Object.prototype.hasOwnProperty.call(nextAttributes, name)) {
        target.removeAttribute(name);
      }
    });

    Object.keys(nextAttributes).forEach(function (name) {
      target.setAttribute(name, nextAttributes[name]);
    });
  }

  function managedHeadNodeKey(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }

    const explicitKey = node.getAttribute("data-volt-head-key");

    if (explicitKey) {
      return "explicit:" + explicitKey;
    }

    const tag = node.tagName.toLowerCase();

    if (tag === "meta") {
      if (node.hasAttribute("name")) {
        return "meta:name:" + (node.getAttribute("name") || "");
      }

      if (node.hasAttribute("property")) {
        return "meta:property:" + (node.getAttribute("property") || "");
      }

      if (node.hasAttribute("http-equiv")) {
        return "meta:http-equiv:" + (node.getAttribute("http-equiv") || "");
      }

      return null;
    }

    if (tag === "link") {
      const rel = (node.getAttribute("rel") || "").toLowerCase();
      const href = node.getAttribute("href") || "";

      if (!rel || !href) {
        return null;
      }

      return "link:" + rel + ":" + href + ":" + (node.getAttribute("as") || "");
    }

    if (tag === "script") {
      const src = node.getAttribute("src") || "";

      if (!src) {
        return null;
      }

      return "script:" + (node.getAttribute("type") || "") + ":" + src;
    }

    if (tag === "style") {
      const styleId = node.getAttribute("id") || "";

      if (styleId) {
        return "style:id:" + styleId;
      }
    }

    return null;
  }

  function managedHeadEntries(head) {
    if (!head || !head.children) {
      return [];
    }

    const order = [];
    const map = new Map();

    Array.from(head.children).forEach(function (node) {
      const key = managedHeadNodeKey(node);

      if (!key) {
        return;
      }

      if (!map.has(key)) {
        order.push(key);
      }

      map.set(key, node);
    });

    return order.map(function (key) {
      return {
        key: key,
        node: map.get(key),
      };
    });
  }

  function syncManagedHeadNode(currentNode, nextNode) {
    if (!currentNode || !nextNode) {
      return;
    }

    setElementAttributes(currentNode, nextNode);

    const tag = currentNode.tagName.toLowerCase();

    if (tag === "script" || tag === "style") {
      const nextContent = nextNode.textContent || "";

      if (currentNode.textContent !== nextContent) {
        currentNode.textContent = nextContent;
      }
    }
  }

  function waitForManagedHeadNode(node) {
    if (!node || node.tagName.toLowerCase() !== "link") {
      return Promise.resolve();
    }

    const rel = (node.getAttribute("rel") || "").toLowerCase();

    if (rel !== "stylesheet") {
      return Promise.resolve();
    }

    return new Promise(function (resolve) {
      let settled = false;

      function finish() {
        if (settled) {
          return;
        }

        settled = true;
        resolve();
      }

      if (node.sheet) {
        finish();
        return;
      }

      node.addEventListener("load", finish, { once: true });
      node.addEventListener("error", finish, { once: true });
      window.setTimeout(finish, 1500);
    });
  }

  async function reconcileDocumentHead(nextHead) {
    if (!nextHead || !document.head) {
      return;
    }

    const nextEntries = managedHeadEntries(nextHead);
    const nextMap = new Map();
    const currentMap = new Map();
    const currentEntries = [];
    const pendingLoads = [];
    const hasManagedHead = nextEntries.length > 0;

    nextEntries.forEach(function (entry) {
      nextMap.set(entry.key, entry.node);
    });

    Array.from(document.head.children).forEach(function (node) {
      const key = managedHeadNodeKey(node);

      if (!key) {
        return;
      }

      if (currentMap.has(key)) {
        node.remove();
        return;
      }

      currentMap.set(key, node);
      currentEntries.push({
        key: key,
        node: node,
      });
    });

    if (hasManagedHead) {
      currentEntries.forEach(function (entry) {
        if (!nextMap.has(entry.key)) {
          entry.node.remove();
        }
      });
    }

    nextEntries.forEach(function (entry) {
      const existing = currentMap.get(entry.key);

      if (existing) {
        syncManagedHeadNode(existing, entry.node);
        return;
      }

      const clone = entry.node.cloneNode(true);
      document.head.appendChild(clone);
      currentMap.set(entry.key, clone);
      pendingLoads.push(waitForManagedHeadNode(clone));
    });

    if (pendingLoads.length > 0) {
      await Promise.all(pendingLoads);
    }
  }

  async function applyDocumentPayload(doc, meta) {
    const payloadMeta = meta && typeof meta === "object" ? meta : {};
    const fragmentControl = fragmentControlForDocument(doc);
    const pageTransition =
      payloadMeta.pageTransition || parsePageTransition("", "default");
    const payloadHydrate =
      payloadMeta.hydrate && typeof payloadMeta.hydrate === "object"
        ? payloadMeta.hydrate
        : null;
    const fragmentSummary = {
      preservedCount: 0,
      discardedCount: 0,
      persistedCount: 0,
      discardedPersistentCount: 0,
      capturedPersistentCount: 0,
      persistentRegistrySize: runtime.persistentFragments.size,
    };

    if (doc.title) {
      document.title = doc.title;
    }

    if (doc.head) {
      await reconcileDocumentHead(doc.head);
    }

    if (doc.body) {
      const fragmentMeta = Object.assign({}, payloadMeta, {
        fragmentControl: fragmentControl,
      });
      const capturedPersistent = shouldRestorePreservedFragments(
        fragmentControl,
        payloadMeta,
      )
        ? capturePersistentFragments(document.body)
        : discardPersistentFragments();
      const preservedFragments = capturePreservedFragments(
        document.body,
        fragmentMeta,
      );
      replaceBodyAttributes(doc.body);
      document.body.innerHTML = doc.body.innerHTML;
      applyHydrationFallbackToBody(payloadHydrate);
      resolveGlobalBusyState({
        source: "navigation",
      });
      const restoredPersistent = shouldRestorePreservedFragments(
        fragmentControl,
        payloadMeta,
      )
        ? restorePersistentFragments(document.body)
        : discardPersistentFragments();
      const restoredFragments = shouldRestorePreservedFragments(
        fragmentControl,
        payloadMeta,
      )
        ? restorePreservedFragments(
            document.body,
            preservedFragments,
            fragmentMeta,
          )
        : discardPreservedFragments(
            preservedFragments,
            fragmentMeta,
            fragmentControl.mode === "reset"
              ? "document-policy"
              : "navigation-policy",
            {
              policyMode: fragmentControl.mode,
              policySource: fragmentControl.source,
              cacheMode:
                payloadMeta.cacheControl && payloadMeta.cacheControl.mode
                  ? payloadMeta.cacheControl.mode
                  : "default",
            },
          );

      fragmentSummary.preservedCount = restoredFragments.preservedCount;
      fragmentSummary.discardedCount = restoredFragments.discardedCount;
      fragmentSummary.persistedCount = restoredPersistent.persistedCount;
      fragmentSummary.discardedPersistentCount =
        capturedPersistent.discardedCount + restoredPersistent.discardedCount;
      fragmentSummary.capturedPersistentCount =
        capturedPersistent.capturedCount || 0;
      fragmentSummary.persistentRegistrySize =
        restoredPersistent.registrySize || 0;
      syncAllRuntimeStateDirectives();
      refreshActiveComponentsRegistry(
        payloadMeta.type ? payloadMeta.type : "navigation",
      );
      registerViewportPrefetchTargets(document);
      scheduleHeuristicPrefetch(document);
      await runPageTransitionPhase(document.body, "enter", pageTransition);
    }

    return fragmentSummary;
  }

