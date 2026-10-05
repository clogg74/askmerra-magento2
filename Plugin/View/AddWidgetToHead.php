<?php

declare(strict_types=1);

namespace AskMerra\Connector\Plugin\View;

use AskMerra\Connector\Block\Widget;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Result\Layout;

/**
 * Puts the AskMerra widget into the <head> of storefronts that do not render Magento's layout -
 * ScandiPWA serves its own HTML page, so the layout block (default.xml, head.additional) never
 * shows there. Luma and Hyvä pages already have it and are left as they are.
 *
 * Runs after Magento's "move JS to the bottom" (sortOrder -10), so the script stays in the head,
 * and before the full page cache stores the page.
 */
class AddWidgetToHead
{
    private const MARKER = 'id="' . Widget::SCRIPT_ID . '"';

    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly Logger $logger
    ) {
    }

    public function afterRenderResult(Layout $subject, Layout $result, ResponseInterface $response): Layout
    {
        if (!method_exists($response, 'getContent') || !method_exists($response, 'setContent')) {
            return $result;
        }

        $content = (string) $response->getContent();
        $headEnd = stripos($content, '</head>');

        if ($headEnd === false || str_contains($content, self::MARKER)) {
            return $result;
        }

        try {
            $html = (string) $this->layout->createBlock(Widget::class, 'askmerra.widget.head', [
                'data' => ['single_page_app' => true],
            ])->setTemplate('AskMerra_Connector::widget.phtml')->toHtml();
        } catch (\Throwable $e) {
            $this->logger->error('Adding the widget to the page head: ' . $e->getMessage(), ['exception' => $e]);

            return $result;
        }

        if (trim($html) !== '') {
            $response->setContent(substr_replace($content, $html . "\n", $headEnd, 0));
        }

        return $result;
    }
}
