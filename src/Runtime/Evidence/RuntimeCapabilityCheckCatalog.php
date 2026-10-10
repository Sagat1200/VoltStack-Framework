<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

/**
 * Catálogo cerrado de IDs de verificación runtime por familia/plataforma.
 *
 * Este catálogo NO ejecuta nada: define la superficie de chequeo que una
 * plataforma externa (FrankenPHP, RoadRunner, OpenSwoole, SAPI) debe cumplir
 * para promocionar una evidencia a `native-verified`.
 *
 * Familias cerradas que cuentan para `provesNativeIntegration()`:
 *   - http.native.*
 *   - loop.native.*
 *   - worker.native.*
 *   - signal.native.*
 *   - adapter.native.*
 *
 * Cualquier check id que no empiece por una de estas familias no puede
 * promover un report a `native-verified`, aunque pase.
 */
final class RuntimeCapabilityCheckCatalog
{
    /**
     * @return list<string>
     */
    public static function nativeFamilies(): array
    {
        return [
            'http.native',
            'loop.native',
            'worker.native',
            'signal.native',
            'adapter.native',
        ];
    }

    /**
     * Definiciones cerradas. Cada entrada contiene:
     *   - id
     *   - description (texto humano, español por convención de interfaz VoltStack)
     *   - family (una de nativeFamilies, o categoría no-native para checks auxiliares)
     *   - required_for_native_by_platform: map driver => true si este check es
     *     obligatorio para considerar el driver `native-verified` en esa plataforma
     *   - recommended_for_platforms: list<string> drivers donde el check es
     *     recomendado pero no bloqueante para `native-verified`
     *   - capability_flag: nombre del flag de RuntimeCapabilities que este check
     *     soporta cuando pasa (persistent|concurrent|streaming|drain_control|native_http|null)
     *
     * @return array<string, array{
     *     description: string,
     *     family: string,
     *     required_for_native_by_platform: array<string, bool>,
     *     recommended_for_platforms: list<string>,
     *     capability_flag: string|null,
     * }>
     */
    public static function definitions(): array
    {
        return [
            // --- familia loop.native ---
            'loop.native.boot' => [
                'description' => 'El worker/process nativo arranca y bootstrap de la app se completa sin errores.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'loop.native.start' => [
                'description' => 'El bucle de ejecución nativo se inicializa y acepta la primera iteración.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'loop.native.heartbeat' => [
                'description' => 'El bucle nativo se mantiene vivo y estable tras N iteraciones sin requests.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'loop.native.request_loop' => [
                'description' => 'El bucle de requests nativo procesa N requests inyectadas por el runner sin reinicios.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'loop.native.low_latency' => [
                'description' => 'La latencia p50 del request loop nativo se mantiene por debajo del umbral del adapter.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'loop.native.parallel' => [
                'description' => 'El runtime admite concurrencia genuina (coroutines, workers paralelos o task pool).',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'concurrent',
            ],
            'loop.native.task_steal' => [
                'description' => 'El scheduler puede robar tareas entre workers para balancear carga.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole'],
                'capability_flag' => 'concurrent',
            ],
            'loop.native.coro_support' => [
                'description' => 'El runtime nativo soporta coroutines explícitas (sched cooperativo sin blocking de loops).',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole', 'frankenphp', 'roadrunner'],
                'capability_flag' => 'concurrent',
            ],
            'loop.native.jobs_queue' => [
                'description' => 'El runner puede despachar tareas a una cola/jobs pool nativa sin bloquear el request loop.',
                'family' => 'loop.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'concurrent',
            ],

            // --- familia http.native ---
            'http.native.status200' => [
                'description' => 'Una petición HTTP básica retorna status 200 a través del bridge nativo, no vía SAPI fallback.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.headers' => [
                'description' => 'Los headers HTTP de la respuesta salen por el canal nativo con valores esperados.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.status200_nofallback' => [
                'description' => 'Se confirma que la respuesta no pasó por ningún fallback SAPI ni emulador de request/response.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.running_status' => [
                'description' => 'El estado runtime (running worker / warm / idle) se expone correctamente por la API del runner.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.requestcount' => [
                'description' => 'El contador nativo de requests procesadas coincide con requests inyectadas.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.response_time_under_100ms' => [
                'description' => 'El tiempo de respuesta end-to-end HTTP nativo (despacho a flush) se mantiene p95 por debajo de 100 ms.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.streaming_enabled' => [
                'description' => 'El bridge HTTP nativo envía respuestas chunked y no bufferiza el body completo.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'streaming',
            ],
            'http.native.body_readable' => [
                'description' => 'El cuerpo HTTP leído por la app a través del bridge nativo es byte-identico al enviado por el cliente.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.headers_present' => [
                'description' => 'Los headers nativos esperados (X-Powered-By FrankenPHP, Server: RoadRunner etc.) aparecen intactos.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.routing_ok' => [
                'description' => 'El router de la app resuelve paths típicos inyectados a través del bridge nativo sin errores.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.middleware_stack' => [
                'description' => 'La pila de middlewares de la app se ejecuta completa en orden para requests servidos por el runner nativo.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                ],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.persistent_connection' => [
                'description' => 'El runner nativo mantiene conexiones TCP persistentes cuando el cliente envía Connection: keep-alive.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],
            'http.native.error_recovery' => [
                'description' => 'Si una request levanta excepción, el worker nativo no crashea y la request siguiente sigue siendo procesada.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'persistent',
            ],
            'http.native.stream' => [
                'description' => 'El runtime emite respuestas streaming chunked correctamente por el bridge nativo.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'streaming',
            ],
            'http.native.ssechunk' => [
                'description' => 'El runtime emite eventos SSE chunk a chunk sin buffer por el bridge nativo.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'streaming',
            ],
            'http.native.keepalive' => [
                'description' => 'Conexiones keep-alive TCP son mantenidas correctamente por el runner nativo.',
                'family' => 'http.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'native_http',
            ],

            // --- familia worker.native ---
            'worker.native.spawn' => [
                'description' => 'El runner crea un worker PHP listo para aceptar requests.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'worker.native.loop' => [
                'description' => 'El worker cumple N iteraciones de aceptar/procesar requests sin crashear.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'worker.native.persistent' => [
                'description' => 'El worker PHP se mantiene vivo entre requests, sin reiniciar después de cada despacho.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'persistent',
            ],
            'worker.native.concurrent_serve' => [
                'description' => 'El worker nativo sirve múltiples requests concurrentes (coros o pool) sin serializar todas las llamadas.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'concurrent',
            ],
            'worker.native.graceful_drain' => [
                'description' => 'Cuando llega señal de parada, el worker drena las requests en vuelo y termina limpiamente.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'drain_control',
            ],
            'worker.native.pool' => [
                'description' => 'El runner levanta un pool de >1 workers y los balancea.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'concurrent',
            ],
            'worker.native.max_requests' => [
                'description' => 'Al alcanzar max_requests el worker termina limpiamente sin dejar conexiones colgadas.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => null,
            ],
            'worker.native.stream' => [
                'description' => 'El worker puede transmitir respuestas streaming sin bloquear la aceptación de nuevas requests.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'streaming',
            ],
            'worker.native.drain' => [
                'description' => 'El worker drena requests en vuelo antes de apagarse sin perder tráfico.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'drain_control',
            ],
            'worker.native.job_pool' => [
                'description' => 'El runner expone un pool de jobs/tasks separado del pool HTTP para background work.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'concurrent',
            ],
            'worker.native.job_dispatch' => [
                'description' => 'Una tarea enviada al job pool es procesada y reporta ACK sin perder el request caller.',
                'family' => 'worker.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'concurrent',
            ],

            // --- familia signal.native ---
            'signal.native.stop' => [
                'description' => 'Señal de stop nativa termina el bucle y libera recursos sin SIGKILL.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.drain' => [
                'description' => 'Señal de drain nativa deja de aceptar nuevas requests y espera las en-vuelo antes de apagar.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.sigterm' => [
                'description' => 'SIGTERM dispara un drain ordenado y termina el proceso con exit code limpio.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.sigusr1' => [
                'description' => 'SIGUSR1 dispara la acción esperada (reload / health dump / stats) sin interrumpir tráfico.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.reload' => [
                'description' => 'Señal de reload nativa recrea workers sin perder tráfico de requests en-vuelo.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole', 'frankenphp'],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.heartbeat' => [
                'description' => 'Señal de heartbeat periódica confirma worker vivo ante supervisor externo.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole', 'roadrunner'],
                'capability_flag' => 'drain_control',
            ],
            'signal.native.stream_hook' => [
                'description' => 'Los hooks de señal se integran con streaming sin cortar chunks en vuelo.',
                'family' => 'signal.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner', 'openswoole'],
                'capability_flag' => 'streaming',
            ],

            // --- familia coroutine.native (nueva en DV-BS-022; familia non-native por ahora, IDs de trazabilidad) ---
            'coroutine.native.go' => [
                'description' => 'El runtime permite lanzar coroutines ligeras mediante API go()/spawn() equivalente.',
                'family' => 'coroutine.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole'],
                'capability_flag' => 'concurrent',
            ],
            'coroutine.native.channel' => [
                'description' => 'Las coroutines se comunican por channels MPSC con semántica push/pop bloqueante o timeout.',
                'family' => 'coroutine.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole'],
                'capability_flag' => 'concurrent',
            ],

            // --- familia task.native (nueva en DV-BS-022; IDs de trazabilidad) ---
            'task.native.dispatch' => [
                'description' => 'El runner puede despachar tasks de worker a pool y recibir resultado sin reiniciar worker caller.',
                'family' => 'task.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole', 'roadrunner'],
                'capability_flag' => 'concurrent',
            ],
            'task.native.parallel' => [
                'description' => 'N tasks idénticas se ejecutan en paralelo y recolectan resultados con orden preservado.',
                'family' => 'task.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole', 'roadrunner'],
                'capability_flag' => 'concurrent',
            ],

            // --- familia adapter.native ---
            'adapter.native.registration' => [
                'description' => 'El adapter de VoltStack se registra contra la API nativa del runner sin bridges intermedios.',
                'family' => 'adapter.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => null,
            ],
            'adapter.native.handler' => [
                'description' => 'El handler de request del adapter es el que ejecuta el callback nativo, confirmado por stacktrace.',
                'family' => 'adapter.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole'],
                'capability_flag' => null,
            ],
            'adapter.native.snapshot_match' => [
                'description' => 'El snapshot de capabilities declarado por el adapter coincide con lo realmente ofrecido por el runner nativo.',
                'family' => 'adapter.native',
                'required_for_native_by_platform' => [
                    'frankenphp' => true,
                    'roadrunner' => true,
                    'openswoole' => true,
                ],
                'recommended_for_platforms' => [],
                'capability_flag' => null,
            ],
            'adapter.native.spiral_match' => [
                'description' => 'RoadRunner (Spiral) reporta estado ok vía proto/status y coincide con snapshot adapter.',
                'family' => 'adapter.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['roadrunner'],
                'capability_flag' => null,
            ],
            'adapter.native.openswoole_snapshot_match' => [
                'description' => 'OpenSwoole server stats (worker_num, task_num) coincide con snapshot adapter + max_requests.',
                'family' => 'adapter.native',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['openswoole'],
                'capability_flag' => null,
            ],

            // --- checks no nativos (solo de trazabilidad; no promocionan a native-verified) ---
            'bootstrap.app_booted' => [
                'description' => 'El contenedor de la app bootea dentro del worker sin errores.',
                'family' => 'bootstrap',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole', 'sapi'],
                'capability_flag' => null,
            ],
            'config.snapshot_stable' => [
                'description' => 'El snapshot de config publicado coincide con el efectivo cargado por el worker.',
                'family' => 'config',
                'required_for_native_by_platform' => [],
                'recommended_for_platforms' => ['frankenphp', 'roadrunner', 'openswoole', 'sapi'],
                'capability_flag' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allKnownCheckIds(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @param list<string> $checkIds
     * @return list<string> ids que NO existen en el catálogo cerrado.
     */
    public static function unknownCheckIds(array $checkIds): array
    {
        $known = array_flip(self::allKnownCheckIds());

        return array_values(array_filter(
            $checkIds,
            static fn (string $id): bool => ! isset($known[$id]),
        ));
    }

    /**
     * @return array{
     *     required_for_native: list<string>,
     *     recommended: list<string>,
     * }
     */
    public static function platformChecks(string $driver): array
    {
        $required = [];
        $recommended = [];

        foreach (self::definitions() as $id => $def) {
            if (($def['required_for_native_by_platform'][$driver] ?? false) === true) {
                $required[] = $id;
            }
            if (in_array($driver, $def['recommended_for_platforms'], true)) {
                $recommended[] = $id;
            }
        }

        sort($required);
        sort($recommended);

        return [
            'required_for_native' => $required,
            'recommended' => $recommended,
        ];
    }

    /**
     * Dado un report, devuelve los checks obligatorios para ese driver que NO
     * han pasado o ni siquiera aparecen en el report. Útil para auditorías.
     *
     * @return array{missing_required: list<string>, failed_required: list<string>}
     */
    public static function auditRequiredChecks(RuntimeCapabilityVerificationReport $report): array
    {
        $expected = self::platformChecks($report->driver());
        $required = $expected['required_for_native'];

        $byId = [];
        foreach ($report->checks() as $check) {
            $byId[$check->id()] = $check;
        }

        $missing = [];
        $failed = [];
        foreach ($required as $id) {
            if (! isset($byId[$id])) {
                $missing[] = $id;
                continue;
            }
            if (! $byId[$id]->passed()) {
                $failed[] = $id;
            }
        }

        sort($missing);
        sort($failed);

        return [
            'missing_required' => $missing,
            'failed_required' => $failed,
        ];
    }

    /**
     * Devuelve true si el id de check pertenece a alguna familia nativa.
     */
    public static function isNativeFamilyCheck(string $checkId): bool
    {
        foreach (self::nativeFamilies() as $family) {
            if (str_starts_with($checkId, $family . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> plataformas (drivers) cubiertos por el catálogo.
     */
    public static function supportedPlatforms(): array
    {
        $platforms = [];
        foreach (self::definitions() as $def) {
            foreach (array_keys($def['required_for_native_by_platform']) as $driver) {
                $platforms[$driver] = true;
            }
            foreach ($def['recommended_for_platforms'] as $driver) {
                $platforms[$driver] = true;
            }
        }
        $platforms = array_keys($platforms);
        sort($platforms);

        return $platforms;
    }

    /**
     * Auditoría compacta de un report contra el catálogo para un driver.
     * Devuelve unknowns + required faltantes + required fallidos.
     *
     * @return array{
     *     unknown_ids: list<string>,
     *     missing_required_ids: list<string>,
     *     failed_required_ids: list<string>,
     * }
     */
    public static function auditReport(RuntimeCapabilityVerificationReport $report, ?string $driver = null): array
    {
        $checkIds = array_map(
            static fn(RuntimeCapabilityVerificationCheck $check): string => $check->id(),
            $report->checks(),
        );

        $requiredAudit = self::auditRequiredChecks($report);

        return [
            'unknown_ids' => self::unknownCheckIds($checkIds),
            'missing_required_ids' => $requiredAudit['missing_required'],
            'failed_required_ids' => $requiredAudit['failed_required'],
        ];
    }
}
