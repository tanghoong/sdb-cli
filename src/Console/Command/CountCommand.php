<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Sdb\Support\WhereParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'count', description: 'Count documents, optionally matching filters')]
final class CountCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->addOption('where', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter: field=value or field:op:value (repeatable)')
            ->setHelp("Print the number of documents (optionally filtered) as a plain integer.\n\n"
                . "  <info>sdb count orders</info>\n"
                . "  <info>sdb count orders --where status=pending</info>");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->openDb($input);

        /** @var string[] $where */
        $where = $input->getOption('where');

        if ($where === []) {
            $count = $db->count();
        } else {
            $qb = $db->newQuery();
            WhereParser::applyWhere($qb, $where);
            $count = $qb->count();
        }

        $output->writeln((string) $count);

        return SdbApplication::EXIT_SUCCESS;
    }
}
