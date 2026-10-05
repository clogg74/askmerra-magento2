<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** What the askmerra:* commands share: the --store option and running like cron does. */
abstract class AbstractCommand extends Command
{
    public function __construct(
        protected readonly Config $config,
        protected readonly StoreManagerInterface $storeManager,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function addStoreOption(string $description): void
    {
        $this->addOption('store', 's', InputOption::VALUE_REQUIRED, $description);
    }

    /**
     * The store views of --store (codes or ids, comma separated).
     *
     * @param bool $syncingOnly without --store: only the store views that sync, else all of them
     * @return int[]
     */
    protected function getStoreIds(InputInterface $input, bool $syncingOnly = true): array
    {
        $option = trim((string) $input->getOption('store'));

        if ($option === '') {
            if ($syncingOnly) {
                return $this->config->getSyncStoreIds();
            }

            return array_values(array_map(static fn ($store) => (int) $store->getId(), $this->storeManager->getStores()));
        }

        $ids = [];

        foreach (array_filter(array_map('trim', explode(',', $option))) as $value) {
            $ids[] = (int) $this->storeManager->getStore(ctype_digit($value) ? (int) $value : $value)->getId();
        }

        return array_values(array_unique($ids));
    }

    protected function getStoreLabel(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);

        return sprintf('%s (%s, #%d)', $store->getName(), $store->getCode(), $storeId);
    }

    /** Products are built as cron builds them: as a guest, in the crontab area. */
    protected function initArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }
    }
}
