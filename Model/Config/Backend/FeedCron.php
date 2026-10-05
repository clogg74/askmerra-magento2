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
 * Turns "Regenerate the feed" into the cron expression of the askmerra_feed_generate job.
 */
class FeedCron extends Value
{
    public const CRON_PATH = 'crontab/default/jobs/askmerra_feed_generate/schedule/cron_expr';

    private const EXPRESSIONS = [
        '60' => '5 * * * *',
        '180' => '5 */3 * * *',
        '360' => '5 */6 * * *',
        '480' => '5 */8 * * *',
        '720' => '5 */12 * * *',
        '1440' => '5 2 * * *',
    ];

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
        $this->configValueFactory->create()
            ->load(self::CRON_PATH, 'path')
            ->setValue(self::EXPRESSIONS[(string) $this->getValue()] ?? self::EXPRESSIONS['60'])
            ->setPath(self::CRON_PATH)
            ->save();

        return parent::afterSave();
    }
}
