# VoltStack Fases And Versions

## Introducción

Este documento define las fases oficiales de desarrollo de VoltStack y las versiones asociadas a cada etapa de madurez del framework.

Su propósito es convertir la visión arquitectónica en un plan progresivo, medible y publicable, evitando saltos desordenados entre ideas, implementaciones parciales y releases sin utilidad real.

Tambien registra el estado de avance de cada linea para mantener alineadas la documentacion, la implementacion y las demos reales del framework.

---

## Estado De Avance Actual

```txt
0.1.x -> completada
0.2.x -> completada
0.3.x -> completada
0.4.x -> completada
0.5.x -> completada
0.6.x -> completada
0.7.x -> completada
0.8.x -> completada
0.9.x -> completada como release candidate tecnico
0.10.x -> completada (Bloque 11: Explicit Exposure baseline)
0.11.x -> completada (Bloque 12: PHP Attributes wiring security)
0.12.x -> completada (Bloque 13: Observabilidad y contabilidad controllers + security)
0.13.x -> completada (pre-Bloque 14: interceptor pipeline y metadata providers)
0.14.x -> completada (Bloque 14: Worker Safety Budget Hardening + sandbox engine)
0.15.x -> completada (Bloque 15: Policy Composition allOf / anyOf / not + expression parser)
0.16.x -> completada (Bloque 16: End-to-end Skeleton smoke test security)
1.0.0 -> pendiente de consolidacion final
```

### Cierre operativo de 0.9.x

- contratos publicos basicos del framework definidos
- manejo centralizado de excepciones HTML y JSON implementado
- validacion, CSRF y auth base ya integrados
- runtime reactivo minimo validado con `volt-click`, `volt-model` y `volt-submit`
- `app-skeleton` integrado con bootstrap, rutas, controller HTML y pagina reactiva
- pruebas del framework en verde y smoke checks reales del skeleton validados

### Cierre operativo de 0.10.x — Bloque 11 (Explicit Exposure)

- atributo `#[Expose]` para endpoints GDPR/confidenciales (exposed/priority)
- config `controller_security.explicit_exposure` + `deny_by_default`
- excepción `ControllerExposureViolationException` mapeada a HTTP 451 Unavailable For Legal Reasons (RFC 7725)
- fallo seguro fail-closed si la metadata exposure no coincide con la evaluación de la policy

### Cierre operativo de 0.11.x — Bloque 12 (PHP Attributes Security)

- atributos de controller/method: `#[AuthenticationRequired]`, `#[TenantRequired]`, `#[Policies]`, `#[Permissions]`, `#[Expose]`
- extractor de metadata `ControllerEngine::extractSecurityMetadata` con herencia class→method
- `ControllerSecurityContextFactory::create` con triple fallback de headers (server key / HTTP_ prefix / request header)
- parsing Bearer JWT-lite de 3 partes (header.payload.sig) base64 → Principal + AuthenticationStrength

### Cierre operativo de 0.12.x — Bloque 13 (Observabilidad y Contabilidad)

- `ControllerObservabilityManager` con dispatchers: Null, InMemory, JsonLine
- eventos de ciclo de vida controllers: execution.started/completed, invocation.started/completed, compilation.hit/miss
- eventos security: context.created, authorization.evaluating/allowed/denied, authentication.failed
- `ControllerSecurityBudget` para límite de tiempo/memoria/pasos en evaluación de policies
- trazas de decisión `SecurityDecisionCache` por executionId

### Cierre operativo de 0.13.x — Pre-Bloque 14 (Interceptor Pipeline)

- `ControllerInterceptorPipeline` + condiciones de interceptor (env, HTTP method, route name)
- `ControllerRuntimeResolver` con scoped controller disposal
- `MetadataProviderPipeline` (Attribute / Config / Convention / Reflection / Route)
- bindings container para servicios de security, observabilidad y compilation

### Cierre operativo de 0.14.x — Bloque 14 (Worker Safety Budget Hardening)

- `HardenedControllerSecurityDecisionEngine` (wrapper sandbox + budget enforcement)
- `PolicyEvaluationSandbox` con aislamiento de evaluaciones externas (pesos)
- auto-wrap fail-safe de policies expression (`auto_wrap_metadata_policies` + `use_expression_parser`)
- fail closed `SecurityInfrastructureFailureException` → 403 `policy_resolution_failed_fail_closed` sin exponer stack

### Cierre operativo de 0.15.x — Bloque 15 (Policy Composition)

