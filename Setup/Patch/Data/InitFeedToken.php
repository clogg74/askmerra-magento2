<?php

declare(strict_types=1);

namespace AskMerra\Connector\Setup\Patch\Data;

use AskMerra\Connector\Model\Config;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/** The secret part of the feed URLs, created once per installation. */
class InitFeedToken implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $exists = $connection->fetchOne(
            $connection->select()->from($table, ['config_id'])
                ->where('path = ?', Config::PATH_FEED_TOKEN)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );

        if (!$exists) {
            $connection->insert($table, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => Config::PATH_FEED_TOKEN,
                'value' => bin2hex(random_bytes(16)),
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
