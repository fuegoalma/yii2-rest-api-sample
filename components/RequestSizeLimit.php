<?php

declare(strict_types=1);

namespace app\components;

use app\models\exception\PayloadTooLargeException;
use yii\base\ActionFilter;
use Yii;

/**
 * Answers an oversized request with 413 instead of letting it look like an
 * empty one.
 *
 * PHP enforces `post_max_size` itself, and the way it does so is destructive:
 * a body over the limit is discarded outright, before any application code
 * runs, so `$_POST` and `$_FILES` arrive empty. The upload endpoint then sees a
 * request with no fields and answers "title cannot be blank" — a 422 blaming
 * the caller for omitting what they did send, with no hint that size was the
 * problem or what the limit is.
 *
 * `Content-Length` is what the filter reads, not the emptiness of `$_POST`:
 * the header is present before parsing, it is what PHP compares against, and
 * it stays meaningful for a body that was too large for us but not for PHP.
 */
class RequestSizeLimit extends ActionFilter
{
    /**
     * Largest accepted body in bytes; 0 disables the check. Defaults to
     * whatever `post_max_size` is set to, because PHP is what actually discards
     * the body — a second copy of the number in application code could disagree
     * with the one doing the work.
     */
    public int $maxBytes = 0;

    public function init(): void
    {
        parent::init();

        if ($this->maxBytes === 0) {
            $this->maxBytes = self::parseSize((string) ini_get('post_max_size'));
        }
    }

    /**
     * @throws PayloadTooLargeException when the declared body exceeds the limit
     */
    public function beforeAction($action): bool
    {
        $declared = (int) (Yii::$app->request->headers->get('Content-Length') ?? 0);

        if ($this->maxBytes > 0 && $declared > $this->maxBytes) {
            throw new PayloadTooLargeException(
                sprintf('The request body is larger than the %d bytes this endpoint accepts.', $this->maxBytes),
                'payload.too_large'
            );
        }

        return parent::beforeAction($action);
    }

    /**
     * Resolves php.ini shorthand ("12M", "8K", "1G") to bytes.
     *
     * Public because it is also the single definition tests compare against —
     * a private copy of this arithmetic is how the filter's limit and PHP's
     * would drift apart.
     */
    public static function parseSize(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