- composite policies: `AnyOfPolicy`, `AllOfPolicy`, `NotPolicy`, `WeightedVotingPolicy`, `AtLeastOnePolicy`
- `PolicyExpressionResolver::parse` con operators: `||`, `&&`, `!`, paréntesis, términos `role:x`/`permission:y`/`tenant:z`
- `ControllerSecurityPolicyRegistry::registerExpression` → id = string expression literal (coincide con lookup de metadata)
- re-construcción de composite policies por constructor (evita readonly reflection) y `ReflectionProperty` setAccessible para children protected

### Cierre operativo de 0.16.x — Bloque 16 (End-to-end Skeleton Smoke Test)

- Controller base `Quantum\Controllers\Controller` implementa `ControllerExecutionContextAwareInterface`
  - métodos protegidos `request()`, `route()`, `security()` (inyectados por `ControllerContextInjector`)
- `SecurityDemoController` con 5 endpoints representativos:
  - `GET /security/demo/public` → explicit allow policy `always.allow.public.smoke`
  - `GET /security/demo/auth-token` → `AuthenticationRequired(Token)` + `Policies(['role:user || permission:dashboard:read'])`
  - `GET /security/demo/admin-mfa` → `AuthenticationRequired(MultiFactor)` + `Policies(['role:admin && permission:admin.panel'])`
  - `GET /security/demo/tenant-scoped` → `TenantRequired` + `Policies(['role:user && tenant:acme-corp'])`
  - `GET /security/demo/gdpr-exposed` → `Expose(exposed:true,priority:10)` + `Policies(['role:admin || (role:officer && permission:gdpr.export)'])`
- ExceptionHandler HTML modo development → bloque EXCEPTION DEBUG (class/message/file/line + trace) para depurar 500 en tests
- `SkeletonSecuritySmokeTest` (PHPUnit Feature) → 9 escenarios E2E por HttpKernel sim-Request:
  - T1 public 200, T2 auth-token sin credenciales 401 WWW-Authenticate, T3 auth-token Bearer role:user 200
  - T4 admin-mfa sin MFA 401, T5 admin-mfa con MFA + admin 200
  - T6 tenant-scoped sin tenant 404, T7 tenant-scoped X-Tenant-Id=acme-corp + role:user 200
  - T8 gdpr-exposed role:user 451 reason_code=unavailable_for_legal_reasons, T9 gdpr-exposed admin 200 con X-Volt-Exposure-Level
- Suite framework completa: **580 tests, 3091 assertions, 0 failures, 0 errors** (sin regresiones respecto a 0.9.x)

### Enfoque inmediato para 1.0.0

- consolidar el alcance oficial de la release estable (Security Enterprise 0.16.0 es baseline)
- reforzar documentacion de APIs publicas y limitaciones del módulo Security
- mantener estabilidad del flujo end-to-end sobre `app-skeleton` (9 smoke scenarios + 5 endpoints)
- mover features no esenciales a fases posteriores (security fine-grained RBAC, ABAC, session auth real)

### Referencia de consolidacion

La definicion oficial del alcance de la release estable queda registrada en `Docs/20-Stable_Release_1.0.0.md`.

---

## Objetivo Principal

Definir:

- las fases reales del framework
- las versiones internas y públicas de cada fase
- los entregables mínimos por versión
- los criterios para avanzar de una fase a otra

---

## Filosofía De Fases

### 1. Cada fase entrega valor real

Una fase no existe para acumular código, sino para habilitar capacidades concretas.

---

### 2. Cada versión debe ser verificable

Toda versión debe poder validarse mediante:

- pruebas
- documentación
- demos funcionales
- criterios claros de salida

---

### 3. Foundation before scale

VoltStack no debe avanzar hacia:

- SPA avanzada
- runtime persistente complejo
- features empresariales
- runtime distribuido

sin un core estable previamente.

---

## Estrategia General De Versionado

```txt
0.1.x → Foundation bootstrap
0.2.x → HTTP and routing foundation
0.3.x → Views, controllers and actions
0.4.x → Reactive components alpha
0.5.x → Volt Protocol and SPA base
0.6.x → Runtime persistence preparation
0.7.x → Enterprise foundation
0.8.x → Advanced reactive preview
0.9.x → Release candidate line
0.10.x → Bloque 11: Explicit Exposure (deny_by_default + 451 Exposure)
0.11.x → Bloque 12: PHP Attributes security wiring
0.12.x → Bloque 13: Observabilidad controllers + security
0.13.x → Pre-Bloque 14: interceptors + metadata providers
0.14.x → Bloque 14: Worker Safety Budget Hardening
0.15.x → Bloque 15: Policy Composition (allOf/anyOf/not + expression)
0.16.x → Bloque 16: End-to-end Skeleton smoke test security
1.0.0 → Stable production release
```

