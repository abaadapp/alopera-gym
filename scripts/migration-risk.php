<?php

/*
 * What a new migration does that putting the old code back cannot undo — run by scripts/deploy.sh before it pulls,
 * on the migrations main brings that the server has not run yet, so nothing is changed on the server when it stops.
 *
 *   php scripts/migration-risk.php <migration.php>...
 *
 * Looks inside up() only (down() drops what up() made, as it should), comments left out: dropping or renaming a table
 * or a column (from Schema or from the table's blueprint), dropping a key, an index or a constraint, changing a
 * column's type, deleting or truncating rows (destroy() too), and SQL that does one of these, in any string of up()
 * (quoted, heredoc or nowdoc, or put together from pieces). A migration meant to do one of these says so with the
 * marker below, which the owner sees in its pull request before merging (merging is deploying).
 *
 * Reported, not stopped (the deploy shows each as a warning to look at): rewriting rows (update(), UPDATE … SET), how a
 * new column is filled and a role given a permission, a third of the migrations so far; and a unique key or primary key
 * added to a table that has rows (Schema::table, CREATE UNIQUE INDEX, ADD … UNIQUE), which fails if they repeat.
 *
 * Exit 0: nothing found, only what is reported, or each finding marked as reviewed · 1: something not reviewed · 2: no
 * file, or unreadable.
 * Plain PHP, no Laravel: the deploy runs it from main's tree before main is pulled.
 */

const MARKER = '@deploy-destructive-reviewed';

const SCHEMA = 'Schema::(?:connection\([^)]*\)->)?';

const PATTERNS = [
    'drops a table' => '/'.SCHEMA.'drop(?:IfExists|AllTables|AllViews|AllTypes|DatabaseIfExists)?\s*\(|->drop(?:IfExists)?\s*\(/',
    'renames a table' => '/'.SCHEMA.'rename\s*\(|->rename\s*\(/',
    'drops a column' => '/->drop(?:Column|Columns|Timestamps|TimestampsTz|SoftDeletes|SoftDeletesTz|Morphs|NullableMorphs|RememberToken|ConstrainedForeignId|ConstrainedForeignIdFor)\s*\(|'.SCHEMA.'dropColumns\s*\(/',
    'renames a column' => '/->renameColumn\s*\(/',
    'drops a key, index or constraint' => '/->drop(?:Primary|Unique|Index|FullText|SpatialIndex|VectorIndex|Foreign|ForeignIdFor)\s*\(/',
    'changes a column' => '/->change\s*\(\s*\)/',
    'deletes rows' => '/(?:->(?:delete|forceDelete|truncate)|DB::delete|::destroy)\s*\(/',
];

/** SQL that does the same, in the strings of up(): each keyword followed by what SQL puts after it. */
const SQL_DESTRUCTIVE = '/\b(?:DROP|RENAME)\s+\w|\bTRUNCATE\b|\bDELETE\s+FROM\b|\bALTER\s+(?!TABLE\b)(?:COLUMN\s+)?\S+\s+(?:SET\s+DATA\s+)?TYPE\b/i';

/** Reported, does not stop the deploy. */
const REWRITES = '/(?:->update|DB::update)\s*\(/';
const SQL_REWRITES = '/\bUPDATE\s+\S+\s+SET\b/i';
const UNIQUE = '/->(?:unique|primary)\s*\(/';
const SQL_UNIQUE = '/\bCREATE\s+UNIQUE\s+INDEX\b|\bADD\s+(?:CONSTRAINT\s+\S+\s+)?(?:UNIQUE|PRIMARY\s+KEY)\b/i';

/** The body of up(), comments left out; the whole file (comments left out) if there is no up(). */
function upBody(string $source): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $code = '';
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;

        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (! (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_STRING && strtolower($tokens[$j][1]) === 'up')) {
            continue;
        }
        while ($j < $count && $tokens[$j] !== '{') {
            $j++;
        }
        $depth = 0;
        $body = '';
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            $body .= is_array($t) ? $t[1] : $t;
            if ($depth === 0) {
                return $body;
            }
        }

        return $body;
    }

    return $code;
}

/**
 * The strings of the code, quoted or heredoc/nowdoc, each piece of SQL read as a whole: pieces joined by "." or with a
 * value put inside ("… DROP " . "COLUMN", "DROP COLUMN {$name}") stay together, and SQL kept in a variable before
 * DB::statement($sql) is read too. Anything else between two strings (a comma, "=>", a parenthesis) keeps them apart
 * with a character SQL's spaces never match, so ['note' => 'drop', 'permission' => …] is not "drop permission".
 */
function strings(string $code): string
{
    $sameSql = ['.', '"', '}', '[', ']'];
    $sameSqlTokens = [T_START_HEREDOC, T_END_HEREDOC, T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR, T_STRING, T_NUM_STRING, T_STRING_VARNAME];
    $text = '';
    foreach (token_get_all("<?php\n".$code) as $token) {
        [$id, $value] = is_array($token) ? $token : [null, $token];
        if ($id === T_OPEN_TAG || $id === T_WHITESPACE) {
            continue;
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $text .= substr($value, 1, -1);
        } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
            $text .= $value;
        } else {
            $text .= in_array($value, $sameSql, true) || in_array($id, $sameSqlTokens, true) ? ' ' : "\0";
        }
    }

    return $text;
}

/** @return list<string> what the migration's up() does that cannot be undone */
function findings(string $source): array
{
    $body = upBody($source);
    $found = [];
    foreach (PATTERNS as $what => $pattern) {
        if (preg_match($pattern, $body)) {
            $found[] = $what;
        }
    }
    if (preg_match(SQL_DESTRUCTIVE, strings($body))) {
        $found[] = 'runs SQL that drops, renames, truncates, deletes or changes a type';
    }

    return $found;
}

/** @return list<string> what is reported without stopping the deploy */
function notes(string $source): array
{
    $body = upBody($source);
    $notes = [];
    if (preg_match(REWRITES, $body) || preg_match(SQL_REWRITES, strings($body))) {
        $notes[] = 'rewrites rows (reported, not stopped: look that it only fills what it adds)';
    }
    // A unique key inside Schema::table() (a table made before, with rows), not Schema::create().
    $onExistingTable = false;
    foreach (preg_split('/(?=Schema::)/', $body) as $call) {
        $onExistingTable = $onExistingTable || (preg_match('/^'.SCHEMA.'table\s*\(/', $call) && preg_match(UNIQUE, $call));
    }
    if ($onExistingTable || preg_match(SQL_UNIQUE, strings($body))) {
        $notes[] = 'adds a unique key to a table that may have rows (reported, not stopped: the migration fails if they repeat)';
    }

    return $notes;
}

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: php scripts/migration-risk.php <migration.php>...\n");
    exit(2);
}

$unreviewed = 0;
foreach ($files as $file) {
    $source = @file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, "$file: cannot be read\n");
        exit(2);
    }
    $found = findings($source);
    $notes = notes($source);
    $note = $notes === [] ? '' : ' · '.implode(' · ', $notes);
    if ($found === []) {
        echo $file.': '.($note === '' ? 'adds only' : 'adds'.$note)."\n";

        continue;
    }
    $reviewed = str_contains($source, MARKER);
    echo $file.': '.implode(', ', $found).($reviewed ? ' — marked '.MARKER : ' — NOT REVIEWED').$note."\n";
    $unreviewed += $reviewed ? 0 : 1;
}

exit($unreviewed > 0 ? 1 : 0);
