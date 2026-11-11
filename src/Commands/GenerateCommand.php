<?php

namespace ApiTypes\Commands;

use ApiTypes\Collectors\EnumDiscoverer;
use ApiTypes\Collectors\ResponseResolver;
use ApiTypes\Collectors\RouteCollector;
use ApiTypes\Collectors\RulesExtractor;
use ApiTypes\Support\EnumRegistry;
use ApiTypes\Support\ModelInspector;
use ApiTypes\Support\ResourceTypeBuilder;
use ApiTypes\Support\TypeMapper;
use ApiTypes\Writers\TypeScriptWriter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;

class GenerateCommand extends Command
{
    protected $signature = 'api-types:generate
        {--path= : Output directory (default: config api-types.output_path)}
        {--enum-style= : enum | union | const}
        {--check : Do not write; fail if generated files are out of date (for CI)}';

    protected $description = 'Generate TypeScript types, enums and interfaces from API routes and FormRequests';

    public function handle(Router $router, Filesystem $files): int
    {
        $config = config('api-types');
        $path = rtrim($this->option('path') ?: $config['output_path'], '/');
        $style = $this->option('enum-style') ?: ($config['enums']['style'] ?? 'enum');

        if (! in_array($style, ['enum', 'union', 'const'], true)) {
            $this->error("Unknown enum style [{$style}]. Use enum, union or const.");

            return self::FAILURE;
        }

        $collector = new RouteCollector(
            $router,
            new RulesExtractor($this->laravel),
            new ResponseResolver,
            $config['routes'] ?? [],
        );
        $endpoints = $collector->collect();

        foreach ($collector->warnings() as $warning) {
            $this->warn($warning);
        }

        $enums = new EnumRegistry;
        $resources = new ResourceTypeBuilder(new ModelInspector($enums, $config), $enums, $config);

        $output = (new TypeScriptWriter(new TypeMapper($config), $resources, $enums, $style))->write(
            $endpoints,
            (new EnumDiscoverer)->discover($config['enums']['paths'] ?? []),
        );

        if ($this->option('check')) {
            return $this->check($files, $path, $output);
        }

        $files->ensureDirectoryExists($path);

        foreach ($output as $file => $contents) {
            $files->put("{$path}/{$file}", $contents);
            $this->line("  <info>✓</info> {$path}/{$file}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Generated %d endpoints, %d request types, %d with a known response.',
            count($endpoints),
            count(array_unique(array_filter(array_map(fn ($e) => $e->requestClass, $endpoints)))),
            count(array_filter($endpoints, fn ($e) => $e->response !== null)),
        ));

        return self::SUCCESS;
    }

    private function check(Filesystem $files, string $path, array $output): int
    {
        $stale = array_filter(
            array_keys($output),
            fn ($file) => ! $files->exists("{$path}/{$file}") || $files->get("{$path}/{$file}") !== $output[$file],
        );

        if ($stale !== []) {
            $this->error('TypeScript types are out of date: '.implode(', ', $stale));
            $this->line('Run `php artisan api-types:generate` and commit the result.');

            return self::FAILURE;
        }

        $this->info('TypeScript types are up to date.');

        return self::SUCCESS;
    }
}
