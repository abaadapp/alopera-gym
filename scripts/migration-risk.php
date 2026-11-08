<?php

/*
 * What a new migration does that putting the old code back cannot undo — run by scripts/deploy.sh before it pulls,
 * on the migrations main brings that the server has not run yet, so nothing is changed on the server when it stops.
 *
 *   php scripts/migration-risk.php <migration.php>...
 *
 * Looks inside up() only (down() drops what up() made, as it should), comments left out: dropping or renaming a table
 * or a column, changing a column's type, deleting or truncating rows, raw SQL that drops, truncates, deletes, renames
 * or changes a column's type. A migration meant to do one of these says so with the marker below, which the owner sees
 * in its pull request before merging (merging is deploying).
 *
 * Exit 0: nothing found, or each finding marked as reviewed · 1: something not reviewed · 2: no file, or unreadable.
 * Plain PHP, no Laravel: the deploy runs it from main's tree before main is pulled.
 */

const MARKER = '@deploy-destructive-reviewed';

const PATTERNS = [
    'drops a table' => '/Schema::(?:connection\([^)]*\)->)?drop(?:IfExists)?\s*\(/',
    'renames a table' => '/Schema::(?:connection\([^)]*\)->)?rename\s*\(/',
    'drops a column' => '/->drop(?:Column|Columns|Timestamps|TimestampsTz|SoftDeletes|SoftDeletesTz|Morphs|NullableMorphs|RememberToken|ConstrainedForeignId)\s*\(/',
    'renames a column' => '/->renameColumn\s*\(/',
    'changes a column' => '/->change\s*\(\s*\)/',
    'deletes rows' => '/(?:->(?:delete|forceDelete|truncate)|DB::delete)\s*\(/',
];

const RAW_SQL = '/(?:statement|unprepared)\s*\(\s*([\'"])(.*?)(?<!\\\\)\1/s';
const RAW_DESTRUCTIVE = '/\b(?:DROP|TRUNCATE|DELETE|RENAME)\b|\bALTER\s+COLUMN\s+\S+\s+(?:SET\s+DATA\s+)?TYPE\b/i';

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
    if (preg_match_all(RAW_SQL, $body, $matches)) {
        foreach ($matches[2] as $sql) {
            if (preg_match(RAW_DESTRUCTIVE, $sql)) {
                $found[] = 'runs SQL that drops, truncates, deletes, renames or changes a type';
                break;
            }
        }
    }

    return $found;
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
    if ($found === []) {
        echo "$file: adds only\n";

        continue;
    }
    $reviewed = str_contains($source, MARKER);
    echo $file.': '.implode(', ', $found).($reviewed ? ' — marked '.MARKER : ' — NOT REVIEWED')."\n";
    $unreviewed += $reviewed ? 0 : 1;
}

exit($unreviewed > 0 ? 1 : 0);
