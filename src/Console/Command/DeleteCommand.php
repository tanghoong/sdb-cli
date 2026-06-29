<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use SimpleDB\Exceptions\DocumentNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'delete', description: 'Delete a document by ID')]
final class DeleteCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->setAliases(['del', 'rm'])
            ->addCollectionArgument()
            ->addArgument('id', InputArgument::REQUIRED, 'Document ID')
            ->setHelp("Delete a document, or exit 1 if it does not exist.\n\n"
                . "  <info>sdb delete users alice</info>");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->openDb($input);
        $id = (string) $input->getArgument('id');

        try {
            $db->delete($id);
        } catch (DocumentNotFoundException) {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stderr->writeln(sprintf(
                "<comment>sdb: document '%s' not found in collection '%s'</comment>",
                OutputFormatter::escape($id),
                OutputFormatter::escape($db->getCollection()),
            ));
            return SdbApplication::EXIT_NOT_FOUND;
        }

        $output->writeln($id);

        return SdbApplication::EXIT_SUCCESS;
    }
}
