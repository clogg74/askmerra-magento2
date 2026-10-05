<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Feed;

use AskMerra\Connector\Model\Config;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * The secret part of the feed file names. AskMerra cannot send credentials when it downloads a
 * feed, so an unguessable URL is what keeps the catalog file private. Regenerating it changes every
 * feed URL; the AskMerra feed sources then need the new ones.
 */
class FeedToken
{
    public function __construct(
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    public function get(): string
    {
        $token = $this->config->getFeedToken();

        return preg_match('/^[a-f0-9]{32}$/', $token) ? $token : $this->regenerate();
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->configWriter->save(Config::PATH_FEED_TOKEN, $token);
        $this->reinitableConfig->reinit();

        return $token;
    }
}
