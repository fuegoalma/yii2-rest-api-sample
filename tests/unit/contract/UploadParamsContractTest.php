<?php

namespace tests\unit\contract;

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

    /**
     * A description reworded so the numbers vanish must fail rather than pass
     * on an empty comparison — hence the `preg_match` assertions above.
     */
    private function uploadDescription(): string
    {
        return $this->spec()->operation(self::UPLOAD_PATH, 'POST')['description'] ?? '';
    }
}
