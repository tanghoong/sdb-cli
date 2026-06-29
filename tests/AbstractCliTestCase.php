<?php

declare(strict_types=1);

namespace Sdb\Tests;

use PHPUnit\Framework\TestCase;
use Sdb\Console\SdbApplication;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Base for CLI tests. Each test gets an isolated temp data directory and a
 * helper that runs a command through the real SdbApplication (so exit-code
 * mapping in doRun() is exercised too).
 */
abstract class AbstractCliTestCase extends TestCase
{
    protected string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sdb-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dataDir);
    }

    /**
     * Run an sdb command. --data and --adapter are injected automatically.
     *
     * @param array<string, mixed> $input  e.g. ['command' => 'get', 'collection' => 'users', 'id' => 'a']
     * @return array{code: int, out: string, err: string}
     */
    protected function sdb(array $input, string $adapter = 'file'): array
    {
        $input['--data']    = $this->dataDir;
        $input['--adapter'] = $adapter;

        $app = new SdbApplication();
        $app->setAutoExit(false);

        $tester = new ApplicationTester($app);
        $tester->run($input, [
            'capture_stderr_separately' => true,
            'decorated'                 => false,
            'interactive'               => false,
        ]);

        return [
            'code' => $tester->getStatusCode(),
            'out'  => $this->normalizeEol($tester->getDisplay()),
            'err'  => $this->normalizeEol($tester->getErrorOutput()),
        ];
    }

    /** Decode a command's stdout JSON. */
    protected function decode(string $out): mixed
    {
        return json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Convenience: put a document and assert it succeeded. */
    protected function put(string $collection, string $id, array $doc): void
    {
        $r = $this->sdb([
            'command'    => 'put',
            'collection' => $collection,
            'id'         => $id,
            'json'       => json_encode($doc, JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(0, $r['code'], 'put should succeed: ' . $r['err']);
    }

    private function normalizeEol(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
