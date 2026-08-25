<?php

declare(strict_types=1);

namespace app\controllers\basic;

use app\components\ApiSerializer;
use app\components\ConditionalGet;
use app\components\CorrelationId;
use app\components\RateLimiter;
use app\models\form\basic\ApiForm;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\Cors;
use yii\web\Response;
use Yii;

/**
 * Shared plumbing for REST controllers: unified response serializer,
 * JSON-only content negotiation, CORS, optional JWT bearer authentication
 * and request-body validation via form requests.
 */
trait ApiControllerTrait
{
    /**
     * PHP forbids a trait from redeclaring the inherited $serializer
     * property with a different default, so it is assigned here instead.
     */
    public function init(): void
    {
        parent::init();
        $this->serializer = [
            'class' => ApiSerializer::class,
        ];
    }

    /**
     * The authenticator is attached after the CORS filter so preflight
     * OPTIONS requests stay public.
     *
     * @param array<string, mixed> $behaviors the parent's behaviour definitions
     *
     * @return array<string, mixed>
     */
    protected function apiBehaviors(array $behaviors, bool $requireAuth = true): array
    {
        // re-added below (when required) so it runs after the CORS filter
        unset($behaviors['authenticator']);

        $behaviors['contentNegotiator']['formats'] = [
            'application/json' => Response::FORMAT_JSON,
        ];

        // setting up CORS. The origin list is read from params with no fallback
        // here on purpose: a binding that has gone dead must fail loudly rather
        // than silently restore a wildcard nobody chose (the rule ADR 10 states
        // for the encoder's bounding box, applied to a security setting).
        $cors = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => Yii::$app->params['cors_allowed_origins'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
                // A browser hides every response header outside the CORS
                // safelist, and Yii emits this one only when the key is present
                // — so an omission here is silent: the API keeps sending all
                // three and no cross-origin client can read any of them. The
                // list is composed from the emitting components' own constants
                // rather than written out, so it cannot come to name a header
                // that has been renamed or has stopped being sent.
                'Access-Control-Expose-Headers' => [
                    ConditionalGet::HEADER,  // the validator a client sends back
                    RateLimiter::HEADER,     // how long a 429 wants to be left alone
                    CorrelationId::HEADER,   // the id a bug report quotes
                ],
            ],
        ];

        // Revalidation for read endpoints. Attached for every controller using
        // this trait, but it only ever acts on a 200 from a GET, so the write
        // and error paths are untouched.
        $behaviors['conditionalGet'] = ConditionalGet::class;

        if ($requireAuth) {
            $behaviors['authenticator'] = [
                'class' => HttpBearerAuth::class,
                'except' => ['options'],
            ];
        }

        // The CORS filter goes first, not merely before the authenticator:
        // filters run in the order the array declares them, and everything after
        // this line can *refuse* the request — the throttle, the authenticator,
        // the verb filter, content negotiation. A refusal produced ahead of the
        // CORS filter carries no `Access-Control-*` at all, so the browser hands
        // the page nothing to read and the client sees a network error where a
        // 429 with a `Retry-After` was sent.
        //
        // Prepending rather than assigning is the load-bearing part.
        // `yii\rest\Controller::behaviors()` already declares a `rateLimiter`
        // key, so a controller overwriting it (see AuthController) keeps the
        // parent's *position* for it — which was ahead of a `corsFilter`
        // appended at the end. That is how a 429 came to be the one response a
        // browser could not read, on the endpoint whose whole point is telling
        // a client to wait.
        return ['corsFilter' => $cors] + $behaviors;
    }

    /**
     * Loads request data into the form request; a validation failure turns
     * the response into a 422. Defaults to the request body, but index
     * endpoints pass the query params for their search forms.
     *
     * Internal to {@see withValidatedForm()}, which is the one way in — so the
     * status code and the error body can never be set by one caller and not the
     * other.
     *
     * @param array<string, mixed>|null $data null → the request body
     */
    private function validateRequest(ApiForm $form, ?array $data = null): bool
    {
        $form->load($data ?? Yii::$app->request->bodyParams);
        if (!$form->validate()) {
            Yii::$app->response->statusCode = 422;
            return false;
        }
        return true;
    }

    /**
     * Runs $then only if the form validates, otherwise answers with the 422 body.
     *
     * This is the shape of every write action in the API, and it lives on the
     * trait rather than on {@see ApiController} because the two controller
     * hierarchies both need it: the REST resources extend `ApiController`, while
     * `AuthController` and `PermissionsController` extend `yii\rest\Controller`
     * directly and could otherwise only get here by copying the three lines.
     *
     * Generic in the form so the callback is handed back the concrete type it
     * was written against, rather than the abstract one this signature accepts.
     *
     * @template TForm of ApiForm
     *
     * @param TForm $form
     * @param callable(TForm): mixed $then
     * @param array<string, mixed>|null $data null → the request body
     */
    protected function withValidatedForm(ApiForm $form, callable $then, ?array $data = null): mixed
    {
        return $this->validateRequest($form, $data) ? $then($form) : $form->getErrors();
    }

    /**
     * The "it worked and there is nothing to say" answer.
     *
     * One place, because 204 has a rule attached — the body must be empty — and
     * nine hand-written copies are nine chances to return something alongside it.
     */
    protected function noContent(): null
    {
        Yii::$app->response->statusCode = 204;

        return null;
    }
}
