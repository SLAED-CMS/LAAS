<?php

declare(strict_types=1);

namespace Laas\Theme;

final class ThemeValidationResult
{
    /** @var array<int, array{code: string, file: string, message: string}> */
    private array $violations = [];
    /** @var array<int, array{code: string, file: string, message: string}> */
    private array $warnings = [];

    public function __construct(private string $themeName)
    {
    }

    public function getThemeName(): string
    {
        return $this->themeName;
    }

    /**
     * @return array<int, array{code: string, file: string, message: string}>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /**
     * @return array<int, array{code: string, file: string, message: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function addViolation(string $code, string $file, string $message): void
    {
        $this->violations[] = [
            'code' => $code,
            'file' => $file,
            'message' => $message,
        ];
    }

    public function addWarning(string $code, string $file, string $message): void
    {
        $this->warnings[] = [
            'code' => $code,
            'file' => $file,
            'message' => $message,
        ];
    }
}
