<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Sdb\Exception\UsageException;
use Sdb\Support\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import', description: 'Load NDJSON from stdin (or a file) into a collection')]
final class ImportCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Read NDJSON from this file instead of stdin')
            ->setHelp(
                "Read one JSON object per line and upsert each document.\n"
                . "A line's <info>_id</info> field (if present) becomes its storage ID; otherwise an ID is generated.\n"
                . "Prints the number of documents imported.\n\n"
                . "  <info>sdb import users < users.ndjson</info>\n"
                . "  <info>sdb export users | sdb import users-copy</info>"
            );
    }

    /** Flush documents in batches of this size — one transaction per chunk on sqlite. */
    private const BATCH_SIZE = 1000;

    /**
     * Hard cap on a single NDJSON line. A plain `fgets($stream)` reads until the
     * next newline with no upper bound, so one malformed multi-gigabyte "line"
     * (no newline) would be pulled entirely into memory. We accumulate in chunks
     * and abort once a line crosses this limit, keeping import memory bounded.
     * Set comfortably above the engine's 5 MiB per-document limit to leave room
     * for the `_id` field and JSON whitespace.
     */
    private const MAX_LINE_BYTES = 16 * 1024 * 1024;

    /** Chunk size for the bounded line reader. */
    private const READ_CHUNK_BYTES = 1024 * 1024;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db     = $this->openDb($input);
        $from   = $input->getOption('from');
        $stream = $this->openInputStream($from === null ? null : (string) $from);

        $count  = 0;
        $lineNo = 0;

        /** @var array<string, array> $putBuffer  documents with an explicit _id */
        $putBuffer = [];
        /** @var list<array> $postBuffer  documents needing a generated id */
        $postBuffer = [];

        $flush = static function () use ($db, &$putBuffer, &$postBuffer): void {
            if ($putBuffer !== []) {
                $db->batchPut($putBuffer);   // single transaction on sqlite
                $putBuffer = [];
            }
            if ($postBuffer !== []) {
                $db->batchPost($postBuffer);
                $postBuffer = [];
            }
        };

        try {
            while (($line = $this->readLine($stream, $lineNo + 1)) !== false) {
                $lineNo++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                [$id, $doc] = $this->parseLine($line, $lineNo);

                if ($id !== null) {
                    $putBuffer[$id] = $doc;
                } else {
                    $postBuffer[] = $doc;
                }

                $count++;

                if (count($putBuffer) + count($postBuffer) >= self::BATCH_SIZE) {
                    $flush();
                }
            }

            $flush();
        } finally {
            if ($from !== null) {
                fclose($stream);
            }
        }

        $output->writeln((string) $count);

        return SdbApplication::EXIT_SUCCESS;
    }

    /**
     * Read one NDJSON line with a hard length cap.
     *
     * Accumulates the line in fixed-size chunks and throws once it crosses
     * MAX_LINE_BYTES, so a pathological newline-free input cannot exhaust memory.
     * Returns the full line (including any trailing newline), or false at EOF.
     *
     * @param  resource $stream
     * @param  int      $lineNo  prospective 1-based line number, for the error message
     */
    private function readLine($stream, int $lineNo): string|false
    {
        $line = '';

        while (true) {
            $chunk = fgets($stream, self::READ_CHUNK_BYTES + 1);

            if ($chunk === false) {
                // EOF: return a final newline-less line if we read one, else signal done.
                return $line === '' ? false : $line;
            }

            $line .= $chunk;

            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw new UsageException(
                    "Line {$lineNo}: exceeds the maximum line length of "
                    . self::MAX_LINE_BYTES . ' bytes.'
                );
            }

            if (str_ends_with($chunk, "\n")) {
                return $line;
            }
            // No newline yet and under the cap — keep reading this same line.
        }
    }

    /**
     * Decode one NDJSON line into [explicitId|null, document].
     *
     * @return array{0: string|null, 1: array}
     */
    private function parseLine(string $line, int $lineNo): array
    {
        try {
            $doc = Json::decodeDocument($line);
        } catch (UsageException $e) {
            throw new UsageException("Line {$lineNo}: " . $e->getMessage());
        }

        if (!array_key_exists('_id', $doc)) {
            return [null, $doc];
        }

        $id = (string) $doc['_id'];
        unset($doc['_id']);

        if ($id === '') {
            throw new UsageException("Line {$lineNo}: '_id' must be a non-empty string.");
        }

        return [$id, $doc];
    }

    /**
     * @return resource
     */
    private function openInputStream(?string $file)
    {
        if ($file === null) {
            return \defined('STDIN') ? STDIN : fopen('php://stdin', 'rb');
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new UsageException("Cannot open file for import: {$file}");
        }

        return $handle;
    }
}
