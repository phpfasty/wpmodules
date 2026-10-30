<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Manifest;

use JsonException;
use PhpFasty\Moduly\Exception\ManifestException;
use PhpFasty\Moduly\Version\Constraint;
use PhpFasty\Moduly\Version\Semver;

/**
 * Validates module.json against schema/module-1.json rules (hand-written: the core has no runtime dependencies).
 * Collects every error, then throws once.
 */
final class ManifestValidator
{
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{1,40}$/';
    public const SCHEMA = 'https://moduly.phpfasty.dev/schema/module-1.json';

    private const TOP = [
        '$schema', 'id', 'name', 'version', 'publisher', 'license', 'description', 'requires', 'provides',
        'permissions', 'entry', 'autoload', 'hooks', 'settings',
    ];
    private const REQUIRED = ['id', 'name', 'version', 'publisher', 'license', 'entry', 'autoload'];
    private const RESERVED_NAMESPACES = ['PhpFasty\\Moduly\\', 'WP_', 'WPFasty\\Core\\'];
    private const HOOK_TYPES = ['action', 'filter'];
    private const SETTINGS_KEYS = [
        'type', 'properties', 'additionalProperties', 'required', 'enum', 'minimum', 'maximum', 'minLength', 'maxLength',
        'pattern', 'format', 'default', 'title', 'description', 'items',
    ];

    /** @var list<string> */
    private array $errors = [];

