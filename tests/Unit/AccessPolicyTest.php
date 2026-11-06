<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use Modules\ModuleCallInsights\Lib\AccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase
{
    public function testAdminsAreAllowed(): void
    {
        self::assertTrue(AccessPolicy::isAllowedRole('admins'));
    }

    /** @return iterable<string, array{?string}> */
    public static function refusedRoles(): iterable
    {
        yield 'no role' => [null];
        yield 'empty' => [''];
        yield 'guests' => ['guests'];
        yield 'module-defined role' => ['operators'];
        yield 'case variant' => ['Admins'];
        yield 'padded' => [' admins'];
    }

    #[DataProvider('refusedRoles')]
    public function testOtherRolesAreRefused(?string $role): void
    {
        self::assertFalse(AccessPolicy::isAllowedRole($role));
    }

    public function testWriteNeedsPostAjaxAndBearer(): void
    {
        self::assertTrue(AccessPolicy::isAllowedWrite(true, true, 'Bearer abc.def.ghi'));
    }

    /** @return iterable<string, array{bool, bool, ?string}> */
    public static function refusedWrites(): iterable
    {
        yield 'GET' => [false, true, 'Bearer abc'];
        yield 'not AJAX' => [true, false, 'Bearer abc'];
        yield 'cookie only, no header' => [true, true, null];
        yield 'empty header' => [true, true, ''];
        yield 'empty token' => [true, true, 'Bearer '];
        yield 'other scheme' => [true, true, 'Basic YWRtaW46YWRtaW4='];
    }

    #[DataProvider('refusedWrites')]
    public function testWriteRefusedOtherwise(bool $isPost, bool $isAjax, ?string $header): void
    {
        self::assertFalse(AccessPolicy::isAllowedWrite($isPost, $isAjax, $header));
    }
}
