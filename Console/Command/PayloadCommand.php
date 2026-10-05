<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Catalog\ProductBuilder;
use AskMerra\Connector\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento askmerra:payload <id or SKU> - a product exactly as AskMerra receives it. */
class PayloadCommand extends AbstractCommand
{
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly ProductBuilder $productBuilder,
        private readonly ProductResource $productResource
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:payload')
            ->setDescription('Shows a product as AskMerra receives it (sends nothing)')
            ->addArgument('product', InputArgument::REQUIRED, 'Product id or SKU');
        $this->addStoreOption('Store view code or id (default: the first store view that syncs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initArea();
        $value = trim((string) $input->getArgument('product'));
        $productId = ctype_digit($value) ? (int) $value : (int) $this->productResource->getIdBySku($value);

        if (!$productId) {
            $output->writeln(sprintf('<error>No product with the id or SKU "%s".</error>', $value));

            return self::FAILURE;
        }

        $storeIds = $this->getStoreIds($input, false);
        $storeId = $input->getOption('store') ? reset($storeIds) : ($this->config->getSyncStoreIds()[0] ?? null);

        if (!$storeId) {
            $output->writeln('<error>No store view syncs with AskMerra yet: pass --store.</error>');

            return self::FAILURE;
        }

        $built = $this->productBuilder->build((int) $storeId, [$productId]);

        if (isset($built['payloads'][$productId])) {
            $output->writeln((string) json_encode(
                $built['payloads'][$productId],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>Not sent in %s: %s</comment>',
            $this->getStoreLabel((int) $storeId),
            $built['ineligible'][$productId] ?? $built['errors'][$productId] ?? 'unknown reason'
        ));

        return self::SUCCESS;
    }
}
