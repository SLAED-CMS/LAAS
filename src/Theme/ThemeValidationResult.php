<?php
declare(strict_types=1);

namespace Laas\Theme;

final class ThemeValidationResult
{
    /** @var array<int, array{code: string, file: string, message: string}> */
    private array $violations = [];

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

    public function addViolation(string $code, string $file, string $message): void
    {
        $this->violations[] = [
            'code' => $code,
            'file' => $file,
            'message' => $message,
        ];
    }
}
