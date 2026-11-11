<?php

namespace ApiTypes\Support;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

/**
 * A parsed PHP source file with all class names resolved to their FQCN.
 */
final class PhpFile
{
    /** @var array<string, self|null> */
    private static array $cache = [];

    private function __construct(
        public readonly array $stmts,
        private readonly array $uses,
        private readonly string $namespace,
    ) {}

    public static function forClass(string $class): ?self
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if (! $file || ! is_file($file)) {
            return null;
        }

        if (! array_key_exists($file, self::$cache)) {
            self::$cache[$file] = self::parse($file);
        }

        return self::$cache[$file];
    }

    public function findClass(string $class): ?Class_
    {
        $class = ltrim($class, '\\');

        return (new NodeFinder)->findFirst(
            $this->stmts,
            fn ($node) => $node instanceof Class_ && $node->namespacedName?->toString() === $class,
        );
    }

    /** Resolves a name the way PHP would inside this file (used for docblocks). */
    public function resolveName(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $parts = explode('\\', $name, 2);
        $alias = strtolower($parts[0]);

        if (isset($this->uses[$alias])) {
            return $this->uses[$alias].(isset($parts[1]) ? '\\'.$parts[1] : '');
        }

        return ltrim($this->namespace.'\\'.$name, '\\');
    }

    private static function parse(string $file): ?self
    {
        try {
            $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (Throwable) {
            return null;
        }

        $stmts = (new NodeTraverser(new NameResolver))->traverse($stmts);
        $finder = new NodeFinder;

        $uses = [];
        foreach ($finder->findInstanceOf($stmts, Use_::class) as $use) {
            if ($use->type !== Use_::TYPE_NORMAL) {
                continue;
            }
            foreach ($use->uses as $item) {
                $uses[strtolower($item->getAlias()->toString())] = $item->name->toString();
            }
        }

        $namespace = $finder->findFirstInstanceOf($stmts, Namespace_::class);

        return new self($stmts, $uses, $namespace?->name?->toString() ?? '');
    }
}
