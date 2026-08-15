<?php

namespace app\models\contract\image;

use yii\base\Exception;

/**
 * Turns an image file into the bytes that get stored.
 *
 * The interface is deliberately capability-level: the format, dimensions and
 * quality are the implementation's business, configured once when it is built.
 * Nothing here names an imaging library, so the extension in use is a DI
 * decision rather than something callers have to know about.
 */
interface ImageEncoderInterface
{
    /**
     * @param string $sourcePath absolute path of the file to encode
     * @return string the encoded image bytes
     *
     * @throws Exception when the file does not contain a decodable image.
     *         Implementations MUST translate their library's native failure
     *         into this exception — callers turn it into a 422, so an
     *         untranslated error would surface as a 500 instead. The message
     *         reaches the client, so it must stay free of paths and internals.
     */
    public function encode(string $sourcePath): string;

    /**
     * File extension the encoded bytes are stored under, without a leading dot.
     */
    public function extension(): string;
}
