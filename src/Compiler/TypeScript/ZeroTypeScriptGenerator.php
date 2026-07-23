<?php

namespace NickWelsh\LaravelZero\Compiler\TypeScript;

use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Context\ContextTypeCompiler;
use NickWelsh\LaravelZero\Compiler\Inputs\ZodRuleCompiler;
use NickWelsh\LaravelZero\Compiler\Mutations\ZeroMutationCompiler;
use NickWelsh\LaravelZero\Compiler\Queries\ZeroQueryCompiler;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Inputs\ZeroInput;
use NickWelsh\LaravelZero\Support\GeneratedPaths;

final readonly class ZeroTypeScriptGenerator
{
    private const HEADER = "// This file is generated. Do not edit directly.\n\n";

    public function __construct(
        private ZeroRegistry $registry,
        private ContextTypeCompiler $contexts,
        private ZeroQueryCompiler $queries,
        private ZeroMutationCompiler $mutations,
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
        $context = $this->contexts->compile(config('laravel-zero.context.class'), $schemaImport);
        $manifest = [
            '_generated' => 'This file is generated. Do not edit directly.',
            'zeroVersion' => config('laravel-zero.zero_version', '1.8.0') ?: '1.8.0',
            'queries' => array_keys($queryOperations),
            'mutations' => array_keys($mutationOperations),
            'serverOnlyValidationRules' => $notices,
        ];

        return [
            'files' => [
                'context.generated.ts' => self::HEADER.$context,
                'inputs.generated.ts' => self::HEADER."import {z} from 'zod';\n\n".$inputSource,
                'queries.generated.ts' => self::HEADER."import {defineQueries, defineQuery} from '@rocicorp/zero';\nimport {z} from 'zod';\nimport {zql} from '{$schemaImport}';\n".$this->inputImportLine($queryOperations)."import './context.generated';\n\nexport const queries = defineQueries(".$this->renderTree($queryTree).");\n",
                'mutations.generated.ts' => self::HEADER."import {defineMutators, defineMutator} from '@rocicorp/zero';\nimport {z} from 'zod';\n".$this->inputImportLine($mutationOperations)."import './context.generated';\n\nexport const mutations = defineMutators(".$this->renderTree($mutationTree).");\n",
                'manifest.generated.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            ],
            'notices' => $notices,
        ];
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

        $barrel = GeneratedPaths::barrel();
        if ($this->writeIfChanged($barrel, $this->barrelContents())) {
            $changed[] = $barrel;
        }

        $legacyBarrel = $directory.'/index.ts';
        if ($legacyBarrel !== $barrel && $this->files->exists($legacyBarrel) && str_starts_with($this->files->get($legacyBarrel), self::HEADER)) {
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

        if (! $this->files->exists(GeneratedPaths::barrel()) || $this->files->get(GeneratedPaths::barrel()) !== $this->barrelContents()) {
            $stale[] = GeneratedPaths::barrel();
        }

        return $stale;
    }

    private function barrelContents(): string
    {
        $barrel = GeneratedPaths::barrel();
        $directory = GeneratedPaths::outputDirectory();
        $exports = [
            $directory.'/context.generated.ts',
            $directory.'/inputs.generated.ts',
            $directory.'/queries.generated.ts',
            $directory.'/mutations.generated.ts',
            GeneratedPaths::schema(),
        ];

        if (config('laravel-zero.frontend.framework', 'react') === 'react') {
            $exports[] = GeneratedPaths::provider();
        }

        return self::HEADER.implode("\n", array_map(
            fn (string $path): string => "export * from '".GeneratedPaths::moduleImport($barrel, $path)."';",
            $exports,
        ))."\n";
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

    /** @param array<string, Operation> $operations @return array{string, array<string, list<string>>} */
    private function inputs(array $operations): array
    {
        $compiler = new ZodRuleCompiler;
        $schemas = [];
        foreach ($operations as $operation) {
            $shape = ArgumentShape::from($operation->method);
            if ($shape->kind !== 'input') {
                continue;
            }
            $class = $shape->parameters[0]->getType()->getName();
            if (isset($schemas[$class])) {
                continue;
            }
            /** @var ZeroInput $input */
            $input = new $class;
            $name = lcfirst(class_basename($class)).'Schema';
            $schemas[$class] = "export const {$name} = ".$compiler->object($input->rules(), class_basename($class)).';';
        }
        ksort($schemas);

        return [implode("\n", $schemas).($schemas ? "\n" : ''), $compiler->notices()];
    }

    /** @param array<string, Operation> $operations */
    private function inputImports(array $operations): string
    {
        $names = [];
        foreach ($operations as $operation) {
            $shape = ArgumentShape::from($operation->method);
            if ($shape->kind === 'input') {
                $names[] = lcfirst(class_basename($shape->parameters[0]->getType()->getName())).'Schema';
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

    /** @param array<string, Operation> $operations @return array<string, mixed> */
    private function tree(array $operations, callable $compile): array
    {
        $tree = [];
        foreach ($operations as $name => $operation) {
            $cursor = &$tree;
            $parts = explode('.', $name);
            $leaf = array_pop($parts);
            foreach ($parts as $part) {
                $cursor = &$cursor[$part];
            }
            $cursor[$leaf] = $compile($operation);
            unset($cursor);
        }

        return $tree;
    }

    /** @param array<string, mixed> $tree */
    private function renderTree(array $tree, int $depth = 0): string
    {
        if ($tree === []) {
            return '{}';
        }
        $indent = str_repeat('  ', $depth + 1);
        $lines = [];
        foreach ($tree as $key => $value) {
            $rendered = is_array($value)
                ? $this->renderTree($value, $depth + 1)
                : str_replace("\n", "\n{$indent}", $value);
            $lines[] = $indent.$key.': '.$rendered.',';
        }

        return "{\n".implode("\n", $lines)."\n".str_repeat('  ', $depth).'}';
    }
}
