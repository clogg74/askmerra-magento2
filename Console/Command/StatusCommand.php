<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Status;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento askmerra:status - what the Sync status page shows. */
class StatusCommand extends AbstractCommand
{
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly Status $status
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:status')->setDescription('Shows the AskMerra sync of every store view');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Store view', 'Sync', 'Language', 'In AskMerra', 'Waiting', 'Failed', 'Last sent (UTC)', 'Problem']);
        $feeds = [];

        foreach ($this->status->getStores() as $store) {
            $table->addRow([
                sprintf('%s (%s)', $store['name'], $store['code']),
                $store['mode'] ?? ($store['missing_key'] ? 'off: no secret key' : 'off'),
                $store['locale'] ?? '-',
                $store['products'],
                $store['pending'],
                $store['failed'],
                $store['last_synced_at'] ?? '-',
                $store['problem']['message'] ?? '',
            ]);

            if ($store['feed']) {
                $feeds[] = sprintf(
                    '%s: %s (%s)',
                    $store['code'],
                    $store['feed']['url'],
                    $store['feed']['exists']
                        ? sprintf('%d KB, written %s UTC', $store['feed']['bytes'] / 1024, $store['feed']['modified_at'])
                        : ($store['feed']['ready'] ? 'not written yet' : 'being prepared')
                );
            }
        }

        $table->render();

        foreach ($feeds as $line) {
            $output->writeln('Feed ' . $line);
        }

        foreach ($this->status->getWarnings() as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return self::SUCCESS;
    }
}
