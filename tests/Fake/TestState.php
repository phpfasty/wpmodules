<?php

declare(strict_types=1);

namespace PhpFasty\WpModules\Tests\Fake;

/** Typed state of the function stubs (instead of untyped $GLOBALS entries). */
final class TestState
{
    public static string $uploadBase = '';

    /** @var array<int, string> attachment id => file path */
    public static array $files = [];

    /** @var array<int, string> post id => post type (downloads storage stubs) */
    public static array $types = [];

    /** @var array<int, string> post id => post type (bootstrap stub, default "product") */
    public static array $postTypes = [];
}
