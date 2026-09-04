# VoltStack Directory Structure

## Introducción

Este documento define la estructura oficial de directorios de VoltStack.

La organización del framework está diseñada para:

- modularidad
- bajo acoplamiento
- escalabilidad empresarial
- runtime persistente
- separación clara de responsabilidades
- compatibilidad SPA reactiva
- mantenimiento a largo plazo

VoltStack adopta una arquitectura organizada alrededor de:

- Platform
- Quantum
- Runtime
- Support
- Facades
- Frontend Runtime
- Application Structure

---

## Filosofía de la Estructura

### 1. Modular First

Cada sistema importante debe vivir en módulos claramente delimitados.

---

### 2. Runtime Aware

La estructura debe facilitar runtimes persistentes como FrankenPHP.

---

### 3. Reactive Native

La reactividad forma parte de la estructura principal.

---

### 4. Scalable Architecture

La estructura debe escalar hacia:

- aplicaciones empresariales
- cloud runtimes
- distributed runtimes
- realtime systems

---

### 5. Convention Over Configuration

Las convenciones deben reducir configuración manual.

---

## Estructura General del Framework

```txt
voltstack/
├── bin/
├── bootstrap/
├── config/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── vendor/
├── frontend/
├── src/
├── composer.json
├── package.json
├── volt
└── .env
```

---

## Root Directories

---

## /bin

Contiene ejecutables internos.

---

## Ejemplo

```txt
bin/
└── volt
```

---

## /bootstrap

Archivos de arranque del framework.

---

## Ejemplo

```txt
bootstrap/
├── app.php
├── providers.php
├── runtime.php
└── cache/
```

---

## /config

Archivos de configuración.

---

## Ejemplo

```txt
config/
├── app.php
├── cache.php
├── runtime.php
├── view.php
├── protocol.php
└── session.php
```

---

## /public

Punto de entrada HTTP.

---

## Ejemplo

```txt
public/
├── index.php
├── assets/
└── build/
```

---

## /resources

Recursos frontend y vistas.

---

## Ejemplo

```txt
resources/
├── views/
├── layouts/
├── fragments/
├── components/
├── css/
├── js/
└── lang/
```

---

## /routes

Definición de rutas.

---

## Ejemplo

```txt
routes/
├── web.php
├── api.php
├── console.php
└── channels.php
```

---

## /storage

Archivos temporales y cache.

---

## Ejemplo

```txt
storage/
├── cache/
├── logs/
├── framework/
├── sessions/
└── views/
```

---

## /tests

Pruebas automatizadas.

---

## Ejemplo

```txt
tests/
├── Feature/
├── Unit/
├── Runtime/
├── Reactive/
└── Protocol/
```

---

## /frontend

Runtime frontend oficial.

---

## Ejemplo

```txt
frontend/
├── runtime/
├── protocol/
├── navigation/
├── hydration/
├── dom/
├── directives/
├── effects/
└── state/
```

---

## /src

Núcleo principal del framework.

---

## Estructura Core

```txt
src/
├── Platform/
├── Quantum/
├── Facades/
├── Helper/
├── Support/
├── Testing/
└── Runtime/
```

---

## src/Platform

Contiene infraestructura principal del framework.

---

## Responsabilidades

- Application Core
- Runtime coordination
- Environment management
- Service providers
- Module loading
- Driver management

---

## Estructura

```txt
Platform/
├── Application.php
├── Kernel.php
├── RuntimeManager.php
├── RuntimeContext.php
├── ModuleRegistry.php
├── Environment.php
├── Providers/
├── Contracts/
└── Exceptions/
```

---

## src/Quantum

Contiene todos los micro-paquetes internos.

---

## Filosofía

Cada módulo Quantum debe tener:

- responsabilidad única
- contratos claros
- bajo acoplamiento
- extensibilidad

---

## Estructura General Quantum

```txt
Quantum/
├── Actions/
├── Auth/
├── Bootstrap/
├── Cache/
├── Collections/
├── Config/
├── Concurrency/
├── Console/
├── Container/
├── Controllers/
├── Cookies/
├── Encryption/
├── Events/
├── Exceptions/
├── Filesystem/
├── Hashing/
├── Http/
├── HttpKernel/
├── Localization/
├── Logging/
├── Mail/
├── Middlewares/
├── Navigation/
├── Pipeline/
├── Protocol/
├── Queue/
├── Reactive/
├── Routing/
├── Runtime/
├── Scheduling/
├── Security/
├── Session/
├── Signals/
├── State/
├── Support/
├── Validation/
├── View/
└── Workers/
```

---

## Estructura Recomendada de un Quantum Module

```txt
Quantum/Cache/
├── Contracts/
├── Drivers/
├── Exceptions/
├── Facades/
├── Providers/
├── Support/
├── Tests/
├── CacheManager.php
└── CacheServiceProvider.php
```

---

## src/Facades

API estática elegante del framework.

---

## Ejemplo

```txt
Facades/
├── App.php
├── Cache.php
├── Config.php
├── Event.php
├── Route.php
├── Runtime.php
├── State.php
└── View.php
```

---

## src/Helper

Funciones helper globales.

---

## Ejemplo

```txt
Helper/
├── app.php
├── paths.php
├── runtime.php
├── state.php
└── helpers.php
```

---

## src/Support

Utilidades reutilizables.

---

## Estructura

```txt
Support/
├── Arr.php
├── Str.php
├── Collection.php
├── AttributeBag.php
├── MetadataBag.php
├── Reflection/
├── Serialization/
└── Runtime/
```

