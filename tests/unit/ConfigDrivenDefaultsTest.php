<?php

declare(strict_types=1);

namespace tests\unit;

use app\components\image\ImagickWebpEncoder;
use app\components\JwtService;
use app\models\contract\queue\QueueInterface;
use app\models\contract\repository\OneTimeTokenRepositoryInterface;
use app\models\contract\repository\RefreshTokenRepositoryInterface;
use app\models\contract\repository\UserRepositoryInterface;
use app\models\service\basic\OneTimeTokenFlow;
use app\models\service\EmailVerificationService;
use app\models\service\PasswordService;
use app\models\service\RefreshTokenService;
use ArgumentCountError;
use yii\base\InvalidConfigException;

/**
 * One standing rule, checked in one place: a parameter whose value comes from
 * `config/` declares **no default**.
 *
 * The point is the failure mode. A default turns a binding that has silently
 * gone dead — a renamed env variable, a dropped `__construct()` entry in
 * config/di.php — into a service that keeps running on a hard-coded number
 * nobody chose, and the only symptom is behaviour drifting away from the
 * published contract. Without a default the container fails at construction,
 * immediately and unmistakably.
 *
 * These are contract tests for the parameter lists, not for the services'
 * behaviour: each service's own test class covers what it does. They live
 * together because the rule is one rule, and a new config-driven service
 * should be added here rather than growing a third copy of the same assertion.
 */
class ConfigDrivenDefaultsTest extends BaseUnitTest
{
    /**
     * The bounding box and quality are a published OpenAPI contract
     * (config/params.php), which is why this encoder set the precedent the
     * other two now follow.
     */
    public function testImageEncoderRequiresItsBoundingBoxAndQualityExplicitly(): void
    {
        $this->expectException(ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (that is the assertion) */
        new ImagickWebpEncoder(maxWidth: 500, maxHeight: 500);
    }

    /**
     * $ttl comes from JWT_REFRESH_TTL via config/di.php.
     */
    public function testRefreshTokenServiceRequiresTtlToBeSuppliedExplicitly(): void
    {
        $this->expectException(ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (that is the assertion) */
        new RefreshTokenService($this->createStub(RefreshTokenRepositoryInterface::class));
    }

    /**
     * $ttl comes from PASSWORD_RESET_TTL via config/di.php. Deliberately short:
     * a default here would quietly extend the life of a bearer credential
     * sitting in an inbox, which is the one thing this value exists to bound.
     */
    public function testPasswordServiceRequiresTtlToBeSuppliedExplicitly(): void
    {
        $this->expectException(ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (that is the assertion) */
        new PasswordService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(OneTimeTokenRepositoryInterface::class),
            $this->createStub(RefreshTokenRepositoryInterface::class),
            $this->createStub(QueueInterface::class),
            $this->oneTimeTokenFlow(),
        );
    }

    /**
     * $ttl comes from EMAIL_VERIFICATION_TTL via config/di.php. It is longer
     * than the reset above, which is exactly why neither may default: two
     * lifetimes that differ on purpose are two lifetimes that must both be
     * stated.
     */
    public function testEmailVerificationServiceRequiresTtlToBeSuppliedExplicitly(): void
    {
        $this->expectException(ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (that is the assertion) */
        new EmailVerificationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(QueueInterface::class),
            $this->oneTimeTokenFlow(),
        );
    }

    /** The shared token machinery both services above take, with nothing behind it. */
    private function oneTimeTokenFlow(): OneTimeTokenFlow
    {
        return new OneTimeTokenFlow(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(OneTimeTokenRepositoryInterface::class),
        );
    }

    /**
     * JwtService is a yii\base\Component, configured with an array rather than
     * constructor arguments, so "no default" is expressed as an uninitialised
     * typed property plus an init() guard — the same guard the secret already
     * has. A missing JWT_TTL must not silently become an hour.
     */
    public function testJwtServiceRequiresTtlToBeSuppliedExplicitly(): void
    {
        $this->expectException(InvalidConfigException::class);

        new JwtService(['secret' => 'a-secret-that-is-long-enough-for-hs256']);
    }
}
