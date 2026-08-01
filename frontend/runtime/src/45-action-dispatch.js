  async function dispatchAction(root, action, params, updates, trigger) {
    const snapshot = root.getAttribute("data-volt-snapshot");
    const component = root.getAttribute("data-volt-component");
    const endpoint = root.getAttribute("data-volt-endpoint") || "/_volt/action";
    const csrf = root.getAttribute("data-volt-csrf");

    if (!snapshot || !component || !action) {
      return;
    }

    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      enqueueOfflineAction(
        component,
        action,
        params || {},
        updates || {},
        triggerDescriptor(trigger),
      );
      return;
    }

    return runRuntimeMiddleware(
      "runtime",
      {
        kind: "action",
        component: component,
        action: action,
        trigger: triggerDescriptor(trigger),
      },
      async function () {
    const state = componentRequestState(component);
    const previousController =
      state && state.controller ? state.controller : null;
    const requestId = state ? state.requestId + 1 : 1;
    const controller =
      typeof AbortController === "function" ? new AbortController() : null;
    const requestMeta = {
      component: component,
      action: action,
      requestId: requestId,
      trigger: triggerDescriptor(trigger),
    };
    const requestStartedAt = runtimeNow();
    let requestPayloadBytes = 0;
    let responsePayloadBytes = 0;
    let htmlBytes = 0;
    let snapshotBytes = 0;
    let patchDurationMs = null;
    let effectCount = 0;
    let usedHtmlFallback = false;
    const timeoutMs = resolveRequestTimeoutMs("action", null, [
      trigger || null,
      root,
    ]);
    const retryPolicy = resolveRequestRetryPolicy("action", null, [
      trigger || null,
      root,
    ]);
    let retryCount = 0;
    let errorKind = null;
    let errorMessage = null;
    let responseStatus = null;
    const syncedPayload = applySelectiveStateSync(
      root,
      trigger,
      params,
      updates,
      requestMeta,
    );

    if (state) {
      state.requestId = requestId;
      state.controller = controller;
    }

    resolveGlobalBusyState({
      source: "action",
      phase: "request-start",
      requestId: requestId,
      component: component,
      action: action,
      target:
        requestMeta && requestMeta.trigger ? requestMeta.trigger.target || null : null,
    });

    if (previousController) {
      abortControllerWithMeta(previousController, {
        kind: "aborted",
        message: "Action request was superseded by a newer request.",
      });
    }

    clearDirtyDebounce(root);
    setErrorState(component, false, requestMeta);
    setSuccessState(
      component,
      false,
      Object.assign({}, requestMeta, {
        reason: "request",
      }),
    );

    if (
      trigger &&
      "disabled" in trigger &&
      action !== MODEL_SYNC_INTERNAL_ACTION
    ) {
      trigger.disabled = true;
    }

    scheduleLoadingDelay(root, trigger, requestMeta);
    emitRuntimeHook(
      "volt:request-start",
      requestHookDetail("action", requestMeta, {
        selectiveSyncAppliedCount: Array.isArray(syncedPayload.applied)
          ? syncedPayload.applied.length
          : 0,
        selectiveSyncSkippedCount: Array.isArray(syncedPayload.skipped)
          ? syncedPayload.skipped.length
          : 0,
        timeoutMs: timeoutMs,
        retryAttempts: retryPolicy.attempts,
        retryDelayMs: retryPolicy.delayMs,
      }),
      resolveRuntimeRoot(root, component) || document,
    );

    let outcome = "success";

    try {
      const requestBody = {
        component: component,
        action: action,
        params: syncedPayload.params,
        updates: syncedPayload.updates,
        snapshot: JSON.parse(snapshot),
      };
      const serializedRequestBody = JSON.stringify(requestBody);
      requestPayloadBytes = serializedPayloadBytes(serializedRequestBody);
      let attempt = 0;
      let response = null;
      let payload = null;

      while (true) {
        try {
          response = await withRequestTimeout(
            fetch(endpoint, {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "VoltStack",
                "X-CSRF-TOKEN": csrf || "",
              },
              credentials: "same-origin",
              signal: controller ? controller.signal : undefined,
              body: serializedRequestBody,
            }),
            controller,
            timeoutMs,
            {
              message: "Action request timed out after " + timeoutMs + "ms.",
            },
          );
        } catch (error) {
          if (isAbortError(error)) {
            const abortDetail = requestAbortDetail(
              "action",
              requestMeta,
              controller ? controller.signal : null,
            );

            if (abortDetail.errorKind === "timeout") {
              const timeoutDetail = timeoutErrorDetail(
                "action",
                requestMeta,
                controller ? controller.signal : null,
                {
                  retryAttempt: attempt + 1,
                },
              );

              if (
                shouldRetryActionRequest(timeoutDetail, retryPolicy, attempt)
              ) {
                retryCount = attempt + 1;
                emitRuntimeHook(
                  "volt:request-retry",
                  requestHookDetail("action", requestMeta, {
                    retryAttempt: retryCount,
                    retryAttempts: retryPolicy.attempts,
                    retryDelayMs: retryPolicy.delayMs,
                    errorKind: timeoutDetail.errorKind,
                    message: timeoutDetail.message,
                    status: null,
                  }),
                  resolveRuntimeRoot(root, component) || document,
                );

                await waitForRetryDelay(
                  retryPolicy.delayMs,
                  controller ? controller.signal : null,
                );
                attempt += 1;
                continue;
              }

              outcome = "timeout";
              errorKind = timeoutDetail.errorKind;
              errorMessage = timeoutDetail.message;
              setErrorState(component, true, timeoutDetail);
              emitRuntimeHook(
                "volt:request-error",
                timeoutDetail,
                resolveRuntimeRoot(root, component) || document,
              );
              return;
            }

            outcome = "aborted";
            errorKind = abortDetail.errorKind;
            errorMessage = abortDetail.message;
            emitRuntimeHook(
              "volt:request-abort",
              abortDetail,
              resolveRuntimeRoot(root, component) || document,
            );
            return;
          }

          const exceptionDetail = exceptionErrorDetail(
            "action",
            error,
            requestMeta,
            {
              retryAttempt: attempt + 1,
            },
          );

          if (
            shouldRetryActionRequest(exceptionDetail, retryPolicy, attempt)
          ) {
            retryCount = attempt + 1;
            emitRuntimeHook(
              "volt:request-retry",
              requestHookDetail("action", requestMeta, {
                retryAttempt: retryCount,
                retryAttempts: retryPolicy.attempts,
                retryDelayMs: retryPolicy.delayMs,
                errorKind: exceptionDetail.errorKind,
                message: exceptionDetail.message,
                status:
                  typeof exceptionDetail.status === "number"
                    ? exceptionDetail.status
                    : null,
              }),
              resolveRuntimeRoot(root, component) || document,
            );

            await waitForRetryDelay(
              retryPolicy.delayMs,
              controller ? controller.signal : null,
            );
            attempt += 1;
            continue;
          }

          outcome = exceptionDetail.errorKind;
          errorKind = exceptionDetail.errorKind;
          errorMessage = exceptionDetail.message;
          responseStatus =
            error && typeof error.status === "number" ? error.status : null;
          setErrorState(component, true, exceptionDetail);
          emitRuntimeHook(
            "volt:request-error",
            exceptionDetail,
            resolveRuntimeRoot(root, component) || document,
          );
          throw error;
        }

        try {
          payload = await response.json();
        } catch (error) {
          payload = null;
        }

        responsePayloadBytes = serializedPayloadBytes(payload);
        htmlBytes = serializedPayloadBytes(payload && payload.html ? payload.html : "");
        snapshotBytes = serializedPayloadBytes(
          payload && payload.snapshot ? payload.snapshot : null,
        );
        effectCount = Array.isArray(payload && payload.effects)
          ? payload.effects.length
          : 0;

        if (state && state.requestId !== requestId) {
          outcome = "stale";
          errorKind = "stale";
          emitRuntimeHook(
            "volt:request-stale",
            requestHookDetail("action", requestMeta, {
              status: response.status,
              outcome: outcome,
            }),
            resolveRuntimeRoot(root, component) || document,
          );
          return;
        }

        if (!response.ok) {
          const errorDetail = responseErrorDetail(
            "action",
            response,
            payload,
            requestMeta,
          );
          responseStatus = response.status;

          if (shouldRetryActionRequest(errorDetail, retryPolicy, attempt)) {
            retryCount = attempt + 1;
            emitRuntimeHook(
              "volt:request-retry",
              requestHookDetail("action", requestMeta, {
                status: responseStatus,
                retryAttempt: retryCount,
                retryAttempts: retryPolicy.attempts,
                retryDelayMs: retryPolicy.delayMs,
                errorKind: errorDetail.errorKind,
                message: errorDetail.message,
              }),
              resolveRuntimeRoot(root, component) || document,
            );

            await waitForRetryDelay(
              retryPolicy.delayMs,
              controller ? controller.signal : null,
            );
            attempt += 1;
            continue;
          }

          outcome = errorDetail.errorKind;
          errorKind = errorDetail.errorKind;
          errorMessage = errorDetail.message;
          setErrorState(component, true, errorDetail);
          emitRuntimeHook(
            "volt:request-error",
            errorDetail,
            resolveRuntimeRoot(root, component) || document,
          );
          return;
        }

        break;
      }

      const patchMeta = {
        type: "action",
        component: component,
        action: action,
        effects: Array.isArray(payload.effects)
          ? payload.effects
              .map(function (effect) {
                return effect && effect.type ? effect.type : null;
              })
              .filter(function (value) {
                return value !== null;
              })
          : [],
        usedHtmlFallback: false,
      };

      const patchRoot = resolveRuntimeRoot(root, component) || root;

      const patchStartedAt = runtimeNow();
      await withPreservedUiState(
        patchRoot,
        async function () {
          const activeRoot = resolveRuntimeRoot(root, component) || root;
          const result = await applyEffects(activeRoot, payload.effects);

          if (
            !result.preventsHtmlFallback &&
            payload.html &&
            activeRoot.isConnected &&
            !(
              action === MODEL_SYNC_INTERNAL_ACTION &&
              Array.isArray(payload.effects) &&
              payload.effects.length === 0
            )
          ) {
            patchMeta.usedHtmlFallback = true;
            activeRoot.outerHTML = payload.html;
          }

          const updatedRoot = resolveRuntimeRoot(activeRoot, component);

          if (payload.snapshot && updatedRoot) {
            updatedRoot.setAttribute(
              "data-volt-snapshot",
              JSON.stringify(payload.snapshot),
            );
            persistOfflineSnapshot(updatedRoot);
          }

          return result;
        },
        patchMeta,
      );
      patchDurationMs = roundedMetricValue(runtimeNow() - patchStartedAt);
      usedHtmlFallback = patchMeta.usedHtmlFallback === true;

      setDirtyState(component, false, requestMeta);
      setSuccessState(component, true, requestMeta);
    } finally {
      if (state && state.requestId === requestId) {
        state.controller = null;
        clearLoadingDelay(resolveRuntimeRoot(root, component) || root);
        setLoadingState(component, false, trigger, requestMeta);
      }

      resolveGlobalBusyState({
        source: "action",
        phase: "request-finish",
        requestId: null,
        component: component,
        action: action,
        target:
          requestMeta && requestMeta.trigger ? requestMeta.trigger.target || null : null,
      });

      const finishDetail = requestHookDetail("action", requestMeta, {
        outcome: outcome,
        errorKind: errorKind,
        message: errorMessage,
        status: responseStatus,
        timeoutMs: timeoutMs,
        requestPayloadBytes: requestPayloadBytes,
        responsePayloadBytes: responsePayloadBytes,
        htmlBytes: htmlBytes,
        snapshotBytes: snapshotBytes,
        patchDurationMs: patchDurationMs,
        totalDurationMs: roundedMetricValue(runtimeNow() - requestStartedAt),
        retryCount: retryCount,
        retryAttempts: retryPolicy.attempts,
        retryDelayMs: retryPolicy.delayMs,
        effectCount: effectCount,
        usedHtmlFallback: usedHtmlFallback,
        selectiveSyncAppliedCount: Array.isArray(syncedPayload.applied)
          ? syncedPayload.applied.length
          : 0,
        selectiveSyncSkippedCount: Array.isArray(syncedPayload.skipped)
          ? syncedPayload.skipped.length
          : 0,
      });
      const telemetryEntry = recordRuntimeTelemetry("action", finishDetail);

      emitRuntimeHook(
        "volt:request-finish",
        Object.assign({}, finishDetail, {
          telemetrySequence: telemetryEntry.sequence,
        }),
        resolveRuntimeRoot(root, component) || document,
      );
    }
      },
    );
  }

