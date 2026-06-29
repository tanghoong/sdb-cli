<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'list', description: 'List all document IDs in a collection')]
final class ListCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->setAliases(['ls'])
            ->addCollectionArgument()
            ->addOutputOptions()
            ->setHelp("Print every document ID in the collection (a JSON array of strings).\n\n"
                . "  <info>sdb list users</info>\n"
                . "  <info>sdb list users --ndjson</info>");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db  = $this->openDb($input);
        $ids = [];

        foreach ($db->stream() as $id => $_doc) {
            $ids[] = (string) $id;
        }

        $this->writeList($input, $output, $ids);

        return SdbApplication::EXIT_SUCCESS;
    }
}
