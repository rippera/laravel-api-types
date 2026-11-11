<?php

namespace ApiTypes\Collectors;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class EnumDiscoverer
{
    /**
     * @param  string[]  $paths
     * @return array<string, class-string<\UnitEnum>>  TS name => enum class
     */
    public function discover(array $paths): array
    {
        $enums = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                if (! preg_match('/^\s*enum\s+(\w+)/m', $code, $enum)) {
                    continue;
                }

                $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $ns) ? $ns[1].'\\' : '';
                $class = $namespace.$enum[1];

                if (enum_exists($class)) {
                    $enums[$enum[1]] = $class;
                }
            }
        }

        return $enums;
    }
}
