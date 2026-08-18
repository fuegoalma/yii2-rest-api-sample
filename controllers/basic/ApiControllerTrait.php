<?php

declare(strict_types=1);

namespace app\controllers\basic;

use app\components\ApiSerializer;
use app\components\ConditionalGet;
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
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => Yii::$app->params['cors_allowed_origins'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
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

        return $behaviors;
    }

    /**
     * Loads request data into the form request; a validation failure turns
     * the response into a 422. Defaults to the request body, but index
     * endpoints pass the query params for their search forms.
     *
     * @param array<string, mixed>|null $data null → the request body
     */
    protected function validateRequest(ApiForm $form, ?array $data = null): bool
    {
        $form->load($data ?? Yii::$app->request->bodyParams);
        if (!$form->validate()) {
            Yii::$app->response->statusCode = 422;
            return false;
        }
        return true;
    }
}
