<?php

/**
 * Generates the module layers + rules of deptrac.yaml from every modules/<id>/module.json (`requires.modules`):
 *   <Module>Domain → Core and the Domains of the modules it requires;
 *   <Module> (WordPress adapter) → Core, Support, WordPress, its own Domain, the required modules (adapter + Domain).
 * Cross-module use (Domain or adapter) is a Deptrac violation unless the manifest declares the dependency.
 * Usage: php tools/deptrac-modules.php [--check]   (--check: exit 1 when deptrac.yaml is not up to date)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root.'/deptrac.yaml';
/** Host services a module adapter may use besides the defaults (container `wp.*` services). */
$extra = ['types' => ['HostServices']];

$name = static fn (string $id): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
$modules = [];
foreach (glob($root.'/modules/*/module.json') ?: [] as $json) {
    $m = json_decode((string) file_get_contents($json), true);
    if (!is_array($m) || !is_string($m['id'] ?? null)) {
        fwrite(STDERR, "invalid manifest $json\n");
        exit(2);
    }
    $requires = is_array($m['requires']['modules'] ?? null) ? array_keys($m['requires']['modules']) : [];
    sort($requires);
    $modules[$m['id']] = $requires;
}
ksort($modules);

$layers = '';
$rules = '';
foreach ($modules as $id => $requires) {
    $n = $name($id);
    $layers .= "    - name: {$n}Domain\n"
        ."      collectors:\n"
        ."        - { type: classLike, value: '^PhpFasty\\\\Module\\\\{$n}\\\\Domain\\\\.*' }\n"
        ."    - name: {$n}\n"
        ."      collectors:\n"
        ."        - type: bool\n"
        ."          must:\n"
        ."            - { type: classLike, value: '^PhpFasty\\\\Module\\\\{$n}\\\\.*' }\n"
        ."          must_not:\n"
        ."            - { type: classLike, value: '^PhpFasty\\\\Module\\\\{$n}\\\\Domain\\\\.*' }\n";
    $req = array_map($name, $requires);
    $domain = array_merge(['Core'], array_map(static fn (string $r): string => $r.'Domain', $req));
    $adapter = array_merge(['Core', 'Support', 'WordPress'], $extra[$id] ?? [], [$n.'Domain']);
    foreach ($req as $r) {
        $adapter[] = $r;
        $adapter[] = $r.'Domain';
    }
    $rules .= "    {$n}Domain: [".implode(', ', $domain)."]\n";
    $rules .= "    {$n}: [".implode(', ', $adapter)."]\n";
}

$yaml = (string) file_get_contents($file);
$replace = static function (string $yaml, string $tag, string $body) use ($file): string {
    $begin = "    # BEGIN generated: $tag (tools/deptrac-modules.php)\n";
    $end = "    # END generated: $tag\n";
    $a = strpos($yaml, $begin);
    $b = strpos($yaml, $end);
    if (false === $a || false === $b) {
        fwrite(STDERR, "markers for $tag missing in $file\n");
        exit(2);
    }

    return substr($yaml, 0, $a + strlen($begin)).$body.substr($yaml, $b);
};
$new = $replace($replace($yaml, 'module layers', $layers), 'module rules', $rules);

if (in_array('--check', $argv, true)) {
    if ($new !== $yaml) {
        fwrite(STDERR, "deptrac.yaml is out of date with modules/*/module.json: run php tools/deptrac-modules.php\n");
        exit(1);
    }
    echo 'deptrac.yaml module layers match '.count($modules)." manifests\n";
    exit(0);
}
file_put_contents($file, $new);
echo 'deptrac.yaml: '.count($modules)." modules\n";
