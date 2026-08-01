  function resolveRuntimeRoot(rootOrComponent, fallbackComponent) {
    if (
      rootOrComponent &&
      typeof rootOrComponent === "object" &&
      rootOrComponent.isConnected
    ) {
      return rootOrComponent;
    }

    if (typeof rootOrComponent === "string" && rootOrComponent !== "") {
      return findRootByComponent(rootOrComponent);
    }

    if (typeof fallbackComponent === "string" && fallbackComponent !== "") {
      return findRootByComponent(fallbackComponent);
    }

    return null;
  }

  function isAbortError(error) {
    return !!(
      error &&
      typeof error === "object" &&
      (error.name === "AbortError" || error.code === 20)
    );
  }

  function triggerDescriptor(trigger) {
    if (!trigger || typeof trigger.getAttribute !== "function") {
      return null;
    }

    return {
      tag: trigger.tagName ? String(trigger.tagName).toLowerCase() : null,
      target: trigger.getAttribute("data-volt-target"),
      action: directiveValue(trigger, [
        "volt-click",
        "volt:click",
        "volt-submit",
        "volt:submit",
      ]),
    };
  }

  function requestHookDetail(kind, meta, extra) {
    return Object.assign(
      {
        type: kind,
        component: meta && meta.component ? meta.component : null,
        action: meta && meta.action ? meta.action : null,
        requestId: meta && meta.requestId ? meta.requestId : null,
        trigger: meta && meta.trigger ? meta.trigger : null,
      },
      extra || {},
    );
  }

  const REQUEST_TIMEOUT_ATTRIBUTE_NAMES = [
    "data-volt-request-timeout",
    "volt-request-timeout",
    "volt:request-timeout",
    "data-volt-timeout",
    "volt-timeout",
    "volt:timeout",
  ];
  const REQUEST_RETRY_ATTRIBUTE_NAMES = [
    "data-volt-request-retry",
    "volt-request-retry",
    "volt:request-retry",
    "data-volt-retry",
    "volt-retry",
    "volt:retry",
  ];
  const REQUEST_RETRY_DELAY_ATTRIBUTE_NAMES = [
    "data-volt-request-retry-delay",
    "volt-request-retry-delay",
    "volt:request-retry-delay",
    "data-volt-retry-delay",
    "volt-retry-delay",
    "volt:retry-delay",
  ];
  const REQUEST_ERROR_KINDS = [
    "aborted",
    "stale",
    "timeout",
    "http-error",
    "protocol-error",
    "network-error",
    "unexpected-error",
  ];

  function normalizedRequestErrorKind(value, fallback) {
    if (
      typeof value === "string" &&
      REQUEST_ERROR_KINDS.indexOf(value) !== -1
    ) {
      return value;
    }

    return fallback;
  }

  function requestTimeoutForElement(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    const attribute = directiveAttribute(element, REQUEST_TIMEOUT_ATTRIBUTE_NAMES);
    return attribute ? parseDirectiveTimeout(attribute.value) : null;
  }

  function resolveRequestTimeoutMs(kind, options, elements) {
    const settings = options && typeof options === "object" ? options : {};
    const explicitTimeout =
      Object.prototype.hasOwnProperty.call(settings, "timeout")
        ? parseDirectiveTimeout(settings.timeout)
        : null;

    if (explicitTimeout !== null) {
      return explicitTimeout;
    }

    if (Array.isArray(elements)) {
      for (let index = 0; index < elements.length; index += 1) {
        const elementTimeout = requestTimeoutForElement(elements[index]);

        if (elementTimeout !== null) {
          return elementTimeout;
        }
      }
    }

    return kind === "navigation"
      ? NAVIGATION_REQUEST_TIMEOUT
      : ACTION_REQUEST_TIMEOUT;
  }

  function retryAttemptsValue(value) {
    if (typeof value === "number") {
      return Number.isFinite(value) && value >= 0 ? Math.round(value) : null;
    }

    if (typeof value !== "string") {
      return null;
    }

    const normalized = value.trim().toLowerCase();

    if (normalized === "") {
      return null;
    }

    if (normalized === "true" || normalized === "on" || normalized === "yes") {
      return 1;
    }

    if (normalized === "false" || normalized === "off" || normalized === "no") {
      return 0;
    }

    const parsed = Number.parseInt(normalized, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
  }

  function requestRetryAttemptsForElement(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    const attribute = directiveAttribute(element, REQUEST_RETRY_ATTRIBUTE_NAMES);
    return attribute ? retryAttemptsValue(attribute.value) : null;
  }

  function requestRetryDelayForElement(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    const attribute = directiveAttribute(
      element,
      REQUEST_RETRY_DELAY_ATTRIBUTE_NAMES,
    );

    return attribute ? parseDirectiveTimeout(attribute.value) : null;
  }

  function requestRetryStatusAllowed(status) {
    return [408, 425, 429, 500, 502, 503, 504].indexOf(status) !== -1;
  }

  function resolveRequestRetryPolicy(kind, options, elements) {
    const settings = options && typeof options === "object" ? options : {};
    const explicitRetry = settings.retry;
    let attempts = kind === "navigation" ? NAVIGATION_REQUEST_RETRY_ATTEMPTS : 0;
    let delayMs =
      kind === "navigation" ? NAVIGATION_REQUEST_RETRY_DELAY : 0;

    if (Array.isArray(elements)) {
      for (let index = 0; index < elements.length; index += 1) {
        const elementAttempts = requestRetryAttemptsForElement(elements[index]);

        if (elementAttempts !== null) {
          attempts = elementAttempts;
          break;
        }
      }

      for (let index = 0; index < elements.length; index += 1) {
        const elementDelay = requestRetryDelayForElement(elements[index]);

        if (elementDelay !== null) {
          delayMs = elementDelay;
          break;
        }
      }
    }

    if (typeof explicitRetry === "boolean") {
      attempts = explicitRetry ? attempts || 1 : 0;
    } else if (typeof explicitRetry === "number") {
      attempts = retryAttemptsValue(explicitRetry);
    } else if (typeof explicitRetry === "string") {
      const parsedAttempts = retryAttemptsValue(explicitRetry);

      if (parsedAttempts !== null) {
        attempts = parsedAttempts;
      }
    } else if (explicitRetry && typeof explicitRetry === "object") {
      if (Object.prototype.hasOwnProperty.call(explicitRetry, "attempts")) {
        const parsedAttempts = retryAttemptsValue(explicitRetry.attempts);

        if (parsedAttempts !== null) {
          attempts = parsedAttempts;
        }
      }

      if (Object.prototype.hasOwnProperty.call(explicitRetry, "delay")) {
        const parsedDelay = parseDirectiveTimeout(explicitRetry.delay);

        if (parsedDelay !== null) {
          delayMs = parsedDelay;
        }
      }
    }

    if (!Number.isFinite(attempts) || attempts < 0) {
      attempts = 0;
    }

    if (!Number.isFinite(delayMs) || delayMs < 0) {
      delayMs = 0;
    }

    return {
      enabled: attempts > 0,
      attempts: attempts,
      delayMs: delayMs,
    };
  }

  function shouldRetryNavigationRequest(errorDetail, policy, attemptIndex) {
    if (
      !policy ||
      policy.enabled !== true ||
      attemptIndex >= policy.attempts
    ) {
      return false;
    }

    if (!errorDetail || typeof errorDetail !== "object") {
      return false;
    }

    if (
      errorDetail.errorKind === "timeout" ||
      errorDetail.errorKind === "network-error"
    ) {
      return true;
    }

    return (
      errorDetail.errorKind === "http-error" &&
      typeof errorDetail.status === "number" &&
      requestRetryStatusAllowed(errorDetail.status)
    );
  }

  function shouldRetryActionRequest(errorDetail, policy, attemptIndex) {
    return shouldRetryNavigationRequest(errorDetail, policy, attemptIndex);
  }

  function waitForRetryDelay(delayMs, signal) {
    if (!delayMs || delayMs <= 0) {
      return Promise.resolve();
    }

    return new Promise(function (resolve, reject) {
      let timeoutId = null;
      let aborted = false;

      function cleanup() {
        if (timeoutId !== null) {
          window.clearTimeout(timeoutId);
        }

        if (signal && typeof signal.removeEventListener === "function") {
          signal.removeEventListener("abort", handleAbort);
        }
      }

      function handleAbort() {
        if (aborted) {
          return;
        }

        aborted = true;
        cleanup();
        const abortError = createRuntimeRequestError(
          "aborted",
          "Retry delay aborted.",
          {
            signal: signal || null,
          },
        );
        abortError.name = "AbortError";
        reject(
          abortError,
        );
      }

      if (signal && signal.aborted) {
        handleAbort();
        return;
      }

      timeoutId = window.setTimeout(function () {
        cleanup();
        resolve();
      }, delayMs);

      if (signal && typeof signal.addEventListener === "function") {
        signal.addEventListener("abort", handleAbort, {
          once: true,
        });
      }
    });
  }

  function createRuntimeRequestError(errorKind, message, detail) {
    const error = new Error(
      typeof message === "string" && message !== ""
        ? message
        : "Unexpected runtime error.",
    );

    error.voltErrorKind = normalizedRequestErrorKind(
      errorKind,
      "unexpected-error",
    );

    if (detail && typeof detail === "object") {
      Object.assign(error, detail);
    }

    return error;
  }

  function abortControllerWithMeta(controller, detail) {
    if (!controller || typeof controller.abort !== "function") {
      return;
    }

    if (controller.signal && typeof controller.signal === "object") {
      controller.signal.__voltAbortMeta =
        detail && typeof detail === "object"
          ? Object.assign({}, detail)
          : {
              kind: "aborted",
            };
    }

    controller.abort();
  }

  function requestAbortMeta(signal) {
    if (!signal || typeof signal !== "object") {
      return null;
    }

    const detail = signal.__voltAbortMeta;
    return detail && typeof detail === "object" ? detail : null;
  }

  function withRequestTimeout(promise, controller, timeoutMs, detail) {
    if (timeoutMs === null) {
      return promise;
    }

    let timeoutId = null;
    const timeoutDetail = detail && typeof detail === "object" ? detail : {};
    const timeoutPromise = new Promise(function (_, reject) {
      timeoutId = window.setTimeout(function () {
        const message =
          timeoutDetail.message ||
          "Request timed out after " + timeoutMs + "ms.";

        if (controller) {
          abortControllerWithMeta(controller, {
            kind: "timeout",
            timeoutMs: timeoutMs,
            message: message,
          });
        }

        reject(
          createRuntimeRequestError("timeout", message, {
            timeoutMs: timeoutMs,
          }),
        );
      }, timeoutMs);
    });

    return Promise.race([promise, timeoutPromise]).finally(function () {
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId);
      }
    });
  }

  function requestErrorDetail(kind, meta, errorKind, message, extra) {
    const resolvedErrorKind = normalizedRequestErrorKind(
      errorKind,
      "unexpected-error",
    );

    return requestHookDetail(
      kind,
      meta,
      Object.assign(
        {
          ok: false,
          message:
            typeof message === "string" && message !== ""
              ? message
              : "Unexpected runtime error.",
          outcome: resolvedErrorKind,
          errorKind: resolvedErrorKind,
        },
        extra || {},
      ),
    );
  }

  function requestAbortDetail(kind, meta, signal, extra) {
    const abortMeta = requestAbortMeta(signal);
    const errorKind = normalizedRequestErrorKind(
      abortMeta && abortMeta.kind ? abortMeta.kind : null,
      "aborted",
    );

    return requestHookDetail(
      kind,
      meta,
      Object.assign(
        {
          outcome: errorKind,
          errorKind: errorKind,
          message:
            abortMeta && abortMeta.message
              ? abortMeta.message
              : errorKind === "timeout"
                ? "Request timed out."
                : "Request was aborted.",
          timeoutMs:
            abortMeta && typeof abortMeta.timeoutMs === "number"
              ? abortMeta.timeoutMs
              : null,
        },
        extra || {},
      ),
    );
  }

  function timeoutErrorDetail(kind, meta, signal, extra) {
    const abortMeta = requestAbortMeta(signal);
    const message =
      abortMeta && abortMeta.message
        ? abortMeta.message
        : "Request timed out.";

    return requestErrorDetail(
      kind,
      meta,
      "timeout",
      message,
      Object.assign(
        {
          timeoutMs:
            abortMeta && typeof abortMeta.timeoutMs === "number"
              ? abortMeta.timeoutMs
              : null,
        },
        extra || {},
      ),
    );
  }

  function responseErrorKind(response, payload) {
    const payloadError =
      payload && payload.error && typeof payload.error === "object"
        ? payload.error
        : {};

    if (payloadError && typeof payloadError.kind === "string") {
      return normalizedRequestErrorKind(payloadError.kind, "protocol-error");
    }

    if (payload && payload.error && typeof payload.error === "object") {
      return "protocol-error";
    }

    return "http-error";
  }

  function responseErrorDetail(kind, response, payload, meta, extra) {
    const payloadError =
      payload && payload.error && typeof payload.error === "object"
        ? payload.error
        : {};
    const errorKind = responseErrorKind(response, payload);

    return requestErrorDetail(
      kind,
      meta,
      errorKind,
      payloadError.message ||
        "Request failed with status " + response.status + ".",
      Object.assign(
        {
          status: response.status,
          error: payloadError,
        },
        extra || {},
      ),
    );
  }

  function exceptionErrorKind(error) {
    if (!error || typeof error !== "object") {
      return "unexpected-error";
    }

    if (typeof error.voltErrorKind === "string") {
      return normalizedRequestErrorKind(
        error.voltErrorKind,
        "unexpected-error",
      );
    }

    if (typeof error.errorKind === "string") {
      return normalizedRequestErrorKind(error.errorKind, "unexpected-error");
    }

    if (error.name === "TypeError") {
      return "network-error";
    }

    return "unexpected-error";
  }

  function exceptionErrorDetail(kind, error, meta, extra) {
    const errorKind = exceptionErrorKind(error);

    return requestErrorDetail(
      kind,
      meta,
      errorKind,
      error && error.message
        ? error.message
        : errorKind === "network-error"
          ? "Network request failed."
          : "Unexpected runtime error.",
      extra,
    );
  }

  function stateTargetValue(detail) {
    if (!detail || typeof detail !== "object") {
      return null;
    }

    if (detail.target) {
      return detail.target;
    }

    if (detail.trigger && detail.trigger.target) {
      return detail.trigger.target;
    }

    return null;
  }

  function fieldStateTarget(element) {
    if (!element || typeof element.getAttribute !== "function") {
      return null;
    }

    return (
      directiveValue(element, ["volt-model", "volt:model"]) ||
      element.getAttribute("data-volt-target") ||
      element.getAttribute("name") ||
      element.id ||
      null
    );
  }

  function syncRequestStatus(root) {
    if (!root) {
      return;
    }

    if (root.getAttribute("data-volt-loading") === "true") {
      root.setAttribute("data-volt-request-status", "loading");
      root.setAttribute("aria-busy", "true");
    } else if (root.getAttribute("data-volt-error") === "true") {
      root.setAttribute("data-volt-request-status", "error");
      root.setAttribute("aria-busy", "false");
    } else if (root.getAttribute("data-volt-success") === "true") {
      root.setAttribute("data-volt-request-status", "success");
      root.setAttribute("aria-busy", "false");
    } else if (root.getAttribute("data-volt-dirty") === "true") {
      root.setAttribute("data-volt-request-status", "dirty");
      root.setAttribute("aria-busy", "false");
    } else {
      root.setAttribute("data-volt-request-status", "idle");
      root.setAttribute("aria-busy", "false");
    }

    resolveGlobalBusyState({
      source: "action",
      component: root.getAttribute("data-volt-component") || null,
      action: root.getAttribute("data-volt-loading-action") || null,
      target: root.getAttribute("data-volt-loading-target") || null,
      requestId: root.getAttribute("data-volt-request-id") || null,
    });
  }

  function setLoadingState(rootOrComponent, active, trigger, meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const root = resolveRuntimeRoot(rootOrComponent, detail.component);

    if (root) {
      const previous = root.getAttribute("data-volt-loading") === "true";
      const context = active
        ? {
            action: detail.action || null,
            target: stateTargetValue(detail),
          }
        : runtimeStateContext(root, "loading");
      const minDuration = previous
        ? resolveStateDirectiveMinDuration(root, "loading", context)
        : null;
      const activatedAt = runtime.loadingActivatedAt.get(root) || null;
      const elapsed = activatedAt === null ? null : Date.now() - activatedAt;
      const remainingMinDuration =
        minDuration !== null && elapsed !== null
          ? Math.max(0, minDuration - elapsed)
          : null;

      if (
        !active &&
        previous &&
        remainingMinDuration !== null &&
        remainingMinDuration > 0 &&
        detail.reason !== "min-duration"
      ) {
        scheduleLoadingMinDurationClear(
          root,
          trigger,
          detail,
          remainingMinDuration,
        );
        return;
      }

      clearLoadingMinDuration(root);

      if (active) {
        runtime.loadingActivatedAt.set(root, Date.now());
      } else {
        runtime.loadingActivatedAt.delete(root);
      }

      root.setAttribute("data-volt-loading", active ? "true" : "false");

      if (active && detail.action) {
        root.setAttribute("data-volt-loading-action", detail.action);
      } else {
        root.removeAttribute("data-volt-loading-action");
      }

      if (active && detail.trigger && detail.trigger.target) {
        root.setAttribute("data-volt-loading-target", detail.trigger.target);
      } else {
        root.removeAttribute("data-volt-loading-target");
      }

      if (active && detail.requestId) {
        root.setAttribute("data-volt-request-id", String(detail.requestId));
      } else {
        root.removeAttribute("data-volt-request-id");
      }

      syncRequestStatus(root);
      syncRuntimeStateDirectives(root);
    }

    if (
      trigger &&
      "disabled" in trigger &&
      (!meta || meta.action !== MODEL_SYNC_INTERNAL_ACTION)
    ) {
      trigger.disabled = active;
    }
  }

  function setErrorState(rootOrComponent, active, meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const root = resolveRuntimeRoot(rootOrComponent, detail.component);

    if (!root) {
      return;
    }

    const previous = root.getAttribute("data-volt-error") === "true";
    clearErrorTimeout(root);
    root.setAttribute("data-volt-error", active ? "true" : "false");

    if (active) {
      if (detail.action) {
        root.setAttribute("data-volt-error-action", detail.action);
      } else {
        root.removeAttribute("data-volt-error-action");
      }

      if (detail.trigger && detail.trigger.target) {
        root.setAttribute("data-volt-error-target", detail.trigger.target);
      } else {
        root.removeAttribute("data-volt-error-target");
      }

      if (detail.message) {
        root.setAttribute("data-volt-error-message", String(detail.message));
      } else {
        root.removeAttribute("data-volt-error-message");
      }

      syncRequestStatus(root);
      syncRuntimeStateDirectives(root);
      scheduleErrorTimeout(root, detail);
      return;
    }

    root.removeAttribute("data-volt-error-message");
    root.removeAttribute("data-volt-error-action");
    root.removeAttribute("data-volt-error-target");

    syncRequestStatus(root);
    syncRuntimeStateDirectives(root);

    if (previous) {
      emitRuntimeHook(
        "volt:error-cleared",
        requestHookDetail("error", detail, {
          target: stateTargetValue(detail),
          active: false,
          reason: detail.reason || null,
        }),
        root,
      );
    }
  }

  function setDirtyState(rootOrComponent, active, meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const root = resolveRuntimeRoot(rootOrComponent, detail.component);

    if (!root) {
      return;
    }

    if (!active) {
      clearDirtyDebounce(root);
    }

    const previous = root.getAttribute("data-volt-dirty") === "true";
    root.setAttribute("data-volt-dirty", active ? "true" : "false");

    if (active) {
      const target = stateTargetValue(detail);

      if (target) {
        root.setAttribute("data-volt-dirty-target", target);
      } else {
        root.removeAttribute("data-volt-dirty-target");
      }
    } else {
      root.removeAttribute("data-volt-dirty-target");
    }

    syncRequestStatus(root);
    syncRuntimeStateDirectives(root);

    if (previous !== active) {
      emitRuntimeHook(
        active ? "volt:dirty" : "volt:clean",
        requestHookDetail("dirty", detail, {
          target: stateTargetValue(detail),
          active: active,
          reason: detail.reason || null,
          debounce: detail.debounce || null,
        }),
        root,
      );
    }
  }

  function setSuccessState(rootOrComponent, active, meta) {
    const detail = meta && typeof meta === "object" ? meta : {};
    const root = resolveRuntimeRoot(rootOrComponent, detail.component);

    if (!root) {
      return;
    }

    const previous = root.getAttribute("data-volt-success") === "true";
    const context = active
      ? {
          action: detail.action || null,
          target: stateTargetValue(detail),
        }
      : runtimeStateContext(root, "success");
    const minDuration = previous
      ? resolveStateDirectiveMinDuration(root, "success", context)
      : null;
    const activatedAt = runtime.successActivatedAt.get(root) || null;
    const elapsed = activatedAt === null ? null : Date.now() - activatedAt;
    const remainingMinDuration =
      minDuration !== null && elapsed !== null
        ? Math.max(0, minDuration - elapsed)
        : null;

    if (
      !active &&
      previous &&
      remainingMinDuration !== null &&
      remainingMinDuration > 0 &&
      detail.reason !== "min-duration"
    ) {
      scheduleSuccessMinDurationClear(root, detail, remainingMinDuration);
      return;
    }

    clearSuccessTimeout(root);
    clearSuccessMinDuration(root);

    if (active) {
      runtime.successActivatedAt.set(root, Date.now());
    } else {
      runtime.successActivatedAt.delete(root);
    }

    root.setAttribute("data-volt-success", active ? "true" : "false");

    if (active) {
      if (detail.action) {
        root.setAttribute("data-volt-success-action", detail.action);
      } else {
        root.removeAttribute("data-volt-success-action");
      }

      const target = stateTargetValue(detail);

      if (target) {
        root.setAttribute("data-volt-success-target", target);
      } else {
        root.removeAttribute("data-volt-success-target");
      }
    } else {
      root.removeAttribute("data-volt-success-action");
      root.removeAttribute("data-volt-success-target");
    }

    syncRequestStatus(root);
    syncRuntimeStateDirectives(root);

    const timeout = active
      ? resolveStateDirectiveTimeout(root, "success")
      : null;

    if (active) {
      scheduleSuccessTimeout(root, detail);
    }

    if (previous !== active) {
      emitRuntimeHook(
        active ? "volt:success" : "volt:success-cleared",
        requestHookDetail("success", detail, {
          target: stateTargetValue(detail),
          active: active,
          timeout: timeout,
          minDuration: minDuration,
          reason: detail.reason || null,
        }),
        root,
      );
    }
  }