---

## Resumen Global

```txt
Phase 0 → Project foundation
Phase 1 → Core foundation
Phase 2 → HTTP application layer
Phase 3 → Rendering and application programming model
Phase 4 → Reactive runtime alpha
Phase 5 → SPA runtime beta
Phase 6 → Persistent runtime optimization
Phase 7 → Enterprise base
Phase 8 → Advanced reactive preview
Phase 9 → Release candidate
Phase 10 → Stable 1.0
```

---

## Phase 0 — Project Foundation

### Versiones

```txt
0.0.1
0.0.2
0.0.3
```

### Objetivo

Definir la visión, arquitectura, naming, estructura y roadmap del framework.

### Entregables

- documentación fundacional
- lineamientos de arquitectura
- estructura conceptual del proyecto
- roadmap inicial

### Estado esperado

```txt
Architectural foundation only
```

### Nota

Esta es la fase actual documentada del proyecto.

---

## Phase 1 — Core Foundation

### Version Range

```txt
0.1.0 → 0.1.x
```

### Objetivo

Construir la base ejecutable del framework.

### Módulos obligatorios

- bootstrap
- application core
- container
- configuration
- helpers base
- service providers base

### Entregables mínimos

- `Application`
- `Container`
- `ServiceProvider`
- `Bootstrapper`
- `ConfigRepository`
- helper `app()`

### Demo obligatoria

```txt
framework boot successful
```

### Criterio de salida

La aplicación arranca y resuelve servicios base.

---

## Phase 2 — HTTP Application Layer

### Version Range

```txt
0.2.0 → 0.2.x
```

### Objetivo

Construir el flujo HTTP tradicional mínimo.

### Módulos obligatorios

- http
- http kernel
- middleware pipeline
- routing

### Entregables mínimos

- `Request`
- `Response`
- `JsonResponse`
- `RedirectResponse`
- `HttpKernel`
- `Router`
- `Route`

### Demo obligatoria

```txt
GET /
↓
Route resolved
↓
Response sent
```

### Criterio de salida

Una request HTTP puede atravesar todo el pipeline y devolver respuesta.

---

## Phase 3 — Rendering And Programming Model

### Version Range

```txt
0.3.0 → 0.3.x
```

### Objetivo

Habilitar programación de aplicaciones reales sobre el framework.

### Módulos obligatorios

- controllers
- actions
- view system
- facades mínimas

### Entregables mínimos

- `Controller`
- `Action`
- `ViewFactory`
- `PhpViewEngine`
- helpers `view()` y `config()`
- facade `Route`

### Demo obligatoria

```txt
Route
↓
Controller
↓
View
↓
HTML response
```

### Criterio de salida

Ya pueden construirse páginas y endpoints no reactivos.

---

## Phase 4 — Reactive Runtime Alpha

### Version Range

```txt
0.4.0 → 0.4.x
```

### Objetivo

Introducir el modelo de componentes reactivos.

### Módulos obligatorios

- component system
- hydration base
- dehydration
- snapshot model
- action execution

### Entregables mínimos

- `Component`
- `ComponentManager`
- `Snapshot`
- `Hydrator`
- `Dehydrator`
- component rendering

### Demo obligatoria

```txt
Counter component
↓
mount
↓
render
↓
snapshot generated
```

### Criterio de salida

Un componente reactivo puede montarse, serializar estado y rerenderizarse.

---

## Phase 5 — SPA Runtime Beta

### Version Range

```txt
0.5.0 → 0.5.x
```

### Objetivo

Conectar el backend reactivo con un frontend runtime mínimo.

### Módulos obligatorios

- Volt Protocol mínimo
- protocol validation
- reactive endpoint
- frontend runtime base
- DOM replace simple

### Entregables mínimos

- `ProtocolController`
- `ActionRequest`
- `ActionResponse`
- `Checksum`
- JS runtime mínimo

### Demo obligatoria

```txt
click event
↓
POST /_volt/action
↓
hydrate
↓
execute action
↓
render
↓
html patch
```

### Criterio de salida

Una interacción reactiva completa funciona sin recarga de página.

---

## Phase 6 — Persistent Runtime Optimization

### Version Range

```txt
0.6.0 → 0.6.x
```

### Objetivo

Preparar el framework para runtimes persistentes seguros.

### Módulos obligatorios

- runtime context
- scoped services base
- request scope reset
- metadata persistence preparation
- runtime safety guards

### Entregables mínimos

- `RuntimeContext`
- `ScopeManager`
- reset hooks
- runtime-safe service rules

### Demo obligatoria

