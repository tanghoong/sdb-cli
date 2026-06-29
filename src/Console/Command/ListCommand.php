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
        // listIds() is a cheap directory scan (file) or `SELECT id` (sqlite) —
        // no documents are read, so this stays fast on large collections.
        $collection = (string) $input->getArgument('collection');
        $ids        = array_map(strval(...), $this->openAdapter($input)->listIds($collection));

        $this->writeList($input, $output, $ids);

        return SdbApplication::EXIT_SUCCESS;
    }
}
