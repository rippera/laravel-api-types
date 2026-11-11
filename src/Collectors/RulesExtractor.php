<?php

namespace ApiTypes\Collectors;

use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;

/**
 * Builds a FormRequest instance WITHOUT resolving it through the container
 * (resolving would trigger validation) and reads its rules().
 */
class RulesExtractor
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed>
     */
    public function extract(string $class, Route $route, string $method): array
    {
        /** @var FormRequest $request */
        $request = $class::create('/'.ltrim($route->uri(), '/'), $method);
        $request->setContainer($this->container);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);

        if (! method_exists($request, 'rules')) {
            return [];
        }

        return (array) $this->container->call([$request, 'rules']);
    }
}
