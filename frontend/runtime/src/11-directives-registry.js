  function ensureFrontendDirectiveRegistry() {
    if (runtime.frontendDirectives && runtime.frontendDirectiveIndex) {
      return;
    }

    runtime.frontendDirectives = new Map();
    runtime.frontendDirectiveIndex = {
      "before-state": [],
      "after-state": [],
      render: [],
      all: [],
    };
  }

  function normalizeFrontendDirectiveGroup(value) {
    const normalized = typeof value === "string" ? value.trim() : "";

    if (normalized === "before-state") {
      return "before-state";
    }

    if (normalized === "after-state") {
      return "after-state";
    }

    return "render";
  }

  function normalizeFrontendDirectivePriority(value) {
    if (typeof value === "number") {
      return Number.isFinite(value) ? value : 0;
    }

    if (typeof value !== "string") {
      return 0;
    }

    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function normalizeFrontendDirectiveMaxIterations(value, fallback) {
    const defaultValue =
      typeof fallback === "number" && Number.isFinite(fallback) && fallback > 0
        ? fallback
        : 5;

    if (typeof value === "number") {
      return Number.isFinite(value) && value > 0
        ? Math.round(value)
        : defaultValue;
    }

    if (typeof value !== "string") {
      return defaultValue;
    }

    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : defaultValue;
  }

  function rebuildFrontendDirectiveIndex() {
    ensureFrontendDirectiveRegistry();

    const entries = Array.from(runtime.frontendDirectives.values()).sort(
      function (left, right) {
        if (left.group !== right.group) {
          const order = {
            "before-state": 1,
            "after-state": 2,
            render: 3,
          };
          return (order[left.group] || 99) - (order[right.group] || 99);
        }

        if (left.priority !== right.priority) {
          return left.priority - right.priority;
        }

        return String(left.id).localeCompare(String(right.id));
      },
    );

    runtime.frontendDirectiveIndex = {
      "before-state": entries.filter(function (entry) {
        return entry.group === "before-state";
      }),
      "after-state": entries.filter(function (entry) {
        return entry.group === "after-state";
      }),
      render: entries.filter(function (entry) {
        return entry.group === "render";
      }),
      all: entries,
    };
  }

  function registerFrontendDirective(definition, options) {
    ensureFrontendDirectiveRegistry();

    const payload =
      definition && typeof definition === "object" ? definition : {};
    const settings = options && typeof options === "object" ? options : {};
    const id = typeof payload.id === "string" ? payload.id.trim() : "";

    if (id === "") {
      return false;
    }

    const names =
      typeof payload.names === "function"
        ? payload.names
        : Array.isArray(payload.names)
          ? payload.names.slice()
          : null;
    const parse = typeof payload.parse === "function" ? payload.parse : null;
    const apply = typeof payload.apply === "function" ? payload.apply : null;
    const sync = typeof payload.sync === "function" ? payload.sync : null;

    if (!sync && !(names && parse && apply)) {
      return false;
    }

    if (
      runtime.frontendDirectives.has(id) &&
      settings.replace !== true &&
      settings.builtin !== true
    ) {
      return false;
    }

    const entry = {
      id: id,
      group: normalizeFrontendDirectiveGroup(payload.group || payload.phase),
      priority: normalizeFrontendDirectivePriority(payload.priority),
      stabilize: payload.stabilize === true,
      maxIterations: normalizeFrontendDirectiveMaxIterations(
        payload.maxIterations,
        5,
      ),
      names: names,
      parse: parse,
      apply: apply,
      sync: sync,
      builtin: settings.builtin === true,
    };

    runtime.frontendDirectives.set(id, entry);
    rebuildFrontendDirectiveIndex();
    return true;
  }

  function unregisterFrontendDirective(id, options) {
    ensureFrontendDirectiveRegistry();

    const settings = options && typeof options === "object" ? options : {};
    const key = typeof id === "string" ? id.trim() : "";

    if (key === "") {
      return false;
    }

    const entry = runtime.frontendDirectives.get(key);

    if (!entry) {
      return false;
    }

    if (entry.builtin === true && settings.force !== true) {
      return false;
    }

    runtime.frontendDirectives.delete(key);
    rebuildFrontendDirectiveIndex();
    return true;
  }

  function ensureBuiltinFrontendDirectivesRegistered() {
    ensureFrontendDirectiveRegistry();

    if (runtime.frontendBuiltinDirectivesRegistered === true) {
      return;
    }

    registerFrontendDirective(
      {
        id: "for",
        group: "before-state",
        priority: 100,
        stabilize: false,
        sync: function (root) {
          syncForDirectives(root);
          return false;
        },
        names: function () {
          return forDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "if",
        group: "before-state",
        priority: 200,
        stabilize: true,
        maxIterations: 5,
        sync: function (root) {
          return syncIfDirectives(root) === true;
        },
        names: function () {
          return ifDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "portal",
        group: "after-state",
        priority: 100,
        stabilize: false,
        sync: function (root) {
          syncPortalDirectives(root);
          return false;
        },
        names: function () {
          return portalDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "html",
        group: "after-state",
        priority: 200,
        stabilize: true,
        maxIterations: 5,
        sync: function (root) {
          return syncHtmlDirectives(root) === true;
        },
        names: function () {
          return htmlDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "text",
        group: "render",
        priority: 100,
        stabilize: false,
        sync: function (root) {
          syncTextDirectives(root);
          return false;
        },
        names: function () {
          return textDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "model.local",
        group: "render",
        priority: 200,
        stabilize: false,
        sync: function (root) {
          syncModelLocalDirectives(root);
          return false;
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "model.sync",
        group: "render",
        priority: 210,
        stabilize: false,
        sync: function (root) {
          syncModelSyncDirectives(root);
          return false;
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "bind",
        group: "render",
        priority: 220,
        stabilize: false,
        sync: function (root) {
          syncBindDirectives(root);
          return false;
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "class",
        group: "render",
        priority: 300,
        stabilize: false,
        sync: function (root) {
          syncClassDirectives(root);
          return false;
        },
        names: function () {
          return classDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "attr",
        group: "render",
        priority: 310,
        stabilize: false,
        sync: function (root) {
          syncAttrDirectives(root);
          return false;
        },
        names: function () {
          return attrDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "style",
        group: "render",
        priority: 320,
        stabilize: false,
        sync: function (root) {
          syncStyleDirectives(root);
          return false;
        },
        names: function () {
          return styleDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "show",
        group: "render",
        priority: 330,
        stabilize: false,
        sync: function (root) {
          syncShowDirectives(root);
          return false;
        },
        names: function () {
          return showDirectiveNames();
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    registerFrontendDirective(
      {
        id: "focus",
        group: "render",
        priority: 340,
        stabilize: false,
        sync: function (root) {
          syncFocusDirectives(root);
          return false;
        },
      },
      {
        builtin: true,
        replace: true,
      },
    );

    runtime.frontendBuiltinDirectivesRegistered = true;
  }

  function safeDirectiveInvoke(fn, meta) {
    try {
      return fn();
    } catch (error) {
      console.error("VoltStack runtime directive error:", meta || null, error);
      return false;
    }
  }

  function directiveEntryNames(entry) {
    if (!entry) {
      return [];
    }

    if (typeof entry.names === "function") {
      const resolved = safeDirectiveInvoke(
        function () {
          return entry.names();
        },
        {
          id: entry.id,
          group: entry.group,
          op: "names",
        },
      );
      return Array.isArray(resolved) ? resolved : [];
    }

    return Array.isArray(entry.names) ? entry.names : [];
  }

  function normalizeDirectiveInstructionList(value) {
    if (!value) {
      return [];
    }

    return Array.isArray(value) ? value : [value];
  }

  function syncFrontendDirectiveEntry(root, entry) {
    if (!entry) {
      return false;
    }

    if (typeof entry.sync === "function") {
      return entry.sync(root) === true;
    }

    if (typeof entry.apply !== "function" || typeof entry.parse !== "function") {
      return false;
    }

    const names = directiveEntryNames(entry);

    if (names.length === 0) {
      return false;
    }

    let mutated = false;

    collectElementsWithDirectiveAttributes(root, names).forEach(function (element) {
      names.forEach(function (name) {
        if (!element || typeof element.hasAttribute !== "function") {
          return;
        }

        if (!element.hasAttribute(name)) {
          return;
        }

        const attributeValue =
          typeof element.getAttribute === "function"
            ? element.getAttribute(name) || ""
            : "";

        const instructions = normalizeDirectiveInstructionList(
          entry.parse({
            root: root,
            element: element,
            attributeName: name,
            attributeValue: attributeValue,
            directive: attributeValue,
          }),
        );

        instructions.forEach(function (instruction) {
          const result = entry.apply({
            root: root,
            element: element,
            attributeName: name,
            attributeValue: attributeValue,
            directive: attributeValue,
            instruction: instruction,
          });

          if (result === true) {
            mutated = true;
            return;
          }

          if (
            result &&
            typeof result === "object" &&
            result.mutatedDom === true
          ) {
            mutated = true;
          }
        });
      });
    });

    return mutated;
  }

  function runFrontendDirectiveEntriesOnce(root, entries) {
    entries.forEach(function (entry) {
      safeDirectiveInvoke(
        function () {
          return syncFrontendDirectiveEntry(root, entry);
        },
        {
          id: entry.id,
          group: entry.group,
        },
      );
    });
  }

  function syncFrontendDirectiveGroup(root, group, options, depth) {
    if (!root) {
      return;
    }

    ensureBuiltinFrontendDirectivesRegistered();
    ensureFrontendDirectiveRegistry();

    const maxDepth = 2;
    const currentDepth = typeof depth === "number" ? depth : 0;

    if (currentDepth > maxDepth) {
      return;
    }

    const index = runtime.frontendDirectiveIndex || {};
    const entries = Array.isArray(index[group]) ? index[group] : [];
    const rerunGroups =
      options && Array.isArray(options.rerunGroups) ? options.rerunGroups : [];

    const pre = entries.filter(function (entry) {
      return entry.stabilize !== true;
    });
    const stabilizers = entries.filter(function (entry) {
      return entry.stabilize === true;
    });

    runFrontendDirectiveEntriesOnce(root, pre);

    stabilizers.forEach(function (entry) {
      let iterations = 0;

      while (
        safeDirectiveInvoke(
          function () {
            return syncFrontendDirectiveEntry(root, entry);
          },
          {
            id: entry.id,
            group: entry.group,
          },
        ) === true &&
        iterations < entry.maxIterations
      ) {
        iterations += 1;

        rerunGroups.forEach(function (rerunGroup) {
          syncFrontendDirectiveGroup(root, rerunGroup, null, currentDepth + 1);
        });

        runFrontendDirectiveEntriesOnce(root, pre);
      }
    });
  }

  function publicDirectivesList() {
    ensureBuiltinFrontendDirectivesRegistered();
    ensureFrontendDirectiveRegistry();

    const entries = runtime.frontendDirectiveIndex
      ? runtime.frontendDirectiveIndex.all
      : [];

    return entries.map(function (entry) {
      let names = null;

      if (entry.names) {
        names = directiveEntryNames(entry);
      }

      return {
        id: entry.id,
        group: entry.group,
        priority: entry.priority,
        stabilize: entry.stabilize === true,
        maxIterations: entry.maxIterations,
        builtin: entry.builtin === true,
        names: Array.isArray(names) ? names : null,
      };
    });
  }

  function createPublicDirectivesApi() {
    ensureBuiltinFrontendDirectivesRegistered();

    return {
      register: function (definition, options) {
        return registerFrontendDirective(definition, options || {});
      },
      unregister: function (id, options) {
        return unregisterFrontendDirective(id, options || {});
      },
      resolveValue: function (expression) {
        return resolveStoreDirectiveValue(expression);
      },
      resolveActive: function (expression) {
        return resolveStoreDirectiveActive(expression);
      },
      list: function () {
        return publicDirectivesList();
      },
    };
  }
