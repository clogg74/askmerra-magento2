<?php

declare(strict_types=1);

namespace AskMerra\Connector\Console\Command;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Sync\Remover;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * bin/magento askmerra:remove --store=<code> - takes a store view's products out of AskMerra, e.g.
 * before removing the module. AskMerra must be turned off for the store view first.
 */
class RemoveCommand extends AbstractCommand
{
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        AppState $appState,
        private readonly Remover $remover
    ) {
        parent::__construct($config, $storeManager, $appState);
    }

    protected function configure(): void
    {
        $this->setName('askmerra:remove')
            ->setDescription('Removes every product of a store view from AskMerra (turn AskMerra off for it first)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        $this->addStoreOption('Store view codes or ids, comma separated (required)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('store')) {
            $output->writeln('<error>Pass --store with the store view code.</error>');

            return self::FAILURE;
        }

        $this->initArea();
        $storeIds = $this->getStoreIds($input, false);

        foreach ($storeIds as $storeId) {
            if ($this->config->isSyncEnabled($storeId)) {
                $output->writeln(sprintf(
                    '<error>%s still syncs: turn AskMerra off for it first, or its products are sent again at the next daily check.</error>',
                    $this->getStoreLabel($storeId)
                ));

                return self::FAILURE;
            }
        }

        $question = new ConfirmationQuestion('Remove every product of these store views from AskMerra? [y/N] ', false);

        if (!$input->getOption('yes') && !$this->getHelper('question')->ask($input, $output, $question)) {
            return self::SUCCESS;
        }

        foreach ($storeIds as $storeId) {
            $output->writeln(sprintf('%s: %d products removed.', $this->getStoreLabel($storeId), $this->remover->removeAll($storeId)));
        }

        $output->writeln('A feed source must also be deleted in the AskMerra dashboard: AskMerra keeps a feed\'s products when the file disappears.');

        return self::SUCCESS;
    }
}
