<?php
// Inert fixed-root capture loader; runs once as root before candidate execution.
declare(strict_types=1);
$input = stream_get_contents(STDIN, 24 * 1024 * 1024 + 1);
if (strlen($input) > 24 * 1024 * 1024) { throw new RuntimeException('Capture exceeds budget.'); }
if (!isset($expectedPayloadSha256) || !is_string($expectedPayloadSha256)
    || !preg_match('/^[0-9a-f]{64}$/D', $expectedPayloadSha256)
    || !hash_equals($expectedPayloadSha256, hash('sha256', $input))) {
    throw new RuntimeException('Capture differs from independently retained input.');
}
$bundle = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
$roots = ['app', 'config', 'database', 'public', 'resources', 'routes'];
$package = '/app/waterline';
$served = '/app/public/vendor/waterline';
foreach ([$package, $served] as $root) {
    $metadata = lstat($root);
    if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000
        || $metadata['uid'] !== 0 || $metadata['gid'] !== 82 || scandir($root) !== ['.', '..']) {
        throw new RuntimeException('Expected fresh root-owned capture mount.');
    }
}
if ($bundle['classification'] !== 'source-overlay-candidate'
    || !preg_match('/^[0-9a-f]{40}$/D', $bundle['commit'])
    || !preg_match('/^[0-9a-f]{40}$/D', $bundle['tree'])
    || !is_array($bundle['leaves']) || count($bundle['leaves']) > 1000) {
    throw new RuntimeException('Unexpected capture envelope.');
}
$validated = []; $names = []; $bytes = 0; $observedRoots = [];
foreach ($bundle['leaves'] as $leaf) {
    $name = $leaf['path'];
    if (!is_string($name) || strlen($name) > 512
        || !preg_match('~^(app|config|database|public|resources|routes)/(?:[A-Za-z0-9_.@-]+/)*[A-Za-z0-9_.@-]+$~D', $name)
        || array_intersect(['.', '..'], explode('/', $name)) !== [] || isset($names[$name])
        || !in_array($leaf['git_mode'], ['100644', '100755'], true)
        || !preg_match('/^[0-9a-f]{40}$/D', $leaf['git_blob'])
        || !preg_match('/^[0-9a-f]{64}$/D', $leaf['sha256'])) {
        throw new RuntimeException('Unexpected capture member.');
    }
    $data = base64_decode($leaf['base64'], true);
    if ($data === false || !is_int($leaf['size']) || strlen($data) !== $leaf['size']
        || hash('sha256', $data) !== $leaf['sha256']) {
        throw new RuntimeException('Capture member bytes differ.');
    }
    $bytes += strlen($data);
    if ($bytes > 16 * 1024 * 1024) { throw new RuntimeException('Capture byte budget exceeded.'); }
    $names[$name] = true; $observedRoots[explode('/', $name)[0]] = true;
    $validated[] = [$name, $data, $leaf['sha256']];
}
if ($bytes !== $bundle['bytes'] || array_diff($roots, array_keys($observedRoots)) !== []) {
    throw new RuntimeException('Incomplete capture.');
}
$directories = [$package => true, $served => true]; $receipt = [];
foreach ($validated as [$name, $data, $sha256]) {
    $targets = [$package.'/'.$name];
    if (str_starts_with($name, 'public/')) { $targets[] = $served.'/'.substr($name, 7); }
    foreach ($targets as $target) {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0750, true)) { throw new RuntimeException('Capture directory failed.'); }
        while ($parent !== $package && $parent !== $served) {
            $directories[$parent] = true; $parent = dirname($parent);
        }
        $file = fopen($target, 'x');
        if ($file === false || fwrite($file, $data) !== strlen($data)) { throw new RuntimeException('Capture write failed.'); }
        fclose($file);
        if (!chmod($target, 0440) || hash_file('sha256', $target) !== $sha256) { throw new RuntimeException('Capture readback failed.'); }
    }
    $receipt[$name] = $sha256;
}
$paths = array_keys($directories); usort($paths, static fn ($a, $b) => strlen($b) <=> strlen($a));
foreach ($paths as $directory) {
    if (!chmod($directory, 0550)) { throw new RuntimeException('Capture directory sealing failed.'); }
}
echo json_encode(['classification' => 'source-overlay-candidate', 'source' => $bundle['commit'],
    'tree' => $bundle['tree'], 'leaves' => $receipt, 'bytes' => $bytes], JSON_THROW_ON_ERROR), PHP_EOL;
