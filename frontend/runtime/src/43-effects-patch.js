  function resolveEffectTarget(root, effect) {
    if (!effect || typeof effect !== "object") {
      return null;
    }

    if (effect.target === "root" || effect.target === "self") {
      return root;
    }

    if (typeof effect.selector === "string" && effect.selector !== "") {
      return document.querySelector(effect.selector);
    }

    if (typeof effect.target !== "string" || effect.target === "") {
      return null;
    }

    const escapedTarget = cssEscape(effect.target);
    const scopedTarget = root
      ? root.querySelector("#" + escapedTarget) ||
        root.querySelector('[data-volt-target="' + effect.target + '"]')
      : null;

    if (scopedTarget) {
      return scopedTarget;
    }

    return (
      document.getElementById(effect.target) ||
      document.querySelector('[data-volt-target="' + effect.target + '"]')
    );
  }

  function dispatchRuntimeEvent(effect, target) {
    const name = effect.name || effect.event;

    if (!name) {
      return;
    }

    const eventTarget = target || document;
    eventTarget.dispatchEvent(
      new CustomEvent(name, {
        detail: effect.payload || effect.detail || {},
        bubbles: true,
      }),
    );
  }

  function applyHtmlReplace(root, target, effect) {
    if (!target) {
      return null;
    }

    const html = typeof effect.html === "string" ? effect.html : effect.value;

    if (typeof html !== "string") {
      return null;
    }

    const descriptor = buildStableElementDescriptor(target);

    if (
      effect.outer === true ||
      effect.mode === "outer" ||
      target === document.body ||
      target.hasAttribute("data-volt-root")
    ) {
      target.outerHTML = html;

      if (target === document.body) {
        return document.body;
      }

      if (target.hasAttribute("data-volt-root")) {
        const componentName = target.getAttribute("data-volt-component");

        return componentName ? findRootByComponent(componentName) : null;
      }

      return descriptor
        ? findByDescriptor(root || document.body, descriptor)
        : null;
    }

    target.innerHTML = html;
    return target;
  }

  function applyClassToggle(target, effect) {
    if (!target) {
      return;
    }

    const className = effect.class || effect.className || effect.value;

    if (typeof className !== "string" || className === "") {
      return;
    }

    if (typeof effect.force === "boolean") {
      target.classList.toggle(className, effect.force);
      return;
    }

    target.classList.toggle(className);
  }

  function applyStyleSet(target, effect) {
    if (!target) {
      return;
    }

    if (effect.styles && typeof effect.styles === "object") {
      Object.keys(effect.styles).forEach(function (property) {
        if (effect.styles[property] === null) {
          target.style.removeProperty(property);
          return;
        }

        target.style.setProperty(property, String(effect.styles[property]));
      });

      return;
    }

    if (typeof effect.property === "string") {
      if (effect.value === null) {
        target.style.removeProperty(effect.property);
        return;
      }

      if (typeof effect.value !== "undefined") {
        target.style.setProperty(effect.property, String(effect.value));
      }
    }
  }

  function resolveContainerTarget(root, effect) {
    if (!effect || typeof effect !== "object") {
      return null;
    }

    if (typeof effect.parentTarget === "string" && effect.parentTarget !== "") {
      return resolveEffectTarget(root, { target: effect.parentTarget });
    }

    return resolveEffectTarget(root, effect);
  }

  function applyDomInsert(root, effect) {
    const container = resolveContainerTarget(root, effect);
    const fragment = fragmentFromHtml(effect.html);

    if (!container || typeof effect.html !== "string" || !fragment) {
      return [];
    }

    const insertedNodes = Array.from(fragment.childNodes);
    const insertedElements = insertedNodes.filter(function (node) {
      return node.nodeType === 1;
    });

    if (
      typeof effect.beforeSelector === "string" &&
      effect.beforeSelector !== ""
    ) {
      const anchor = document.querySelector(effect.beforeSelector);

      if (anchor) {
        anchor.parentNode.insertBefore(fragment, anchor);
        return insertedElements;
      }
    }

    if (
      (effect.position || "beforeend") === "afterbegin" &&
      container.firstChild
    ) {
      container.insertBefore(fragment, container.firstChild);
      return insertedElements;
    }

    container.appendChild(fragment);
    return insertedElements;
  }

  function applyDomMove(root, target, effect) {
    const container = resolveContainerTarget(root, effect);

    if (!target || !container) {
      return false;
    }

    if (
      typeof effect.beforeSelector === "string" &&
      effect.beforeSelector !== ""
    ) {
      const anchor = document.querySelector(effect.beforeSelector);

      if (anchor && anchor.parentNode === container) {
        container.insertBefore(target, anchor);
        return true;
      }
    }

    container.appendChild(target);
    return true;
  }

  function syncAttributeProperty(target, name, value) {
    if (!target || typeof name !== "string") {
      return;
    }

    if (name === "value" && "value" in target) {
      target.value = value;
      return;
    }

    if (name === "checked" && "checked" in target) {
      target.checked = value !== null;
      return;
    }

    if (name === "selected" && "selected" in target) {
      target.selected = value !== null;
      return;
    }

    if (name === "disabled" && "disabled" in target) {
      target.disabled = value !== null;
    }
  }

  function applyScroll(target, effect) {
    const behavior = effect.behavior === "smooth" ? "smooth" : "auto";

    if (target && typeof target.scrollIntoView === "function") {
      target.scrollIntoView({
        behavior: behavior,
        block: effect.block || "start",
        inline: effect.inline || "nearest",
      });
      return;
    }

    window.scrollTo({
      top: typeof effect.top === "number" ? effect.top : 0,
      left: typeof effect.left === "number" ? effect.left : 0,
      behavior: behavior,
    });
  }

  function ensureRuntimeCustomEffectsRegistry() {
    if (runtime.customEffects && runtime.customEffectsIndex) {
      return;
    }

    runtime.customEffects = new Map();
    runtime.customEffectsIndex = [];
  }

  function rebuildRuntimeCustomEffectsIndex() {
    ensureRuntimeCustomEffectsRegistry();

    runtime.customEffectsIndex = Array.from(runtime.customEffects.entries())
      .map(function (entry) {
        return entry[1];
      })
      .sort(function (left, right) {
        if (left.priority !== right.priority) {
          return left.priority - right.priority;
        }

        return String(left.type).localeCompare(String(right.type));
      });
  }

  function normalizeCustomEffectPriority(value) {
    if (typeof value === "number") {
      return Number.isFinite(value) ? value : 0;
    }

    if (typeof value !== "string") {
      return 0;
    }

    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function registerCustomEffect(type, handler, options) {
    ensureRuntimeCustomEffectsRegistry();

    const settings = options && typeof options === "object" ? options : {};
    const key = typeof type === "string" ? type.trim() : "";

    if (key === "" || typeof handler !== "function") {
      return false;
    }

    if (runtime.customEffects.has(key) && settings.replace !== true) {
      return false;
    }

    runtime.customEffects.set(key, {
      type: key,
      handler: handler,
      priority: normalizeCustomEffectPriority(settings.priority),
    });
    rebuildRuntimeCustomEffectsIndex();
    return true;
  }

  function unregisterCustomEffect(type) {
    ensureRuntimeCustomEffectsRegistry();

    const key = typeof type === "string" ? type.trim() : "";

    if (key === "") {
      return false;
    }

    const deleted = runtime.customEffects.delete(key);
    rebuildRuntimeCustomEffectsIndex();
    return deleted;
  }

  function listCustomEffects() {
    ensureRuntimeCustomEffectsRegistry();

    return runtime.customEffectsIndex.map(function (entry) {
      return {
        type: entry.type,
        priority: entry.priority,
      };
    });
  }

  function normalizeCustomEffectResult(value) {
    if (value === true) {
      return {
        handled: true,
        preventsHtmlFallback: true,
      };
    }

    if (value === false || value === null || typeof value === "undefined") {
      return null;
    }

    if (typeof value !== "object") {
      return null;
    }

    return {
      handled: value.handled === true,
      preventsHtmlFallback: value.preventsHtmlFallback !== false,
      target:
        value.target && typeof value.target === "object" && value.target.isConnected
          ? value.target
          : null,
      detail: value.detail && typeof value.detail === "object" ? value.detail : null,
    };
  }

  async function applyCustomEffect(root, effect, target) {
    ensureRuntimeCustomEffectsRegistry();

    const entry = runtime.customEffects.get(effect.type);

    if (!entry || typeof entry.handler !== "function") {
      return null;
    }

    let rawResult = null;

    try {
      rawResult = await entry.handler({
        root: root,
        effect: effect,
        target: target,
      });
    } catch (error) {
      console.error("VoltStack runtime custom effect error:", effect.type, error);
      rawResult = null;
    }

    return normalizeCustomEffectResult(rawResult);
  }

  function createPublicEffectsApi() {
    return {
      register: function (type, handler, options) {
        return registerCustomEffect(type, handler, options || {});
      },
      unregister: function (type) {
        return unregisterCustomEffect(type);
      },
      list: function () {
        return listCustomEffects();
      },
    };
  }

  async function applyEffect(root, effect) {
    if (!effect || typeof effect.type !== "string") {
      return {
        handled: false,
        preventsHtmlFallback: false,
      };
    }

    return runRuntimeMiddleware(
      "effect",
      {
        root: root,
        effect: effect,
        target: null,
      },
      async function (context) {
        const root = context && context.root ? context.root : null;
        const effect = context && context.effect ? context.effect : null;

        if (!effect || typeof effect.type !== "string") {
          return {
            handled: false,
            preventsHtmlFallback: false,
          };
        }

        const target =
          context && context.target
            ? context.target
            : resolveEffectTarget(root, effect);

        if (context && typeof context === "object") {
          context.target = target;
        }

        emitRuntimeHook(
          "volt:before-effect",
          effectHookDetail(root, effect, target),
          target || root || document,
        );

        const customResult = await applyCustomEffect(root, effect, target);

        if (customResult && customResult.handled === true) {
          const resolvedTarget = customResult.target || target;
          return createEffectResult(
            root,
            effect,
            resolvedTarget,
            true,
            customResult.preventsHtmlFallback !== false,
            customResult.detail || null,
          );
        }

        switch (effect.type) {
          case "text.update":
            if (target && typeof effect.value !== "undefined") {
              target.textContent = String(effect.value);
              await runElementTransition(root, target, "update", effect);
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          case "html.replace": {
            const replacedTarget = applyHtmlReplace(root, target, effect);

            if (replacedTarget) {
              await runElementTransition(
                root,
                replacedTarget,
                effect.outer === true || effect.mode === "outer"
                  ? "enter"
                  : "update",
                effect,
              );
            }

            return createEffectResult(
              root,
              effect,
              replacedTarget || target,
              !!target,
              !!target,
            );
          }

          case "dom.append":
            if (target && typeof effect.html === "string") {
              const insertedElements = applyDomInsert(
                root,
                Object.assign({}, effect, {
                  beforeSelector: null,
                  position: effect.position || "beforeend",
                }),
              );

              for (let index = 0; index < insertedElements.length; index += 1) {
                await runElementTransition(
                  root,
                  insertedElements[index],
                  "enter",
                  effect,
                );
              }

              return createEffectResult(root, effect, target, true, true, {
                insertedCount: insertedElements.length,
              });
            }
            break;

          case "dom.insert":
            {
              const insertedElements = applyDomInsert(root, effect);

              if (insertedElements.length > 0) {
                for (let index = 0; index < insertedElements.length; index += 1) {
                  await runElementTransition(
                    root,
                    insertedElements[index],
                    "enter",
                    effect,
                  );
                }

                return createEffectResult(root, effect, target, true, true, {
                  insertedCount: insertedElements.length,
                });
              }
            }
            break;

          case "dom.remove":
            if (target) {
              await runElementTransition(root, target, "leave", effect);
              target.remove();
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          case "dom.move":
            if (applyDomMove(root, target, effect)) {
              await runElementTransition(root, target, "move", effect);
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          case "attribute.set":
            if (target && typeof effect.name === "string") {
              const attributeValue =
                typeof effect.value === "undefined" ? "" : String(effect.value);
              target.setAttribute(effect.name, attributeValue);
              syncAttributeProperty(target, effect.name, attributeValue);
              await runElementTransition(root, target, "update", effect);
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          case "attribute.remove":
            if (target && typeof effect.name === "string") {
              target.removeAttribute(effect.name);
              syncAttributeProperty(target, effect.name, null);
              await runElementTransition(root, target, "update", effect);
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          case "class.toggle":
            applyClassToggle(target, effect);
            if (target) {
              await runElementTransition(root, target, "update", effect);
            }
            return createEffectResult(root, effect, target, !!target, !!target);

          case "style.set":
            applyStyleSet(target, effect);
            if (target) {
              await runElementTransition(root, target, "update", effect);
            }
            return createEffectResult(root, effect, target, !!target, !!target);

          case "focus":
            if (target && typeof target.focus === "function") {
              target.focus();
              return createEffectResult(root, effect, target, true, false);
            }
            break;

          case "blur":
            if (target && typeof target.blur === "function") {
              target.blur();
              return createEffectResult(root, effect, target, true, false);
            }
            break;

          case "scroll":
            applyScroll(target, effect);
            return createEffectResult(root, effect, target, true, false);

          case "dispatch.event":
            dispatchRuntimeEvent(effect, target);
            return createEffectResult(root, effect, target, true, false);

          case "runtime.policy": {
            const component =
              root && root.getAttribute
                ? root.getAttribute("data-volt-component")
                : null;
            const activeRoot = resolveRuntimeRoot(root, component) || root;

            return createEffectResult(
              activeRoot,
              effect,
              activeRoot,
              registerRuntimePolicy(activeRoot, effect),
              false,
            );
          }

          case "state.set":
            if (typeof effect.key === "string" && effect.key !== "") {
              setRuntimeStateValue(effect.key, effect.value, {
                scope: effect.scope,
                action: "effect",
              });
              return createEffectResult(root, effect, target, true, false);
            }
            break;

          case "state.merge":
            if (typeof effect.key === "string" && effect.key !== "") {
              mergeRuntimeStateValue(effect.key, effect.value, {
                scope: effect.scope,
              });
              return createEffectResult(root, effect, target, true, false);
            }
            break;

          case "state.delete":
            if (typeof effect.key === "string" && effect.key !== "") {
              deleteRuntimeStateValue(effect.key, {
                scope: effect.scope,
              });
              return createEffectResult(root, effect, target, true, false);
            }
            break;

          case "state.clear":
            clearRuntimeState(effect.scope, effect.reason || "effect");
            return createEffectResult(root, effect, target, true, false);

          case "navigate":
            if (typeof effect.url === "string" && effect.url !== "") {
              await visit(effect.url, {
                historyMode: effect.replace ? "replace" : "push",
                preserveScroll: !!effect.preserveScroll,
              });
              return createEffectResult(root, effect, target, true, true);
            }
            break;

          default:
            return createEffectResult(root, effect, target, false, false);
        }

        return createEffectResult(root, effect, target, false, false);
      },
    );
  }

  async function applyEffects(root, effects) {
    if (!Array.isArray(effects) || effects.length === 0) {
      return {
        handled: false,
        preventsHtmlFallback: false,
      };
    }

    let handled = false;
    let preventsHtmlFallback = false;

    for (let index = 0; index < effects.length; index += 1) {
      const result = await applyEffect(root, effects[index]);
      handled = result.handled || handled;
      preventsHtmlFallback =
        result.preventsHtmlFallback || preventsHtmlFallback;
    }

    return {
      handled: handled,
      preventsHtmlFallback: preventsHtmlFallback,
    };
  }