```txt
multiple requests
↓
scope reset
↓
no state leakage
```

### Criterio de salida

El diseño base ya evita contaminación entre requests.

---

## Phase 7 — Enterprise Foundation

### Version Range

```txt
0.7.0 → 0.7.x
```

### Objetivo

Agregar capacidades base necesarias para adopción empresarial inicial.

### Módulos candidatos

- auth base
- validation
- cache
- events
- security middleware

### Entregables mínimos

- validación backend-first
- CSRF estable
- policies o authorization base
- cache simple

### Criterio de salida

El framework soporta aplicaciones de negocio con seguridad mínima sólida.

---

## Phase 8 — Advanced Reactive Preview

### Version Range

```txt
0.8.0 → 0.8.x
```

### Objetivo

Explorar características reactivas avanzadas sin comprometer estabilidad.

### Módulos candidatos

- signals
- optimistic UI
- lazy hydration
- transitions
- partial navigation avanzada

### Criterio de salida

Existe preview funcional con APIs marcadas como experimentales.

---

## Phase 9 — Release Candidate Line

### Version Range

```txt
0.9.0 → 0.9.x
```

### Objetivo

Congelar APIs principales y preparar la línea estable.

### Requisitos

- documentación alineada al código
- tests del core estables
- demos funcionales consistentes
- seguridad mínima validada
- performance básica medida

### Criterio de salida

Las APIs públicas principales dejan de cambiar de forma agresiva.

---

## Phase 10 — Stable 1.0

### Version

```txt
1.0.0
```

### Objetivo

Publicar la primera versión estable general de VoltStack.

### Debe incluir

- foundation core estable
- HTTP layer estable
- controllers y actions estables
- view system estable
- component system estable
- Volt Protocol funcional
- frontend runtime mínimo estable
- seguridad mínima
- documentación oficial suficiente

### Criterio de salida

VoltStack puede ser usado en proyectos reales con expectativas razonables de estabilidad.

---

## Tabla De Versiones

```txt
0.0.x = arquitectura y documentos base
0.1.x = bootstrap, application, container, config
0.2.x = request, response, kernel, router
0.3.x = controllers, actions, views, helpers
0.4.x = components, hydration, snapshots
0.5.x = protocol, reactive endpoint, frontend runtime base
0.6.x = request scope, runtime context, persistence safety
0.7.x = security, validation, auth base, cache base
0.8.x = features reactivas avanzadas preview
0.9.x = release candidate y estabilización
1.0.0 = release estable
```

---

## Orden Recomendado De Implementación

```txt
0.1.x
↓
0.2.x
↓
0.3.x
↓
0.4.x
↓
0.5.x
↓
0.6.x
↓
0.7.x
↓
0.8.x
↓
0.9.x
↓
1.0.0
```

---

## Versiones Que No Deben Saltarse

Existen fases que no deben comprimirse ni omitirse:

- `0.1.x`
- `0.2.x`
- `0.4.x`
- `0.5.x`
- `0.6.x`

Estas fases forman el núcleo real del framework.

---

## Reglas De Publicación

### Se puede publicar una nueva versión menor cuando:

- existe una capacidad nueva completa
- hay documentación asociada
- existen tests mínimos
- la demo obligatoria funciona

---

### No se debe publicar una nueva versión menor cuando:

- solo existen stubs vacíos
- la arquitectura no está validada en ejecución
- la documentación promete más de lo implementado
- la funcionalidad no puede probarse end-to-end

---

## Criterios Para Moverse Entre Fases

VoltStack solo debe avanzar a la siguiente fase cuando:

- la fase anterior funciona realmente
- los contratos base están claros
- el flujo principal está testeado
- la documentación refleja el estado exacto del repositorio

---

## Primer Objetivo Operativo Recomendado

La primera meta técnica concreta debe ser:

```txt
0.1.x + 0.2.x + 0.3.x
```

Resultado:

```txt
framework bootable
+ routing
+ controllers
+ views
```

Después:

```txt
0.4.x + 0.5.x
```

Resultado:

```txt
reactive component MVP
```

---

## Meta De La Primera Demo Pública

La primera demo que justifica continuidad del desarrollo debe mostrar:

- una aplicación arrancando
- rutas funcionando
- una vista renderizada
- un componente `Counter`
- una acción reactiva
- actualización parcial sin recarga

---

## Conclusión

Las fases y versiones de VoltStack deben expresar una evolución técnica progresiva, realista y verificable.

El framework debe pasar primero por una línea sólida `0.1.x` a `0.6.x`, donde se construya el corazón del sistema, antes de aspirar a características empresariales, runtime avanzado o capacidades distribuidas.
