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
    /**
     * Named here rather than at the `set()` call below because the CORS filter
     * has to expose it: a browser hides every header outside the safelist, so a
     * validator this class sets and nobody may read is a validator no
     * cross-origin client can ever send back.
     */
    public const string HEADER = 'ETag';

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

        // Set before the comparison below, so the 304 carries them too: a client
        // that revalidated and got back no caching headers has nothing to store
        // the refreshed copy under, and stops revalidating.
        //
        // Without a freshness directive a browser has no basis to keep the
        // response at all, so it never sends `If-None-Match` and the 304 path is
        // unreachable from a browser — the filter was machinery no browser
        // client could get to. `no-cache` is not "do not store": it is store and
        // revalidate every time, which is the only safe way to let a per-token
        // body be kept, since every reuse is re-authorized by the request that
        // revalidates it. `private` keeps it out of shared caches.
        //
        // `Vary` names what the stored copy is keyed by. `Origin` belongs there
        // for a reason that is not obvious: `Access-Control-Allow-Origin` echoes
        // the caller whenever the allowed list is not a wildcard, so the grant
        // differs per origin while the browser's cache key does not include
        // `Origin` on its own. It is set here rather than by the CORS filter
        // because this is the class that decides the response may be stored at
        // all — one `Vary`, in the one place that says "you may keep this".
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set('Vary', 'Authorization, Origin');

        $etag = 'W/"' . sha1((string) $response->content) . '"';
        $response->headers->set(self::HEADER, $etag);

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
