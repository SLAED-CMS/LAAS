<?php
declare(strict_types=1);

use Laas\Core\Validation\ValidationResult;
use Laas\Http\ProblemDetails;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function testValidationProblemDetailsShape(): void
    {
        $request = new Request('POST', '/submit', [], [], ['accept' => 'application/json'], '');
        $result = new ValidationResult();
        $result->addError('title', 'required');
        $result->addError('title', 'max');
        $result->addError('slug', 'slug');

        $problem = ProblemDetails::validationFailed($request, $result, 'ERR-TEST');
        $data = $problem->toArray();

        $this->assertSame('Validation failed', $data['title'] ?? null);
        $this->assertSame(422, $data['status'] ?? null);
        $this->assertSame('/submit', $data['instance'] ?? null);
        $this->assertSame('ERR-TEST', $data['error_id'] ?? null);
        $this->assertSame(['required', 'max'], $data['errors']['title'] ?? []);
        $this->assertSame(['slug'], $data['errors']['slug'] ?? []);
    }
}
