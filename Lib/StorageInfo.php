<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

/**
 * Decides whether a SQLite file may use WAL. WAL relies on shared memory,
 * which is unsafe on network filesystems and can corrupt the database.
 *
 * Mirrors the core's private DatabaseProviderBase::isWalSafeFilesystem()
 * (plan rule 11), so the module's own database follows the same rule as
 * the CDR. Pure PHP, no core dependencies.
 */
final class StorageInfo
{
    /** Same list as the core. */
    private const array UNSAFE_FS_TYPES = [
        'nfs', 'nfs4', 'cifs', 'smb', 'smbfs', 'smb2', 'smb3',
        'sshfs', 'davfs', 'glusterfs', 'ceph', 'lustre', 'gpfs',
    ];

    /**
     * @param string      $dbFile        Absolute path of the database file.
     * @param string|null $mountsContent Contents of /proc/mounts; read from disk when null.
     */
    public static function isWalSafeFilesystem(string $dbFile, ?string $mountsContent = null): bool
    {
        $mountsContent ??= (string)@file_get_contents('/proc/mounts');
        if ($mountsContent === '') {
            // Same choice as the core: no mount table means a plain local system.
            return true;
        }

        $fsType = self::filesystemTypeOf($dbFile, $mountsContent);

        return $fsType === null || !in_array(strtolower($fsType), self::UNSAFE_FS_TYPES, true);
    }

    /**
     * Type of the filesystem holding $path: the longest mount point that contains it.
     */
    public static function filesystemTypeOf(string $path, string $mountsContent): ?string
    {
        $bestMountPoint = null;
        $bestType = null;
        foreach (explode("\n", $mountsContent) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 3 || $parts[1] === '') {
                continue;
            }
            [, $mountPoint, $type] = $parts;
            $prefix = $mountPoint === '/' ? '/' : $mountPoint . '/';
            if (
                str_starts_with($path . '/', $prefix)
                && ($bestMountPoint === null || strlen($mountPoint) > strlen($bestMountPoint))
            ) {
                $bestMountPoint = $mountPoint;
                $bestType = $type;
            }
        }

        return $bestType;
    }
}
