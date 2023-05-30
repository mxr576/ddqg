<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\UpdateStatusApi;

use Prewk\XmlStringStreamer\StreamInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;

/**
 * @internal
 */
final class XmlStreamBridgeForPsrStream implements StreamInterface
{
    private PsrStreamInterface $stream;

    private int $chunkSize;

    private int $readBytes = 0;

    /**
     * Constructs a new object.
     */
    public function __construct(PsrStreamInterface $stream, int $chunkSize = 1024)
    {
        $this->stream = $stream;
        $this->chunkSize = $chunkSize;
    }

    public function getChunk(): string|bool
    {
        if (!$this->stream->eof()) {
            $buffer = $this->stream->read($this->chunkSize);
            $this->readBytes += strlen($buffer);

            return $buffer;
        }

        return false;
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function rewind(): void
    {
        if (false === $this->isSeekable()) {
            throw new \Exception('Attempted to rewind an unseekable stream.');
        }

        $this->readBytes = 0;
        $this->stream->rewind();
    }
}
