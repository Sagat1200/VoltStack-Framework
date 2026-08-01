  function wait(duration) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, duration);
    });
  }

  function nextFrame() {
    return new Promise(function (resolve) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(resolve);
      });
    });
  }

  function transitionDurationFor(element, effect, phase) {
    if (!element) {
      return 180;
    }

    const phaseDuration = transitionConfigValue(effect, phase, "duration");

    if (typeof phaseDuration === "number" && phaseDuration >= 0) {
      return phaseDuration;
    }

    if (
      effect &&
      typeof effect.transitionDuration === "number" &&
      effect.transitionDuration >= 0
    ) {
      return effect.transitionDuration;
    }

    const phaseAttribute = element.getAttribute(
      "data-volt-transition-" + phase + "-duration",
    );
    const phaseParsed = phaseAttribute ? Number(phaseAttribute) : NaN;

    if (Number.isFinite(phaseParsed) && phaseParsed >= 0) {
      return phaseParsed;
    }

    const attributeValue = element.getAttribute(
      "data-volt-transition-duration",
    );
    const parsed = attributeValue ? Number(attributeValue) : NaN;

    return Number.isFinite(parsed) && parsed >= 0 ? parsed : 180;
  }

  function transitionConfigValue(effect, phase, key) {
    if (!effect || typeof effect !== "object") {
      return null;
    }

    if (
      effect.transition &&
      typeof effect.transition === "object" &&
      effect.transition !== null
    ) {
      const phaseConfig = effect.transition[phase];

      if (
        phaseConfig &&
        typeof phaseConfig === "object" &&
        Object.prototype.hasOwnProperty.call(phaseConfig, key)
      ) {
        return phaseConfig[key];
      }

      if (key === "name" && typeof phaseConfig === "string") {
        return phaseConfig;
      }
    }

    if (
      effect.transitions &&
      typeof effect.transitions === "object" &&
      effect.transitions !== null
    ) {
      const phaseConfig = effect.transitions[phase];

      if (
        phaseConfig &&
        typeof phaseConfig === "object" &&
        Object.prototype.hasOwnProperty.call(phaseConfig, key)
      ) {
        return phaseConfig[key];
      }

      if (key === "name" && typeof phaseConfig === "string") {
        return phaseConfig;
      }
    }

    return null;
  }

  function transitionVariantFor(element, effect, phase) {
    if (effect && effect.transition === false) {
      return null;
    }

    const phaseVariant = transitionConfigValue(effect, phase, "name");

    if (typeof phaseVariant === "string" && phaseVariant !== "") {
      return phaseVariant;
    }

    if (
      effect &&
      typeof effect.transition === "string" &&
      effect.transition !== ""
    ) {
      return effect.transition;
    }

    if (effect && effect.transition === true) {
      return "default";
    }

    if (!element) {
      return null;
    }

    const phaseAttribute = element.getAttribute(
      "data-volt-transition-" + phase,
    );

    if (phaseAttribute === "") {
      return "default";
    }

    if (phaseAttribute) {
      return phaseAttribute;
    }

    const attributeValue = element.getAttribute("data-volt-transition");

    if (attributeValue === "") {
      return "default";
    }

    return attributeValue || null;
  }

  function transitionClassListFor(element, effect, phase, variant) {
    const classes = ["volt-transition", "volt-transition-" + phase];

    if (variant) {
      classes.push("volt-transition-" + variant);
    }

    const phaseClasses = [];
    const classConfig = transitionConfigValue(effect, phase, "className");

    if (typeof classConfig === "string" && classConfig !== "") {
      phaseClasses.push(classConfig);
    }

    if (element) {
      const phaseAttribute = element.getAttribute(
        "data-volt-transition-" + phase + "-class",
      );

      if (phaseAttribute) {
        phaseClasses.push(phaseAttribute);
      }

      const globalAttribute = element.getAttribute(
        "data-volt-transition-class",
      );

      if (globalAttribute) {
        phaseClasses.push(globalAttribute);
      }
    }

    phaseClasses.forEach(function (classList) {
      classList.split(/\s+/).forEach(function (className) {
        if (className) {
          classes.push(className);
        }
      });
    });

    return classes;
  }

  async function runElementTransition(root, element, phase, effect) {
    const variant = transitionVariantFor(element, effect, phase);

    if (!element || !phase || !variant) {
      return false;
    }

    const duration = transitionDurationFor(element, effect, phase);
    const activeClass = "volt-transition-" + phase + "-active";
    const classes = transitionClassListFor(element, effect, phase, variant);
    const detail = effectHookDetail(root, effect, element, {
      phase: phase,
      variant: variant,
      duration: duration,
      transitionSource:
        effect && effect.pageTransitionSource
          ? effect.pageTransitionSource
          : null,
      transitionMode:
        effect && effect.pageTransitionMode ? effect.pageTransitionMode : null,
      transitionName:
        effect && effect.pageTransitionName ? effect.pageTransitionName : null,
    });

    emitRuntimeHook("volt:before-" + phase, detail, element);
    element.style.setProperty("--volt-transition-duration", duration + "ms");
    element.classList.add.apply(element.classList, classes);
    await nextFrame();
    element.classList.add(activeClass);
    await wait(duration);
    element.classList.remove(activeClass);
    element.classList.remove.apply(element.classList, classes);
    element.style.removeProperty("--volt-transition-duration");
    emitRuntimeHook("volt:after-" + phase, detail, element);

    return true;
  }

  function fragmentFromHtml(html) {
    if (typeof html !== "string" || html === "") {
      return null;
    }

    const template = document.createElement("template");
    template.innerHTML = html.trim();

    return template.content;
  }

  function effectHookDetail(root, effect, target, extra) {
    return Object.assign(
      {
        type: effect && effect.type ? effect.type : null,
        target:
          effect && typeof effect.target === "string" ? effect.target : null,
        selector:
          effect && typeof effect.selector === "string"
            ? effect.selector
            : null,
        component:
          root && typeof root.getAttribute === "function"
            ? root.getAttribute("data-volt-component")
            : null,
        element: target || null,
      },
      extra || {},
    );
  }

  function createEffectResult(
    root,
    effect,
    target,
    handled,
    preventsHtmlFallback,
    extra,
  ) {
    emitRuntimeHook(
      "volt:after-effect",
      effectHookDetail(
        root,
        effect,
        target,
        Object.assign(
          {
            handled: handled,
            preventsHtmlFallback: preventsHtmlFallback,
          },
          extra || {},
        ),
      ),
      target || root || document,
    );

    return {
      handled: handled,
      preventsHtmlFallback: preventsHtmlFallback,
    };
  }

  function classifyPatchTelemetryOutcome(detail) {
    const meta = detail && typeof detail === "object" ? detail : {};
    const operationType =
      typeof meta.type === "string" && meta.type !== "" ? meta.type : "unknown";
    const action =
      typeof meta.action === "string" && meta.action !== "" ? meta.action : null;
    const effectCount = Array.isArray(meta.effects) ? meta.effects.length : 0;
    const usedHtmlFallback = meta.usedHtmlFallback === true;
    const isModelSync = action === MODEL_SYNC_INTERNAL_ACTION;

    if (operationType === "navigation") {
      return "navigation-patch";
    }

    if (operationType === "action") {
      if (isModelSync) {
        if (usedHtmlFallback) {
          return "model-sync-html-fallback";
        }

        if (effectCount > 0) {
          return "model-sync-effects";
        }

        return "model-sync-no-op";
      }

      if (usedHtmlFallback) {
        return "action-html-fallback";
      }

      if (effectCount > 0) {
        return "action-effects";
      }

      return "action-no-op";
    }

    if (usedHtmlFallback) {
      return "html-fallback";
    }

    if (effectCount > 0) {
      return "effects-only";
    }

    return "no-op";
  }

  async function withPreservedUiState(root, callback, meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const focusState = captureFocusState(root);
    const scrollState = captureScrollState(root);
    const patchStartedAt = runtimeNow();

    emitRuntimeHook(
      "volt:before-patch",
      Object.assign({}, detail, {
        patchStartedAt: roundedMetricValue(patchStartedAt),
      }),
      root,
    );
    const result = await runRuntimeMiddleware(
      "hydration",
      {
        root: root,
        meta: Object.assign({}, detail),
      },
      function () {
        return callback();
      },
    );
    const updatedRoot =
      root && root.isConnected
        ? root
        : root && root.getAttribute
          ? findRootByComponent(root.getAttribute("data-volt-component"))
          : null;
    const patchDurationMs = roundedMetricValue(runtimeNow() - patchStartedAt);

    if (updatedRoot) {
      restoreScrollState(updatedRoot, scrollState);
      restoreFocusState(updatedRoot, focusState);
    }

    refreshActiveComponentsRegistry(
      detail && detail.type ? "patch:" + detail.type : "patch",
    );

    const patchDetail = Object.assign({}, detail, {
      patchDurationMs: patchDurationMs,
      updatedRoot: updatedRoot || null,
      activeComponentCount: runtime.activeComponents.size,
    });

    recordRuntimeTelemetry("patch", {
      outcome: classifyPatchTelemetryOutcome(detail),
      operationType: detail && detail.type ? detail.type : "unknown",
      source: detail && detail.source ? detail.source : null,
      component: detail && detail.component ? detail.component : null,
      action: detail && detail.action ? detail.action : null,
      url: detail && detail.url ? detail.url : null,
      finalUrl: detail && detail.finalUrl ? detail.finalUrl : null,
      effectCount:
        detail && Array.isArray(detail.effects) ? detail.effects.length : 0,
      effects:
        detail && Array.isArray(detail.effects) ? detail.effects.slice() : [],
      usedHtmlFallback:
        detail && detail.usedHtmlFallback === true ? true : false,
      patchDurationMs: patchDurationMs,
      activeComponentCount: runtime.activeComponents.size,
    });

    emitRuntimeHook(
      "volt:after-patch",
      patchDetail,
      updatedRoot || root || document,
    );

    return result;
  }

