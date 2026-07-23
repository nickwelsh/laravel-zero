<?php

namespace NickWelsh\LaravelZero\Discovery;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Attributes\ZeroMutationCollection;
use NickWelsh\LaravelZero\Attributes\ZeroQueryCollection;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Context\ZeroContext;
use NickWelsh\LaravelZero\Contracts\ZeroMutations;
use NickWelsh\LaravelZero\Contracts\ZeroQueries;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ZeroRegistry
{
    /** @var array<string, Operation>|null */
    private ?array $queries = null;

    /** @var array<string, Operation>|null */
    private ?array $mutations = null;

    public function __construct(private readonly Filesystem $files, private readonly Cache $cache) {}

    /** @return array<string, Operation> */
    public function queries(): array
    {
        $this->discover();

        return $this->queries;
    }

    /** @return array<string, Operation> */
    public function mutations(): array
    {
        $this->discover();

        return $this->mutations;
    }

    public function query(string $name): Operation
    {
        return $this->queries()[$name] ?? throw new ZeroCompilerException('ZERO-D104', "Unknown Zero query [{$name}].");
    }

    public function mutation(string $name): Operation
    {
        return $this->mutations()[$name] ?? throw new ZeroCompilerException('ZERO-D105', "Unknown Zero mutation [{$name}].");
    }

    public function clear(): void
    {
        $this->queries = $this->mutations = null;
        $this->cache->forget(config('laravel-zero.discovery.cache_key'));
    }

    private function discover(): void
    {
        if ($this->queries !== null) {
            return;
        }

        $before = get_declared_classes();
        $queryFiles = $this->files('queries');
        $mutatorFiles = $this->files('mutators');

        foreach (array_unique([...$queryFiles, ...$mutatorFiles]) as $file) {
            require_once $file;
        }

        if (config('laravel-zero.discovery.cache') && is_array($cached = $this->cache->get(config('laravel-zero.discovery.cache_key')))) {
            $this->queries = $this->hydrateCached($cached['queries'] ?? []);
            $this->mutations = $this->hydrateCached($cached['mutations'] ?? []);

            return;
        }

        $classes = array_unique([...array_diff(get_declared_classes(), $before), ...get_declared_classes()]);
        $queries = [];
        $mutations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();

            if (! $file) {
                continue;
            }

            if (in_array(realpath($file), $queryFiles, true) && $reflection->implementsInterface(ZeroQueries::class)) {
                $this->addCollection($reflection, ZeroQueryCollection::class, 'query', $queries);
            }

            if (in_array(realpath($file), $mutatorFiles, true) && $reflection->implementsInterface(ZeroMutations::class)) {
                $this->addCollection($reflection, ZeroMutationCollection::class, 'mutation', $mutations);
            }
        }

        ksort($queries);
        ksort($mutations);
        $this->queries = $queries;
        $this->mutations = $mutations;

        if (config('laravel-zero.discovery.cache')) {
            $this->cache->forever(config('laravel-zero.discovery.cache_key'), [
                'queries' => $this->cacheable($queries),
                'mutations' => $this->cacheable($mutations),
            ]);
        }
    }

    /** @param class-string $attribute @param array<string, Operation> $operations */
    private function addCollection(ReflectionClass $class, string $attribute, string $kind, array &$operations): void
    {
        $attributes = $class->getAttributes($attribute);

        if (count($attributes) !== 1) {
            throw new ZeroCompilerException('ZERO-D100', "{$class->getName()} must declare exactly one {$attribute} attribute.", $class->getFileName() ?: null, $class->getName());
        }

        $prefix = trim($attributes[0]->newInstance()->name);
        $this->assertName($prefix, $class);

        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName() || $method->isConstructor()) {
                continue;
            }

            if (! $method->isPublic()) {
                throw new ZeroCompilerException('ZERO-D102', "Zero operation {$class->getName()}::{$method->getName()}() must be public.", $class->getFileName() ?: null, $class->getName(), $method->getName(), $method->getStartLine());
            }

            $this->assertParameters($class, $method);
            $name = $prefix.'.'.$method->getName();

            if (isset($operations[$name])) {
                $other = $operations[$name];
                throw new ZeroCompilerException('ZERO-D103', "Duplicate Zero {$kind} [{$name}] at {$other->method->getFileName()}:{$other->method->getStartLine()} and {$method->getFileName()}:{$method->getStartLine()}.");
            }

            $operations[$name] = new Operation($kind, $name, $prefix, $class->getName(), $method);
        }
    }

    private function assertName(string $prefix, ReflectionClass $class): void
    {
        if ($prefix === '' || ! preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $prefix)) {
            throw new ZeroCompilerException('ZERO-D101', "Invalid Zero collection name [{$prefix}]. Use dot-separated TypeScript identifier segments.", $class->getFileName() ?: null, $class->getName());
        }
    }

    private function assertParameters(ReflectionClass $class, ReflectionMethod $method): void
    {
        $parameters = $method->getParameters();
        $type = $parameters[0]->getType() ?? null;
        $configured = config('laravel-zero.context.class', ZeroContext::class);

        if ($parameters === [] || ! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_a($configured, $type->getName(), true)) {
            throw new ZeroCompilerException('ZERO-D106', "First parameter of {$class->getName()}::{$method->getName()}() must accept configured Zero context [{$configured}].", $class->getFileName() ?: null, $class->getName(), $method->getName(), $method->getStartLine());
        }

        foreach (array_slice($parameters, 1) as $parameter) {
            if (! $parameter->hasType() || ! $parameter->getType() instanceof ReflectionNamedType) {
                throw new ZeroCompilerException('ZERO-D107', "Parameter \${$parameter->getName()} must have one supported named type.", $class->getFileName() ?: null, $class->getName(), $method->getName(), $method->getStartLine());
            }
        }
    }

    /** @return list<string> */
    private function files(string $kind): array
    {
        $files = [];
        $patterns = config("laravel-zero.discovery.{$kind}");

        if (! is_array($patterns)) {
            $patterns = config('laravel-zero.discovery.directories', []);
        }

        foreach ($patterns as $pattern) {
            $directories = glob($pattern, GLOB_ONLYDIR) ?: [];
            if (is_dir($pattern)) {
                $directories[] = $pattern;
            }
            foreach (array_unique($directories) as $directory) {
                foreach ($this->files->allFiles($directory) as $file) {
                    if ($file->getExtension() === 'php') {
                        $files[] = $file->getRealPath();
                    }
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /** @param array<string, Operation> $operations @return list<array{kind: string, name: string, prefix: string, class: string, method: string}> */
    private function cacheable(array $operations): array
    {
        return array_values(array_map(fn (Operation $operation): array => [
            'kind' => $operation->kind,
            'name' => $operation->name,
            'prefix' => $operation->prefix,
            'class' => $operation->class,
            'method' => $operation->method->getName(),
        ], $operations));
    }

    /** @param list<array{kind: string, name: string, prefix: string, class: class-string, method: string}> $cached @return array<string, Operation> */
    private function hydrateCached(array $cached): array
    {
        $operations = [];
        foreach ($cached as $item) {
            $operations[$item['name']] = new Operation(
                $item['kind'],
                $item['name'],
                $item['prefix'],
                $item['class'],
                new ReflectionMethod($item['class'], $item['method']),
            );
        }
        ksort($operations);

        return $operations;
    }
}
