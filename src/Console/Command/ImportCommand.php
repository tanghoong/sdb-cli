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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db     = $this->openDb($input);
        $from   = $input->getOption('from');
        $stream = $this->openInputStream($from === null ? null : (string) $from);

        $count    = 0;
        $lineNo   = 0;
        try {
            while (($line = fgets($stream)) !== false) {
                $lineNo++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $doc = Json::decodeDocument($line);
                } catch (UsageException $e) {
                    throw new UsageException("Line {$lineNo}: " . $e->getMessage());
                }

                if (array_key_exists('_id', $doc)) {
                    $id = (string) $doc['_id'];
                    unset($doc['_id']);

                    if ($id === '') {
                        throw new UsageException("Line {$lineNo}: '_id' must be a non-empty string.");
                    }

                    $db->put($id, $doc);
                } else {
                    $db->post($doc);
                }

                $count++;
            }
        } finally {
            if ($from !== null) {
                fclose($stream);
            }
        }

        $output->writeln((string) $count);

        return SdbApplication::EXIT_SUCCESS;
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
