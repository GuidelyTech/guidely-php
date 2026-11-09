<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * SQL statements as they leave the process: literal values replaced by `?` (the Node SDK does the
 * same, packages/node/src/tracing/db.ts).
 *
 * @internal
 */
final class Sql
{
    /** PDO driver names → OpenTelemetry `db.system`. */
    private const SYSTEMS = ['mysql' => 'mysql', 'mariadb' => 'mariadb', 'pgsql' => 'postgresql', 'postgres' => 'postgresql', 'sqlite' => 'sqlite', 'sqlsrv' => 'mssql', 'dblib' => 'mssql', 'oci' => 'oracle', 'firebird' => 'firebird', 'ibm' => 'db2', 'odbc' => 'odbc'];

    public static function system(?string $driver): ?string
    {
        if ($driver === null || $driver === '') {
            return null;
        }
        $d = strtolower($driver);
        return self::SYSTEMS[$d] ?? $d;
    }

    /**
     * Quoted strings (and, for MySQL, double-quoted ones), dollar-quoted strings, hex and numeric
     * literals become `?`; placeholders (`?`, `$1`, `:name`) stay, so a prepared statement reads as written.
     */
    public static function scrub(string $sql, ?string $system = null): string
    {
        // The dollar-quote tag group always takes part (possibly empty): PCRE never matches a
        // backreference to an unset group. MySQL escapes quotes with backslashes and also quotes
        // strings with "; PostgreSQL doubles quotes (backslashes escape only in E'' strings) and
        // quotes identifiers with ".
        $out = (string) preg_replace('/\$([a-z_]\w*|)\$[\s\S]*?\$\1\$/i', '?', $sql);
        if ($system === 'mysql' || $system === 'mariadb') {
            $out = (string) preg_replace(["/(?:\\b[bxn])?'(?:[^'\\\\]|\\\\.|'')*'/i", '/"(?:[^"\\\\]|\\\\.|"")*"/'], '?', $out);
        } else {
            $out = (string) preg_replace(["/\\be'(?:[^'\\\\]|\\\\.|'')*'/i", "/(?:\\b[bxn])?'(?:[^']|'')*'/i"], '?', $out);
        }
        $out = (string) preg_replace(['/\b0x[0-9a-f]+\b/i', '/(?<![\w$."`\]:])\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/i'], '?', $out);
        return ErrorCapture::cut($out, 2000);
    }

    /** The first keyword (`SELECT`, `INSERT`…), OpenTelemetry's `db.operation`. */
    public static function operation(string $sql): ?string
    {
        return preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*([a-z]+)/i', $sql, $m) === 1 ? strtoupper($m[1]) : null;
    }
}
