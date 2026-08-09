# VoltStack Roadmap

## Introducción

Este documento define la evolución planificada de VoltStack desde su núcleo inicial hasta convertirse en un framework SPA reactivo moderno impulsado por PHP.

Tambien registra el estado operativo actual del proyecto para que el roadmap no funcione solo como vision, sino como referencia de entrega real.

---

## Estado Actual

### Linea actual

```txt
0.17.0 - Public Contract Freeze (Bloque 17: Signature Alignment & Baseline SHA256) completada
```

### Capacidades ya verificadas

- bootstrap real de aplicacion
- container con `bind`, `singleton`, `instance` y `scoped`
- request/response HTTP
- `HttpKernel` con middleware pipeline
- routing funcional para controllers y paginas
- controllers, actions y vistas PHP
- componentes reactivos con hydration/dehydration
- snapshots firmados con checksum
- endpoint reactivo `/_volt/action`
- runtime frontend minimo con `volt-click`, `volt-model` y `volt-submit`
- `RuntimeContext` y `ScopeManager` para aislamiento por request
- validacion backend-first
- CSRF estable
- auth base por request
- manejo centralizado de errores HTML y JSON (incluye bloque debug throwable en dev)
- integracion real con `app-skeleton`
- **Security Enterprise (Bloques 11-16)**
  - Bloque 11: Explicit Exposure (Expose attribute + deny_by_default)
  - Bloque 12: PHP Attributes wiring (AuthenticationRequired / TenantRequired / Policies / Permissions)
  - Bloque 13: Observabilidad y contabilidad (ControllerObservabilityManager + eventos security)
  - Bloque 14: Worker Safety Budget Hardening (Hardened engine + sandbox)
  - Bloque 15: Policy Composition (allOf / anyOf / not + expression parser + composite policies)
  - Bloque 16: End-to-end Skeleton smoke test (5 endpoints demo + 9 escenarios E2E PHPUnit)
- **Contract Freeze (Bloque 17)**
  - Interfaces públicas estables marcadas con `@api` (Container/Kernel/Security/Observability/etc.)
  - `PublicContractSignatureTest` con baseline SHA256 de firmas públicas (interfaces + enums + DTO readonly + attributes + exceptions)
  - Congelación de firmas Backward Compatible hasta versión MAJOR 2.x
  - Regla "safe reflection": sólo símbolos sin side-effects en baseline (evita crash por clases que necesitan container bootstrap)

### Evidencia de cierre de 0.17.0

```txt
Framework tests core:
- PublicContractSignatureTest: OK (1 test, 1 assertion)
  - Baseline SHA256 = b2988ce073b081cfe04ea5a45dfc78fefea670d6c9a7d8f7567a370afd4f7e51
    (actualizado fix BC Throwable import ExceptionHandlerInterface 2026-08-09)
  - Longitud canonical = 27 145 bytes
  - 80+ símbolos públicos congelados: 39 contracts / 3 enums / 8 security DTOs / 7 composition policies
                                / 7 security exceptions / 6 runtime exception DTOs / 6 PHP attributes
- SkeletonSecuritySmokeTest: OK (9 / 9, 74 assertions)
- PolicyCompositionTest: OK (40 tests Bloque 15, sin regresiones)
- ContainerTest / BootstrapperTest: OK (sin regresiones)
- ControllerSecurityContextFactoryTest: OK (6 / 6 Bloque 12)

Interfaces públicas @api marcadas:
- ContainerInterface, MiddlewareInterface, Kernel (platform)
- ControllerExecutionContextAwareInterface
- ExceptionHandlerInterface, ExceptionMapperInterface
- SecurityManagerInterface, DecisionEngineInterface, PolicyRegistryInterface, PrincipalInterface
- Contracts Observability, Interceptors, Metadata, Routing, Compilation, Cache
```

### Interpretacion

VoltStack ya no se encuentra solo en fase conceptual. La linea `0.9.x` deja un core ejecutable, y la linea `0.16.0` cierra el stack de seguridad empresarial completo (explicitness, composición, hardening, observabilidad y smoke test end-to-end) con demo real en `app-skeleton` y validación PHPUnit.

