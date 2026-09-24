<?php

declare(strict_types=1);

use Quantum\Config\ConfigRepository;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Auth\AuthManager;
use Quantum\Cache\Repository as CacheRepository;
use Quantum\Http\Response;
use Quantum\Http\ResponseFactory;
use Quantum\Routing\Router;
use Quantum\Security\CsrfTokenManager;
use Quantum\Validation\Validator;
use Quantum\View\View;
use Quantum\View\ViewFactory;
use VoltStack\Framework\Application;

if (! function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $app = Application::getInstance();

        if ($app === null) {
            throw new RuntimeException('The VoltStack application instance has not been bootstrapped.');
        }

        if ($abstract === null) {
            return $app;
        }

        return $app->make($abstract, $parameters);
    }
}

if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        /** @var Application $app */
        $app = app();
        /** @var ConfigRepository $repository */
        $repository = $app->make(ConfigRepository::class);

        if ($key === null) {
            return $repository->all();
        }

        return $repository->get($key, $default);
    }
}

if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        /** @var Application $app */
        $app = app();

        return $app->basePath($path);
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        /** @var Application $app */
        $app = app();

        return $app->storagePath($path);
    }
}

if (! function_exists('cache_path')) {
    function cache_path(string $path = ''): string
    {
        /** @var Application $app */
        $app = app();

        return $app->cachePath($path);
    }
}

if (! function_exists('class_path')) {
    function class_path(string $path = ''): string
    {
        return base_path($path);
    }
}

if (! function_exists('view_path')) {
    function view_path(string $path = ''): string
    {
        return base_path($path);
    }
}

if (! function_exists('single_page_path')) {
    function single_page_path(string $path = ''): string
    {
        return base_path($path);
    }
}

if (! function_exists('view')) {
    function view(string $name, array $data = []): View
    {
        /** @var ViewFactory $factory */
        $factory = app(ViewFactory::class);

        return $factory->make($name, $data);
    }
}

if (! function_exists('response')) {
    function response(string $content = '', int $statusCode = 200, array $headers = []): ResponseFactory|Response
    {
        /** @var ResponseFactory $factory */
        $factory = app(ResponseFactory::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($content, $statusCode, $headers);
    }
}

if (! function_exists('route')) {
    function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        /** @var Router $router */
        $router = app(Router::class);

        return $router->route($name, $parameters, $absolute);
    }
}

if (! function_exists('signed_route')) {
    function signed_route(string $name, array $parameters = [], bool $absolute = true): string
    {
        /** @var Router $router */
        $router = app(Router::class);

        return $router->signedRoute($name, $parameters, $absolute);
    }
}

if (! function_exists('temporary_signed_route')) {
    function temporary_signed_route(
        string $name,
        \DateInterval|\DateTimeInterface|int $expiration,
        array $parameters = [],
        bool $absolute = true,
    ): string {
        /** @var Router $router */
        $router = app(Router::class);

        return $router->temporarySignedRoute($name, $expiration, $parameters, $absolute);
    }
}

if (! function_exists('cache')) {
    function cache(?string $key = null, mixed $default = null): mixed
    {
        /** @var CacheRepository $repository */
        $repository = app(CacheRepository::class);

        if ($key === null) {
            return $repository;
        }

        return $repository->get($key, $default);
    }
}

if (! function_exists('e')) {
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('volt_runtime_path')) {
    function volt_runtime_path(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'volt.js';

        if (! is_file($path)) {
            throw new RuntimeException('The VoltStack frontend runtime script could not be found.');
        }

        return $path;
    }
}

if (! function_exists('volt_runtime_contents')) {
    function volt_runtime_contents(): string
    {
        $contents = file_get_contents(volt_runtime_path());

        if (! is_string($contents)) {
            throw new RuntimeException('The VoltStack frontend runtime script could not be read.');
        }

        return $contents;
    }
}

if (! function_exists('volt_runtime_version')) {
    function volt_runtime_version(): string
    {
        $timestamp = filemtime(volt_runtime_path());

        if ($timestamp === false) {
            return '0';
        }

        return (string) $timestamp;
    }
}

if (! function_exists('volt_runtime_url')) {
    function volt_runtime_url(): string
    {
        return '/_volt/runtime.js?v=' . rawurlencode(volt_runtime_version());
    }
}

if (! function_exists('volt_runtime_script')) {
    function volt_runtime_script(): string
    {
        return sprintf(
            '<script data-volt-runtime="true" src="%s" defer></script>',
            e(volt_runtime_url()),
        );
    }
}

if (! function_exists('validator')) {
    function validator(): Validator
    {
        return app(Validator::class);
    }
}

if (! function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(CsrfTokenManager::class)->token();
    }
}

if (! function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (! function_exists('auth')) {
    function auth(): AuthManager
    {
        return app(AuthManager::class);
    }
}

if (! function_exists('authorization')) {
    function authorization(): AuthorizationManagerInterface
    {
        return app(AuthorizationManagerInterface::class);
    }
}

if (! function_exists('can')) {
    function can(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return authorization()->check($ability, $subject, $context, $principal);
    }
}

if (! function_exists('cannot')) {
    function cannot(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return authorization()->cannot($ability, $subject, $context, $principal);
    }
}

if (! function_exists('authorize')) {
    function authorize(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult {
        return authorization()->authorize($ability, $subject, $context, $principal);
    }
}
