<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\RateLimiter;
use app\controllers\basic\ApiControllerTrait;
use app\models\contract\service\AuthServiceInterface;
use app\models\db\User;
use app\models\form\LoginForm;
use app\models\contract\service\EmailVerificationInterface;
use app\models\contract\service\PasswordServiceInterface;
use app\models\form\ForgotPasswordForm;
use app\models\form\RefreshTokenForm;
use app\models\form\ResetPasswordForm;
use app\models\form\VerifyEmailForm;
use app\models\form\UserCreateForm;
use Yii;
use yii\rest\Controller;

class AuthController extends Controller
{
    use ApiControllerTrait;

    public function __construct(
        $id,
        $module,
        private readonly AuthServiceInterface $service,
        private readonly PasswordServiceInterface $passwords,
        private readonly EmailVerificationInterface $verification,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        // the auth endpoints stay public: they are what issue the tokens
        $behaviors = $this->apiBehaviors(parent::behaviors(), requireAuth: false);

        // brute-force protection: attempts are throttled per client IP,
        // with an independent counter per action (login/register/refresh)
        $behaviors['rateLimiter'] = RateLimiter::class;

        return $behaviors;
    }

    public function actionLogin(): mixed
    {
        return $this->withValidatedForm(
            new LoginForm(),
            fn (LoginForm $form) => $this->service->login($form->email, $form->password)->toArray()
        );
    }

    public function actionRegister(): mixed
    {
        return $this->withValidatedForm(new UserCreateForm(), function (UserCreateForm $form) {
            $result = $this->service->register($form->validatedData());

            // a persistence-level validation failure still surfaces as a 422
            if ($result instanceof User) {
                Yii::$app->response->statusCode = 422;
                return $result->getErrors();
            }

            Yii::$app->response->statusCode = 201;
            return $result->toArray();
        });
    }

    public function actionRefresh(): mixed
    {
        return $this->withValidatedForm(
            new RefreshTokenForm(),
            fn (RefreshTokenForm $form) => $this->service->refresh($form->refresh_token)->toArray()
        );
    }

    public function actionLogout(): mixed
    {
        // revoking the current device's session is idempotent → 204
        return $this->withValidatedForm(
            new RefreshTokenForm(),
            function (RefreshTokenForm $form): null {
                $this->service->logout($form->refresh_token);

                return $this->noContent();
            }
        );
    }

    public function actionLogoutAll(): mixed
    {
        return $this->withValidatedForm(
            new RefreshTokenForm(),
            function (RefreshTokenForm $form): null {
                $this->service->logoutAll($form->refresh_token);

                return $this->noContent();
            }
        );
    }

    /**
     * Always 204, whether or not the address is registered. Answering
     * differently would make this the account-enumeration oracle that
     * AuthService::login() takes trouble to avoid being.
     */
    public function actionForgotPassword(): mixed
    {
        return $this->withValidatedForm(
            new ForgotPasswordForm(),
            function (ForgotPasswordForm $form): null {
                $this->passwords->requestReset((string) $form->email);

                return $this->noContent();
            }
        );
    }

    public function actionResetPassword(): mixed
    {
        // the reset ends every session, so there is no token pair to hand back:
        // the caller logs in with the password they have just chosen
        return $this->withValidatedForm(
            new ResetPasswordForm(),
            function (ResetPasswordForm $form): null {
                $this->passwords->reset((string) $form->token, (string) $form->password);

                return $this->noContent();
            }
        );
    }

    /**
     * Public: the token *is* the proof, and requiring a session as well would
     * break the common case of opening the link in a different browser.
     */
    public function actionVerifyEmail(): mixed
    {
        return $this->withValidatedForm(
            new VerifyEmailForm(),
            function (VerifyEmailForm $form): null {
                $this->verification->verify((string) $form->token);

                return $this->noContent();
            }
        );
    }

    /** @return array<string, list<string>> */
    protected function verbs(): array
    {
        return [
            'login' => ['POST', 'OPTIONS'],
            'register' => ['POST', 'OPTIONS'],
            'refresh' => ['POST', 'OPTIONS'],
            'logout' => ['POST', 'OPTIONS'],
            'logout-all' => ['POST', 'OPTIONS'],
            'forgot-password' => ['POST', 'OPTIONS'],
            'reset-password' => ['POST', 'OPTIONS'],
            'verify-email' => ['POST', 'OPTIONS'],
        ];
    }
}
