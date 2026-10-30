<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Kernel;

/**
 * Port: append-only audit trail (install, enable, disable, update, rollback, uninstall, safe-mode, signature-fail…).
 */
interface AuditLogInterface
{
    /** @param array<string, mixed> $context */
    public function record(string $action, string $moduleId, string $version, string $result, array $context = []): void;
}
