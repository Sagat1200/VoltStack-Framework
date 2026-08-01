  function matchingStateDirectiveElements(
    root,
    state,
    suffix,
    active,
    contextOverride,
  ) {
    const context = contextOverride || runtimeStateContext(root, state);
    const names = stateDirectiveNames(state, suffix);

    return collectElementsWithDirectiveAttributes(root, names).filter(
      function (element) {
        return stateDirectiveIsActive(
          element,
          state,
          active,
          stateDirectiveShorthandValue(element, state),
          context,
        );
      },
    );
  }

  function resolveStateDirectiveDuration(root, state, suffix, context) {
    const values = matchingStateDirectiveElements(
      root,
      state,
      suffix,
      true,
      context,
    )
      .map(function (element) {
        return parseDirectiveTimeout(
          directiveValue(element, stateDirectiveNames(state, suffix)),
        );
      })
      .filter(function (value) {
        return value !== null;
      });
    const policyValues = matchingRuntimePolicyDurations(
      root,
      state,
      suffix,
      context || runtimeStateContext(root, state),
    );
    const allValues = values.concat(policyValues);

    if (allValues.length === 0) {
      return null;
    }

    return Math.min.apply(null, allValues);
  }

  function resolveStateDirectiveTimeout(root, state) {
    return resolveStateDirectiveDuration(root, state, "timeout");
  }

  function resolveStateDirectiveDelay(root, state, context) {
    return resolveStateDirectiveDuration(root, state, "delay", context);
  }

  function resolveStateDirectiveMinDuration(root, state, context) {
    return resolveStateDirectiveDuration(root, state, "min-duration", context);
  }

  function resolveStateDirectiveDebounce(root, state, context) {
    return resolveStateDirectiveDuration(root, state, "debounce", context);
  }

  function clearLoadingDelay(root) {
    const timeoutId = runtime.loadingDelays.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.loadingDelays.delete(root);
    }
  }

  function clearLoadingMinDuration(root) {
    const timeoutId = runtime.loadingMinClearDelays.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.loadingMinClearDelays.delete(root);
    }
  }

  function clearSuccessTimeout(root) {
    const timeoutId = runtime.successTimeouts.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.successTimeouts.delete(root);
    }
  }

  function clearSuccessMinDuration(root) {
    const timeoutId = runtime.successMinClearDelays.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.successMinClearDelays.delete(root);
    }
  }

  function scheduleLoadingDelay(root, trigger, meta) {
    if (!root) {
      return;
    }

    clearLoadingDelay(root);

    const detail = meta && typeof meta === "object" ? meta : {};
    const context = {
      action: detail.action || null,
      target: stateTargetValue(detail),
    };
    const delay = resolveStateDirectiveDelay(root, "loading", context);

    if (delay === null || delay <= 0) {
      setLoadingState(root, true, trigger, detail);
      return;
    }

    const component =
      root.getAttribute("data-volt-component") || detail.component || null;
    const requestId = detail.requestId || null;
    const timeoutId = window.setTimeout(function () {
      runtime.loadingDelays.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);
      const state = componentRequestState(component);

      if (!activeRoot || !state || state.requestId !== requestId) {
        return;
      }

      setLoadingState(
        activeRoot,
        true,
        trigger,
        Object.assign({}, detail, {
          component: component,
        }),
      );
    }, delay);

    runtime.loadingDelays.set(root, timeoutId);
  }

  function scheduleLoadingMinDurationClear(root, trigger, meta, remaining) {
    if (!root) {
      return;
    }

    clearLoadingMinDuration(root);

    const component =
      root.getAttribute("data-volt-component") ||
      (meta && meta.component) ||
      null;
    const detail = Object.assign({}, meta || {}, {
      component: component,
      reason: "min-duration",
    });

    const timeoutId = window.setTimeout(function () {
      runtime.loadingMinClearDelays.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);

      if (
        !activeRoot ||
        activeRoot.getAttribute("data-volt-loading") !== "true"
      ) {
        return;
      }

      setLoadingState(activeRoot, false, trigger, detail);
    }, remaining);

    runtime.loadingMinClearDelays.set(root, timeoutId);
  }

  function clearErrorTimeout(root) {
    const timeoutId = runtime.errorTimeouts.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.errorTimeouts.delete(root);
    }
  }

  function clearDirtyDebounce(root) {
    const timeoutId = runtime.dirtyDebounces.get(root);

    if (timeoutId !== undefined) {
      window.clearTimeout(timeoutId);
      runtime.dirtyDebounces.delete(root);
    }
  }

  function scheduleSuccessTimeout(root, meta) {
    if (!root) {
      return;
    }

    clearSuccessTimeout(root);

    const timeout = resolveStateDirectiveTimeout(root, "success");

    if (timeout === null) {
      return;
    }

    const component =
      root.getAttribute("data-volt-component") ||
      (meta && meta.component) ||
      null;
    const detail = Object.assign({}, meta || {}, {
      component: component,
      reason: "timeout",
    });

    const timeoutId = window.setTimeout(function () {
      runtime.successTimeouts.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);

      if (
        !activeRoot ||
        activeRoot.getAttribute("data-volt-success") !== "true"
      ) {
        return;
      }

      setSuccessState(activeRoot, false, detail);
    }, timeout);

    runtime.successTimeouts.set(root, timeoutId);
  }

  function scheduleSuccessMinDurationClear(root, meta, remaining) {
    if (!root) {
      return;
    }

    clearSuccessMinDuration(root);

    const component =
      root.getAttribute("data-volt-component") ||
      (meta && meta.component) ||
      null;
    const detail = Object.assign({}, meta || {}, {
      component: component,
      reason: "min-duration",
    });

    const timeoutId = window.setTimeout(function () {
      runtime.successMinClearDelays.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);

      if (
        !activeRoot ||
        activeRoot.getAttribute("data-volt-success") !== "true"
      ) {
        return;
      }

      setSuccessState(activeRoot, false, detail);
    }, remaining);

    runtime.successMinClearDelays.set(root, timeoutId);
  }

  function scheduleErrorTimeout(root, meta) {
    if (!root) {
      return;
    }

    clearErrorTimeout(root);

    const timeout = resolveStateDirectiveTimeout(root, "error");

    if (timeout === null) {
      return;
    }

    const component =
      root.getAttribute("data-volt-component") ||
      (meta && meta.component) ||
      null;
    const detail = Object.assign({}, meta || {}, {
      component: component,
      reason: "timeout",
    });

    const timeoutId = window.setTimeout(function () {
      runtime.errorTimeouts.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);

      if (
        !activeRoot ||
        activeRoot.getAttribute("data-volt-error") !== "true"
      ) {
        return;
      }

      setErrorState(activeRoot, false, detail);
    }, timeout);

    runtime.errorTimeouts.set(root, timeoutId);
  }

  function scheduleDirtyDebounce(root, meta) {
    if (!root) {
      return;
    }

    clearDirtyDebounce(root);

    const detail = meta && typeof meta === "object" ? meta : {};
    const context = {
      action: detail.action || null,
      target: stateTargetValue(detail),
    };
    const debounce = resolveStateDirectiveDebounce(root, "dirty", context);

    if (debounce === null || debounce <= 0) {
      setDirtyState(root, true, detail);
      return;
    }

    const component =
      root.getAttribute("data-volt-component") || detail.component || null;
    const timeoutId = window.setTimeout(function () {
      runtime.dirtyDebounces.delete(root);

      const activeRoot = resolveRuntimeRoot(root, component);

      if (!activeRoot) {
        return;
      }

      setDirtyState(
        activeRoot,
        true,
        Object.assign({}, detail, {
          component: component,
          reason: "debounce",
          debounce: debounce,
        }),
      );
    }, debounce);

    runtime.dirtyDebounces.set(root, timeoutId);
  }

  function syncRuntimeStateDirective(root, state, active) {
    const showNames = stateDirectiveNames(state);
    const hideNames = stateDirectiveNames(state, "hide");
    const classNames = stateDirectiveNames(state, "class");
    const attrNames = stateDirectiveNames(state, "attr");

    collectElementsWithDirectiveAttributes(root, showNames).forEach(
      function (element) {
        applyDirectiveVisibility(
          element,
          state,
          stateDirectiveIsActive(
            element,
            state,
            active,
            stateDirectiveShorthandValue(element, state),
            runtimeStateContext(root, state),
          ),
          false,
        );
      },
    );

    collectElementsWithDirectiveAttributes(root, hideNames).forEach(
      function (element) {
        applyDirectiveVisibility(
          element,
          state,
          stateDirectiveIsActive(
            element,
            state,
            active,
            stateDirectiveShorthandValue(element, state),
            runtimeStateContext(root, state),
          ),
          true,
        );
      },
    );

    collectElementsWithDirectiveAttributes(root, classNames).forEach(
      function (element) {
        applyDirectiveClasses(
          element,
          stateDirectiveIsActive(
            element,
            state,
            active,
            stateDirectiveShorthandValue(element, state),
            runtimeStateContext(root, state),
          ),
          directiveValue(element, stateDirectiveNames(state, "class")),
          "state:" + state + ":class",
        );
      },
    );

    collectElementsWithDirectiveAttributes(root, attrNames).forEach(
      function (element) {
        applyDirectiveAttributes(
          element,
          state,
          stateDirectiveIsActive(
            element,
            state,
            active,
            stateDirectiveShorthandValue(element, state),
            runtimeStateContext(root, state),
          ),
          parseDirectiveAttributes(
            directiveValue(element, stateDirectiveNames(state, "attr")),
          ),
        );
      },
    );
  }

  function syncRuntimeStateDirectives(root) {
    if (!root) {
      return;
    }

    syncFrontendDirectiveGroup(root, "before-state");

    syncRuntimeStateDirective(
      root,
      "loading",
      root.getAttribute("data-volt-loading") === "true",
    );
    syncRuntimeStateDirective(
      root,
      "error",
      root.getAttribute("data-volt-error") === "true",
    );
    syncRuntimeStateDirective(
      root,
      "dirty",
      root.getAttribute("data-volt-dirty") === "true",
    );
    syncRuntimeStateDirective(
      root,
      "success",
      root.getAttribute("data-volt-success") === "true",
    );
    syncFrontendDirectiveGroup(root, "after-state", {
      rerunGroups: ["before-state"],
    });
    syncFrontendDirectiveGroup(root, "render");
    cleanupRuntimeOrphans();
  }

  function syncAllRuntimeStateDirectives() {
    const roots = document.querySelectorAll('[data-volt-root="true"]');

    roots.forEach(function (root) {
      syncRuntimeStateDirectives(root);
    });
  }

