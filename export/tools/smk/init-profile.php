<?php

// Standalone developer tool: does not bootstrap Laravel, Statamic CP or .env.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Support\Smk\Profile\ManifestValidator;
use App\Support\Smk\Profile\ProfileFilesystem;
use App\Support\Smk\Profile\ProfileInitializer;

try {
    $options = getopt('', ['root:', 'profile:', 'manifest:', 'apply:', 'accept:', 'recover', 'validate', 'offline-exclusive']);
    if (! isset($options['root'])) {
        throw new RuntimeException('usage: --root=PATH [--profile=REL --manifest=REL ... | --apply=PLAN --accept=SHA256 | --recover] [--offline-exclusive]');
    }
    $tool = new ProfileInitializer($options['root']);
    if (count(array_intersect(['apply', 'recover', 'validate'], array_keys($options))) > 1) {
        throw new RuntimeException('select only one of apply, recover or validate');
    }
    if (isset($options['validate'])) {
        $result = (new ManifestValidator(new ProfileFilesystem($options['root'])))->validate($options['profile'] ?? 'resources/smk/profiles/core.yaml', (array) ($options['manifest'] ?? 'resources/smk/core/manifest.yaml'), ManifestValidator::platform());
        echo ProfileFilesystem::json(['result' => 'valid', 'manifests' => array_keys($result['manifests'])]);
    } elseif (isset($options['recover'])) {
        $result = $tool->recover(isset($options['offline-exclusive']));
        echo ProfileFilesystem::json(['result' => $result]);
    } elseif (isset($options['apply'])) {
        $document = json_decode(file_get_contents($options['apply']), true, flags: JSON_THROW_ON_ERROR);
        $plan = $document['plan'] ?? $document;
        echo ProfileFilesystem::json(['result' => $tool->apply($plan, $options['accept'] ?? '', isset($options['offline-exclusive']))]);
    } else {
        $plan = $tool->plan($options['profile'] ?? 'resources/smk/profiles/core.yaml', (array) ($options['manifest'] ?? 'resources/smk/core/manifest.yaml'));
        echo ProfileFilesystem::json(['approval_sha256' => ProfileInitializer::digest($plan), 'plan' => $plan]);
    }
} catch (Throwable $e) {
    // Manifest bytes and stack traces are never executed or printed.
    fwrite(STDERR, 'SMK init refused: '.$e->getMessage()."\n");
    exit(1);
}