---

## src/Testing

Infraestructura de testing.

---

## Ejemplo

```txt
Testing/
├── TestCase.php
├── RuntimeTestCase.php
├── ComponentTestCase.php
├── Traits/
├── Assertions/
└── Helpers/
```

---

## src/Runtime

Infraestructura específica del runtime reactivo.

---

## Responsabilidades

- hydration
- dehydrate
- snapshots
- runtime lifecycle
- request scope
- reactive orchestration

---

## Estructura

```txt
Runtime/
├── ComponentRegistry/
├── Hydration/
├── Lifecycle/
├── Effects/
├── Diffing/
├── Serialization/
├── Snapshots/
├── State/
├── Workers/
├── Context/
└── Drivers/
```

---

## Frontend Runtime Structure

El runtime frontend vive separado del backend PHP.

---

## Estructura Recomendada

```txt
frontend/runtime/
├── boot/
├── components/
├── directives/
├── dom/
├── effects/
├── events/
├── hydration/
├── navigation/
├── protocol/
├── state/
├── transitions/
├── utils/
└── workers/
```

---

## Frontend Directives

```txt
directives/
├── click.ts
├── model.ts
├── show.ts
├── navigate.ts
├── loading.ts
└── transition.ts
```

---

## DOM Engine Structure

```txt
dom/
├── patcher.ts
├── reconciler.ts
├── fragments.ts
├── morphing.ts
└── dom-manager.ts
```

---

## Protocol Client Structure

```txt
protocol/
├── client.ts
├── serializer.ts
├── payload.ts
├── transport.ts
└── validator.ts
```

---

## Application Structure

Estructura recomendada para aplicaciones VoltStack.

---

## app/

```txt
app/
├── Actions/
├── Components/
├── Console/
├── DTOs/
├── Events/
├── Exceptions/
├── Fragments/
├── Http/
├── Jobs/
├── Layouts/
├── Listeners/
├── Middleware/
├── Models/
├── Notifications/
├── Pages/
├── Policies/
├── Providers/
├── Services/
├── State/
├── Support/
└── Validators/
```

---

## app/Components

Componentes reactivos reutilizables.

---

## Ejemplo

```txt
Components/
├── Button.php
├── Modal.php
├── UserCard.php
└── Dropdown.php
```

---

## app/Pages

Páginas SPA principales.

---

## Ejemplo

```txt
Pages/
├── DashboardPage.php
├── UsersPage.php
└── SettingsPage.php
```

---

## app/Layouts

Layouts globales.

---

## Ejemplo

```txt
Layouts/
├── AppLayout.php
├── AuthLayout.php
└── AdminLayout.php
```

---

## app/Fragments

Fragmentos reutilizables optimizados.

---

## Ejemplo

```txt
Fragments/
├── SidebarFragment.php
├── NavbarFragment.php
└── FooterFragment.php
```

---

## Runtime Cache Structure

VoltStack debe soportar caches persistentes.

---

## Ejemplo

```txt
storage/framework/runtime/
├── metadata/
├── hydration/
├── protocol/
├── fragments/
└── reflection/
```

---

## Worker Structure

Infraestructura runtime persistente.

---

## Ejemplo

```txt
Runtime/Workers/
├── FrankenPhpWorker.php
├── ScopeManager.php
├── WorkerLifecycle.php
└── MemoryMonitor.php
```

---

## Runtime Driver Structure

```txt
Runtime/Drivers/
├── DriverInterface.php
├── FrankenPhpDriver.php
├── FpmDriver.php
├── RoadRunnerDriver.php
└── SwooleDriver.php
```

---

## Naming Conventions

### Classes

```txt
PascalCase
```

---

## Files

```txt
PascalCase.php
```

---

## Directories

```txt
PascalCase/
```

---

## Frontend Runtime Files

```txt
kebab-case.ts
```

---

## Contracts

Todos los contratos deben vivir en:

```txt
Contracts/
```

---

## Providers

Todos los providers deben vivir en:

```txt
Providers/
```

---

## Exceptions

Todas las excepciones deben vivir en:

```txt
Exceptions/
```

---

## Traits

Todos los traits deben vivir en:

```txt
Traits/
```

---

## Testing Strategy

Cada módulo Quantum debe incluir:

```txt
Tests/
```

---

## Runtime-Aware Structure

La estructura debe soportar:

- runtimes persistentes
- runtime isolation
- scoped services
- request scope reset

---

## Separation of Concerns

Separaciones principales:

```txt
Platform = orchestration
Quantum = infrastructure modules
Runtime = reactive runtime
Frontend = browser runtime
Support = reusable utilities
```

---

## Future Goals

La estructura debe permitir evolucionar hacia:

- distributed runtime
- edge runtime
- streaming rendering
- realtime systems
- mobile renderers
- desktop renderers
- microfrontends

---

## MVP Minimal Structure

La primera versión mínima debe incluir:

```txt
src/
├── Platform/
├── Quantum/
│   ├── Container/
│   ├── Config/
│   ├── Http/
│   ├── Routing/
│   ├── Reactive/
│   ├── Protocol/
│   └── View/
├── Runtime/
├── Support/
└── Facades/
```

---

## Conclusión

La estructura de directorios de VoltStack está diseñada para soportar un framework reactivo moderno, modular y optimizado para runtimes persistentes como FrankenPHP.

La separación clara entre:

- Platform
- Quantum
- Runtime
- Frontend Runtime
- Support

permitirá mantener escalabilidad, mantenibilidad y evolución tecnológica a largo plazo.
