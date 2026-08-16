<?php

namespace tests\unit\contract;

use Yii;

/**
 * Gate 1: the route table and the OpenAPI document describe the same API.
 *
 * Both directions, by two different mechanisms — a set difference for
 * "implemented but undocumented", and the real `UrlManager` for "documented but
 * not reachable". The second cannot be replaced by a second set difference: a
 * rule can be present and still be shadowed by one declared before it.
 */
final class RouteContractTest extends ContractTestCase
{
    public function testEveryRoutableOperationIsDocumented(): void
    {
        $documented = array_keys($this->spec()->operations());
        $routable = $this->routes()->documentedRoutes();

        $this->assertNotEmpty($routable, 'The route table is empty — the application did not boot.');

        $this->assertSame(
            [],
            array_values(array_diff($routable, $documented)),
            'Routable via config/url_rules.php but absent from config/openapi.yaml'
        );
    }

    public function testEveryDocumentedOperationIsRoutable(): void
    {
        $unreachable = [];

        foreach (array_keys($this->spec()->operations()) as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $route = $this->routes()->routeFor($path, $method);

            if ($route === null) {
                $unreachable[] = "$operation → no rule matches";
            } elseif (str_ends_with($route, '/options')) {
                // Parsed, but only by the CORS catch-all `yii\rest\UrlRule`
                // emits — so the operation is documented and not actually served.
                $unreachable[] = "$operation → resolved to $route";
            }
        }

        $this->assertSame([], $unreachable, 'Documented in config/openapi.yaml but not routable');
    }

    /**
     * A typo in a route string is something both set comparisons above would
     * happily agree on, because they compare paths and methods rather than
     * targets.
     */
    public function testEveryDocumentedOperationTargetsAnExistingAction(): void
    {
        $missing = [];

        foreach (array_keys($this->spec()->operations()) as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $route = $this->routes()->routeFor($path, $method);

            if ($route === null) {
                continue; // already reported by testEveryDocumentedOperationIsRoutable
            }

            $parts = Yii::$app->createController($route);
            if ($parts === false) {
                $missing[] = "$operation → controller for \"$route\" does not exist";
                continue;
            }

            [$controller, $actionId] = $parts;
            if ($controller->createAction($actionId) === null) {
                $missing[] = sprintf(
                    '%s → %s has no action "%s"',
                    $operation,
                    $controller::class,
                    $actionId
                );
            }
        }

        $this->assertSame([], $missing, 'Documented routes with no action behind them');
    }
}
