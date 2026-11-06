<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;

/**
 * Named Explore reports (m_SavedReport), shared by all admins.
 *
 * A definition is rebuilt from checked values only: every choice must be on
 * QueryService's whitelists, dates must be real, filter values short and
 * plain. Unknown keys are dropped. So a stored report can only ever ask the
 * server for a query it would accept anyway, and it holds no call data.
 *
 * Plain PHP, tested without a PBX.
 */
final class SavedReports
{
    public const string TABLE = 'm_SavedReport';
    public const int MAX_REPORTS = 200;
    public const int MAX_NAME = 80;
    public const int MAX_DEFINITION_BYTES = 4096;
    public const int MAX_FILTER_VALUES = 20;

    public const array CHARTS = ['line', 'bar', 'stacked', 'pie', 'table'];
    /** Date presets that move with today, as the page's header offers them. */
    public const array PRESETS = ['today', '7', '30', 'month', '90', '365', 'custom'];

    /** @return list<array{id: int, name: string, definition: array<string, mixed>, updated_at: string}> */
    public static function all(PDO $db): array
    {
        $rows = $db->query('SELECT id, name, definition, updated_at FROM ' . self::TABLE . ' ORDER BY name COLLATE NOCASE')
            ->fetchAll(PDO::FETCH_ASSOC);
        $reports = [];
        foreach ($rows as $row) {
            try {
                $definition = self::check(json_decode((string)$row['definition'], true, 8, JSON_THROW_ON_ERROR));
            } catch (JsonException | InvalidArgumentException) {
                continue;       // a hand-edited or outdated row is skipped, not trusted
            }
            $reports[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'definition' => $definition,
                'updated_at' => (string)$row['updated_at']];
        }

        return $reports;
    }

    /**
     * Saves a report under $name; an existing report of that name is replaced.
     *
     * @return array{id: int, name: string, definition: array<string, mixed>}
     */
    public static function save(PDO $db, mixed $name, mixed $definitionJson, ?DateTimeImmutable $now = null): array
    {
        $name = self::name($name);
        if (!is_string($definitionJson) || strlen($definitionJson) > self::MAX_DEFINITION_BYTES) {
            throw new InvalidArgumentException('The report definition is missing or too long.');
        }
        try {
            $definition = self::check(json_decode($definitionJson, true, 8, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new InvalidArgumentException('The report definition is not valid JSON.');
        }
        $stmt = $db->prepare('SELECT id FROM ' . self::TABLE . ' WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id === false && (int)$db->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn() >= self::MAX_REPORTS) {
            throw new InvalidArgumentException('There are already ' . self::MAX_REPORTS . ' saved reports; delete one first.');
        }
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $at = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        if ($id === false) {
            $db->prepare('INSERT INTO ' . self::TABLE . ' (name, definition, updated_at) VALUES (?, ?, ?)')->execute([$name, $json, $at]);
            $id = $db->lastInsertId();
        } else {
            $db->prepare('UPDATE ' . self::TABLE . ' SET definition = ?, updated_at = ? WHERE id = ?')->execute([$json, $at, $id]);
        }

        return ['id' => (int)$id, 'name' => $name, 'definition' => $definition];
    }

    public static function delete(PDO $db, mixed $id): bool
    {
        if (!is_string($id) && !is_int($id) || !preg_match('/^[1-9]\d{0,9}$/', (string)$id)) {
            throw new InvalidArgumentException('Unknown report.');
        }
        $stmt = $db->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([(int)$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The definition rebuilt from checked values.
     *
     * @return array{metric: string, breakdown: string, interval: string, chart: string, preset: string,
     *               from?: string, to?: string, compare: bool, filters: array<string, list<string>>}
     */
    public static function check(mixed $d): array
    {
        if (!is_array($d)) {
            throw new InvalidArgumentException('The report definition must be an object.');
        }
        $catalog = QueryService::catalog();
        $pick = static function (string $field, array $allowed) use ($d): string {
            $value = $d[$field] ?? null;
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException("Unknown $field.");
            }
            return $value;
        };
        $clean = [
            'metric'    => $pick('metric', array_column($catalog['metrics'], 'key')),
            'breakdown' => $pick('breakdown', ['', ...$catalog['breakdowns']]),
            'interval'  => $pick('interval', ['auto', ...$catalog['intervals']]),
            'chart'     => $pick('chart', self::CHARTS),
            'preset'    => $pick('preset', self::PRESETS),
        ];
        if ($clean['preset'] === 'custom') {
            foreach (['from', 'to'] as $field) {
                $value = $d[$field] ?? null;
                // The pattern first: createFromFormat() throws (not returns false) on a NUL byte.
                $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $value)
                    : false;
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException("$field must be a date (YYYY-MM-DD).");
                }
                $clean[$field] = $value;
            }
            if ($clean['from'] > $clean['to']) {
                throw new InvalidArgumentException('from must be on or before to.');
            }
        }
        $clean['compare'] = ($d['compare'] ?? false) === true;

        $filters = $d['filters'] ?? [];
        if (!is_array($filters)) {
            throw new InvalidArgumentException('filters must be an object.');
        }
        $clean['filters'] = [];
        foreach ($filters as $name => $values) {
            if (!in_array($name, $catalog['filters'], true)) {
                throw new InvalidArgumentException('Unknown filter.');
            }
            if (!is_array($values) || $values === [] || count($values) > self::MAX_FILTER_VALUES || !array_is_list($values)) {
                throw new InvalidArgumentException("Filter $name must be a list of 1 to " . self::MAX_FILTER_VALUES . ' values.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || !preg_match('/^[0-9A-Za-z+*#_.,-]{0,64}$/', $value)) {
                    throw new InvalidArgumentException("Filter $name has an invalid value.");
                }
            }
            $clean['filters'][$name] = array_values(array_unique($values));
        }
        ksort($clean['filters']);

        return $clean;
    }

    private static function name(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || !mb_check_encoding($name, 'UTF-8')
            || preg_match('/\p{C}/u', $name)) {
            throw new InvalidArgumentException('A report name is 1 to ' . self::MAX_NAME . ' characters of plain text.');
        }
        return $name;
    }
}
