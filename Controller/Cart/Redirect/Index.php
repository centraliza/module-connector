<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Cart\Redirect;

use Centraliza\Connector\Messaging\Api\CartRedirectConfigInterface;
use Centraliza\Connector\Messaging\Api\ReplaceCartServiceInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Index implements HttpGetActionInterface
{
    private const PRIVATE_CONTENT_VERSION_LENGTH = 32;

    /**
     * @param ResultFactory $resultFactory
     * @param CartRedirectConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param RequestInterface $request
     * @param ReplaceCartServiceInterface $replaceCartService
     * @param CustomerSession $customerSession
     * @param ManagerInterface $messageManager
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param Random $random
     * @param LoggerInterface $logger
     * @param UrlInterface $urlBuilder
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly CartRedirectConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly ReplaceCartServiceInterface $replaceCartService,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly Random $random,
        private readonly LoggerInterface $logger,
        private readonly UrlInterface $urlBuilder,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $store = $this->storeManager->getStore();

        // A website that has not enabled the feature answers 404, so it never exposes an
        // unauthenticated link that replaces its customers' carts.
        if (!$this->config->isEnabled((int) $store->getWebsiteId())) {
            /** @var Forward $forward */
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);

            return $forward->forward('noroute');
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!$this->request instanceof HttpRequest) {
            return $redirect->setPath('checkout/cart');
        }

        // Link previews (the WhatsApp crawler among them) may probe the URL with HEAD; only a
        // real visit replaces a cart.
        if ($this->request->isHead()) {
            return $redirect->setPath('checkout/cart');
        }

        // Only the query string is read: Centraliza generates ?cart_id=, and the router would also
        // accept the value as a path segment, a second link format outside the contract. An
        // array parameter (?cart_id[]=) is read as missing and rejected by the format check.
        $cartId = $this->request->getQuery('cart_id', '');
        if (!is_string($cartId)) {
            $cartId = '';
        }

        try {
            $this->replaceCartService->replace($cartId);
        } catch (LocalizedException $exception) {
            // A customer's own cart opens for that customer only (the link can be forwarded): a visitor who
            // is not logged in is asked to sign in, and comes back through the same link.
            if ($exception->getRawMessage() === ReplaceCartServiceInterface::REASON_UNAUTHORIZED_QUOTE
                && !$this->customerSession->isLoggedIn()
            ) {
                return $this->redirectToLogin($redirect, $cartId);
            }

            return $this->redirectWithFailure($redirect);
        } catch (Throwable $exception) {
            $this->logger->error('Centraliza cart redirect failed', [
                'exception_class' => $exception::class,
                'store_id' => (int) $store->getId(),
            ]);

            return $this->redirectWithFailure($redirect);
        }

        $this->renewPrivateContentVersion();

        if ($this->customerSession->isLoggedIn()) {
            return $redirect->setPath('checkout');
        }

        return $redirect->setPath('checkout/cart');
    }

    /**
     * Send the visitor to the login page, then back to this link.
     *
     * @param Redirect $redirect
     * @param string $cartId
     * @return Redirect
     */
    private function redirectToLogin(Redirect $redirect, string $cartId): Redirect
    {
        $link = $this->urlBuilder->getUrl('centraliza/cart_redirect', ['_query' => ['cart_id' => $cartId]]);

        // The core's own "sign in, then come back" URL: consumed once by the next login, whatever the
        // dashboard setting. It is set after the last URL is built: the core parses it into the shared
        // URL query state, which the next URL built would carry.
        $redirect->setPath(CustomerUrl::ROUTE_ACCOUNT_LOGIN);
        $this->customerSession->setBeforeAuthUrl($link);

        // The login would merge the session cart into the customer's active cart, the link's cart, and
        // then delete it, though it may be another shared link's cart. It leaves the session instead,
        // kept as it is, so the checkout shows only what the link carries.
        $this->checkoutSession->setQuoteId(null);
        $this->renewPrivateContentVersion();
        $this->messageManager->addNoticeMessage(__('Sign in to see your cart.'));

        return $redirect;
    }

    /**
     * @param Redirect $redirect
     * @return Redirect
     */
    private function redirectWithFailure(Redirect $redirect): Redirect
    {
        // Always this fixed text, never the exception message: storefront messages are rendered
        // as HTML, and the reason carries values that only belong in the log.
        $this->messageManager->addErrorMessage(
            __('We could not load the products into your cart. Please try the link again or contact the store.')
        );

        return $redirect->setPath('checkout/cart');
    }

    /**
     * Change the private content version so the storefront reloads the customer sections.
     *
     * @return void
     */
    private function renewPrivateContentVersion(): void
    {
        // The core renews this cookie on POST requests only, and the storefront reloads the
        // minicart only when it changes; this GET changes the session cart, so it renews the cookie the
        // same way the core does.
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setDuration(Version::COOKIE_PERIOD)
            ->setPath('/')
            ->setSecure($this->request->isSecure())
            ->setHttpOnly(false)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(
            Version::COOKIE_NAME,
            $this->random->getRandomString(self::PRIVATE_CONTENT_VERSION_LENGTH),
            $metadata
        );
    }
}
