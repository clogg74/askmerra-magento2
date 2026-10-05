<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Feed;

use Magento\Framework\Filesystem\File\WriteInterface;

/**
 * The JSON feed: {"products": [...]} with every product exactly as the Push API would receive it.
 * AskMerra maps these field names directly and merges the nested "attributes" object as it is, so
 * every selected attribute arrives.
 */
class JsonWriter implements FeedWriterInterface
{
    private ?WriteInterface $file = null;

    private bool $first = true;

    public function getExtension(): string
    {
        return 'json';
    }

    public function start(WriteInterface $file, array $meta): void
    {
        $this->file = $file;
        $this->first = true;

        $header = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // {"generator":...,"store":... then the products
        $file->write(substr((string) $header, 0, -1) . ',"products":[');
    }

    public function add(string $productJson): void
    {
        $this->file?->write(($this->first ? "\n" : ",\n") . $productJson);
        $this->first = false;
    }

    public function finish(int $count): void
    {
        $this->file?->write("\n],\"count\":" . $count . "}\n");
    }
}
