<?php

namespace NickWelsh\LaravelZero\Compiler\Diagnostics;

use RuntimeException;

final class ZeroCompilerException extends RuntimeException
{
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
        public readonly ?string $sourceFile = null,
        public readonly ?string $class = null,
        public readonly ?string $method = null,
        public readonly ?int $sourceLine = null,
        public readonly ?string $suggestion = null,
    ) {
        parent::__construct($this->render($message));
    }

    private function render(string $message): string
    {
        $location = array_filter([$this->sourceFile, $this->class, $this->method, $this->sourceLine]);
        $text = $this->diagnosticCode."\n".$message;

        if ($location !== []) {
            $text .= "\n".implode(':', $location);
        }

        return $this->suggestion ? $text."\n".$this->suggestion : $text;
    }
}
