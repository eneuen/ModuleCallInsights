<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use Modules\ModuleCallInsights\Lib\StorageInfo;
use PHPUnit\Framework\TestCase;

final class StorageInfoTest extends TestCase
{
    private const string DB = '/mountpoint/mikopbx/custom_modules/ModuleCallInsights/db/module.db';

    private const string LOCAL_MOUNTS = <<<'MOUNTS'
        overlay / overlay rw,relatime 0 0
        /dev/vda4 /mountpoint ext4 rw,relatime 0 0
        tmpfs /tmp tmpfs rw 0 0
        MOUNTS;

    private const string NFS_MOUNTS = <<<'MOUNTS'
        overlay / overlay rw,relatime 0 0
        /dev/vda4 /mountpoint ext4 rw,relatime 0 0
        nas:/export/pbx /mountpoint/mikopbx nfs4 rw,relatime 0 0
        MOUNTS;

    public function testLocalDiskIsWalSafe(): void
    {
        self::assertTrue(StorageInfo::isWalSafeFilesystem(self::DB, self::LOCAL_MOUNTS));
    }

    public function testNfsIsNotWalSafe(): void
    {
        self::assertFalse(StorageInfo::isWalSafeFilesystem(self::DB, self::NFS_MOUNTS));
    }

    public function testSmbIsNotWalSafe(): void
    {
        $mounts = "overlay / overlay rw 0 0\n//nas/pbx /mountpoint/mikopbx cifs rw 0 0\n";
        self::assertFalse(StorageInfo::isWalSafeFilesystem(self::DB, $mounts));
    }

    public function testLongestMountPointWins(): void
    {
        self::assertSame('nfs4', StorageInfo::filesystemTypeOf(self::DB, self::NFS_MOUNTS));
        self::assertSame('ext4', StorageInfo::filesystemTypeOf('/mountpoint/other/file.db', self::NFS_MOUNTS));
    }

    public function testSimilarPrefixIsNotAMatch(): void
    {
        // /mountpoint/mikopbx-old is not inside /mountpoint/mikopbx.
        $mounts = "overlay / overlay rw 0 0\nnas:/x /mountpoint/mikopbx nfs rw 0 0\n";
        self::assertSame('overlay', StorageInfo::filesystemTypeOf('/mountpoint/mikopbx-old/a.db', $mounts));
    }

    public function testEmptyMountTableCountsAsLocal(): void
    {
        self::assertTrue(StorageInfo::isWalSafeFilesystem(self::DB, ''));
    }
}
