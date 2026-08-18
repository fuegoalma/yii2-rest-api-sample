<?php

declare(strict_types=1);

namespace tests\unit\contract;

use app\components\RequestSizeLimit;
use app\models\form\PhotoCreateForm;
use Yii;

/**
 * Gate 6: the encoding numbers in `config/params.php` are the ones the document
 * publishes.
 *
 * `params.php` says of these three values that they are a published API
 * contract; this is what makes that comment true rather than aspirational.
 *
 * Deliberately **not** part of `tests/unit/ConfigDrivenDefaultsTest.php`, which
 * pins a different and orthogonal rule — that a config-driven parameter
 * declares no default anywhere in the code. That test would stay green if the
 * parameter and the document drifted together; this one would stay green if the
 * encoder grew a default. Both are needed.
 */
final class UploadParamsContractTest extends ContractTestCase
{
    private const string UPLOAD_PATH = '/albums/{albumId}/photos';

    public function testTheDocumentedBoundingBoxMatchesTheConfiguredOne(): void
    {
        // U+00D7, as the document writes it: "scaled to fit 500×500"
        $this->assertSame(
            1,
            preg_match('/(\d+)×(\d+)/u', $this->uploadDescription(), $matches),
            'POST ' . self::UPLOAD_PATH . ' no longer states the bounding box it scales uploads to'
        );

        $this->assertSame((int) $matches[1], (int) Yii::$app->params['photo_max_width']);
        $this->assertSame((int) $matches[2], (int) Yii::$app->params['photo_max_height']);
    }

    public function testTheDocumentedQualityMatchesTheConfiguredOne(): void
    {
        $this->assertSame(
            1,
            preg_match('/quality (\d+)/', $this->uploadDescription(), $matches),
            'POST ' . self::UPLOAD_PATH . ' no longer states the quality it encodes uploads at'
        );

        $this->assertSame((int) $matches[1], (int) Yii::$app->params['photo_quality']);
    }

    public function testTheDocumentedSizeLimitMatchesTheConfiguredOne(): void
    {
        $this->assertSame(
            1,
            preg_match('/at most (\d+) bytes/', $this->uploadDescription(), $matches),
            'POST ' . self::UPLOAD_PATH . ' no longer states the largest upload it accepts'
        );

        $this->assertSame((int) $matches[1], (int) Yii::$app->params['photo_max_upload_bytes']);
    }

    /**
     * The form's rule is what actually rejects an oversized file, so the number
     * the document publishes has to be the one the rule was given — not merely
     * the one sitting in params.php next to it.
     */
    public function testTheUploadFormEnforcesTheDocumentedSizeLimit(): void
    {
        $maxSize = null;

        foreach ((new PhotoCreateForm())->rules() as $rule) {
            if (($rule[1] ?? null) === 'file' && isset($rule['maxSize'])) {
                $maxSize = (int) $rule['maxSize'];
            }
        }

        $this->assertNotNull($maxSize, 'PhotoCreateForm no longer caps the upload size at all');
        $this->assertSame((int) Yii::$app->params['photo_max_upload_bytes'], $maxSize);
    }

    /**
     * PHP discards a body over `post_max_size` before any rule can run, so a
     * form limit above it would be unreachable and the caller would get the
     * generic 413 instead of a message naming the file.
     */
    public function testPhpWouldNotDiscardAnUploadTheFormWouldAccept(): void
    {
        $postMax = RequestSizeLimit::parseSize((string) ini_get('post_max_size'));
        $uploadMax = RequestSizeLimit::parseSize((string) ini_get('upload_max_filesize'));

        $this->assertGreaterThanOrEqual((int) Yii::$app->params['photo_max_upload_bytes'], $uploadMax);
        $this->assertGreaterThan($uploadMax, $postMax);
    }

    /**
     * A description reworded so the numbers vanish must fail rather than pass
     * on an empty comparison — hence the `preg_match` assertions above.
     */
    private function uploadDescription(): string
    {
        return $this->spec()->operation(self::UPLOAD_PATH, 'POST')['description'] ?? '';
    }
}
