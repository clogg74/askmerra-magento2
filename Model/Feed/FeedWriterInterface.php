<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Feed;

use Magento\Framework\Filesystem\File\WriteInterface;

/** Streams a feed file product by product, so its size never matters to PHP's memory. */
interface FeedWriterInterface
{
    public function getExtension(): string;

    /**
     * @param array $meta generator, generated_at, store, locale, currency; for Google XML also
     *                    attribute_map (attribute label => Google element name)
     */
    public function start(WriteInterface $file, array $meta): void;

    /** @param string $productJson one product, as built for the Push API */
    public function add(string $productJson): void;

    public function finish(int $count): void;
}
