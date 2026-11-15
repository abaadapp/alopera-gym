<?php

namespace App\Core\Backup;

use Generator;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The rows of an archive's data.json, read a row at a time from the file it was taken out to, so a restore or the
 * check of a new archive holds one row (one chunk of rows), never the whole club, in memory, whatever its size.
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
 * still one plain JSON document, which the code before this class reads as it always did. Older archives hold the
 * same document on one line (json_encode of every row at once): it is read in pieces too — each row found by its
 * braces, strings and their escapes stepped over — and written a row per line to a file of its own, read from then on
 * as a newer one.
 */
final class ArchiveRows
{
    private const PIECE = 1 << 20;

    /** @var resource|null */
    private $handle = null;

    /** The row-per-line copy of an older one-line data.json, removed on close. */
    private ?string $copy = null;

    /** @var array<string, int> each table's first row line, as an offset in the file */
    private array $offsets = [];

    /** @var array<string, int> */
    private array $counts = [];

    // An older one-line data.json being read: the piece in hand, where in it, and the file it comes from.
    /** @var resource|null */
    private $in = null;

    private string $buf = '';

    private int $pos = 0;

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
        try {
            if (fread($handle, 2) === "{\n") {
                $rows->handle = $handle;
                $rows->readLines($handle, $each);
            } else {
                rewind($handle);
                $rows->readOneLine($handle, $each);
            }
        } catch (Throwable $e) {
            $rows->close();
            is_resource($handle) && fclose($handle);

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
        if ($this->copy !== null) {
            @unlink($this->copy);
            $this->copy = null;
        }
    }

    // ── A row per line (archives written since this class) ─────────────────────────────────────────────────────────

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

    // ── One line (archives of the code before this class), read in pieces ──────────────────────────────────────────

    /**
     * {"table":[{row},{row}],"next":[]} read a piece at a time: each row checked and handed to $each, and written a row
     * per line to a copy, which of() then reads.
     *
     * @param  resource  $in
     */
    private function readOneLine($in, callable $each): void
    {
        $this->in = $in;
        $this->copy = (string) tempnam(sys_get_temp_dir(), 'gymrs');
        $out = fopen($this->copy, 'w+b');
        if ($out === false) {
            throw new RuntimeException('data.json could not be read through (no room for a copy)');
        }
        $this->handle = $out;
        $put = function (string $piece) use ($out): void {
            if (fwrite($out, $piece) !== strlen($piece)) {
                throw new RuntimeException('data.json could not be read through (is the disk full?)');
            }
        };

        $this->expect('{');
        $put("{\n");
        $tables = 0;
        if ($this->peek() === '}') {
            $this->pos++;
        } else {
            while (true) {
                $table = json_decode($this->unit('"'));
                if (! is_string($table) || isset($this->offsets[$table])) {
                    throw new RuntimeException('data.json names a table twice or not at all');
                }
                $this->expect(':');
                $this->expect('[');
                $put(($tables++ ? "],\n" : '').json_encode($table).":[\n");
                $this->offsets[$table] = (int) ftell($out);
                $this->counts[$table] = 0;
                if ($this->peek() === ']') {
                    $this->pos++;
                } else {
                    while (true) {
                        if ($this->peek() !== '{') {
                            throw new RuntimeException('data.json holds a row that is not one');
                        }
                        $text = $this->unit('{');
                        $row = self::row($text);
                        $each($table, $row);
                        $line = strpbrk($text, "\r\n") === false ? $text : json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $put(($this->counts[$table]++ ? ",\n" : '').$line);
                        $next = $this->peek();
                        $this->pos++;
                        if ($next === ']') {
                            break;
                        }
                        if ($next !== ',') {
                            throw new RuntimeException('data.json is not in the form an archive holds');
                        }
                    }
                    $put("\n");
                }
                $next = $this->peek();
                $this->pos++;
                if ($next === '}') {
                    break;
                }
                if ($next !== ',') {
                    throw new RuntimeException('data.json is not in the form an archive holds');
                }
            }
        }
        $put($tables ? "]\n}\n" : "}\n");
        if ($this->peek() !== null) {
            throw new RuntimeException('data.json goes on after its end');
        }
        fclose($in);
        $this->in = null;
        $this->buf = '';
        if (fflush($out) === false || rewind($out) === false) {
            throw new RuntimeException('data.json could not be read through');
        }
    }

    /** The next character that is not white space, without taking it; null at the end of the file. */
    private function peek(): ?string
    {
        // Between two units: what was read already goes, so the piece in hand stays about one piece long.
        if ($this->pos > self::PIECE) {
            $this->buf = substr($this->buf, $this->pos);
            $this->pos = 0;
        }
        while (true) {
            $this->pos += strspn($this->buf, " \t\r\n", $this->pos);
            if ($this->pos < strlen($this->buf)) {
                return $this->buf[$this->pos];
            }
            if (! $this->more()) {
                return null;
            }
        }
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new RuntimeException($this->peek() === null ? 'data.json is cut short' : 'data.json is not in the form an archive holds');
        }
        $this->pos++;
    }

    /**
     * The whole of the string ('"') or object ('{') starting here, as it is written: braces counted, strings (and
     * their escaped quotes and backslashes) stepped over, more of the file read as long as it is not closed.
     */
    private function unit(string $opens): string
    {
        if ($this->peek() !== $opens) {
            throw new RuntimeException('data.json is not in the form an archive holds');
        }
        $start = $this->pos;
        $i = $start + 1;
        $inString = $opens === '"';
        $depth = $opens === '{' ? 1 : 0;
        while (true) {
            $stop = $inString ? '"\\' : '"{}';
            $i += strcspn($this->buf, $stop, $i);
            if ($i >= strlen($this->buf) || ($this->buf[$i] === '\\' && $i + 1 >= strlen($this->buf))) {
                if (! $this->more()) {
                    throw new RuntimeException('data.json is cut short');
                }

                continue;
            }
            $char = $this->buf[$i];
            if ($char === '\\') {
                $i += 2;

                continue;
            }
            $i++;
            if ($char === '"') {
                $inString = ! $inString;
                if (! $inString && $opens === '"') {
                    break;
                }
            } elseif ($char === '{') {
                $depth++;
            } elseif (--$depth === 0) {
                break;
            }
        }
        $this->pos = $i;

        return substr($this->buf, $start, $i - $start);
    }

    /** The next piece of the file added to the one in hand. False at the end of the file. */
    private function more(): bool
    {
        if (! is_resource($this->in) || feof($this->in)) {
            return false;
        }
        $piece = fread($this->in, self::PIECE);
        if ($piece === false || $piece === '') {
            return false;
        }
        $this->buf .= $piece;

        return true;
    }

    /** @return array<string, mixed> */
    private static function row(string $text): array
    {
        try {
            $row = json_decode(rtrim($text, ",\n"), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('data.json holds a row that does not read');
        }
        if (! is_array($row) || array_is_list($row) && $row !== []) {
            throw new RuntimeException('data.json holds a row that is not one');
        }

        return $row;
    }
}
