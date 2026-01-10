<?php
declare(strict_types=1);

use Laas\Core\Validation\ValidationResult;
use Laas\Http\ProblemDetails;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class ValidationJsonTest extends TestCase
{
    public function testValidationErrorsInProblemDetails(): void
    {
        $request = new Request('POST', '/save', [], [], ['accept' => 'application/json'], '');
        $result = new ValidationResult();
        $result->addError('title', 'required');
        $result->addError('slug', 'slug');

        $problem = ProblemDetails::validationFailed($request, $result, 'ERR-VAL');
        $data = $problem->toArray();

        $this->assertSame(422, $data['status'] ?? null);
        $this->assertSame(['required'], $data['errors']['title'] ?? []);
        $this->assertSame(['slug'], $data['errors']['slug'] ?? []);
    }
}
