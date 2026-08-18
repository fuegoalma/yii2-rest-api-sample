<?php

declare(strict_types=1);

namespace tests\support;

use ReflectionProperty;
use Yii;
use yii\web\CompositeUrlRule;
use yii\web\Request;
use yii\web\UrlRule;

/**
 * The application's routing table, expanded and normalised into the vocabulary
 * the OpenAPI document uses (`"GET /albums/{id}"`).
 *
 * Expansion goes through Yii's own `UrlManager` rather than re-reading
 * `config/url_rules.php`, because the two `yii\rest\UrlRule` entries there turn
 * into several routes each: `except => ['index','create']`, the `{id}` token,
 * and seven default patterns are all applied by Yii. Re-implementing that here
 * would create a second source of truth — precisely what these gates exist to
 * prevent.
 */
final class RouteTable
{
    /**
     * A rule that declares no verb answers any of these. HEAD and OPTIONS are
     * deliberately absent — see {@see documentedRoutes()}.
     */
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private static ?self $instance = null;

    /** @var string[]|null memoized, keyed by nothing — the table cannot change mid-run */
    private ?array $routes = null;

    public static function load(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Every route the application answers, as `"METHOD /path"`.
     *
     * Three normalisation rules, and they are rules rather than a list of
     * exempt routes — so a new `OPTIONS foo` is silently fine while a new
     * `GET foo` is not, which is the asymmetry we want:
     *
     *  - HEAD and OPTIONS are dropped. They are transport-level; the document
     *    describes semantic operations.
     *  - Routes ending in `/options` are dropped. `yii\rest\UrlRule` emits
     *    these from its `'{id}' => 'options'` and `'' => 'options'` patterns,
     *    which is why `GET /photos` parses at all despite there being no flat
     *    photo collection.
     *  - `<id:\d[\d,]*>` becomes `{id}`. The parameter *name* is kept: the
     *    document distinguishes `/albums/{id}` from `/albums/{albumId}/photos`,
     *    and collapsing both to `{}` would let a renamed parameter through.
     *
     * @return string[]
     */
    public function documentedRoutes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $routes = [];
        foreach ($this->flatten(Yii::$app->urlManager->rules) as $rule) {
            if (str_ends_with($rule->route, '/options')) {
                continue;
            }

            $path = '/' . preg_replace('/<([\w.-]+)(?::[^>]*)?>/', '{$1}', $rule->name);

            foreach ($rule->verb === null || $rule->verb === [] ? self::METHODS : $rule->verb as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $routes[] = strtoupper($method) . ' ' . $path;
            }
        }

        sort($routes);

        return $this->routes = array_values(array_unique($routes));
    }

    /**
     * The route a documented path+method actually resolves to, or null when
     * nothing matches. Path templates are filled with `1`, the only value the
     * `\d+` patterns accept.
     *
     * This asks the real `UrlManager`, which is the only way to catch
     * *shadowing*: `GET albums/my` is declared before the `albums` REST rule,
     * and swapping those two lines leaves every set comparison green while
     * `/albums/my` starts resolving to `albums/view` with `id=my`.
     */
    public function routeFor(string $path, string $method): ?string
    {
        $request = new class () extends Request {
            public string $httpMethod = 'GET';

            public function getMethod(): string
            {
                return $this->httpMethod;
            }
        };
        $request->httpMethod = strtoupper($method);
        $request->setPathInfo(ltrim(preg_replace('/\{[\w.-]+\}/', '1', $path), '/'));

        $result = Yii::$app->urlManager->parseRequest($request);

        return $result === false ? null : $result[0];
    }

    /**
     * Flattens the rule tree into plain {@see UrlRule} objects.
     *
     * `CompositeUrlRule::$rules` is protected and `yii\rest\UrlRule` nests its
     * children one level deeper (`$rules[$urlName][]`), so the descent is both
     * reflective and recursive. Recursing on `is_array()` as well as on the
     * composite type means a flat-vs-nested change upstream costs nothing here.
     *
     * @param  array<UrlRule|CompositeUrlRule|array> $rules
     * @return UrlRule[]
     */
    private function flatten(array $rules): array
    {
        $flat = [];

        foreach ($rules as $rule) {
            if (is_array($rule)) {
                $flat = [...$flat, ...$this->flatten($rule)];
            } elseif ($rule instanceof CompositeUrlRule) {
                $property = new ReflectionProperty(CompositeUrlRule::class, 'rules');
                $flat = [...$flat, ...$this->flatten($property->getValue($rule))];
            } else {
                $flat[] = $rule;
            }
        }

        return $flat;
    }
}
