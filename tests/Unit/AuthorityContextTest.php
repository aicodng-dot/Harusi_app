<?php

namespace Tests\Unit;

use App\Services\Tenancy\AuthorityContext;
use App\Services\Tenancy\AuthorityContextException;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AuthorityContextTest extends TestCase
{
    public function test_observed_facts_are_private_immutable_and_not_an_action_permission_token(): void
    {
        $context = new AuthorityContext(true, true, 'manager');
        $this->assertSame('{}', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertSame([], get_object_vars($context));
        $reflection = new ReflectionClass($context);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isPrivate());
        }
        foreach (['__toString', 'allows', 'can', 'checkPermission'] as $method) {
            $this->assertFalse($reflection->hasMethod($method));
        }
        try {
            $context->workspaceRole = 'owner';
            $this->fail('An observed context must not acquire a mutable public authority field.');
        } catch (Error) {
            $this->assertSame('manager', $context->workspaceRole());
        }
        $this->expectException(Error::class);
        (string) $context;
    }

    #[DataProvider('inconsistentFacts')]
    public function test_constructor_refuses_inconsistent_or_unknown_authority_facts(bool $eligible, bool $platform, ?string $role): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthorityContext($eligible, $platform, $role);
    }

    public static function inconsistentFacts(): array
    {
        return [
            'ineligible platform account' => [false, true, null],
            'ineligible owner account' => [false, false, 'owner'],
            'ineligible manager account' => [false, false, 'manager'],
            'legacy role is not a membership' => [true, false, 'admin'],
            'membership case is exact' => [true, false, 'Owner'],
            'empty role is not omission' => [true, false, ''],
        ];
    }

    public function test_safe_exception_discards_private_context_and_rejects_unknown_reasons(): void
    {
        foreach (['invalid_identifiers', 'invalid_prerequisites', 'database_unavailable'] as $reason) {
            $exception = AuthorityContextException::because($reason);
            $this->assertSame($reason, $exception->reason());
            $this->assertSame('Authority context could not be resolved.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->expectException(InvalidArgumentException::class);
        AuthorityContextException::because('private SQL password@example.invalid');
    }
}
