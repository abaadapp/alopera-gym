<?php

namespace App\Core\Backup;

use Generator;
use JsonException;
use RuntimeException;

/**
 * The rows of an archive's data.json, read a row at a time from the file it was taken out to, so a restore or the
 * check of a new archive holds one row (one chunk of rows), not the whole club, in memory.
 *
 * Archives written since this class (TenantBackupService::writeRows) hold one row per line:
 *
 *     {
 *     "gym_members":[
 *     {…a row…},
 *     {…a row…}
 *     ],
 *     "gym_other":[
 *     ]
 *     }
 *
 * still one plain JSON document, which the code before this class reads as it always did. Older archives are one
 * line: those are decoded whole, as they always were.
 */
final class ArchiveRows
{
    /** @var resource|null */
    private $handle = null;

    /** @var array<string, int> each table's first row line, as an offset in the file */
    private array $offsets = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, list<array<string, mixed>>>|null an older one-line data.json, decoded whole */
    private ?array $whole = null;

    /**
     * Reads $path through once, each row decoded and handed to $each(table, row): the checks made before anything is
     * replaced. Then each table can be read again, in any order (of()).
     *
     * @param  callable(string, array<string, mixed>): void  $each
     *
     * @throws RuntimeException when the file is not the document an archive holds (cut, altered, of another shape)
     */
    public static function read(string $path, callable $each): self
    {
        $rows = new self;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('data.json could not be read');
        }
        if (fgets($handle) !== "{\n") {
            fclose($handle);
            $rows->readWhole($path, $each);

            return $rows;
        }
        $rows->handle = $handle;
        try {
            $rows->readLines($handle, $each);
        } catch (\Throwable $e) {
            $rows->close();

            throw $e;
        }

        return $rows;
    }

    /** @return array<string, int> rows per table */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return Generator<int, array<string, mixed>> the rows of $table, in the archive's order */
    public function of(string $table): Generator
    {
        if ($this->whole !== null) {
            yield from $this->whole[$table] ?? [];

            return;
        }
        if (! isset($this->offsets[$table]) || ! is_resource($this->handle)) {
            return;
        }
        if (fseek($this->handle, $this->offsets[$table]) !== 0) {
            throw new RuntimeException('data.json could not be read again');
        }
        // read() went through every line already: only rows follow, up to the line closing the table.
        while (($line = fgets($this->handle)) !== false && $line[0] === '{') {
            yield self::row($line);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /** @param resource $handle */
    private function readLines($handle, callable $each): void
    {
        $table = null;
        $state = 'tables'; // tables → rows → (tables | last) → end
        while (($line = fgets($handle)) !== false) {
            if ($state === 'rows' && $line[0] === '{') {
                $each($table, self::row($line));
                $this->counts[$table]++;

                continue;
            }
            if ($state === 'rows' && ($line === "],\n" || $line === "]\n")) {
                $state = $line === "]\n" ? 'last' : 'tables';

                continue;
            }
            if ($state === 'tables' && preg_match('/^("(?:[^"\\\\]|\\\\.)*"):\[\n$/', $line, $m)) {
                $table = json_decode($m[1]);
                if (! is_string($table) || isset($this->offsets[$table])) {
                    throw new RuntimeException('data.json names a table twice or not at all');
                }
                $this->offsets[$table] = (int) ftell($handle);
                $this->counts[$table] = 0;
                $state = 'rows';

                continue;
            }
            if (($state === 'last' || ($state === 'tables' && $table === null)) && $line === "}\n") {
                $state = 'end';

                continue;
            }

            throw new RuntimeException($state === 'end' ? 'data.json goes on after its end' : 'data.json is not in the form an archive holds');
        }
        if ($state !== 'end') {
            throw new RuntimeException('data.json is cut short');
        }
    }

    private function readWhole(string $path, callable $each): void
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException('data.json is not the document an archive holds');
        }
        foreach ($data as $table => $list) {
            if (! is_string($table) || ! is_array($list) || ! array_is_list($list)) {
                throw new RuntimeException('data.json is not the document an archive holds');
            }
            foreach ($list as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('data.json holds a row that is not one');
                }
                $each($table, $row);
            }
            $this->counts[$table] = count($list);
        }
        $this->whole = $data;
    }

    /** @return array<string, mixed> */
    private static function row(string $line): array
    {
        try {
            $row = json_decode(rtrim($line, ",\n"), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('data.json holds a row that does not read');
        }
        if (! is_array($row) || array_is_list($row) && $row !== []) {
            throw new RuntimeException('data.json holds a row that is not one');
        }

        return $row;
    }
}