El roadmap está diseñado bajo una estrategia progresiva:

```txt
Core Stability
↓
Reactive Runtime
↓
SPA Experience
↓
Persistent Runtime Optimization
↓
Enterprise Features
↓
Distributed Runtime Future
```

VoltStack busca evolucionar cuidadosamente evitando:

- complejidad innecesaria
- acoplamiento excesivo
- crecimiento descontrolado
- pérdida de rendimiento
- fragmentación arquitectónica

---

## Filosofía del Roadmap

### 1. Runtime First

La arquitectura reactiva y persistente es prioridad.

---

### 2. Stable Foundations

La infraestructura base debe ser sólida antes de escalar funcionalidades.

---

### 3. Performance Native

Cada fase debe mantener foco en rendimiento.

---

### 4. PHP Developer Experience

La experiencia del desarrollador PHP es prioridad.

---

### 5. Incremental Evolution

VoltStack debe evolucionar por capas bien definidas.

---

## Visión General

### Objetivo Final

Construir un framework PHP moderno capaz de ofrecer:

- SPA reactiva
- rendering incremental
- runtime persistente
- frontend runtime nativo
- experiencia similar a frameworks SPA modernos
- productividad estilo Laravel + Livewire
- rendimiento optimizado para FrankenPHP

---

## Fases del Roadmap

```txt
Phase 1 → Foundation Core
Phase 2 → Reactive Runtime
Phase 3 → SPA Runtime
Phase 4 → Persistent Runtime Optimization
Phase 5 → Enterprise Features
Phase 6 → Advanced Reactive Features
Phase 7 → Distributed Runtime Future
```

---

## Objetivo Inmediato

### Siguiente hito recomendado

```txt
1.0.0 - stable production release (pre-work Security Enterprise ya completo)
```

### Trabajo restante minimo para 1.0.0

- alinear documentacion publica con las APIs realmente expuestas (incluir modulo Security Enterprise)
- congelar contratos publicos del kernel, excepciones, runtime reactivo y security framework
- mantener estabilidad del flujo end-to-end sobre `app-skeleton` (incluir smoke 9 escenarios security)
- cerrar paginas y respuestas de error para uso general (ExceptionHandler RFC9457 + debug throwable HTML)
- documentar limitaciones y APIs experimentales del runtime frontend
- definir alcance oficial de `1.0.0` y mover lo no esencial a roadmap posterior (Security Enterprise 0.16.0 ya es baseline)

### Trabajo que no debe bloquear 1.0.0

- CLI completo
- navegacion SPA avanzada
- cache distribuido
- ORM
- queue system
- runtime distribuido

### Salida esperada de 1.0.0

```txt
Core estable + HTTP estable + vistas estables + componentes reactivos base estables + integracion real de aplicacion
```

### Documento de alcance oficial

El alcance estable de `1.0.0`, sus APIs publicas recomendadas y sus exclusiones quedan definidos en `Docs/20-Stable_Release_1.0.0.md`.

---

## Phase 1 — Foundation Core

### Objetivo

Construir el núcleo estable del framework.

---

## Prioridad

CRÍTICA

---

## Estado esperado

```txt
MVP foundation
```

---

## Componentes Principales

---

## Quantum Core

### Módulos iniciales

```txt
Container
Config
Http
HttpKernel
Routing
View
Events
Support
Bootstrap
```

---

## Platform Core

### Objetivos

- Application lifecycle
- runtime manager
- environment management
- module loading

---

## Facades

### Objetivos

API elegante tipo Laravel.

---

## Runtime Base

### Objetivos

Infraestructura inicial para:

- hydration
- rendering
- snapshots
- protocol runtime

---

## CLI Tooling

### Objetivos

```bash
volt new
volt serve
volt make:component
volt make:page
```

---

## Objetivos Técnicos

- estructura modular
- contracts base
- service providers
- routing system
- request lifecycle
- basic views

---

## Deliverables

```txt
Foundation MVP
```

---

## Phase 2 — Reactive Runtime

### Objetivo

