<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use InvalidArgumentException;
use PDO;

/**
 * The module's settings (one row of m_Settings) and the Rebuild request.
 *
 * Values are checked against fixed ranges before they are saved; a missing
 * row or an out-of-range stored value reads as the default.
 *
 * Plain PHP, tested without a PBX.
 */
final class ModuleSettings
{
    public const array DEFAULTS = [
        'service_level_seconds' => 20,
        'callback_window_hours' => 24,
        'backfill_speed'        => 'normal',
    ];
    /** Inclusive ranges for the whole-number settings. */
    public const array RANGES = [
        'service_level_seconds' => [1, 600],
        'callback_window_hours' => [1, 720],
    ];
    public const array SPEEDS = ['slow', 'normal', 'fast'];

    /** @return array{service_level_seconds: int, callback_window_hours: int, backfill_speed: string} */
    public static function load(PDO $db): array
    {
        $row = $db->query('SELECT service_level_seconds, callback_window_hours, backfill_speed FROM m_Settings ORDER BY id LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $settings = self::DEFAULTS;
        if ($row === false) {
            return $settings;
        }
        foreach (self::RANGES as $name => [$min, $max]) {
            $value = (int)$row[$name];
            if ($value >= $min && $value <= $max) {
                $settings[$name] = $value;
            }
        }
        if (in_array($row['backfill_speed'], self::SPEEDS, true)) {
            $settings['backfill_speed'] = $row['backfill_speed'];
        }

        return $settings;
    }

    /**
     * Checks submitted values; every setting must be present.
     *
     * @param array<string, mixed> $input
     *
     * @return array{service_level_seconds: int, callback_window_hours: int, backfill_speed: string}
     */
    public static function validate(array $input): array
    {
        $clean = [];
        foreach (self::RANGES as $name => [$min, $max]) {
            $value = $input[$name] ?? null;
            if (!is_string($value) && !is_int($value) || !preg_match('/^\d{1,4}$/', (string)$value)
                || (int)$value < $min || (int)$value > $max) {
                throw new InvalidArgumentException("$name must be a whole number from $min to $max.");
            }
            $clean[$name] = (int)$value;
        }
        $speed = $input['backfill_speed'] ?? null;
        if (!in_array($speed, self::SPEEDS, true)) {
            throw new InvalidArgumentException('backfill_speed must be one of: ' . implode(', ', self::SPEEDS) . '.');
        }
        $clean['backfill_speed'] = $speed;

        return $clean;
    }

    /**
     * Saves checked values into the settings row, creating it if needed.
     *
     * @param array<string, mixed> $input
     *
     * @return array{service_level_seconds: int, callback_window_hours: int, backfill_speed: string}
     */
    public static function save(PDO $db, array $input): array
    {
        $clean = self::validate($input);
        $id = $db->query('SELECT id FROM m_Settings ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            $db->prepare('INSERT INTO m_Settings (service_level_seconds, callback_window_hours, backfill_speed) VALUES (?, ?, ?)')
                ->execute(array_values($clean));
        } else {
            $db->prepare('UPDATE m_Settings SET service_level_seconds = ?, callback_window_hours = ?, backfill_speed = ? WHERE id = ?')
                ->execute([...array_values($clean), $id]);
        }

        return $clean;
    }

    /** Asks the worker to empty the index and build it again on its next cycle. */
    public static function requestRebuild(PDO $db): void
    {
        $db->prepare('INSERT INTO ' . IndexStore::STATE . ' (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
            ->execute([IndexStore::REBUILD_REQUESTED, '1']);
    }

    public static function rebuildPending(PDO $db): bool
    {
        $stmt = $db->prepare('SELECT value FROM ' . IndexStore::STATE . ' WHERE name = ?');
        $stmt->execute([IndexStore::REBUILD_REQUESTED]);

        return $stmt->fetchColumn() === '1';
    }
}
