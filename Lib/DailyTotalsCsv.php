<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * CSV export and import of the daily totals, the one thing the module cannot
 * rebuild once the CDR has deleted a day (plan: Data strategy).
 *
 * Export writes every row. Text cells that a spreadsheet would treat as a
 * formula (starting with = + - @, tab or CR) get a leading apostrophe
 * (OWASP CSV injection); import removes it again.
 *
 * Import is strict and conservative:
 * - the header must match exactly and every value is validated (dates,
 *   known directions and outcomes, non-negative integers, short IDs made of
 *   digits, letters and + * # _ -);
 * - any invalid line rejects the whole file;
 * - only days not already in the daily totals are added, so an import never
 *   overwrites anything; imported days are frozen;
 * - everything happens in one transaction.
 *
 * Plain PHP, tested without a PBX.
 */
final class DailyTotalsCsv
{
    public const int MAX_BYTES = 20 * 1024 * 1024;
    public const int MAX_ROWS = 500000;

    public const array COLUMNS = [
        'local_date', 'direction', 'did', 'provider_id', 'queue', 'answered_by', 'outcome',
        'calls', 'answered', 'talk_seconds', 'wait_seconds',
        'tta_0_10', 'tta_10_20', 'tta_20_30', 'tta_30_60', 'tta_60_120', 'tta_120_plus',
    ];
    private const array TEXT = ['did', 'provider_id', 'queue', 'answered_by'];
    private const array INTS = ['calls', 'answered', 'talk_seconds', 'wait_seconds',
        'tta_0_10', 'tta_10_20', 'tta_20_30', 'tta_30_60', 'tta_60_120', 'tta_120_plus'];
    private const array DIRECTIONS = ['in', 'out', 'internal'];
    private const array OUTCOMES = ['answered', 'voicemail', 'abandoned_ivr', 'abandoned_queue', 'out_of_hours',
        'busy', 'failed', 'no_answer'];

    /** The daily totals as CSV text, oldest day first. */
    public static function export(PDO $db): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, self::COLUMNS, ',', '"', '');
        $stmt = $db->query('SELECT ' . implode(', ', self::COLUMNS) . ' FROM ' . IndexStore::DAILY . ' ORDER BY local_date, id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, array_map([self::class, 'protect'], array_values($row)), ',', '"', '');
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Adds the days in $csv that the daily totals don't have yet, frozen.
     *
     * @return array{days_added: int, days_skipped: int, rows_added: int}
     */
    public static function import(PDO $db, string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is too large.');
        }
        $rows = self::parse($csv);

        $existing = array_flip($db->query('SELECT DISTINCT local_date FROM ' . IndexStore::DAILY)->fetchAll(PDO::FETCH_COLUMN));
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['local_date']][] = $row;
        }
        $insert = $db->prepare('INSERT INTO ' . IndexStore::DAILY . ' (' . implode(', ', self::COLUMNS) . ', frozen) VALUES ('
            . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ', 1)');
        $added = 0;
        $skipped = 0;
        $rowsAdded = 0;
        $db->beginTransaction();
        try {
            foreach ($byDay as $day => $dayRows) {
                if (isset($existing[$day])) {
                    $skipped++;
                    continue;
                }
                foreach ($dayRows as $row) {
                    $insert->execute(array_map(fn (string $c) => $row[$c], self::COLUMNS));
                    $rowsAdded++;
                }
                $added++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw new InvalidArgumentException('The file contains rows that conflict with each other.', 0, $e);
        }

        return ['days_added' => $added, 'days_skipped' => $skipped, 'rows_added' => $rowsAdded];
    }

    /**
     * @return list<array<string, int|string>>
     */
    private static function parse(string $csv): array
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, $csv);
        rewind($in);
        $header = fgetcsv($in, null, ',', '"', '');
        if ($header === false || $header !== self::COLUMNS) {
            fclose($in);
            throw new InvalidArgumentException('This is not a Call Insights daily-totals file (the header does not match).');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($in, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;       // blank line
            }
            if (count($cells) !== count(self::COLUMNS) || count($rows) >= self::MAX_ROWS) {
                fclose($in);
                throw new InvalidArgumentException("Line $line has the wrong number of values.");
            }
            $row = array_combine(self::COLUMNS, array_map([self::class, 'unprotect'], $cells));
            // The pattern first: createFromFormat() throws (not returns false) on a NUL byte.
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row['local_date']) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$row['local_date'])
                : false;
            if ($date === false || $date->format('Y-m-d') !== $row['local_date']
                || !in_array($row['direction'], self::DIRECTIONS, true)
                || !in_array($row['outcome'], self::OUTCOMES, true)) {
                fclose($in);
                throw new InvalidArgumentException("Line $line has an invalid date, direction or outcome.");
            }
            foreach (self::TEXT as $c) {
                if (!preg_match('/^[0-9A-Za-z+*#_-]{0,64}$/', (string)$row[$c])) {
                    fclose($in);
                    throw new InvalidArgumentException("Line $line has an invalid $c.");
                }
            }
            foreach (self::INTS as $c) {
                if (!preg_match('/^\d{1,12}$/', (string)$row[$c])) {
                    fclose($in);
                    throw new InvalidArgumentException("Line $line has an invalid $c.");
                }
                $row[$c] = (int)$row[$c];
            }
            $rows[] = $row;
        }
        fclose($in);

        return $rows;
    }

    /** Prefixes formula-like text with an apostrophe (OWASP CSV injection). */
    public static function protect(mixed $value): string
    {
        $value = (string)$value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    private static function unprotect(?string $value): string
    {
        $value = (string)$value;

        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)
            ? substr($value, 1)
            : $value;
    }
}
