# VoltStack Framework

VoltStack es un framework PHP fullstack orientado a aplicaciones reactivas y SPA server-driven.

Su propuesta combina:

- experiencia de desarrollo estilo Laravel,
- componentes reactivos impulsados por PHP,
- navegación SPA sin depender de un framework frontend pesado,
- compatibilidad con runtimes persistentes como FrankenPHP,
- y un núcleo modular propio para HTTP, routing, views, seguridad, consola y base de datos.

## Qué hace diferente a VoltStack

VoltStack no intenta ser solo un micro-framework HTTP con utilidades añadidas después. Su arquitectura nace con estas ideas desde el core:

- `PHP First`: la mayor parte de la aplicación se construye en PHP.
- `Reactive Native`: la reactividad forma parte del framework, no es un addon externo.
- `SPA by Default`: el runtime frontend y el protocolo reactivo forman parte del stack.
- `Persistent Runtime Aware`: está pensado para ejecutarse de forma segura sobre workers persistentes.
- `Operationally Minded`: incluye capacidades reales para observabilidad, salud e idempotencia en operaciones de base de datos.

## Capacidades llamativas del framework

### Runtime reactivo integrado

VoltStack incluye:

- componentes server-driven,
- hydration/dehydration,
- snapshots con checksum,
- ejecución de acciones reactivas,
- protocolo interno `/_volt/action`,
- runtime frontend para navegación y actualización parcial del DOM,
- endpoints internos para runtime asset y manifest de rutas.

Esto permite construir interfaces reactivas sin montar un stack separado con React o Vue para casos donde PHP debe seguir siendo el centro.

### Navegación SPA y runtime frontend

El framework incorpora un runtime JavaScript propio en `frontend/runtime/volt.js` y un protocolo de navegación/reactividad para:

- navegación SPA,
- preserve state,
- preserve scroll,
- render parcial,
- sincronización de estado entre frontend y backend,
- aplicación de efectos y patches sobre el DOM.

### Routing con artifacts compilados

El router soporta:

- rutas HTTP clásicas,
- grupos, prefijos, domains y middleware,
- resource y apiResource,
- generación de URLs,
- signed URLs y temporary signed URLs,
- artifacts compilados de rutas, metadata, tree y pipeline,
- manifest para consumo frontend.

Es una de las piezas más maduras del repo y está pensada para minimizar trabajo en runtime cuando la app está desplegada.

### Capa HTTP y kernel propios

VoltStack implementa su propia base para:

- `Request`,
- `Response`,
- `JsonResponse`,
- `RedirectResponse`,
- `HttpKernel`,
- middlewares,
- normalización de respuestas,
- manejo centralizado de excepciones.

Esto permite controlar mejor el ciclo completo de request/response y adaptarlo a un entorno reactivo y persistente.

### Container y bootstrap del framework

El core incluye:

- container con `bind`, `singleton`, `scoped`, `instance` y `alias`,
- autowiring por reflection,
- service providers,
- bootstrapper para carga de configuración y arranque,
- aislamiento por scope para cada request.

Ese aislamiento es importante para evitar fugas de estado en workers persistentes.

### Sistema de vistas y compilación

VoltStack trae:

- motor de vistas PHP,
- compilación y cache de vistas,
- layouts,
- includes,
- directivas,
- pipeline de compilación de templates.

También incorpora un sistema de compilación de controladores y artifacts para reducir trabajo repetitivo en runtime.

### Seguridad de controladores y runtime

El framework integra varias piezas de seguridad:

- CSRF middleware,
- validación backend-first,
- signed routes,
- checksums de snapshots,
- manejo de errores HTML/JSON/reactivo,
- engine de políticas de seguridad para controladores,
- sandbox endurecido para evaluación de políticas,
- limpieza de scope al terminar cada request.

La seguridad no está tratada como un detalle de middleware suelto, sino como parte del modelo operativo del framework.

### Subsistema de base de datos orientado a operación real

Una de las capacidades más distintivas del proyecto hoy está en base de datos. VoltStack incluye:

- DBAL multi-driver,
- schema introspection para `sqlite`, `pgsql`, `mysql` y `mariadb`,
- migraciones,
- rollback y recovery plan,
- seeders,
- factories,
- ORM/metadata layer en desarrollo activo,
- runtime operacional para queries y mutaciones.

Ese runtime de base de datos añade además:

- circuit breaker,
- deadlines y presupuestos,
- límites de profundidad y filas,
- telemetry,
- health stores,
- idempotencia persistente,
- replay confirmado,
- verificación de evidencia de confirmación,
- soporte para replays federados entre nodos.

Para un framework PHP joven, este nivel de preocupación operativa es especialmente llamativo.

### Consola y tooling

VoltStack incluye CLI propia con comandos para:

- `serve`,
- cache de vistas,
- cache de rutas,
- generación de controllers, actions, pages, components, layouts y views,
- migraciones,
- health/idempotency de DB,
- schema diff/describe/status,
- seeders y factories.

## Filosofía de diseño

VoltStack busca equilibrar:

- productividad,
- control del runtime,
- seguridad,
- modularidad,
- observabilidad,
- y una experiencia moderna para construir UI reactiva desde PHP.

En resumen: **VoltStack quiere que PHP siga siendo el centro de una aplicación moderna, reactiva y operativamente seria.**
