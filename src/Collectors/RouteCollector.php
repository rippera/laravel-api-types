<?php

namespace ApiTypes\Collectors;

use ApiTypes\Data\Endpoint;
use ApiTypes\Data\ResponseDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class RouteCollector
{
    /** @var string[] */
    private array $warnings = [];

    /** @var array<class-string, array<string, mixed>> */
    private array $rulesCache = [];

    public function __construct(
        private readonly Router $router,
        private readonly RulesExtractor $extractor,
        private readonly ResponseResolver $responses,
        private readonly array $config = [],
    ) {}

    /** @return Endpoint[] */
    public function collect(): array
    {
        $endpoints = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $this->shouldInclude($route)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            [$controller, $action] = $this->action($route);
            $requestClass = $controller ? $this->findFormRequest($controller, $action) : null;

            $endpoints[] = new Endpoint(
                key: $route->getName() ?? implode('|', $methods).' '.$route->uri(),
                methods: $methods,
                uri: $route->uri(),
                uriParams: $this->uriParams($route->uri()),
                requestClass: $requestClass,
                rules: $requestClass ? $this->rules($requestClass, $route, $methods[0] ?? 'GET') : [],
                response: $controller ? $this->response($controller, $action) : null,
            );
        }

        return $endpoints;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function shouldInclude(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');
        $include = $this->config['include_prefixes'] ?? [];

        if ($include !== [] && ! Str::startsWith($uri, $include)) {
            return false;
        }

        if (Str::startsWith($uri, $this->config['exclude_prefixes'] ?? [])) {
            return false;
        }

        $name = $route->getName();

        return ! ($name !== null && Str::is($this->config['exclude_names'] ?? [], $name));
    }

    /** @return array<string, bool> */
    private function uriParams(string $uri): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        $params = [];
        foreach ($matches as $match) {
            $params[$match[1]] = ($match[2] ?? '') === '?';
        }

        return $params;
    }

    /** @return array{0: class-string|null, 1: string|null} */
    private function action(Route $route): array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [null, null]; // Closure route
        }

        [$controller, $method] = explode('@', $action, 2);

        return class_exists($controller) && method_exists($controller, $method)
            ? [$controller, $method]
            : [null, null];
    }

    private function response(string $controller, string $method): ?ResponseDefinition
    {
        try {
            return $this->responses->resolve($controller, $method);
        } catch (Throwable $e) {
            $this->warnings[] = sprintf('Could not infer response of %s@%s: %s', $controller, $method, $e->getMessage());

            return null;
        }
    }

    /** @return class-string<FormRequest>|null */
    private function findFormRequest(string $controller, string $method): ?string
    {
        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    private function rules(string $class, Route $route, string $method): array
    {
        if (array_key_exists($class, $this->rulesCache)) {
            return $this->rulesCache[$class];
        }

        try {
            return $this->rulesCache[$class] = $this->extractor->extract($class, $route, $method);
        } catch (Throwable $e) {
            $this->warnings[] = sprintf('Could not read rules of %s: %s', $class, $e->getMessage());

            return $this->rulesCache[$class] = [];
        }
    }
}
