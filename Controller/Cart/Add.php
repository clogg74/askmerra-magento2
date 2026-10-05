<?php

declare(strict_types=1);

namespace AskMerra\Connector\Controller\Cart;

use AskMerra\Connector\Model\Catalog\ExternalId;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Add to cart" in the AskMerra chat (POST, form key required). A product that can be bought as it
 * is - simple or virtual, in stock, without required options - goes into the Magento cart; any
 * other product answers with its page, where the shopper picks the options.
 *
 * Answers JSON: {success: true, name, message, cartUrl, cartLabel} or {success: false, redirect?, message?}.
 */
class Add implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const DIRECT_TYPES = ['simple', 'virtual'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ExternalId $externalId,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Cart $cart,
        private readonly EventManager $eventManager,
        private readonly MessageManager $messageManager,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly Logger $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isWidgetEnabled($storeId) || !$this->config->isAddToCartEnabled($storeId)) {
            return $result->setHttpResponseCode(404)->setData(['success' => false]);
        }

        $product = $this->findProduct(
            trim((string) $this->request->getParam('external_id')),
            trim((string) $this->request->getParam('sku')),
            $storeId
        );

        if ($product === null) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('This product is no longer available.'),
            ]);
        }

        $productUrl = (string) $product->getProductUrl();

        if (!$this->canAddDirectly($product)) {
            return $result->setData(['success' => false, 'redirect' => $productUrl]);
        }

        try {
            $this->cart->addProduct($product, ['qty' => 1]);
            $this->cart->save();
        } catch (LocalizedException $e) {
            // As Magento's own add to cart does: the product page shows why (stock, quantity...).
            $this->messageManager->addErrorMessage($e->getMessage());

            return $result->setData(['success' => false, 'redirect' => $productUrl, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Add to cart of product %d: %s', $product->getId(), $e->getMessage()), ['exception' => $e]);

            return $result->setData(['success' => false, 'redirect' => $productUrl]);
        }

        // Analytics and marketing modules listen to this, as for Magento's own add to cart.
        $this->eventManager->dispatch('checkout_cart_add_product_complete', [
            'product' => $product,
            'request' => $this->request,
            'response' => $this->response,
        ]);

        return $result->setData([
            'success' => true,
            'name' => (string) $product->getName(),
            'message' => (string) __('%1 was added to your cart.', (string) $product->getName()),
            'cartUrl' => $this->urlBuilder->getUrl('checkout/cart'),
            'cartLabel' => (string) __('View cart'),
            'qty' => (float) $this->cart->getSummaryQty(),
        ]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create()
            ->setHttpResponseCode(403)
            ->setData(['success' => false, 'message' => (string) __('Your session has expired. Please refresh the page.')]);
        $response = $this->response;
        $result->renderResult($response);

        return new InvalidRequestException($response);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Magento skips the form key for Ajax requests; js/storefront.js always sends it, so it is
        // always checked here.
        return $this->formKeyValidator->validate($request);
    }

    private function findProduct(string $externalId, string $sku, int $storeId): ?Product
    {
        $productId = $externalId !== '' ? $this->externalId->toProductId($externalId, $storeId) : null;

        try {
            if ($productId !== null) {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } elseif ($sku !== '') {
                $product = $this->productRepository->get($sku, false, $storeId);
            } else {
                return null;
            }
        } catch (NoSuchEntityException) {
            return null;
        }

        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();

        // Only products shown on their own: a configurable's variant or a grouped product's part is
        // not a product AskMerra has (the chat sends the product it shows).
        if (!$product instanceof Product
            || (int) $product->getStatus() !== Product\Attribute\Source\Status::STATUS_ENABLED
            || !$product->isVisibleInSiteVisibility()
            || !in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)
        ) {
            return null;
        }

        return $product;
    }

    private function canAddDirectly(Product $product): bool
    {
        return in_array($product->getTypeId(), self::DIRECT_TYPES, true)
            && $product->isSalable()
            && !$product->getTypeInstance()->hasRequiredOptions($product);
    }
}
