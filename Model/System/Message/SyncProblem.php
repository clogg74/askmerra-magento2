<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\System\Message;

use AskMerra\Connector\Model\Sync\Problems;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** The admin bar message when AskMerra refuses a store view's key or its shop is suspended. */
class SyncProblem implements MessageInterface
{
    private ?array $problems = null;

    public function __construct(
        private readonly Problems $problemsStore,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper
    ) {
    }

    public function getIdentity()
    {
        return 'askmerra_sync_problem_' . sha1((string) json_encode($this->getProblems()));
    }

    public function isDisplayed()
    {
        return (bool) $this->getProblems();
    }

    public function getText()
    {
        $names = [];

        foreach (array_keys($this->getProblems()) as $storeId) {
            try {
                $names[] = $this->storeManager->getStore((int) $storeId)->getName();
            } catch (\Throwable) {
                $names[] = '#' . $storeId;
            }
        }

        return (string) __(
            'AskMerra does not accept the catalog of %1: the secret key was refused or the AskMerra shop is suspended. Changes are kept and sent once fixed. <a href="%2">See the sync status</a>.',
            $this->escaper->escapeHtml(implode(', ', $names)),
            $this->urlBuilder->getUrl('askmerra/status/index')
        );
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    private function getProblems(): array
    {
        return $this->problems ??= $this->problemsStore->all();
    }
}
