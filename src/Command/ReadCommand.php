<?php

namespace App\Command;

use App\Byd\DataProvider;
use App\Mqtt\MqttHandler;
use DateTime;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:read',
    description: 'Add a short description for your command',
)]
class ReadCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private DataProvider $dataProvider,
        private MqttHandler $mqttHandler,
        private bool $dataExport,
        private int $interval,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Starting Logger');
        if ($this->dataExport === true) {
            $io->info('Logging to file enabled');
        } else {
            $io->info('Logging to file disabled');
        }

        $io->info('Writing autodiscovery');
        $this->mqttHandler->writeAutodiscovery();

        if (!is_int($this->interval) || $this->interval < 1) {
            $io->error('Interval must be an integer greater than 0');

            return Command::FAILURE;
        }

        while (true) {
            $this->readAndWriteToFile($io);
            sleep($this->interval);
        }

        return Command::SUCCESS;
    }

    public function readAndWriteToFile(SymfonyStyle $io): void
    {
        $data = $this->dataProvider->getData();

        $rows = [];
        foreach ($data as $name =>$value) {
            $rows[] = [$name, $value];
        }

        $io->horizontalTable(
            ['Name', 'Value'],
            $rows
        );

        if ($data['error']) {
            return;
        }

        $this->mqttHandler->updateState($data['power'], $data['Current'], $data['Battery Voltage'], $data['State of Charge']);

        if ($this->dataExport) {
            // open file with name 'data.csv' in append mode
            $file = fopen('data.csv', 'ab');
            $csvData = [(new DateTime())->format(DateTime::ATOM), ... $data];
            fputcsv($file, $csvData);
            fclose($file);
        }
    }
}
