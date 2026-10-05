<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Api\Client;
use AskMerra\Connector\Model\Config;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento askmerra:test - checks each store view's AskMerra keys. */
class TestCommand extends AbstractCommand
{
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly Client $client
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:test')->setDescription('Checks the AskMerra connection of every store view where AskMerra is enabled');
        $this->addStoreOption('Store view codes or ids, comma separated (default: every store view where AskMerra is enabled)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeIds = $input->getOption('store')
            ? $this->getStoreIds($input, false)
            : array_values(array_filter($this->config->getActiveStoreIds(), fn (int $id) => $this->config->isEnabled($id)));

        if (!$storeIds) {
            $output->writeln('<comment>AskMerra is not enabled in any store view.</comment>');

            return self::SUCCESS;
        }

        $ok = true;

        foreach ($storeIds as $storeId) {
            $output->writeln(sprintf('<info>%s</info>: %s', $this->getStoreLabel($storeId), $this->config->getSyncMethod($storeId)));

            if ($this->config->getSecretKey($storeId) === '') {
                $output->writeln($this->config->getSyncMethod($storeId) === Config::SYNC_FEED
                    ? '  No secret key (not needed with a feed).'
                    : '  <error>No secret key: nothing is sent.</error>');
                $ok = $ok && $this->config->getSyncMethod($storeId) === Config::SYNC_FEED;
            } else {
                try {
                    $shop = $this->client->ping($storeId);
                    $output->writeln(sprintf('  Connected to the AskMerra shop "%s" (%s).', $shop['shopName'], $shop['shopId']));
                } catch (ApiException $e) {
                    $output->writeln('  <error>' . $e->getMessage() . '</error>');
                    $ok = false;
                }
            }

            $output->writeln($this->config->isWidgetEnabled($storeId)
                ? sprintf('  Widget on, site key %s..., language %s.', substr($this->config->getSiteKey($storeId), 0, 12), $this->config->getLocale($storeId) ?? 'shop default')
                : '  Widget off (or no site key).');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
