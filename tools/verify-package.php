<?php
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/distribution-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if ($composer['name'] !== 'robcio/smk-starter-kit' || isset($composer['version']) || !is_file($root.'/starter-kit.yaml')) {
    throw new RuntimeException('Invalid repository package identity/version/config.');
}
$actual = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/export', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isLink()) throw new RuntimeException('Symlinks are forbidden.');
    if ($file->isFile()) {
        $relative = substr($file->getPathname(), strlen($root.'/export/'));
        if (preg_match('~(^|/)(\.env|users|vendor|node_modules|storage|build)(/|$)~', $relative)) throw new RuntimeException('Forbidden exported path.');
        $actual[$relative] = hash_file('sha256', $file->getPathname());
    }
}
ksort($actual);
$expected = $manifest['payload'];
ksort($expected);
if ($actual !== $expected) throw new RuntimeException('Exported payload differs from manifest.');
echo 'PASS: robcio/smk-starter-kit, '.count($actual)." exported files; versions are Git-tag derived.\n";
