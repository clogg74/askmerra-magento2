<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Turns "Daily maintenance at" into the cron expression of the askmerra_daily job. Whether that
 * day also rebuilds everything is decided in the job (setting "Full rebuild").
 */
class DailyCron extends Value
{
    public const CRON_PATH = 'crontab/default/jobs/askmerra_daily/schedule/cron_expr';

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ValueFactory $configValueFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave()
    {
        $time = $this->getValue();
        $time = is_array($time) ? $time : explode(',', (string) $time);

        $this->configValueFactory->create()
            ->load(self::CRON_PATH, 'path')
            ->setValue(sprintf('%d %d * * *', (int) ($time[1] ?? 15), (int) ($time[0] ?? 3)))
            ->setPath(self::CRON_PATH)
            ->save();

        return parent::afterSave();
    }
}