Construir el runtime reactivo principal.

---

## Prioridad

MUY ALTA

---

## Estado esperado

```txt
Live reactive components
```

---

## Features principales

---

## Component System

### Implementar

- component lifecycle
- reactive properties
- nested components
- component registry

---

## Hydration System

### Implementar

- snapshots
- hydration
- dehydration
- dirty detection

---

## State System

### Implementar

- local state
- shared state
- protected state
- computed properties

---

## Event System

### Implementar

- component events
- runtime events
- browser events

---

## Render Pipeline

### Implementar

- partial rendering
- fragment rendering
- effect generation

---

## Deliverables

```txt
Reactive runtime alpha
```

---

## Phase 3 — SPA Runtime

### Objetivo

Construir experiencia SPA completa.

---

## Prioridad

MUY ALTA

---

## Estado esperado

```txt
Reactive SPA experience
```

---

## Frontend Runtime

### Implementar

- component discovery
- DOM patching
- directives
- navigation runtime
- state sync

---

## Volt Protocol

### Implementar

- protocol transport
- payload serialization
- effect transport
- snapshot synchronization

---

## SPA Navigation

### Implementar

- client navigation
- preserve state
- transitions
- prefetch básico

---

## Frontend Directives

### Implementar

```txt
volt:click
volt:model
volt:submit
volt:show
volt:navigate
```

---

## Effects System

### Implementar

```txt
text.update
html.replace
navigate
toast
modal
```

---

## Deliverables

```txt
SPA runtime beta
```

---

## Phase 4 — Persistent Runtime Optimization

### Objetivo

Optimización profunda para runtimes persistentes.

---

## Prioridad

CRÍTICA

---

## Estado esperado

```txt
FrankenPHP optimized runtime
```

---

## FrankenPHP Integration

### Implementar

- persistent workers
- scoped services
- runtime reset
- worker lifecycle

---

## Reflection Cache

### Implementar

- metadata persistence
- reflection reuse
- serializer reuse

---

## Runtime Memory Management

### Implementar

- memory monitoring
- worker recycling
- stale cleanup

---

## Performance Layer

### Implementar

- fragment cache
- render cache
- serializer optimization

---

## Runtime Drivers

### Implementar

```txt
FrankenPhpDriver
FpmDriver
```

---

## Deliverables

```txt
Persistent runtime stable
```

---

## Phase 5 — Enterprise Features

### Objetivo

Expandir VoltStack hacia aplicaciones empresariales.

---

## Prioridad

ALTA

---

## Estado esperado

```txt
Enterprise-ready framework
```

---

## Features

---

## Auth System

### Implementar

- session auth
- SPA auth
- reactive auth
- policies
- gates

---

## Queue System

### Implementar

- jobs
- delayed jobs
- failed jobs

---

## Cache System

### Implementar

- Redis
- runtime cache
- fragment cache

---

## Database Layer

### Implementar

- ORM
- query builder
- transactions

---

## Validation System

### Implementar

- reactive validation
- async validation futuro

---

## Security Layer

### Implementar

- CSRF
- protocol validation
- protected properties
- payload signing

---

## Deliverables

```txt
Enterprise edition foundation
```

---

## Phase 6 — Advanced Reactive Features

### Objetivo

Agregar capacidades reactivas avanzadas.

---

## Prioridad

MEDIA

---

## Estado esperado

```txt
Advanced reactive runtime
```

---

## Signals System

### Implementar

```php
$count = signal(0);
```

---

## Watchers

### Implementar

- reactive watchers
- dependency tracking

---

## Async Actions

### Implementar

```php
#[Async]
```

---

## Streaming Rendering

### Implementar

- incremental rendering
- streaming fragments

---

## Lazy Hydration

### Implementar

- deferred hydration
- progressive hydration

---

## Optimistic UI

### Implementar

- frontend optimistic updates
- state reconciliation

---

## Deliverables

```txt
Advanced runtime preview
```

---

## Phase 7 — Distributed Runtime Future

### Objetivo

Explorar runtime distribuido.

---

## Prioridad

FUTURA

