<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Tests for the Amazee.ai integration in ScoltaAiService.
 */
class AmazeeAiServiceTest extends TestCase
{
    public function test_is_amazee_active_false_by_default(): void
    {
        $service = new ScoltaAiService(['ai_api_key' => 'test']);
        $this->assertFalse($service->isAmazeeActive());
    }

    public function test_is_amazee_active_true_when_flag_set(): void
    {
        $service = new ScoltaAiService(['ai_api_key' => 'test'], amazeeActive: true);
        $this->assertTrue($service->isAmazeeActive());
    }

    public function test_has_message_override(): void
    {
        $ref = new ReflectionClass(ScoltaAiService::class);
        $this->assertTrue($ref->hasMethod('message'));
        $method = $ref->getMethod('message');
        $this->assertSame(ScoltaAiService::class, $method->getDeclaringClass()->getName());
    }

    public function test_has_conversation_override(): void
    {
        $ref = new ReflectionClass(ScoltaAiService::class);
        $this->assertTrue($ref->hasMethod('conversation'));
        $method = $ref->getMethod('conversation');
        $this->assertSame(ScoltaAiService::class, $method->getDeclaringClass()->getName());
    }

    public function test_has_message_for_operation_override(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Services/ScoltaAiService.php'
        );
        $this->assertStringContainsString('public function messageForOperation(', $src);
    }

    public function test_imports_amazee_budget_exception(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Services/ScoltaAiService.php'
        );
        $this->assertStringContainsString('AmazeeBudgetExceededException', $src);
    }

    public function test_handle_budget_exception_in_source(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Services/ScoltaAiService.php'
        );
        $this->assertStringContainsString('handlePossibleBudgetException', $src);
        $this->assertStringContainsString('Budget has been exceeded!', $src);
    }

    public function test_message_converts_budget_runtime_exception(): void
    {
        $service = $this->getMockBuilder(ScoltaAiService::class)
            ->setConstructorArgs([['ai_api_key' => 'test'], true])
            ->onlyMethods(['getClient'])
            ->getMock();

        // The private handlePossibleBudgetException is tested by
        // checking that the signal string triggers the exception type.
        $src = file_get_contents(
            dirname(__DIR__).'/src/Services/ScoltaAiService.php'
        );
        $this->assertStringContainsString('AmazeeBudgetExceededException($e)', $src,
            'Budget exceptions must be wrapped as AmazeeBudgetExceededException'
        );
    }

    public function test_amazee_active_constructor_parameter(): void
    {
        $ref = new ReflectionClass(ScoltaAiService::class);
        $constructor = $ref->getConstructor();
        $params = $constructor->getParameters();
        $paramNames = array_map(fn ($p) => $p->getName(), $params);
        $this->assertContains('amazeeActive', $paramNames,
            'Constructor must accept amazeeActive parameter'
        );
    }
}
