<?php

declare(strict_types=1);

/**
 * Builds a self-contained sdb.phar from src/ + vendor/.
 *
 * Run via the Makefile (which strips dev dependencies first):
 *   make phar
 *
 * Or directly:
 *   php -d phar.readonly=0 build/build-phar.php
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is On. Re-run with: php -d phar.readonly=0 build/build-phar.php\n");
    exit(1);
}

$root     = str_replace('\\', '/', dirname(__DIR__));
$pharFile = $root . '/sdb.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, 'sdb.phar');
$phar->startBuffering();

$includeRoots = ['bin', 'src', 'lib', 'vendor'];

// Trim weight that is never needed at runtime.
$excludePatterns = [
    '#/vendor/[^/]+/[^/]+/(tests?|Tests?|docs?|examples?|\.github)/#i',
    '#/\.git/#',
    '#\.(md|dist|neon)$#i',
    '#/(phpunit\.xml|\.gitignore|\.gitattributes|\.editorconfig)$#',
];

$filter = static function (\SplFileInfo $current) use ($root, $includeRoots, $excludePatterns): bool {
    $path = str_replace('\\', '/', $current->getPathname());
    $rel  = ltrim(substr($path, strlen($root)), '/');
    $top  = explode('/', $rel)[0];

    // Only descend into / include the allowed top-level roots.
    if (!in_array($top, $includeRoots, true)) {
        return false;
    }

    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, '/' . $rel) === 1) {
            return false;
        }
    }

    return true;
};

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        $filter,
    ),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

// buildFromIterator is dramatically faster than per-file addFile().
$mapping = $phar->buildFromIterator($iterator, $root);
$added   = count($mapping);

// CLI stub: bootstrap the autoloader from inside the archive and run the app.
// (We do NOT require bin/sdb here — its shebang line would be echoed when included.)
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('sdb.phar');
require 'phar://sdb.phar/vendor/autoload.php';
exit((new Sdb\Console\SdbApplication())->run());
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

@chmod($pharFile, 0755);

printf("Built %s — %d files, %d KiB\n", $pharFile, $added, (int) round(filesize($pharFile) / 1024));
