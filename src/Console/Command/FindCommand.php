<?php

declare(strict_types=1);

namespace Sdb\Console\Command;

use Sdb\Console\AbstractStorageCommand;
use Sdb\Console\SdbApplication;
use Sdb\Exception\UsageException;
use Sdb\Support\WhereParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'find', description: 'Query documents with filters, ordering and pagination')]
final class FindCommand extends AbstractStorageCommand
{
    protected function configure(): void
    {
        $this->addCollectionArgument()
            ->addOption('where', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter: field=value or field:op:value (repeatable)')
            ->addOption('order', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sort: field or field:asc|desc (repeatable)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of results')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Number of results to skip')
            ->addOutputOptions()
            ->setHelp(
                "Return matching documents as a JSON array. Each result carries its storage ID in an <info>_id</info> field.\n\n"
                . "  <info>sdb find products --where price:lt:500 --order name:asc --limit 10</info>\n"
                . "  <info>sdb find users --where 'role:in:admin,moderator' --ndjson</info>\n\n"
                . "Operators: =  !=  >  >=  <  <=  in  not_in  contains  starts_with  ends_with  null  not_null\n"
                . "Shell-safe aliases (no quoting): eq ne lt lte gt gte"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->openDb($input);
        $qb = $db->newQuery();

        /** @var string[] $where */
        $where = $input->getOption('where');
        /** @var string[] $order */
        $order = $input->getOption('order');

        WhereParser::applyWhere($qb, $where);
        WhereParser::applyOrder($qb, $order);

        if (($limit = $input->getOption('limit')) !== null) {
            $qb->limit($this->intOption('limit', $limit));
        }
        if (($offset = $input->getOption('offset')) !== null) {
            $qb->offset($this->intOption('offset', $offset));
        }

        // Inject the storage id lazily so the --ndjson path can write each row as
        // it is produced instead of buffering a second full copy of the result set.
        $results = $qb->get();
        $withId  = (static function () use ($results): \Generator {
            foreach ($results as $id => $doc) {
                yield ['_id' => (string) $id] + $doc;
            }
        })();

        $this->writeList($input, $output, $withId);

        return SdbApplication::EXIT_SUCCESS;
    }

    private function intOption(string $name, mixed $value): int
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
            throw new UsageException("--{$name} must be an integer; got '{$value}'.");
        }

        $int = (int) $value;
        if ($int < 0) {
            throw new UsageException("--{$name} must be zero or positive; got '{$value}'.");
        }

        return $int;
    }
}
