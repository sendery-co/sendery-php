<?php

namespace Sendery;

final class Attachment implements \JsonSerializable
{
    public function __construct(public readonly string $filename, private readonly string $content, public readonly string $contentType = 'application/octet-stream')
    {
        if ($content === '' || strlen($content) > 5242880) {
            throw new \InvalidArgumentException('Attachments must contain 1 to 5,242,880 bytes.');
        }
    }

    public static function fromPath(string $path, ?string $filename = null, string $contentType = 'application/octet-stream'): self
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException('Unable to read attachment.');
        }
        try {
            $content = stream_get_contents($stream, 5242881);
            if ($content === false) {
                throw new \InvalidArgumentException('Unable to read attachment.');
            }

            return new self($filename ?? basename($path), $content, $contentType);
        } finally {
            fclose($stream);
        }
    }

    public function size(): int
    {
        return strlen($this->content);
    }

    public function jsonSerialize(): array
    {
        return ['filename' => $this->filename, 'content' => base64_encode($this->content), 'content_type' => $this->contentType];
    }
}
