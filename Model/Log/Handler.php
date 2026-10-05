<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Log;

use Magento\Framework\Logger\Handler\Base;

/** Everything the module logs goes to var/log/askmerra.log. */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/askmerra.log';
}
