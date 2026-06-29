<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'get', description: 'Read one document by ID')]
final class GetCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->addArgument('id', InputArgument::REQUIRED, 'Document ID')
            ->addOutputOptions()
            ->setHelp("Print a single document, or exit 1 if it does not exist.\n\n"
                . "  <info>sdb get users alice</info>");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->openDb($input);
        $id = (string) $input->getArgument('id');

        $doc = $db->get($id);

        if ($doc === null) {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stderr->writeln(sprintf(
                "<comment>sdb: document '%s' not found in collection '%s'</comment>",
                $id,
                $db->getCollection(),
            ));
            return SdbApplication::EXIT_NOT_FOUND;
        }

        $this->writeValue($input, $output, $doc);

        return SdbApplication::EXIT_SUCCESS;
    }
}
