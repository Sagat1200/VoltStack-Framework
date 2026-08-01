  function ensureRuntimeMiddlewareRegistry() {
    if (runtime.middlewares && runtime.middlewareIndex) {
      return;
    }

    runtime.middlewares = new Map();
    runtime.middlewareIndex = {
      runtime: [],
      navigation: [],
      hydration: [],
      effect: [],
    };
  }

  function normalizeMiddlewareKind(value) {
    const normalized = typeof value === "string" ? value.trim() : "";

    if (normalized === "navigation") {
      return "navigation";
    }

    if (normalized === "hydration") {
      return "hydration";
    }

    if (normalized === "effect") {
      return "effect";
    }

    return "runtime";
  }

  function normalizeMiddlewarePriority(value) {
    if (typeof value === "number") {
      return Number.isFinite(value) ? value : 0;
    }

    if (typeof value !== "string") {
      return 0;
    }

    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function rebuildMiddlewareIndex() {
    ensureRuntimeMiddlewareRegistry();

    const entries = Array.from(runtime.middlewares.values()).sort(function (
      left,
      right,
    ) {
      if (left.kind !== right.kind) {
        const order = {
          runtime: 1,
          navigation: 2,
          hydration: 3,
          effect: 4,
        };

        return (order[left.kind] || 99) - (order[right.kind] || 99);
      }

      if (left.priority !== right.priority) {
        return left.priority - right.priority;
      }

      return String(left.id).localeCompare(String(right.id));
    });

    runtime.middlewareIndex = {
      runtime: entries.filter(function (entry) {
        return entry.kind === "runtime";
      }),
      navigation: entries.filter(function (entry) {
        return entry.kind === "navigation";
      }),
      hydration: entries.filter(function (entry) {
        return entry.kind === "hydration";
      }),
      effect: entries.filter(function (entry) {
        return entry.kind === "effect";
      }),
    };
  }

  function registerRuntimeMiddleware(kind, handler, options) {
    ensureRuntimeMiddlewareRegistry();

    const settings = options && typeof options === "object" ? options : {};
    const resolvedKind = normalizeMiddlewareKind(kind);

    if (typeof handler !== "function") {
      return false;
    }

    const id =
      typeof settings.id === "string" && settings.id.trim() !== ""
        ? settings.id.trim()
        : resolvedKind + ":" + String((runtime.directiveSequence += 1));

    if (runtime.middlewares.has(id) && settings.replace !== true) {
      return false;
    }

    runtime.middlewares.set(id, {
      id: id,
      kind: resolvedKind,
      priority: normalizeMiddlewarePriority(settings.priority),
      handler: handler,
      registeredAt: new Date().toISOString(),
    });
    rebuildMiddlewareIndex();
    return true;
  }

  function unregisterRuntimeMiddleware(id) {
    ensureRuntimeMiddlewareRegistry();

    const key = typeof id === "string" ? id.trim() : "";

    if (key === "") {
      return false;
    }

    const deleted = runtime.middlewares.delete(key);
    rebuildMiddlewareIndex();
    return deleted;
  }

  function listRuntimeMiddlewares(kind) {
    ensureRuntimeMiddlewareRegistry();

    const resolvedKind = normalizeMiddlewareKind(kind);
    const entries = runtime.middlewareIndex[resolvedKind] || [];

    return entries.map(function (entry) {
      return {
        id: entry.id,
        kind: entry.kind,
        priority: entry.priority,
        registeredAt: entry.registeredAt,
      };
    });
  }

  function middlewareEntriesForKind(kind) {
    ensureRuntimeMiddlewareRegistry();

    const resolvedKind = normalizeMiddlewareKind(kind);
    return runtime.middlewareIndex[resolvedKind] || [];
  }

  function composeMiddlewares(entries, finalHandler) {
    const stack = Array.isArray(entries) ? entries : [];
    const last = typeof finalHandler === "function" ? finalHandler : null;

    if (!last) {
      return async function () {
        return null;
      };
    }

    return async function (context) {
      let index = -1;

      async function dispatch(nextIndex) {
        if (nextIndex <= index) {
          return null;
        }

        index = nextIndex;

        if (nextIndex === stack.length) {
          return last(context);
        }

        const entry = stack[nextIndex];

        if (!entry || typeof entry.handler !== "function") {
          return dispatch(nextIndex + 1);
        }

        return entry.handler(context, function () {
          return dispatch(nextIndex + 1);
        });
      }

      return dispatch(0);
    };
  }

  async function runRuntimeMiddleware(kind, context, finalHandler) {
    const entries = middlewareEntriesForKind(kind);
    const runner = composeMiddlewares(entries, finalHandler);
    return runner(context || {});
  }

  function createPublicMiddlewareApi() {
    return {
      register: function (kind, handler, options) {
        return registerRuntimeMiddleware(kind, handler, options || {});
      },
      unregister: function (id) {
        return unregisterRuntimeMiddleware(id);
      },
      list: function (kind) {
        return listRuntimeMiddlewares(kind);
      },
    };
  }

