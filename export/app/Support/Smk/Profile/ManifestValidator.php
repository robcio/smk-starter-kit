<?php

namespace App\Support\Smk\Profile;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class ManifestValidator
{
    public const OUTPUTS = [
        'resources/fieldsets/smk_page_builder.yaml',
        'resources/smk/generated/profile.json',
        'resources/smk/generated/render-map.json',
        'resources/css/generated/modules.css',
        'resources/js/generated/modules.js',
    ];

    public function __construct(private ProfileFilesystem $fs) {}

    public function load(string $path): array
    {
        $bytes = $this->fs->read($path);
        try {
            $data = Yaml::parse($bytes, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (\Throwable $e) {
            throw new RuntimeException($path.': invalid YAML/data', previous: $e);
        }
        $this->check(is_array($data) && ! array_is_list($data), $path, 'mapping required');

        return $data;
    }

    private function check(bool $ok, string $field, string $expected): void
    {
        $this->fs->need($ok, $field.': expected '.$expected);
    }

    private function keys(array $data, array $required, array $optional, string $at): void
    {
        $this->check(! array_diff($required, array_keys($data)) && ! array_diff(array_keys($data), array_merge($required, $optional)), $at, 'exact schema keys '.implode(', ', array_merge($required, $optional)));
    }

    private function list(mixed $value, string $at): array
    {
        $this->check(is_array($value) && array_is_list($value), $at, 'list');

        return $value;
    }

    private function map(mixed $value, string $at): array
    {
        $this->check(is_array($value) && ($value === [] || ! array_is_list($value)), $at, 'mapping');

        return $value;
    }

    private function string(mixed $value, string $at): string
    {
        $this->check(is_string($value) && $value !== '', $at, 'nonempty string');

        return $value;
    }

    private function version(mixed $v, string $at): void
    {
        $this->check(is_string($v) && preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D', $v) === 1, $at, 'SemVer');
    }

    public static function platform(): array
    {
        $pipes = [];
        $process = proc_open(['node', '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('platform.node: cannot inspect actual Node runtime');
        }
        fclose($pipes[0]);
        $node = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || ! preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $node)) {
            throw new RuntimeException('platform.node: actual node --version required');
        }

        return ['php' => PHP_VERSION, 'statamic' => InstalledVersions::getPrettyVersion('statamic/cms'), 'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'), 'node' => substr($node, 1)];
    }

    public function validate(string $profilePath, array $manifestPaths, array $platform, string $edition = 'core'): array
    {
        $profile = $this->load($profilePath);
        $this->keys($profile, ['profile_schema', 'id', 'core_contract_version', 'modules'], [], 'profile');
        $this->check($profile['profile_schema'] === 1 && $profile['core_contract_version'] === 1 && $profile['id'] === 'core', 'profile', 'schema=1, contract=1, id=core');
        $selected = [];
        foreach ($this->list($profile['modules'], 'profile.modules') as $selection) {
            $this->check(is_array($selection), 'profile.modules', 'id/version mapping');
            $this->keys($selection, ['id', 'version'], [], 'profile.modules');
            $id = $this->string($selection['id'], 'profile.modules.id');
            $this->version($selection['version'], $id.'.version');
            $this->check(! isset($selected[$id]), $id, 'one selection');
            $selected[$id] = $selection['version'];
        }
        $manifests = [];
        foreach ($manifestPaths as $path) {
            $m = $this->load($path);
            $this->keys($m, ['manifest_schema', 'core_contract_version', 'id', 'version', 'requires', 'provides', 'views', 'frontend', 'contributions', 'conflicts', 'owned_paths', 'licenses'], ['generated_outputs'], $path);
            $id = $this->string($m['id'], $path.'.id');
            $this->check(preg_match('/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D', $id) === 1 && ! isset($manifests[$id]), $id, 'unique vendor.module id');
            $this->check($m['manifest_schema'] === 1 && $m['core_contract_version'] === 1, $id.'.schema', 'manifest=1, contract=1');
            $this->version($m['version'], $id.'.version');
            $manifests[$id] = $m;
        }
        ksort($manifests);
        $this->check(isset($manifests['smk.core']), 'manifest', 'smk.core always present');
        $this->check(! array_diff(array_keys($selected), array_keys($manifests)), 'profile.modules', 'all selected manifests present');
        $this->check(count($manifests) === count($selected) + 1, 'manifest', 'only Core and explicitly selected modules');
        $owned = [];
        $identities = [];
        $handles = [];
        $routes = [];
        foreach ($manifests as $id => $m) {
            if ($id !== 'smk.core') {
                $this->check($m['version'] === $selected[$id], $id.'.version', 'exact selected version');
                $this->check(! isset($m['generated_outputs']), $id, 'generator outputs reserved to Core');
            } else {
                $this->check(($m['generated_outputs'] ?? null) === self::OUTPUTS, $id.'.generated_outputs', 'fixed generator allowlist in declared order');
            }
            $r = $this->map($m['requires'], $id.'.requires');
            $this->keys($r, ['core', 'modules', 'edition', 'platform', 'asset_containers'], [], $id.'.requires');
            $this->check(in_array($r['edition'], ['core', 'pro'], true) && ($edition === 'pro' || $r['edition'] === 'core'), $id.'.requires.edition', 'compatible edition');
            $this->satisfies($manifests['smk.core']['version'], $r['core'], $id.'.requires.core');
            foreach ($this->map($r['modules'], $id.'.requires.modules') as $dep => $range) {
                $this->check(isset($manifests[$dep]), $id.'.requires.modules.'.$dep, 'selected dependency');
                $this->satisfies($manifests[$dep]['version'], $range, $id.'.requires.modules.'.$dep);
                $this->check($r['edition'] !== 'core' || $manifests[$dep]['requires']['edition'] === 'core', $id, 'Core-only dependencies');
            }
            $p = $this->map($r['platform'], $id.'.requires.platform');
            $this->keys($p, ['php', 'statamic', 'laravel', 'node'], [], $id.'.requires.platform');
            foreach ($p as $runtime => $range) {
                $this->satisfies($platform[$runtime], $range, $id.'.requires.platform.'.$runtime);
            }
            foreach ($this->list($m['conflicts'], $id.'.conflicts') as $conflict) {
                $this->check(is_string($conflict) && ! isset($manifests[$conflict]), $id.'.conflicts', 'absent conflicting id');
            }
            $this->check($this->map($m['contributions'], $id.'.contributions') === [], $id.'.contributions', 'empty in Core MVP; unsupported contributions refused');
            foreach ($this->list($m['licenses'], $id.'.licenses') as $license) {
                $this->string($license, $id.'.licenses');
            }
            foreach ($this->list($m['owned_paths'], $id.'.owned_paths') as $path) {
                $path = $this->string($path, $id.'.owned_paths');
                ProfileFilesystem::relative($path);
                $this->check(! in_array(strtolower($path), array_map('strtolower', self::OUTPUTS), true), $id.'.owned_paths', 'source, not generated output');
                $key = strtolower($path);
                $this->check(! str_starts_with($key, 'smk-init-state/'), $id.'.owned_paths', 'auxiliary journal state is not a manifest source');
                foreach ($owned as $prior => $owner) {
                    $this->check($key !== $prior && ! str_starts_with($key, $prior.'/') && ! str_starts_with($prior, $key.'/'), $id.'.owned_paths.'.$path, 'disjoint ownership from '.$owner);
                }
                if ($id !== 'smk.core') {
                    foreach (['resources/smk/core/', 'resources/smk/profiles/', 'resources/smk/generated/', 'resources/views/smk/core/', 'resources/views/smk/brand/', 'resources/css/brand/', 'resources/css/smk/', 'resources/js/smk/'] as $reserved) {
                        $this->check(! str_starts_with($key, $reserved), $id.'.owned_paths', 'module namespace; Core/Brand reserved');
                    }
                    $this->check(! in_array($key, ['resources/fieldsets/smk_link.yaml', 'resources/fieldsets/smk_section.yaml', 'resources/fieldsets/smk_seo.yaml'], true), $id.'.owned_paths', 'Core fieldsets reserved');
                }
                $owned[$key] = $id;
                $stat = $this->fs->file($path);
                $this->check($stat !== null, $id.'.owned_paths.'.$path, 'existing explicit file; no directory/glob ownership');
                $identity = $stat['dev'].':'.$stat['ino'];
                $this->check(! isset($identities[$identity]), $id.'.owned_paths', 'unique filesystem identity');
                $identities[$identity] = $path;
            }
            $provides = $this->map($m['provides'], $id.'.provides');
            $this->keys($provides, ['blocks', 'collections', 'blueprints', 'fieldsets', 'asset_containers'], [], $id.'.provides');
            foreach (['collections', 'blueprints', 'fieldsets', 'asset_containers'] as $kind) {
                foreach ($this->list($provides[$kind], $id.'.provides.'.$kind) as $item) {
                    $this->check(is_array($item), $id.'.provides.'.$kind, 'handle/path declaration');
                    $this->keys($item, ['handle', 'path'], $kind === 'collections' ? ['route'] : [], $id.'.provides.'.$kind);
                    $h = $this->string($item['handle'], $id.'.handle');
                    $this->check(preg_match('/\A[a-z][a-z0-9_]*\z/D', $h) === 1 && ! isset($handles[$kind][$h]), $id.'.handle.'.$h, 'unique portable handle');
                    $handles[$kind][$h] = $id;
                    $this->owned($m, $item['path'], $id);
                    if (isset($item['route'])) {
                        $route = strtolower($this->string($item['route'], $id.'.route'));
                        $this->check(! isset($routes[$route]), $id.'.route', 'unique route');
                        $routes[$route] = $id;
                    }
                }
            }
            foreach ($this->map($provides['blocks'], $id.'.provides.blocks') as $handle => $block) {
                $this->check(preg_match('/\A[a-z][a-z0-9_]*\z/D', $handle) === 1 && ! isset($handles['blocks'][$handle]), $id.'.blocks.'.$handle, 'unique handle');
                $this->check(is_array($block), $id.'.blocks.'.$handle, 'mapping');
                $this->keys($block, ['fieldset', 'partial', 'variants', 'default'], [], $id.'.blocks.'.$handle);
                foreach (['fieldset', 'partial'] as $field) {
                    $this->owned($m, $block[$field], $id);
                }
                $this->check(in_array($block['partial'], $m['views'], true), $id.'.blocks.partial', 'declared view');
                $variants = $this->list($block['variants'], $id.'.blocks.variants');
                foreach ($variants as $variant) {
                    $this->check(is_string($variant) && preg_match('/\A[a-z][a-z0-9_]*\z/D', $variant) === 1, $id.'.blocks.variant', 'portable handle');
                }
                $this->check($variants !== [] && count($variants) === count(array_unique($variants)) && in_array($block['default'], $variants, true), $id.'.blocks.variants', 'unique variants with valid default');
                $this->check(str_starts_with($block['fieldset'], 'resources/fieldsets/') && str_ends_with($block['fieldset'], '.yaml') && str_starts_with($block['partial'], 'resources/views/') && str_ends_with($block['partial'], '.antlers.html'), $id.'.blocks', 'fieldset/view locations');
                $handles['blocks'][$handle] = $id;
            }
            foreach ($this->list($m['views'], $id.'.views') as $view) {
                $this->owned($m, $view, $id);
                $this->check(str_starts_with($view, 'resources/views/') && str_ends_with($view, '.antlers.html'), $id.'.views', 'local Antlers view');
            }
            $this->keys($this->map($m['frontend'], $id.'.frontend'), ['css', 'js'], [], $id.'.frontend');
            foreach (['css', 'js'] as $type) {
                foreach ($this->list($m['frontend'][$type], $id.'.frontend.'.$type) as $entry) {
                    $this->owned($m, $entry, $id);
                    $this->check(str_starts_with($entry, 'resources/'.$type.'/') && str_ends_with($entry, '.'.$type), $id.'.frontend', 'local '.$type.' source');
                }
            }
        }
        $visiting = [];
        $done = [];
        $visit = function ($id) use (&$visit, &$visiting, &$done, $manifests) {
            $this->check(! isset($visiting[$id]), $id.'.requires.modules', 'acyclic dependency graph');
            if (isset($done[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach (array_keys($manifests[$id]['requires']['modules']) as $dep) {
                $visit($dep);
            }
            unset($visiting[$id]);
            $done[$id] = true;
        };
        foreach ($manifests as $id => $m) {
            $visit($id);
            foreach ($this->list($m['requires']['asset_containers'], $id.'.requires.asset_containers') as $asset) {
                $owner = $handles['asset_containers'][$asset] ?? null;
                $this->check($owner !== null && ($owner === 'smk.core' || $owner === $id || isset($m['requires']['modules'][$owner])), $id.'.requires.asset_containers', 'Core or explicit dependency provider');
            }
        }

        return ['profile' => $profile, 'manifests' => $manifests];
    }

    private function owned(array $m, mixed $path, string $id): void
    {
        $this->check(is_string($path) && in_array($path, $m['owned_paths'], true), $id.'.references', 'explicit owned file');
    }

    private function satisfies(string $version, mixed $range, string $at): void
    {
        $range = $this->string($range, $at);
        try {
            $ok = Semver::satisfies($version, $range);
        } catch (\Throwable) {
            $ok = false;
        }
        $this->check($ok, $at, 'compatible SemVer range; actual '.$version);
    }
}