    public function fromJson(string $json, ?string $expectedId = null): Manifest
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException('invalid_json', 'module.json is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ManifestException('invalid_manifest', 'module.json must be a JSON object');
        }
        /** @var array<string, mixed> $data */
        return $this->fromArray($data, $expectedId);
    }

    /** @param array<string, mixed> $data */
    public function fromArray(array $data, ?string $expectedId = null): Manifest
    {
        $this->errors = [];
        $this->unknownKeys($data, self::TOP, '');
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $data)) {
                $this->errors[] = "missing required \"{$key}\"";
            }
        }
        if (isset($data['$schema']) && $data['$schema'] !== self::SCHEMA) {
            $this->errors[] = '"$schema" must be ' . self::SCHEMA;
        }

        $id = $this->string($data, 'id');
        if ($id !== '' && preg_match(self::ID_PATTERN, $id) !== 1) {
            $this->errors[] = '"id" must match ' . self::ID_PATTERN;
        }
        if ($expectedId !== null && $id !== '' && $id !== $expectedId) {
            $this->errors[] = "\"id\" ({$id}) must match the module root folder ({$expectedId})";
        }
        $name = $this->string($data, 'name');
        if ($name !== '' && mb_strlen($name) > 80) {
            $this->errors[] = '"name" is longer than 80 characters';
        }
        $versionRaw = $this->string($data, 'version');
        $version = null;
        if ($versionRaw !== '') {
            if (Semver::isValid($versionRaw)) {
                $version = Semver::parse($versionRaw);
            } else {
                $this->errors[] = '"version" must be SemVer 2.0';
            }
        }
        $publisher = $this->string($data, 'publisher');
        if ($publisher !== '' && preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $publisher) !== 1) {
            $this->errors[] = '"publisher" must match ^[a-z0-9][a-z0-9-]{1,63}$';
        }
        $license = $this->string($data, 'license');
        $description = $this->string($data, 'description');

        [$core, $php, $host, $modules] = $this->requires($data['requires'] ?? []);
        [$features, $types, $capabilities] = $this->provides($data['provides'] ?? []);
        $permissions = $this->permissions($data['permissions'] ?? []);
        $psr4 = $this->autoload($data['autoload'] ?? []);
        $entry = $this->string($data, 'entry');
        if ($entry !== '') {
            $this->entry($entry, $psr4);
        }
        $hooks = $this->hooks($data['hooks'] ?? []);
        $settings = $data['settings'] ?? ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
        if (!is_array($settings)) {
            $this->errors[] = '"settings" must be an object';
            $settings = [];
        } else {
            $this->settingsSchema($settings, 'settings');
        }

        if ($this->errors !== [] || $version === null) {
            throw new ManifestException('invalid_manifest', "Invalid module.json:\n- " . implode("\n- ", $this->errors));
        }
        /** @var array<string, mixed> $settings */
        return new Manifest(
            $id,
            $name,
            $version,
            $publisher,
            $license,
            $description,
            $core,
            $php,
            $host,
            $modules,
            $features,
            $types,
            $capabilities,
            $permissions,
            $entry,
            $psr4,
            $hooks,
            $settings,
            $data,
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private function unknownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->errors[] = sprintf('unknown property "%s%s"', $path, (string) $key);
            }
        }
    }

    /** @param array<mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!array_key_exists($key, $data)) {
            return '';
        }
        if (!is_string($data[$key]) || trim($data[$key]) === '') {
            $this->errors[] = "\"{$key}\" must be a non-empty string";
            return '';
        }
        return $data[$key];
    }

    /**
     * @return array{0: ?Constraint, 1: ?Constraint, 2: array<string, Constraint>, 3: array<string, string>}
     */
    private function requires(mixed $requires): array
    {
        $core = $php = null;
        $host = [];
        $modules = [];
        if (!is_array($requires) || ($requires !== [] && array_is_list($requires))) {
            $this->errors[] = '"requires" must be an object';
            return [$core, $php, $host, $modules];
        }
        foreach ($requires as $key => $value) {
            $key = (string) $key;
            if ($key === 'modules') {
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    $this->errors[] = '"requires.modules" must be an object';
                    continue;
                }
                foreach ($value as $mid => $c) {
                    $mid = (string) $mid;
                    if (preg_match(self::ID_PATTERN, $mid) !== 1) {
                        $this->errors[] = "requires.modules: bad module id \"{$mid}\"";
                    } elseif (!is_string($c) || !Constraint::isValid($c)) {
                        $this->errors[] = "requires.modules.{$mid}: bad constraint";
                    } else {
                        $modules[$mid] = $c;
                    }
                }
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $key) !== 1) {
                $this->errors[] = "requires: bad key \"{$key}\"";
                continue;
            }
            if (!is_string($value) || !Constraint::isValid($value)) {
                $this->errors[] = "requires.{$key}: bad constraint";
                continue;
            }
            $c = Constraint::parse($value);
            match ($key) {
                'core' => $core = $c,
                'php' => $php = $c,
                default => $host[$key] = $c,
            };
        }
        return [$core, $php, $host, $modules];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} */
    private function provides(mixed $provides): array
    {
        $out = ['features' => [], 'types' => [], 'capabilities' => []];
        if (!is_array($provides) || ($provides !== [] && array_is_list($provides))) {
            $this->errors[] = '"provides" must be an object';
            return [[], [], []];
        }
        $this->unknownKeys($provides, array_keys($out), 'provides.');
        foreach (array_keys($out) as $key) {
            $list = $provides[$key] ?? [];
            if (!is_array($list) || !array_is_list($list)) {
                $this->errors[] = "\"provides.{$key}\" must be an array";
                continue;
            }
            foreach ($list as $item) {
                $pattern = $key === 'capabilities' ? '/^moduly_[a-z0-9_]{2,60}$/' : '/^[a-z][a-z0-9_-]{1,40}$/';
                if (!is_string($item) || preg_match($pattern, $item) !== 1) {
                    $this->errors[] = "provides.{$key}: bad item";
                    continue;
                }
                $out[$key][] = $item;
            }
        }
        return [$out['features'], $out['types'], $out['capabilities']];
    }

    /** @return list<string> */
    private function permissions(mixed $permissions): array
    {
        if (!is_array($permissions) || !array_is_list($permissions)) {
            $this->errors[] = '"permissions" must be an array';
            return [];
        }
        $out = [];
        foreach ($permissions as $p) {
            if (!is_string($p) || !Permission::isValid($p)) {
                $this->errors[] = 'permissions: unknown permission ' . (is_string($p) ? "\"{$p}\"" : '');
                continue;
            }
            $out[] = $p;
        }
        if (in_array('http:none', $out, true) && preg_grep('/^http:outbound:/', $out) !== []) {
            $this->errors[] = 'permissions: "http:none" conflicts with http:outbound:*';
        }
        return array_values(array_unique($out));
    }

    /** @return array<string, string> */
    private function autoload(mixed $autoload): array
    {
        if (!is_array($autoload) || !isset($autoload['psr-4']) || !is_array($autoload['psr-4']) || count($autoload) !== 1) {
            $this->errors[] = '"autoload" must be {"psr-4": {...}}';
            return [];
        }
        $out = [];
        foreach ($autoload['psr-4'] as $ns => $dir) {
            $ns = (string) $ns;
            if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)+$/', $ns) !== 1) {
                $this->errors[] = "autoload: bad namespace prefix \"{$ns}\"";
                continue;
            }
            foreach (self::RESERVED_NAMESPACES as $reserved) {
                if (str_starts_with($ns, $reserved)) {
                    $this->errors[] = "autoload: namespace \"{$ns}\" is reserved";
                    continue 2;
                }
            }
            if (!is_string($dir) || preg_match('#^(?!/)(?!.*\.\.)[A-Za-z0-9_\-/]+/$#', $dir) !== 1) {
                $this->errors[] = "autoload: dir for \"{$ns}\" must be a relative path ending with /";
                continue;
            }
            $out[$ns] = $dir;
        }
        if ($out === []) {
            $this->errors[] = 'autoload: at least one psr-4 mapping is required';
        }
        return $out;
    }

    /** @param array<string, string> $psr4 */
    private function entry(string $entry, array $psr4): void
    {
        if (preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $entry) !== 1) {
            $this->errors[] = '"entry" must be a fully qualified class name';
            return;
        }
        foreach (array_keys($psr4) as $ns) {
            if (str_starts_with($entry, $ns)) {
                return;
            }
        }
        $this->errors[] = '"entry" must live in a namespace declared in autoload.psr-4';
    }

    /** @return list<array{type: string, name: string, method: string, priority: int, args: int}> */
    private function hooks(mixed $hooks): array
    {
        if (!is_array($hooks) || !array_is_list($hooks)) {
            $this->errors[] = '"hooks" must be an array';
            return [];
        }
        $out = [];
        foreach ($hooks as $i => $hook) {
            if (!is_array($hook)) {
                $this->errors[] = "hooks[{$i}] must be an object";
                continue;
            }
            $this->unknownKeys($hook, ['type', 'name', 'method', 'priority', 'args'], "hooks[{$i}].");
            $type = $hook['type'] ?? null;
            $name = $hook['name'] ?? null;
            $method = $hook['method'] ?? null;
            $priority = $hook['priority'] ?? 10;
            $args = $hook['args'] ?? 1;
            if (
                !is_string($type) || !in_array($type, self::HOOK_TYPES, true)
                || !is_string($name) || preg_match('/^[a-z0-9_\-\/.]{1,100}$/', $name) !== 1
                || !is_string($method) || preg_match('/^[a-z][A-Za-z0-9_]*$/', $method) !== 1
                || !is_int($priority) || !is_int($args) || $args < 0 || $args > 10
            ) {
                $this->errors[] = "hooks[{$i}] is invalid";
                continue;
            }
            $out[] = ['type' => $type, 'name' => $name, 'method' => $method, 'priority' => $priority, 'args' => $args];
        }
        return $out;
    }

    /** @param array<mixed> $schema */
    private function settingsSchema(array $schema, string $path): void
    {
        $this->unknownKeys($schema, self::SETTINGS_KEYS, $path . '.');
        $type = $schema['type'] ?? null;
        if (!in_array($type, ['object', 'string', 'integer', 'number', 'boolean', 'array'], true)) {
            $this->errors[] = "{$path}.type is missing or unsupported";
        }
        if ($type === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false) {
                $this->errors[] = "{$path}.additionalProperties must be false";
            }
            $props = $schema['properties'] ?? [];
            if (!is_array($props)) {
                $this->errors[] = "{$path}.properties must be an object";
                return;
            }
            foreach ($props as $key => $sub) {
                if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) $key) !== 1 || !is_array($sub)) {
                    $this->errors[] = "{$path}.properties.{$key} is invalid";
                    continue;
                }
                $this->settingsSchema($sub, "{$path}.properties.{$key}");
            }
        }
        if ($type === 'array' && isset($schema['items'])) {
            if (!is_array($schema['items'])) {
                $this->errors[] = "{$path}.items must be a schema";
            } else {
                $this->settingsSchema($schema['items'], "{$path}.items");
            }
        }
    }
}
