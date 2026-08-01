  function ensureFrontendPluginsRegistry() {
    if (runtime.frontendPlugins && runtime.frontendPluginsIndex) {
      return;
    }

    runtime.frontendPlugins = new Map();
    runtime.frontendPluginsIndex = [];
  }

  function rebuildFrontendPluginsIndex() {
    ensureFrontendPluginsRegistry();

    runtime.frontendPluginsIndex = Array.from(runtime.frontendPlugins.values()).sort(
      function (left, right) {
        if (left.priority !== right.priority) {
          return left.priority - right.priority;
        }

        return String(left.id).localeCompare(String(right.id));
      },
    );
  }

  function normalizePluginPriority(value) {
    if (typeof value === "number") {
      return Number.isFinite(value) ? value : 0;
    }

    if (typeof value !== "string") {
      return 0;
    }

    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function resolvePluginId(plugin) {
    if (!plugin) {
      return null;
    }

    if (typeof plugin === "function") {
      const name = plugin.name ? String(plugin.name) : "";
      return name.trim() !== "" ? name.trim() : null;
    }

    if (typeof plugin === "object") {
      if (typeof plugin.id === "string" && plugin.id.trim() !== "") {
        return plugin.id.trim();
      }
    }

    return null;
  }

  function resolvePluginInstall(plugin) {
    if (!plugin) {
      return null;
    }

    if (typeof plugin === "function") {
      return plugin;
    }

    if (typeof plugin === "object" && typeof plugin.install === "function") {
      return plugin.install;
    }

    return null;
  }

  function pluginContext(options) {
    const settings = options && typeof options === "object" ? options : {};

    return {
      Volt: window.Volt,
      state: window.Volt && window.Volt.state ? window.Volt.state : null,
      on: window.Volt && window.Volt.on ? window.Volt.on : null,
      directives: window.Volt && window.Volt.directives ? window.Volt.directives : null,
      effects: window.Volt && window.Volt.effects ? window.Volt.effects : null,
      components: window.Volt && window.Volt.components ? window.Volt.components : null,
      telemetry: window.Volt && window.Volt.telemetry ? window.Volt.telemetry : null,
      busy: window.Volt && window.Volt.busy ? window.Volt.busy : null,
      options: settings,
    };
  }

  function registerFrontendPlugin(plugin, options) {
    ensureFrontendPluginsRegistry();

    const settings = options && typeof options === "object" ? options : {};
    const install = resolvePluginInstall(plugin);

    if (!install) {
      return false;
    }

    const id = resolvePluginId(plugin) || "plugin:" + String(runtime.directiveSequence += 1);

    if (runtime.frontendPlugins.has(id) && settings.replace !== true) {
      return false;
    }

    let result = null;

    try {
      result = install(pluginContext(settings));
    } catch (error) {
      console.error("VoltStack runtime plugin error:", id, error);
      return false;
    }

    runtime.frontendPlugins.set(id, {
      id: id,
      priority: normalizePluginPriority(settings.priority),
      installedAt: new Date().toISOString(),
      result: typeof result === "undefined" ? null : result,
    });
    rebuildFrontendPluginsIndex();
    return true;
  }

  function unregisterFrontendPlugin(id) {
    ensureFrontendPluginsRegistry();

    const key = typeof id === "string" ? id.trim() : "";

    if (key === "") {
      return false;
    }

    const deleted = runtime.frontendPlugins.delete(key);
    rebuildFrontendPluginsIndex();
    return deleted;
  }

  function listFrontendPlugins() {
    ensureFrontendPluginsRegistry();

    return runtime.frontendPluginsIndex.map(function (entry) {
      return {
        id: entry.id,
        priority: entry.priority,
        installedAt: entry.installedAt,
      };
    });
  }

  function createPublicPluginsApi() {
    return {
      use: function (plugin, options) {
        return registerFrontendPlugin(plugin, options || {});
      },
      uninstall: function (id) {
        return unregisterFrontendPlugin(id);
      },
      list: function () {
        return listFrontendPlugins();
      },
    };
  }

