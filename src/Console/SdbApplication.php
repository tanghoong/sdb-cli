<?php

declare(strict_types=1);

namespace Sdb\Console;

use Sdb\Console\Command\CountCommand;
use Sdb\Console\Command\DeleteCommand;
use Sdb\Console\Command\ExportCommand;
use Sdb\Console\Command\FindCommand;
use Sdb\Console\Command\GetCommand;
use Sdb\Console\Command\ImportCommand;
use Sdb\Console\Command\ListCommand;
use Sdb\Console\Command\PutCommand;
use Sdb\Exception\UsageException;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Exceptions\SimpleDBException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The sdb console application.
 *
 * Centralises exit-code policy so every command speaks the same dialect:
 *   0  success
 *   1  document / collection not found
 *   2  usage error (bad flags, malformed query, unknown command)
 *   3  storage error (I/O failure, missing extension, corrupt data)
 */
final class SdbApplication extends Application
{
    public const NAME    = 'sdb';
    public const VERSION = '0.1.0';

    public const EXIT_SUCCESS    = 0;
    public const EXIT_NOT_FOUND  = 1;
    public const EXIT_USAGE      = 2;
    public const EXIT_STORAGE    = 3;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new PutCommand(),
            new GetCommand(),
            new DeleteCommand(),
            new ListCommand(),
            new FindCommand(),
            new CountCommand(),
            new ExportCommand(),
            new ImportCommand(),
        ]);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (DocumentNotFoundException $e) {
            return $this->fail($output, $e->getMessage(), self::EXIT_NOT_FOUND);
        } catch (UsageException | ConsoleExceptionInterface $e) {
            return $this->fail($output, $e->getMessage(), self::EXIT_USAGE);
        } catch (SimpleDBException $e) {
            return $this->fail($output, $e->getMessage(), self::EXIT_STORAGE);
        } catch (\JsonException $e) {
            return $this->fail($output, 'could not encode document as JSON: ' . $e->getMessage(), self::EXIT_STORAGE);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($output->isVerbose()) {
                $message .= "\n" . $e->getTraceAsString();
            }
            return $this->fail($output, $message, self::EXIT_STORAGE);
        }
    }

    private function fail(OutputInterface $output, string $message, int $code): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stderr->writeln('<error>sdb: ' . $message . '</error>');

        return $code;
    }
}
