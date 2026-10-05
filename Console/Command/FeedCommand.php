<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedGenerator;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento askmerra:feed:generate - writes the feed files now. */
class FeedCommand extends AbstractCommand
{
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly FeedGenerator $feedGenerator
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:feed:generate')
            ->setDescription('Writes the AskMerra feed files of the store views that send a feed')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Write them even when no product changed');
        $this->addStoreOption('Store view codes or ids, comma separated (default: every store view that sends a feed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initArea();
        $storeIds = array_values(array_filter($this->getStoreIds($input), fn (int $id) => $this->config->isFeedEnabled($id)));

        if (!$storeIds) {
            $output->writeln('<comment>No store view sends its catalog as a feed ("How AskMerra gets the catalog": Product feed).</comment>');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($storeIds as $storeId) {
            try {
                $result = $this->feedGenerator->generate($storeId, (bool) $input->getOption('force'));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s: %s</error>', $this->getStoreLabel($storeId), $e->getMessage()));
                $failed = true;
                continue;
            }

            $line = match ($result['status']) {
                'written' => sprintf('<info>written</info>, %d products, %d KB', $result['products'], $result['bytes'] / 1024),
                'unchanged' => 'unchanged since it was last written (--force writes it anyway)',
                'building' => $result['message'] . ' Run bin/magento askmerra:sync to prepare it now.',
                default => $result['status'],
            };

            $output->writeln(sprintf('%s: %s', $this->getStoreLabel($storeId), $line));
            $output->writeln('  ' . $this->feedGenerator->getUrl($storeId));
        }

        $removed = $this->feedGenerator->removeUnusedFiles();

        if ($removed > 0) {
            $output->writeln(sprintf('%d feed files no store view publishes anymore were removed.', $removed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
