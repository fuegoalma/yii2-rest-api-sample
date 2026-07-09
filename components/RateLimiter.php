<?php

namespace app\components;

use Yii;
use yii\base\Action;
use yii\base\ActionFilter;
use yii\caching\CacheInterface;
use yii\di\Instance;
use yii\web\TooManyRequestsHttpException;

/**
 * Brute-force protection for public endpoints: a cache-backed rate limiter
 * counting attempts per client IP. Every request increments the counter and
 * refreshes the window, so the limit only clears after a full quiet window;
 * a response below 400 (e.g. a successful login) resets the counter early.
 * CORS preflight OPTIONS requests are never throttled.
 */
class RateLimiter extends ActionFilter
{
    /** attempts allowed within one window */
    public int $maxAttempts = 5;

    /** window length in seconds */
    public int $window = 60;

    public string|CacheInterface $cache = 'cache';

    public function init(): void
    {
        parent::init();
        $this->cache = Instance::ensure($this->cache, CacheInterface::class);
    }

    /**
     * @throws TooManyRequestsHttpException when the attempt limit is exhausted
     */
    public function beforeAction($action): bool
    {
        if (Yii::$app->request->isOptions) {
            return parent::beforeAction($action);
        }

        $key = $this->cacheKey($action);
        $attempts = (int) $this->cache->get($key);

        if ($attempts >= $this->maxAttempts) {
            Yii::$app->response->headers->set('Retry-After', (string) $this->window);
            throw new TooManyRequestsHttpException('Too many attempts. Please try again later.');
        }

        $this->cache->set($key, $attempts + 1, $this->window);

        return parent::beforeAction($action);
    }

    public function afterAction($action, $result): mixed
    {
        // failed attempts never reach this point with a success status:
        // bad credentials throw a 401 and validation failures set a 422
        if (!Yii::$app->request->isOptions && Yii::$app->response->statusCode < 400) {
            $this->cache->delete($this->cacheKey($action));
        }

        return parent::afterAction($action, $result);
    }

    private function cacheKey(Action $action): string
    {
        return implode(':', [self::class, $action->getUniqueId(), Yii::$app->request->userIP ?? 'unknown']);
    }
}
