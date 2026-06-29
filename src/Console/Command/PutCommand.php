<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Sdb\Exception\UsageException;
use Sdb\Support\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'put', description: 'Create or overwrite a document')]
final class PutCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->addArgument('id', InputArgument::REQUIRED, 'Document ID')
            ->addArgument('json', InputArgument::REQUIRED, 'Document body as a JSON object')
            ->setHelp("Store a document under an explicit ID, replacing any existing one.\n\n"
                . "  <info>sdb put users alice '{\"name\":\"Alice\",\"age\":30}'</info>");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db   = $this->openDb($input);
        $id   = (string) $input->getArgument('id');
        $data = Json::decodeDocument((string) $input->getArgument('json'));

        // '_id' is reserved: it denotes the storage id in find/export output.
        // Forbidding it here guarantees export -> import round-trips losslessly.
        if (array_key_exists('_id', $data)) {
            throw new UsageException(
                "Documents may not contain a reserved '_id' field — it denotes the storage id. "
                . 'Pass the id as the <id> argument instead.'
            );
        }

        $db->put($id, $data);

        $output->writeln($id);

        return SdbApplication::EXIT_SUCCESS;
    }
}
