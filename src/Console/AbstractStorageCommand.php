<?php

declare(strict_types=1);

namespace Sdb\Console;

use Sdb\Support\Json;
use Sdb\Support\Storage;
use SimpleDB\SimpleDB;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for every sdb command: wires the shared storage flags, opens the
 * collection, and centralises JSON output formatting.
 */
abstract class AbstractStorageCommand extends Command
{
    /** Add the <collection> argument plus the shared --adapter/--data flags. */
    protected function addCollectionArgument(): static
    {
        $this->addArgument('collection', InputArgument::REQUIRED, 'Collection name')
            ->addOption(
                'adapter',
                null,
                InputOption::VALUE_REQUIRED,
                'Storage adapter: file | sqlite | memory',
                'file'
            )
            ->addOption(
                'data',
                null,
                InputOption::VALUE_REQUIRED,
                'Storage directory (overrides $SDB_DATA_DIR and ~/.sdb)'
            );

        return $this;
    }

    /** Add the --raw / --ndjson output-shape flags. */
    protected function addOutputOptions(): static
    {
        $this->addOption('raw', null, InputOption::VALUE_NONE, 'Compact, single-line JSON')
            ->addOption('ndjson', null, InputOption::VALUE_NONE, 'Newline-delimited JSON (one object per line)');

        return $this;
    }

    protected function openDb(InputInterface $input): SimpleDB
    {
        /** @var string $collection */
        $collection = $input->getArgument('collection');
        /** @var string $adapter */
        $adapter = $input->getOption('adapter');
        /** @var string|null $data */
        $data = $input->getOption('data');

        return Storage::open($collection, $adapter, $data);
    }

    /** Render a single value as pretty (default) or compact (--raw) JSON. */
    protected function writeValue(InputInterface $input, OutputInterface $output, mixed $value): void
    {
        $json = $input->getOption('raw') ? Json::compact($value) : Json::pretty($value);
        $output->writeln($json);
    }

    /**
     * Render a list of items. --ndjson emits one compact object per line;
     * otherwise the whole list is rendered as one pretty/compact JSON array.
     *
     * @param iterable<mixed> $items
     */
    protected function writeList(InputInterface $input, OutputInterface $output, iterable $items): void
    {
        if ($input->getOption('ndjson')) {
            foreach ($items as $item) {
                $output->writeln(Json::compact($item));
            }
            return;
        }

        $this->writeValue($input, $output, is_array($items) ? $items : iterator_to_array($items, false));
    }
}
