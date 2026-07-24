<?php

namespace NickWelsh\LaravelZero\Frontend;

use Illuminate\Filesystem\Filesystem;
use NickWelsh\LaravelZero\Support\GeneratedPaths;

abstract readonly class Frontend
{
    protected const HEADER = "// This file is generated. Do not edit directly.\n\n";

    public function __construct(protected Filesystem $files) {}

    /** @return list<string> */
    final public function scaffold(): array
    {
        $outputPath = GeneratedPaths::frontend();
        $generated = $this->generatedFiles($outputPath);
        $changed = [];
        foreach ($generated as $name => $source) {
            $path = $outputPath.'/'.$name;
            $contents = self::HEADER.$source;
            if (! $this->files->exists($path) || $this->files->get($path) !== $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->files->put($path, $contents);
                $changed[] = $path;
            }
        }

        if ($this->files->isDirectory($outputPath)) {
            foreach ($this->files->allFiles($outputPath) as $file) {
                $path = $file->getPathname();
                $name = str_replace('\\', '/', $file->getRelativePathname());
                if (! array_key_exists($name, $generated) && str_starts_with($this->files->get($path), self::HEADER)) {
                    $this->files->delete($path);
                    $changed[] = $path;
                }
            }
        }

        $barrelPath = GeneratedPaths::frontendBarrel();
        $barrel = self::HEADER.$this->barrel($barrelPath, $outputPath);
        if (! $this->files->exists($barrelPath) || $this->files->get($barrelPath) !== $barrel) {
            $this->files->ensureDirectoryExists(dirname($barrelPath));
            $this->files->put($barrelPath, $barrel);
            $changed[] = $barrelPath;
        }

        return [...$changed, ...$this->scaffoldAdditionalFiles()];
    }

    /**
     * @return array<string, string>
     */
    abstract protected function generatedFiles(string $outputPath): array;

    abstract protected function barrel(string $barrelPath, string $outputPath): string;

    /** @return list<string> */
    protected function scaffoldAdditionalFiles(): array
    {
        return [];
    }
}
