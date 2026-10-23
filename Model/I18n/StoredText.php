<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\I18n;

/**
 * Translates texts that were stored in English when a run happened (run summaries, error messages, preview
 * problems) into the language of the admin who reads them.
 *
 * Runs happen in cron, where no admin language is known, so every message is stored in English, the way the code
 * writes it (sprintf formats such as "%d row(s) read."). Each format is also a phrase of i18n/en_US.csv. Shown in
 * another language, a line is cut into sentences; each sentence is matched against those formats, and its values
 * go into the translated phrase (%1, %2 … in the order of the format's placeholders). Sentences no format matches
 * (a value Magento wrote, a message of an older version) stay as they are.
 */
class StoredText
{
    /**
     * A format's placeholders: sprintf (%s, %d, %.1f …) or Magento's (%1 … %9). "%%" is a literal percent sign.
     */
    private const PLACEHOLDER = '/%(?:%|[1-9]|[-+ 0\',]*\d*(?:\.\d+)?[sdfFgGu])/';

    /**
     * Where a sentence may end inside a line: after . ; : ! ? or ) followed by a space.
     */
    private const SENTENCE_END = '/[.;:!?)](?=\s)/';

    /**
     * @var array<string, list<array{0: string, 1: string, 2: list<string>, 3: int}>>|null formats by their first character:
     *      [regex, phrase, the placeholder each captured value fills in the translation, length of its fixed text]
     */
    private ?array $formats = null;

    /**
     * @param PhraseSource $phrases
     * @param Translation $translation
     */
    public function __construct(
        private readonly PhraseSource $phrases,
        private readonly Translation $translation
    ) {
    }

    /**
     * Translates every line of a stored text.
     *
     * @param string $text
     * @return string
     */
    public function translate(string $text): string
    {
        if ($text === '' || !$this->translation->isActive()) {
            return $text;
        }

        return implode("\n", array_map(fn (string $line): string => $this->line($line), explode("\n", $text)));
    }

    /**
     * @param string $line
     * @return string
     */
    private function line(string $line): string
    {
        if (trim($line) === '') {
            return $line;
        }
        $exact = $this->translation->of($line);
        if ($exact !== null) {
            return $exact;
        }
        // A list marker or indentation stays in front.
        preg_match('/^[\s•–-]*/u', $line, $lead);
        $position = strlen($lead[0]);
        $result = $lead[0];
        $length = strlen($line);
        while ($position < $length) {
            $match = $this->sentenceAt($line, $position);
            if ($match !== null) {
                [$translated, $end] = $match;
                $result .= $translated;
                $position = $end;
            } else {
                $end = preg_match(self::SENTENCE_END, $line, $found, PREG_OFFSET_CAPTURE, $position) === 1
                    ? (int) $found[0][1] + 1 : $length;
                $result .= substr($line, $position, $end - $position);
                $position = $end;
            }
            // The spaces between two sentences.
            if (preg_match('/\G\s+/', $line, $space, 0, $position) === 1) {
                $result .= $space[0];
                $position += strlen($space[0]);
            }
        }

        return $result;
    }

    /**
     * The longest format that matches a whole sentence from this position.
     *
     * @param string $line
     * @param int $position
     * @return array{0: string, 1: int}|null translated sentence, position after it
     */
    private function sentenceAt(string $line, int $position): ?array
    {
        $best = null;
        $bestScore = [-1, 0];
        $first = $line[$position];
        foreach ([$this->formats()[$first] ?? [], $this->formats()[''] ?? []] as $candidates) {
            foreach ($candidates as [$regex, $phrase, $names, $fixed]) {
                if (preg_match($regex, $line, $match, 0, $position) !== 1) {
                    continue;
                }
                $end = $position + strlen($match[0]);
                // The most specific phrase wins (the most fixed text matched), then the longer match: "The folder %s"
                // must not take "The folder %s does not exist." apart.
                $score = [$fixed, $end];
                if ($score <= $bestScore) {
                    continue;
                }
                $translated = $this->translation->of($phrase);
                if ($translated === null) {
                    continue;
                }
                $values = [];
                foreach ($names as $index => $name) {
                    $value = $match[$index + 1] ?? '';
                    // A value that is a sentence itself (a nested message) is translated too.
                    $values[$name] = str_contains($value, ' ') ? $this->line($value) : $value;
                }
                $best = [strtr($translated, $values), $end];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Regular expressions of all phrases with placeholders, or ending a sentence, grouped by first character.
     *
     * @return array<string, list<array{0: string, 1: string, 2: list<string>, 3: int}>>
     */
    private function formats(): array
    {
        if ($this->formats !== null) {
            return $this->formats;
        }
        $this->formats = [];
        foreach ($this->phrases->all() as $phrase) {
            preg_match_all(self::PLACEHOLDER, $phrase, $found);
            $placeholders = $found[0];
            // sprintf's values are numbered in order; Magento's carry their number (%2 may come before %1).
            $names = [];
            $next = 0;
            foreach ($placeholders as $placeholder) {
                if ($placeholder !== '%%') {
                    $names[] = preg_match('/^%[1-9]$/', $placeholder) === 1 ? $placeholder : '%' . ++$next;
                }
            }
            // Plain phrases count only when they end a sentence: "Runs" must not match the start of "Runs are …".
            if ($names === [] && preg_match('/[.;:!?)]$/', $phrase) !== 1) {
                continue;
            }
            $literals = (array) preg_split(self::PLACEHOLDER, $phrase);
            $regex = '';
            foreach ($literals as $index => $literal) {
                $regex .= preg_quote((string) $literal, '/');
                if (isset($placeholders[$index])) {
                    $last = $index === count($literals) - 2 && $literals[$index + 1] === '';
                    $regex .= $this->pattern($placeholders[$index], $last);
                }
            }
            $startsWithValue = $names !== [] && preg_match(self::PLACEHOLDER, $phrase, $start) === 1
                && str_starts_with($phrase, $start[0]) && $start[0] !== '%%';
            // A sentence ends at the end of the line or before a space.
            $this->formats[$startsWithValue ? '' : $phrase[0]][] = [
                '/\G' . $regex . '(?=\s|$)/su',
                $phrase,
                $names,
                strlen((string) preg_replace(self::PLACEHOLDER, '', $phrase)),
            ];
        }

        return $this->formats;
    }

    /**
     * @param string $placeholder
     * @param bool $last the phrase ends with this value: it takes the rest of the line
     * @return string
     */
    private function pattern(string $placeholder, bool $last): string
    {
        if ($placeholder === '%%') {
            return '%';
        }
        if (preg_match('/^%[-+ 0\',]*\d*[du]$/', $placeholder) === 1) {
            return '([-+]?\d[\d,.\']*)';
        }
        if (preg_match('/[fFgG]$/', $placeholder) === 1) {
            return '([-+]?[\d.,]+(?:[eE][-+]?\d+)?)';
        }

        return $last ? '(.+)' : '(.+?)';
    }
}
