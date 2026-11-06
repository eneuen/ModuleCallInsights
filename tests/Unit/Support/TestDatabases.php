<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit\Support;

use PDO;

/**
 * Builds throwaway SQLite files that match production:
 * - a CDR database with the real cdr_general schema (tests/fixtures/cdr_general.sql),
 *   filled with recorded fixture legs;
 * - a config database with an m_Providers table;
 * - a module database with the tables the core would create from Models/*.php.
 *
 * The module tables are derived from the model annotations, the same source
 * the core's installer uses, so the tests cannot drift from the real schema.
 */
final class TestDatabases
{
    private const string FIXTURES = __DIR__ . '/../../fixtures';
    private const string MODELS = __DIR__ . '/../../../Models';

    /** @var list<string> */
    private array $files = [];

    public function __destruct()
    {
        foreach ($this->files as $file) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($file . $suffix);
            }
        }
    }

    public function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ci-test-') . '.db';
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    public function cdrDatabase(array $legs = []): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        $pdo->exec((string)file_get_contents(self::FIXTURES . '/cdr_general.sql'));
        self::insertLegs($pdo, $legs);

        return $file;
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    public static function insertLegs(PDO $pdo, array $legs): void
    {
        foreach ($legs as $leg) {
            $columns = array_keys($leg);
            $stmt = $pdo->prepare(sprintf(
                'INSERT INTO cdr_general (%s) VALUES (%s)',
                implode(',', array_map(fn (string $c): string => "`$c`", $columns)),
                implode(',', array_fill(0, count($columns), '?'))
            ));
            $stmt->execute(array_values($leg));
        }
    }

    /**
     * @param list<string> $providerIds
     */
    public function configDatabase(array $providerIds): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        $pdo->exec('CREATE TABLE m_Providers (id INTEGER PRIMARY KEY, uniqid VARCHAR, type VARCHAR)');
        $stmt = $pdo->prepare("INSERT INTO m_Providers (uniqid, type) VALUES (?, 'SIP')");
        foreach ($providerIds as $id) {
            $stmt->execute([$id]);
        }

        return $file;
    }

    public function moduleDatabase(): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        foreach (glob(self::MODELS . '/*.php') as $model) {
            foreach (self::tableSql((string)file_get_contents($model)) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $file;
    }

    public static function writable(string $file): PDO
    {
        return new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @param array<string, mixed> $fixture
     *
     * @return list<array<string, mixed>>
     */
    public static function legsOf(string ...$scenarios): array
    {
        $legs = [];
        foreach ($scenarios as $scenario) {
            $fixture = json_decode(
                (string)file_get_contents(self::FIXTURES . "/cdr/$scenario.json"),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            array_push($legs, ...$fixture['legs']);
        }

        return $legs;
    }

    /** The provider ID every fixture was recorded with. */
    public static function fixtureProviderId(): string
    {
        $fixture = json_decode(
            (string)file_get_contents(self::FIXTURES . '/cdr/inbound_direct_answered.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        return $fixture['setup']['provider_id'];
    }

    /**
     * CREATE TABLE and CREATE INDEX statements for one model file, read from
     * its setSource(), @Column, @Primary/@Identity and @Indexes annotations.
     *
     * @return list<string>
     */
    private static function tableSql(string $source): array
    {
        preg_match("/setSource\\('([^']+)'\\)/", $source, $m);
        $table = $m[1];

        preg_match_all('/\/\*\*(.*?)\*\/\s*public \$(\w+);/s', $source, $props, PREG_SET_ORDER);
        $columns = [];
        foreach ($props as [, $doc, $name]) {
            if (!preg_match('/@Column\(type="(\w+)"/', $doc, $type)) {
                continue;
            }
            if (str_contains($doc, '@Identity')) {
                $columns[] = "`$name` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }
            $sqlType = $type[1] === 'integer' ? 'INTEGER' : 'VARCHAR';
            $null = str_contains($doc, 'nullable=false') ? 'NOT NULL' : 'NULL';
            $default = preg_match('/default="([^"]*)"/', $doc, $d) ? " DEFAULT '{$d[1]}'" : '';
            $columns[] = "`$name` $sqlType $null$default";
        }
        $sql = ["CREATE TABLE `$table` (\n  " . implode(",\n  ", $columns) . "\n)"];

        preg_match_all("/\\[name='(\\w+)', columns=\\[([^\\]]+)\\], type='(\\w*)'\\]/", $source, $indexes, PREG_SET_ORDER);
        foreach ($indexes as [, $name, $cols, $type]) {
            $cols = implode(',', array_map(fn (string $c): string => '`' . trim($c, " '") . '`', explode(',', $cols)));
            $unique = strtoupper($type) === 'UNIQUE' ? 'UNIQUE ' : '';
            $sql[] = "CREATE {$unique}INDEX `i_{$table}_$name` ON `$table` ($cols)";
        }

        return $sql;
    }
}
