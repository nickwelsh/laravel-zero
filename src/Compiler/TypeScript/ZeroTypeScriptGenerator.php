<?php

namespace NickWelsh\LaravelZero\Compiler\TypeScript;

use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Context\ContextTypeCompiler;
use NickWelsh\LaravelZero\Compiler\Filters\ZeroFilterCompiler;
use NickWelsh\LaravelZero\Compiler\Inputs\ZodRuleCompiler;
use NickWelsh\LaravelZero\Compiler\Mutations\ZeroMutationCompiler;
use NickWelsh\LaravelZero\Compiler\Queries\ZeroQueryCompiler;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Inputs\ZeroFilterInput;
use NickWelsh\LaravelZero\Inputs\ZeroInput;
use NickWelsh\LaravelZero\Support\GeneratedPaths;
use ReflectionNamedType;
use UnexpectedValueException;

/**
 * @phpstan-type TypeScriptTree array<string, string|array<string, mixed>>
 * @phpstan-type ExportEntry array{path: string, source: string|null}
 */
final readonly class ZeroTypeScriptGenerator
{
    private const HEADER = "// This file is generated. Do not edit directly.\n\n";

    private const TYPESCRIPT_FILES = [
        'context.generated.ts',
        'inputs.generated.ts',
        'queries.generated.ts',
        'mutations.generated.ts',
    ];

    public function __construct(
        private ZeroRegistry $registry,
        private ContextTypeCompiler $contexts,
        private ZeroQueryCompiler $queries,
        private ZeroMutationCompiler $mutations,
        private ZeroFilterCompiler $filters,
        private Filesystem $files,
    ) {}

    /** @return array{files: array<string, string>, notices: array<string, list<string>>} */
    public function render(): array
    {
        $queryOperations = $this->registry->queries();
        $mutationOperations = $this->registry->mutations();
        [$inputSource, $notices] = $this->inputs([...$queryOperations, ...$mutationOperations]);
        $queryTree = $this->tree($queryOperations, fn (Operation $operation): string => $this->queries->compile($operation));
        $mutationTree = $this->tree($mutationOperations, fn (Operation $operation): string => $this->mutations->compile($operation));
        $schemaImport = GeneratedPaths::moduleImport(GeneratedPaths::outputDirectory().'/context.generated.ts', GeneratedPaths::schema());
        $contextClass = $this->stringConfig('laravel-zero.context.class');
        if (! class_exists($contextClass)) {
            throw new UnexpectedValueException("Configured context class [{$contextClass}] does not exist.");
        }
        $declarationStyle = $this->stringConfig('laravel-zero.generation.declaration_style', 'interface');
        $context = $this->contexts->compile(
            $contextClass,
            $schemaImport,
            $declarationStyle,
        );
        $manifest = [
            '_generated' => 'This file is generated. Do not edit directly.',
            'zeroVersion' => config('laravel-zero.zero_version', '1.8.0') ?: '1.8.0',
            'queries' => array_keys($queryOperations),
            'mutations' => array_keys($mutationOperations),
            'serverOnlyValidationRules' => $notices,
        ];

        $files = [
            'context.generated.ts' => self::HEADER.$context,
        ];
        if ($inputSource !== '') {
            $files['inputs.generated.ts'] = self::HEADER."import {z} from 'zod';\n\n".$inputSource;
        }
        $files['queries.generated.ts'] = self::HEADER.$this->queriesFile($queryOperations, $queryTree, $schemaImport);
        $files['mutations.generated.ts'] = self::HEADER.$this->mutationsFile($mutationOperations, $mutationTree);
        $files['manifest.generated.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return [
            'files' => $files,
            'notices' => $notices,
        ];
    }

    /**
     * @param  array<string, Operation>  $operations
     * @param  TypeScriptTree  $tree
     */
    private function queriesFile(array $operations, array $tree, string $schemaImport): string
    {
        $hasOperations = $operations !== [];
        $imports = ['import {defineQueries'.($hasOperations ? ', defineQuery' : '')."} from '@rocicorp/zero';"];

        if ($this->usesZod($operations)) {
            $imports[] = "import {z} from 'zod';";
        }
        if ($hasOperations) {
            $imports[] = "import {zql} from '{$schemaImport}';";
            if ($inputImport = $this->inputImportLine($operations)) {
                $imports[] = rtrim($inputImport);
            }
            $imports[] = "import './context.generated';";
        }

        return implode("\n", $imports)."\n\nexport const queries = defineQueries(".$this->renderTree($tree).");\n";
    }

    /**
     * @param  array<string, Operation>  $operations
     * @param  TypeScriptTree  $tree
     */
    private function mutationsFile(array $operations, array $tree): string
    {
        $hasOperations = $operations !== [];
        $imports = ['import {defineMutators'.($hasOperations ? ', defineMutator' : '')."} from '@rocicorp/zero';"];

        if ($this->usesZod($operations)) {
            $imports[] = "import {z} from 'zod';";
        }
        if ($hasOperations) {
            if ($inputImport = $this->inputImportLine($operations)) {
                $imports[] = rtrim($inputImport);
            }
            $imports[] = "import './context.generated';";
        }

        return implode("\n", $imports)."\n\nexport const mutations = defineMutators(".$this->renderTree($tree).");\n";
    }

    /** @param array<string, Operation> $operations */
    private function usesZod(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (in_array(ArgumentShape::from($operation->method)->kind, ['scalar', 'object'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function write(): array
    {
        $rendered = $this->render();
        $directory = GeneratedPaths::outputDirectory();
        $this->files->ensureDirectoryExists($directory);
        $changed = [];
        foreach ($rendered['files'] as $name => $contents) {
            $path = $directory.'/'.$name;
            if ($this->writeIfChanged($path, $contents)) {
                $changed[] = $path;
            }
        }
        foreach ($this->omittedTypeScriptFiles($rendered['files']) as $name) {
            $path = $directory.'/'.$name;
            if ($this->files->exists($path) && str_starts_with($this->existingFileContents($path), self::HEADER)) {
                $this->files->delete($path);
                $changed[] = $path;
            }
        }

        $barrel = GeneratedPaths::barrel();
        if ($this->writeIfChanged($barrel, $this->barrelContents($rendered['files']))) {
            $changed[] = $barrel;
        }

        $legacyBarrel = $directory.'/index.ts';
        if ($legacyBarrel !== $barrel && $this->files->exists($legacyBarrel) && str_starts_with($this->existingFileContents($legacyBarrel), self::HEADER)) {
            $this->files->delete($legacyBarrel);
            $changed[] = $legacyBarrel;
        }

        return $changed;
    }

    /** @return list<string> */
    public function stale(): array
    {
        $rendered = $this->render();
        $directory = GeneratedPaths::outputDirectory();
        $stale = array_values(array_filter(array_keys($rendered['files']), fn (string $name): bool => ! $this->files->exists($directory.'/'.$name) || $this->files->get($directory.'/'.$name) !== $rendered['files'][$name]));
        foreach ($this->omittedTypeScriptFiles($rendered['files']) as $name) {
            $path = $directory.'/'.$name;
            if ($this->files->exists($path) && str_starts_with($this->existingFileContents($path), self::HEADER)) {
                $stale[] = $name;
            }
        }

        if (! $this->files->exists(GeneratedPaths::barrel()) || $this->files->get(GeneratedPaths::barrel()) !== $this->barrelContents($rendered['files'])) {
            $stale[] = GeneratedPaths::barrel();
        }

        return $stale;
    }

    /** @param array<string, string> $files */
    private function barrelContents(array $files): string
    {
        $barrel = GeneratedPaths::barrel();
        $directory = GeneratedPaths::outputDirectory();
        /** @var list<ExportEntry> $exports */
        $exports = array_map(
            fn (string $name): array => ['path' => $directory.'/'.$name, 'source' => $files[$name]],
            array_values(array_filter(self::TYPESCRIPT_FILES, fn (string $name): bool => isset($files[$name]))),
        );
        $schema = GeneratedPaths::schema();
        $exports[] = ['path' => $schema, 'source' => $this->fileContents($schema)];

        if (config('laravel-zero.frontend.framework', 'react') === 'react') {
            $provider = GeneratedPaths::provider();
            $exports[] = ['path' => $provider, 'source' => $this->fileContents($provider)];
        }

        return self::HEADER.implode("\n", array_map(
            fn (array $export): string => ($this->hasOnlyTypeExports($export['source']) ? 'export type *' : 'export *')." from '".GeneratedPaths::moduleImport($barrel, $export['path'])."';",
            $exports,
        ))."\n";
    }

    private function fileContents(string $path): ?string
    {
        return $this->files->exists($path) ? $this->existingFileContents($path) : null;
    }

    private function existingFileContents(string $path): string
    {
        return $this->files->get($path);
    }

    private function hasOnlyTypeExports(?string $source): bool
    {
        if ($source === null || preg_match('/^[\\t ]*export[\\t ]+(?:type|interface)\\b/m', $source) !== 1) {
            return false;
        }

        return preg_match('/^[\\t ]*export[\\t ]+(?!(?:type|interface)\\b)/m', $source) !== 1;
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function omittedTypeScriptFiles(array $files): array
    {
        return array_values(array_filter(self::TYPESCRIPT_FILES, fn (string $name): bool => ! isset($files[$name])));
    }

    private function writeIfChanged(string $path, string $contents): bool
    {
        if ($this->files->exists($path) && $this->files->get($path) === $contents) {
            return false;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return true;
    }

    /**
     * @param  array<string, Operation>  $operations
     * @return array{string, array<string, list<string>>}
     */
    private function inputs(array $operations): array
    {
        $compiler = new ZodRuleCompiler;
        $filterSources = [];
        $filterSymbols = [];
        $schemas = [];
        foreach ($operations as $operation) {
            $shape = ArgumentShape::from($operation->method);
            if ($shape->kind !== 'input') {
                continue;
            }
            $class = $this->inputClass($shape);
            if (isset($schemas[$class])) {
                continue;
            }
            /** @var ZeroInput $input */
            $input = new $class;
            $fieldSchemas = [];
            if (is_subclass_of($class, ZeroFilterInput::class)) {
                /** @var class-string<ZeroFilterInput> $filterInputClass */
                $filterInputClass = $class;
                $definition = $filterInputClass::filterDefinition();
                $this->reserveFilterSymbols($filterSymbols, $definition);
                $filterSources[$definition] = $this->filters->compile($definition);
                $fieldSchemas[$filterInputClass::filterField()] = ZeroFilterCompiler::schemaName($definition);
            }
            $name = lcfirst(class_basename($class)).'Schema';
            $schemas[$class] = "export const {$name} = ".$compiler->object($input->rules(), class_basename($class), $input->messages(), $fieldSchemas).';';
        }
        ksort($filterSources);
        ksort($schemas);
        $sources = [...array_values($filterSources), ...array_values($schemas)];

        return [implode("\n", $sources).($sources ? "\n" : ''), $compiler->notices()];
    }

    /**
     * @param  array<string, class-string>  $symbols
     * @param  class-string  $definition
     */
    private function reserveFilterSymbols(array &$symbols, string $definition): void
    {
        foreach ([
            ZeroFilterCompiler::metadataName($definition),
            ZeroFilterCompiler::schemaName($definition),
            ZeroFilterCompiler::applyName($definition),
            ZeroFilterCompiler::typeName($definition),
        ] as $symbol) {
            $existing = $symbols[$symbol] ?? null;
            if ($existing !== null && $existing !== $definition) {
                throw new UnexpectedValueException("Filter definitions [{$existing}] and [{$definition}] generate the same TypeScript symbol [{$symbol}]. Rename one definition.");
            }
            $symbols[$symbol] = $definition;
        }
    }

    /** @param array<string, Operation> $operations */
    private function inputImports(array $operations): string
    {
        $names = [];
        foreach ($operations as $operation) {
            $shape = ArgumentShape::from($operation->method);
            if ($shape->kind === 'input') {
                $class = $this->inputClass($shape);
                $names[] = lcfirst(class_basename($class)).'Schema';
                if (is_subclass_of($class, ZeroFilterInput::class)) {
                    /** @var class-string<ZeroFilterInput> $filterInputClass */
                    $filterInputClass = $class;
                    $names[] = ZeroFilterCompiler::applyName($filterInputClass::filterDefinition());
                }
            }
        }
        sort($names);

        return implode(', ', array_unique($names));
    }

    /** @param array<string, Operation> $operations */
    private function inputImportLine(array $operations): string
    {
        $imports = $this->inputImports($operations);

        return $imports === '' ? '' : "import {{$imports}} from './inputs.generated';\n";
    }

    /**
     * @param  array<string, Operation>  $operations
     * @param  callable(Operation): string  $compile
     * @return TypeScriptTree
     */
    private function tree(array $operations, callable $compile): array
    {
        /** @var TypeScriptTree $tree */
        $tree = [];
        foreach ($operations as $name => $operation) {
            $tree = $this->appendToTree($tree, explode('.', $name), $compile($operation));
        }

        return $tree;
    }

    /**
     * @param  TypeScriptTree  $tree
     * @param  list<string>  $parts
     * @return TypeScriptTree
     */
    private function appendToTree(array $tree, array $parts, string $source): array
    {
        $part = array_shift($parts);
        if ($part === null) {
            throw new UnexpectedValueException('Operation names must not be empty.');
        }
        if ($parts === []) {
            $tree[$part] = $source;

            return $tree;
        }

        $branch = $tree[$part] ?? [];
        if (! is_array($branch)) {
            throw new UnexpectedValueException("Operation namespace [{$part}] conflicts with an operation.");
        }
        $tree[$part] = $this->appendToTree($this->treeBranch($branch), $parts, $source);

        return $tree;
    }

    /** @param TypeScriptTree $tree */
    private function renderTree(array $tree, int $depth = 0): string
    {
        if ($tree === []) {
            return '{}';
        }
        $indent = str_repeat('  ', $depth + 1);
        $lines = [];
        foreach ($tree as $key => $value) {
            $rendered = is_array($value)
                ? $this->renderTree($this->treeBranch($value), $depth + 1)
                : str_replace("\n", "\n{$indent}", $value);
            $lines[] = $indent.$key.': '.$rendered.',';
        }

        return "{\n".implode("\n", $lines)."\n".str_repeat('  ', $depth).'}';
    }

    /**
     * @param  array<array-key, mixed>  $branch
     * @return TypeScriptTree
     */
    private function treeBranch(array $branch): array
    {
        foreach ($branch as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_array($value))) {
                throw new UnexpectedValueException('Operation trees may only contain named branches and compiled operations.');
            }
        }

        /** @var TypeScriptTree $branch */
        return $branch;
    }

    /** @return class-string<ZeroInput> */
    private function inputClass(ArgumentShape $shape): string
    {
        $type = $shape->parameters[0]->getType();
        if (! $type instanceof ReflectionNamedType) {
            throw new UnexpectedValueException('Zero input parameters must have one named type.');
        }

        $class = $type->getName();
        if (! is_a($class, ZeroInput::class, true)) {
            throw new UnexpectedValueException("Expected [{$class}] to extend ".ZeroInput::class.'.');
        }

        return $class;
    }

    private function stringConfig(string $key, ?string $default = null): string
    {
        $value = config($key, $default);
        if (! is_string($value)) {
            throw new UnexpectedValueException("Configuration [{$key}] must be a string.");
        }

        return $value;
    }
}
