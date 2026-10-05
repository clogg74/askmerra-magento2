<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Log;

use Magento\Framework\Logger\Monolog;

/** The module's logger: var/log/askmerra.log only. */
class Logger extends Monolog
{
    /**
     * Only the module's handler. A "handlers" argument in di.xml would be merged with the one
     * Magento gives its own logger (system.log, debug.log, syslog), which this class extends, and
     * every line would be written there too.
     */
    public function __construct(Handler $handler)
    {
        parent::__construct('askmerra', [$handler]);
    }
}
