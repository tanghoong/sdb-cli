<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Sdb\Support\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'export', description: 'Stream a collection to stdout as NDJSON')]
final class ExportCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->setHelp(
                "Emit one JSON object per line, each carrying its storage ID in an <info>_id</info> field.\n"
                . "Round-trips with <info>sdb import</info>.\n\n"
                . "  <info>sdb export users > users.ndjson</info>\n"
                . "  <info>sdb export users | jq 'select(.age > 30)'</info>"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->openDb($input);

        foreach ($db->stream() as $id => $doc) {
            $output->writeln(Json::compact(['_id' => (string) $id] + $doc));
        }

        return SdbApplication::EXIT_SUCCESS;
    }
}
