<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use Magento\Framework\FlagManager;

/**
 * Problems that need the merchant: a rejected key, a suspended AskMerra shop. Shown as an admin
 * notification and on the status page until the next successful call.
 */
class Problems
{
    private const FLAG = 'askmerra_problems';

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    public function set(int $storeId, string $message): void
    {
        $problems = $this->all();
        $problems[$storeId] = ['message' => $message, 'since' => $problems[$storeId]['since'] ?? gmdate('Y-m-d H:i:s')];
        $this->flagManager->saveFlag(self::FLAG, $problems);
    }

    public function clear(int $storeId): void
    {
        $problems = $this->all();

        if (isset($problems[$storeId])) {
            unset($problems[$storeId]);
            $this->flagManager->saveFlag(self::FLAG, $problems);
        }
    }

    /** @return array<int, array{message: string, since: string}> */
    public function all(): array
    {
        $problems = $this->flagManager->getFlagData(self::FLAG);

        return is_array($problems) ? $problems : [];
    }
}
