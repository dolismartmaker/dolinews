<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

/**
 * A Markdown body split into blocks, some to translate, some to leave
 * exactly as they are (SPEC 5.7).
 *
 * An announcement about a Dolibarr module almost always carries code: a
 * configuration line, a command, an excerpt of a module descriptor. An
 * engine translates those like prose, and `need_dolibarr_version`
 * becomes a sentence in Polish. Sending the body as one block makes that
 * certain; splitting it and holding the fenced code out makes it
 * impossible.
 *
 * The split has a second effect, on the engine side: a paragraph left
 * untouched by a correction is byte-identical to the one translated last
 * month, so an engine that caches per segment does not pay for it twice.
 * That is a benefit, not the reason -- the reason is correctness.
 *
 * Blocks are separated on blank lines, which keeps a list, a table or a
 * quotation whole: their lines are consecutive, and cutting them apart
 * would hand the engine fragments it cannot read. Only FENCED code is
 * held out, never the four-space indentation, which a nested list uses
 * just as much as a code block.
 */
class MarkdownSegments
{
    /**
     * @param  array<int, array{text: string, translatable: bool}>  $blocks
     */
    private function __construct(
        private readonly array $blocks,
    ) {}

    /**
     * Split a body into blocks.
     */
    public static function split(string $body): self
    {
        $blocks = [];
        $current = [];
        $fence = null;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = ltrim($line);

            if ($fence === null && preg_match('/^(`{3,}|~{3,})/', $trimmed, $match) === 1) {
                // A fence opens: whatever was being gathered is a block of
                // its own, and the fence starts a verbatim one.
                $blocks = self::flush($blocks, $current, true);
                $current = [$line];
                $fence = $match[1][0];

                continue;
            }

            if ($fence !== null) {
                $current[] = $line;

                if (preg_match('/^'.preg_quote($fence, '/').'{3,}\s*$/', $trimmed) === 1) {
                    $blocks = self::flush($blocks, $current, false);
                    $current = [];
                    $fence = null;
                }

                continue;
            }

            if (trim($line) === '') {
                $blocks = self::flush($blocks, $current, true);
                $current = [];
                $blocks[] = ['text' => $line, 'translatable' => false];

                continue;
            }

            $current[] = $line;
        }

        // An unterminated fence stays verbatim: a body whose code block
        // was never closed is malformed, and translating it would make it
        // worse rather than repair it.
        $blocks = self::flush($blocks, $current, $fence === null);

        return new self($blocks);
    }

    /**
     * The texts to hand to the engine, in order.
     *
     * @return array<int, string>
     */
    public function translatableTexts(): array
    {
        $texts = [];

        foreach ($this->blocks as $block) {
            if ($block['translatable']) {
                $texts[] = $block['text'];
            }
        }

        return $texts;
    }

    /**
     * Put the body back together from the translated blocks, in the
     * order translatableTexts() handed them out.
     *
     * @param  array<int, string>  $translations
     */
    public function reassemble(array $translations): string
    {
        $translations = array_values($translations);
        $lines = [];
        $index = 0;

        foreach ($this->blocks as $block) {
            if (! $block['translatable']) {
                $lines[] = $block['text'];

                continue;
            }

            // A block the engine did not return keeps its source text:
            // the caller refuses the whole version anyway, and silently
            // dropping it would produce a body with holes.
            $lines[] = $translations[$index] ?? $block['text'];
            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Close the block being gathered, if any.
     *
     * @param  array<int, array{text: string, translatable: bool}>  $blocks
     * @param  array<int, string>  $current
     * @return array<int, array{text: string, translatable: bool}>
     */
    private static function flush(array $blocks, array $current, bool $translatable): array
    {
        if ($current === []) {
            return $blocks;
        }

        $blocks[] = ['text' => implode("\n", $current), 'translatable' => $translatable];

        return $blocks;
    }
}