---

## Estado esperado

```txt
Distributed Volt Runtime
```

---

## Features Futuras

---

## WebSocket Runtime

### Implementar

- persistent connections
- realtime events
- streaming updates

---

## Distributed State

### Implementar

- multi-worker synchronization
- shared runtime state

---

## Edge Runtime

### Implementar

- edge rendering
- edge hydration

---

## Runtime Clustering

### Implementar

- worker clusters
- distributed events

---

## Binary Volt Protocol

### Implementar

- compressed payloads
- binary serialization

---

## Deliverables

```txt
Distributed runtime research
```

---

## MVP Scope

### Objetivo realista inicial

VoltStack v1 MVP debe incluir:

---

## Core

```txt
Container
Config
Http
Routing
Views
```

---

## Reactive Runtime

```txt
Components
Hydration
State
Events
Partial rendering
```

---

## SPA Runtime

```txt
Frontend runtime
Volt Protocol
Navigation
Effects
```

---

## Runtime

```txt
FrankenPHP integration
Scoped services
Reflection cache
```

---

## Developer Experience

```txt
CLI
Generators
Error pages
Debug mode
```

---

## Recommended Development Order

### Orden recomendado de implementación

---

## Step 1

```txt
Foundation Core
```

---

## Step 2

```txt
Container + Config + Bootstrap
```

---

## Step 3

```txt
HTTP + Routing + Views
```

---

## Step 4

```txt
Component System
```

---

## Step 5

```txt
Hydration System
```

---

## Step 6

```txt
Volt Protocol
```

---

## Step 7

```txt
Frontend Runtime
```

---

## Step 8

```txt
Render Pipeline
```

---

## Step 9

```txt
SPA Navigation
```

---

## Step 10

```txt
FrankenPHP Runtime Optimization
```

---

## Step 11

```txt
Security Layer
```

---

## Step 12

```txt
Performance Layer
```

---

## Internal Milestones

---

## Alpha

### Objetivo

Reactive components funcionando.

---

## Beta

### Objetivo

SPA completa funcional.

---

## RC

### Objetivo

FrankenPHP optimized stable runtime.

---

## Stable v1

### Objetivo

Framework listo para producción.

---

## Long-Term Vision

VoltStack busca evolucionar hacia:

- runtime PHP reactivo moderno
- SPA-first PHP framework
- cloud-ready framework
- distributed runtime future
- realtime PHP applications
- streaming UI architecture

---

## Technical Priorities

Las prioridades técnicas más importantes son:

---

## 1. Runtime Stability

---

## 2. Hydration Reliability

---

## 3. Performance

---

## 4. Security

---

## 5. Developer Experience

---

## 6. Runtime Persistence Safety

---

## Anti-Goals

VoltStack NO busca:

- reemplazar completamente React
- convertirse en framework JS-first
- depender de Node.js runtime
- requerir frontend complejo
- sacrificar simplicidad PHP

---

## Ecosystem Goals

Objetivos futuros:

- package ecosystem
- VoltStack Cloud
- official UI components
- realtime runtime
- desktop runtime
- mobile renderer

---

## Team Scaling Goals

La arquitectura debe permitir:

- contributors
- package ecosystem
- module isolation
- independent subsystem evolution

---

## Future Research Areas

### Concurrent Rendering

### Binary Protocol

### Runtime Fibers

### Streaming UI

### Distributed Runtime

### Edge Rendering

---

## Success Criteria

VoltStack será exitoso si logra:

- experiencia SPA moderna
- rendimiento alto
- DX elegante para PHP
- runtime persistente estable
- arquitectura modular
- bajo costo cognitivo
- adopción natural por comunidad PHP

---

## Conclusión

El roadmap de VoltStack define una evolución progresiva hacia un framework PHP reactivo moderno optimizado para runtimes persistentes y aplicaciones SPA de nueva generación.

La prioridad principal debe mantenerse siempre en:

- estabilidad arquitectónica
- rendimiento
- simplicidad para desarrolladores PHP
- seguridad
- experiencia reactiva fluida
- integración profunda con FrankenPHP
