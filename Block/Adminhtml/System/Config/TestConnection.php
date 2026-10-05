<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block\Adminhtml\System\Config;

/** "Check connection": tests the keys as typed, before they are saved. */
class TestConnection extends AbstractButton
{
    protected $_template = 'AskMerra_Connector::system/config/test-connection.phtml';

    public function getTestUrl(): string
    {
        return $this->getUrl('askmerra/connection/test', $this->getScopeParams());
    }
}
