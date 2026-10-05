<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Csp;

use AskMerra\Connector\Model\Config;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;

/**
 * Allows the widget script and the AskMerra API in the Content Security Policy for the hosts set
 * under Advanced (a test environment, a custom CDN). The default hosts are in etc/csp_whitelist.xml.
 */
class PolicyCollector implements PolicyCollectorInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function collect(array $defaultPolicies = []): array
    {
        try {
            if (!$this->config->isWidgetEnabled()) {
                return $defaultPolicies;
            }

            $scriptHost = $this->getOrigin($this->config->getWidgetUrl());
            $apiHost = $this->getOrigin($this->config->getApiUrl());
        } catch (\Throwable) {
            return $defaultPolicies;
        }

        if ($scriptHost !== null) {
            $defaultPolicies[] = new FetchPolicy('script-src', false, [$scriptHost]);
        }

        if ($apiHost !== null) {
            $defaultPolicies[] = new FetchPolicy('connect-src', false, [$apiHost]);
        }

        return $defaultPolicies;
    }

    private function getOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
