<?php

declare(strict_types=1);

namespace app\components;

use yii\base\ActionFilter;
use yii\web\Response;
use Yii;

/**
 * Turns an unchanged `GET` into a `304`.
 *
 * The saving is the body, not the work: the action still runs and the response
 * is still built, and only then is its ETag compared against what the client
 * already holds. That is worth having anyway — a photo listing is a few hundred
 * bytes of JSON per page, and a client polling it pays for those bytes on every
 * poll — but it is emphatically *not* a cache, and pretending otherwise would
 * invite somebody to expect it to reduce database load.
 *
 * A real saving would need the ETag computable without doing the work (a
 * `MAX(updated_at)` per collection, say), which is a different feature with a
 * different invalidation problem. See ADR 13.
 *
 * Weak validators (`W/"…"`) because the comparison is over the serialized body:
 * two byte-identical payloads are semantically equivalent, which is all a weak
 * validator claims, and the only comparison a client is allowed to make against
 * one is the equality this filter performs.
 */
class ConditionalGet extends ActionFilter
{
    public function afterAction($action, $result): mixed
    {
        // Deliberately not computed here. At this point `$response->data` is
        // still whatever the action returned — an ActiveDataProvider, a model —
        // and hashing that would produce a validator that does not follow the
        // payload: two different result sets can share an identical object
        // graph as far as json_encode is concerned. The body only exists after
        // ApiSerializer has run, which is during prepare().
        Yii::$app->response->on(Response::EVENT_AFTER_PREPARE, $this->tagAndCompare(...));

        return parent::afterAction($action, $result);
    }

    public function tagAndCompare(): void
    {
        $response = Yii::$app->response;

        if (!$this->isCacheable($response)) {
            return;
        }

        $etag = 'W/"' . sha1((string) $response->content) . '"';
        $response->headers->set('ETag', $etag);

        if ($this->matches($etag)) {
            $response->statusCode = 304;
            // a 304 carries no body; the ETag above stays, because a client that
            // drops it on revalidation has nothing to send next time
            $response->content = '';
            $response->data = null;
        }
    }

    private function isCacheable(Response $response): bool
    {
        return Yii::$app->request->isGet && $response->statusCode === 200;
    }

    /**
     * `If-None-Match` may carry a list, and `*` matches anything the server has.
     */
    private function matches(string $etag): bool
    {
        $header = trim((string) Yii::$app->request->headers->get('If-None-Match'));

        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
