<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Sync\Enqueuer;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\QueueProcessor;
use AskMerra\Connector\Model\Sync\Reconciler;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento askmerra:sync - sends the queue now, instead of waiting for cron. With --rebuild the
 * whole catalog is queued first (the first sync of a large catalog), with --check the daily check
 * runs first, with --product only some products.
 */
class SyncCommand extends AbstractCommand
{
    /** Seconds per round, so progress is shown regularly. */
    private const ROUND_SECONDS = 15;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly QueueProcessor $queueProcessor,
        private readonly Reconciler $reconciler,
        private readonly Enqueuer $enqueuer,
        private readonly Queue $queue,
        private readonly ProductResource $productResource
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:sync')
            ->setDescription('Sends changed products to AskMerra now (or prepares the feed), instead of waiting for cron')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Queue every product first; only those whose content changed are sent')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Run the daily check first: missing products, products that left the catalog, special price dates')
            ->addOption('product', 'p', InputOption::VALUE_REQUIRED, 'Only these products: ids or SKUs, comma separated')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Try the failed products again')
            ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0: when the queue is empty)', '0');
        $this->addStoreOption('Store view codes or ids, comma separated (default: every store view that syncs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initArea();
        $storeIds = $this->getStoreIds($input);

        if (!$storeIds) {
            $output->writeln('<error>No store view syncs with AskMerra. Enable it and enter the keys under Stores > Configuration > AskMerra.</error>');

            return self::FAILURE;
        }

        foreach ($storeIds as $storeId) {
            if (!$this->config->isSyncEnabled($storeId)) {
                $output->writeln(sprintf('<error>%s does not sync with AskMerra: check its settings and secret key.</error>', $this->getStoreLabel($storeId)));

                return self::FAILURE;
            }
        }

        $this->prepare($input, $output, $storeIds);

        $limit = max(0, (int) $input->getOption('time'));
        $deadline = $limit > 0 ? microtime(true) + $limit : null;
        $totals = [];

        while ($deadline === null || microtime(true) < $deadline) {
            $seconds = $deadline === null ? self::ROUND_SECONDS : (int) max(1, min(self::ROUND_SECONDS, $deadline - microtime(true)));
            $stats = $this->queueProcessor->run($seconds, $storeIds);

            if ($stats === null) {
                $output->writeln('Cron is sending the queue right now; waiting for it...');
                sleep(5);
                continue;
            }

            if (!$stats) {
                break;
            }

            foreach ($stats as $storeId => $counts) {
                foreach ($counts as $key => $count) {
                    $totals[$storeId][$key] = ($totals[$storeId][$key] ?? 0) + $count;
                }

                $output->writeln(sprintf(
                    '%s: sent %d, unchanged %d, removed %d, failed %d - %d waiting',
                    $this->getStoreLabel((int) $storeId),
                    $counts['sent'],
                    $counts['unchanged'],
                    $counts['removed'],
                    $counts['failed'],
                    $this->queue->countPending((int) $storeId)
                ));
            }
        }

        $output->writeln('');

        foreach ($storeIds as $storeId) {
            $done = $totals[$storeId] ?? ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
            $output->writeln(sprintf(
                '<info>%s: %d sent, %d unchanged, %d removed, %d failed.</info> %d still waiting, %d failed for good.',
                $this->getStoreLabel($storeId),
                $done['sent'],
                $done['unchanged'],
                $done['removed'],
                $done['failed'],
                $this->queue->countPending($storeId),
                count($this->queue->getFailedProductIds($storeId))
            ));

            if ($this->config->isFeedEnabled($storeId)) {
                $output->writeln('  Feed: run bin/magento askmerra:feed:generate to write the file now, or wait for cron.');
            }
        }

        return self::SUCCESS;
    }

    private function prepare(InputInterface $input, OutputInterface $output, array $storeIds): void
    {
        if ($input->getOption('retry-failed')) {
            foreach ($storeIds as $storeId) {
                $output->writeln(sprintf('%s: %d failed products tried again.', $this->getStoreLabel($storeId), $this->queue->retryFailed($storeId)));
            }
        }

        $products = trim((string) $input->getOption('product'));

        if ($products !== '') {
            $ids = [];

            foreach (array_filter(array_map('trim', explode(',', $products))) as $value) {
                $id = ctype_digit($value) ? (int) $value : (int) $this->productResource->getIdBySku($value);

                if ($id) {
                    $ids[] = $id;
                } else {
                    $output->writeln(sprintf('<comment>No product with the SKU "%s".</comment>', $value));
                }
            }

            $this->enqueuer->enqueue($ids, $storeIds);
            $output->writeln(sprintf('%d products queued, with their configurable, bundle or grouped products.', count($ids)));
        }

        foreach ($storeIds as $storeId) {
            if ($input->getOption('rebuild')) {
                $output->writeln(sprintf('%s: %d products queued for the rebuild.', $this->getStoreLabel($storeId), $this->reconciler->rebuild($storeId)['queued']));
            } elseif ($input->getOption('check')) {
                $stats = $this->reconciler->reconcile($storeId);
                $output->writeln(sprintf(
                    '%s: %d missing, %d left the catalog, %d special price dates.',
                    $this->getStoreLabel($storeId),
                    $stats['missing'],
                    $stats['gone'],
                    $stats['price_dates']
                ));
            }
        }
    }
}
