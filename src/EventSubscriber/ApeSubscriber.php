<?php

namespace Drupal\ape\EventSubscriber;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\PageCache\ResponsePolicyInterface;

/**
 * Alter Cache-control header based on configuration of ape.
 *
 * @package Drupal\ape\EventSubscriber
 */
class ApeSubscriber implements EventSubscriberInterface {

  /**
   * A config object for the system performance configuration.
   */
  protected Config|ImmutableConfig $configApe;

  /**
   * A config object for the system performance configuration.
   */
  protected Config|ImmutableConfig $configSystem;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    /**
     * A policy rule determining the cacheability of a request.
     */
    protected RequestPolicyInterface $requestPolicy,
    /**
     * A policy rule determining the cacheability of the response.
     */
    protected ResponsePolicyInterface $responsePolicy,
    /**
     * Condition plugin manager.
     */
    protected FactoryInterface $conditionManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    $this->configApe = $config_factory->get('ape.settings');
    $this->configSystem = $config_factory->get('system.performance');
  }

  /**
   * Sets extra headers on successful responses.
   */
  public function onRespond(ResponseEvent $event): void {

    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();

    // APE Cache only works with cacheable responses. It does not work
    // with plain Response objects such as JsonResponse and etc.
    if (!$response instanceof CacheableResponseInterface) {
      return;
    }

    $maxAge = ape_cache_set();

    // Check to see if another module or hook has already set an age. This
    // allows rules or other module integration to take precedent.
    if (is_null($maxAge)) {

      // Check if request matches the alternatives, otherwise use default.
      /** @var \Drupal\system\Plugin\Condition\RequestPath $condition */
      $condition = $this->conditionManager->createInstance('request_path');
      $condition->setConfig('pages', $this->configApe->get('alternatives'));

      if (!empty($this->configApe->get('alternatives')) && $condition->evaluate()) {
        $maxAge = $this->configApe->get('lifetime.alternatives');
      }
      else {
        $maxAge = $this->configSystem->get('cache.page.max_age');
      }

    }

    switch ($response->getStatusCode()) {

      case 301:
        $maxAge = $this->configApe->get('lifetime.301');
        break;

      case 302:
        $maxAge = $this->configApe->get('lifetime.302');
        break;

      case 403:
        $maxAge = 0;
        break;

      case 404:
        $maxAge = $this->configApe->get('lifetime.404');
        break;

    }

    // Allow max age to be altered by hook_ape_cache_alter().
    $originalMaxAge = $maxAge;
    $this->moduleHandler->alter('ape_cache', $maxAge, $originalMaxAge);

    // Finally set cache header.
    $this->setCacheHeader($event, $maxAge);
  }

  /**
   * Final cache check to respect defined cache policies and max age.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   *
   * @param int $maxAge
   *   The cache expiration age, in seconds.
   *
   * @return bool
   *   True if caching policies allow caching and max age is greater than 0,
   *   false if not.
   */
  private function checkCacheable(ResponseEvent $event, int $maxAge): bool {
    $request = $event->getRequest();
    $response = $event->getResponse();

    $isCacheable = ($this->requestPolicy->check($request) === RequestPolicyInterface::ALLOW) && ($this->responsePolicy->check($response, $request) !== ResponsePolicyInterface::DENY);

    return ($isCacheable && $maxAge > 0) ? TRUE : FALSE;
  }

  /**
   * Sets the cache control header.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   *
   * @param int $maxAge
   *   The cache expiration age, in seconds.
   */
  private function setCacheHeader(ResponseEvent $event, int $maxAge): void {
    $response = $event->getResponse();

    $value = 'no-cache, must-revalidate';

    if ($this->checkCacheable($event, $maxAge)) {
      $value = 'public, max-age=' . $maxAge;
    }
    $response->headers->set('Cache-Control', $value);
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    // Respond after FinishResponseSubscriber by setting low priority.
    $events[KernelEvents::RESPONSE][] = ['onRespond', -1024];
    return $events;
  }

}
