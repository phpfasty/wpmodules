<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Scan;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CMS-agnostic core: exception messages are data; hosts escape them where they are printed.

use PhpFasty\Moduly\Exception\ScanException;
use PhpFasty\Moduly\Package\Fs;

/**
 * Token-based scan of module PHP (TZ §4.4) before anything is included. Not a sandbox: it blocks the obvious
 * dangerous constructs; trust comes from the publisher signature.
 */
final class StaticScanner
{
    public const FORBIDDEN_FUNCTIONS = [
        'assert', 'create_function', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'ini_set', 'ini_alter', 'error_reporting', 'set_error_handler', 'set_exception_handler', 'extract',
        'parse_str', 'dl', 'putenv', 'apache_setenv', 'mail', 'symlink', 'link', 'chmod', 'chown', 'chgrp',
        'move_uploaded_file', 'highlight_file', 'show_source', 'phpinfo', 'posix_kill',
    ];

    /** Function names that are flagged even as callable strings (case-sensitive, exact). */
    private const CALLABLE_STRINGS = ['exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'create_function', 'assert'];

    /** @param list<string> $extraForbidden additional host functions (e.g. delete_option in migrations) */
    public function __construct(private readonly array $extraForbidden = [])
    {
    }

    /**
     * @return list<string> violations "file:line message"
     */
    public function scanDirectory(string $dir): array
    {
        $out = [];
        foreach (Fs::files($dir) as $rel) {
            if (str_ends_with($rel, '.php')) {
                foreach ($this->scanCode((string) file_get_contents($dir . '/' . $rel)) as [$line, $msg]) {
                    $out[] = "{$rel}:{$line} {$msg}";
                }
            }
        }
        return $out;
    }

    public function assertClean(string $dir): void
    {
        $v = $this->scanDirectory($dir);
        if ($v !== []) {
            throw new ScanException('forbidden_code', "Module code uses forbidden constructs:\n" . implode("\n", array_slice($v, 0, 20)));
        }
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    public function scanCode(string $code): array
    {
        $forbidden = array_merge(self::FORBIDDEN_FUNCTIONS, $this->extraForbidden);
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $out = [];
        $line = 1;
        $inBacktick = false;
        foreach ($tokens as $i => $t) {
            if (is_array($t)) {
                $line = $t[2];
            }
            if ($t === '`') {
                if (!$inBacktick) {
                    $out[] = [$line, 'backtick shell execution'];
                }
                $inBacktick = !$inBacktick;
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            switch ($t[0]) {
                case T_EVAL:
                    $out[] = [$line, 'eval()'];
                    continue 2;
                case T_HALT_COMPILER:
                    $out[] = [$line, '__halt_compiler()'];
                    continue 2;
                case T_INLINE_HTML:
                    if (trim($t[1]) !== '') {
                        $out[] = [$line, 'inline output outside PHP tags'];
                    }
                    continue 2;
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    $out[] = [$line, 'include/require (modules are autoloaded by the core)'];
                    continue 2;
                case T_VARIABLE:
                    if (in_array($t[1], ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER', '$GLOBALS'], true) && !self::allowedSuperglobal($code)) {
                        // Superglobals are allowed but flagged when a file opts out of the convention marker.
                        $out[] = [$line, "direct {$t[1]} access"];
                    }
                    continue 2;
            }
            if (!in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($t[1], '\\'));
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            $isCall = $next === '(';
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);
            if ($isMember) {
                continue;
            }
            if ($isCall && in_array($name, $forbidden, true)) {
                $out[] = [$line, "{$name}()"];
            }
            // Callable strings such as array_map('exec', …) are caught by the string check below.
        }
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $s = trim($t[1], "'\"");
                if (in_array($s, self::CALLABLE_STRINGS, true)) {
                    $out[] = [$t[2], "callable string '{$s}'"];
                }
            }
        }
        return $out;
    }

    private static function allowedSuperglobal(string $code): bool
    {
        // Host adapters read request data through the host API; modules may read superglobals only in files
        // explicitly marked as request handlers (reviewed by the publisher before signing).
        return str_contains($code, '@moduly-request-handler');
    }
}
