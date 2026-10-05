<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

/**
 * Text as AskMerra wants it. The Push API stores descriptions as they arrive - it does not convert
 * HTML the way its feed import does - so every text is turned into plain text here.
 */
class TextCleaner
{
    /**
     * HTML, Page Builder markup and Magento directives ({{widget ...}}) to plain text, cut to
     * $maxLength characters; null when nothing is left.
     */
    public function toPlainText(?string $html, int $maxLength): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = preg_replace('/\{\{[^}]*\}\}/', ' ', $html) ?? $html;
        $text = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#<br\s*/?>|</(p|div|li|ul|ol|h[1-6]|tr|table|section|article)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n[\s]*/u', "\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $this->limit($text, $maxLength);
    }

    /** One line of text (names, labels): tags removed, whitespace collapsed, cut to $maxLength. */
    public function toLine(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $this->limit($text, $maxLength);
    }

    /**
     * Cuts text to $maxLength as AskMerra counts it - in UTF-16 code units, like JavaScript - so
     * an emoji counts twice.
     */
    public function limit(string $text, int $maxLength): string
    {
        // UTF-8 never takes fewer bytes than UTF-16 code units.
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $units = 0;
        $result = '';

        foreach (mb_str_split($text) as $char) {
            $units += strlen($char) === 4 ? 2 : 1;

            if ($units > $maxLength) {
                break;
            }

            $result .= $char;
        }

        return rtrim($result);
    }
}
