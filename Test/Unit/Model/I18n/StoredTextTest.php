<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\I18n;

use Iranimij\OpenImport\Model\I18n\PhraseSource;
use Iranimij\OpenImport\Model\I18n\StoredText;
use Iranimij\OpenImport\Model\I18n\Translation;
use PHPUnit\Framework\TestCase;

/**
 * Run messages are stored in English and shown in the admin's language.
 */
class StoredTextTest extends TestCase
{
    /**
     * @param array<string, string> $dictionary English phrase => translation
     * @param string[]|null $phrases the module's phrases (default: the dictionary's keys)
     * @param bool $active the admin's language is not English
     */
    private function text(array $dictionary, ?array $phrases = null, bool $active = true): StoredText
    {
        $source = new class ($phrases ?? array_keys($dictionary)) extends PhraseSource {
            /**
             * @param string[] $list
             */
            public function __construct(private readonly array $list)
            {
            }

            public function all(): array
            {
                return $this->list;
            }
        };
        $translation = new class ($dictionary, $active) extends Translation {
            /**
             * @param array<string, string> $dictionary
             */
            public function __construct(private readonly array $dictionary, private readonly bool $active)
            {
            }

            public function isActive(): bool
            {
                return $this->active;
            }

            public function of(string $phrase): ?string
            {
                return $this->dictionary[$phrase] ?? null;
            }
        };

        return new StoredText($source, $translation);
    }

    public function testSentencesOfALineAreTranslatedOneByOne(): void
    {
        $text = $this->text([
            'Dry run: %d new, %d changed, %d unchanged, %d deleted.'
                => 'Probelauf: %1 neu, %2 geändert, %3 unverändert, %4 gelöscht.',
            'Magento accepts the other rows; %d row(s) have errors and will be left out (see below).'
                => 'Magento nimmt die anderen Zeilen an; %1 Zeile(n) haben Fehler und werden ausgelassen (siehe unten).',
            'Nothing was imported.' => 'Nichts wurde importiert.',
        ]);

        self::assertSame(
            'Probelauf: 0 neu, 0 geändert, 1 unverändert, 0 gelöscht. Magento nimmt die anderen Zeilen an; 7 Zeile(n) '
            . 'haben Fehler und werden ausgelassen (siehe unten).',
            $text->translate('Dry run: 0 new, 0 changed, 1 unchanged, 0 deleted. Magento accepts the other rows; '
                . '7 row(s) have errors and will be left out (see below).')
        );
        // A sentence no phrase knows (Magento's own, an older version's) stays as it is, the others are translated.
        self::assertSame(
            "Nichts wurde importiert. Something Magento said. Nichts wurde importiert.\nAnother line.",
            $text->translate("Nothing was imported. Something Magento said. Nothing was imported.\nAnother line.")
        );
    }

    public function testValuesGoWhereTheTranslationPutsThem(): void
    {
        $text = $this->text([
            'Picture %s points to a private or local address.' => 'Bild %1 zeigt auf eine private oder lokale Adresse.',
            '%2 of %1 done.' => 'Erledigt: %2 von %1.',
            'This run would %s %d of %d records (%d%%, limit %d%%).'
                => 'Dieser Lauf würde %2 von %3 Datensätzen %1 (%4 %, Grenze %5 %).',
            'delete' => 'löschen',
        ], null);

        self::assertSame(
            'Bild https://10.0.0.1/a b.jpg zeigt auf eine private oder lokale Adresse.',
            $text->translate('Picture https://10.0.0.1/a b.jpg points to a private or local address.')
        );
        self::assertSame('- Erledigt: 3 von 10.', $text->translate('- 3 of 10 done.'), 'list markers stay');
        // A single-word value ("delete") is not looked up: it may be data such as a SKU.
        self::assertSame(
            'Dieser Lauf würde 44 von 100 Datensätzen delete (44 %, Grenze 10 %).',
            $text->translate('This run would delete 44 of 100 records (44%, limit 10%).')
        );
    }

    public function testAValueThatIsAMessageItselfIsTranslatedToo(): void
    {
        $text = $this->text([
            'Row %1: %2' => 'Zeile %1: %2',
            'the pattern is empty' => 'das Muster ist leer',
            'Unknown transform "%s".' => 'Unbekannte Umwandlung „%1“.',
        ]);

        self::assertSame('Zeile 5: das Muster ist leer', $text->translate('Row 5: the pattern is empty'));
        self::assertSame('Zeile 6: Unbekannte Umwandlung „trim“.', $text->translate('Row 6: Unknown transform "trim".'));
    }

    public function testPlainPhrasesOnlyMatchWholeSentences(): void
    {
        $text = $this->text(['Runs' => 'Läufe', 'Dry run' => 'Probelauf']);

        self::assertSame('Läufe', $text->translate('Runs'));
        self::assertSame('Runs are queued.', $text->translate('Runs are queued.'));
        self::assertSame('Dry run again later.', $text->translate('Dry run again later.'));
    }

    public function testEnglishAdminsSeeTheStoredText(): void
    {
        $text = $this->text(['Nothing was imported.' => 'Nichts wurde importiert.'], null, false);

        self::assertSame('Nothing was imported.', $text->translate('Nothing was imported.'));
        self::assertSame('', $this->text([])->translate(''));
    }

    /**
     * Every phrase of the module's i18n/en_US.csv works as a format: a text built from it is matched by it as a whole
     * (no shorter phrase takes the sentence apart), and its values land in the translation.
     */
    public function testEveryModulePhraseMatchesItsOwnText(): void
    {
        $file = dirname(__DIR__, 4) . '/i18n/en_US.csv';
        $phrases = [];
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $handle = fopen($file, 'rb');
        self::assertTrue($handle !== false);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0]) && is_string($row[0]) && $row[0] !== '') {
                $phrases[] = $row[0];
            }
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fclose($handle);
        $placeholder = '/%(?:%|[1-9]|[-+ 0\',]*\d*(?:\.\d+)?[sdfFgGu])/';
        // Each phrase "translates" to a marker with its values: «phrase number|value|value».
        $dictionary = [];
        $examples = [];
        foreach ($phrases as $index => $phrase) {
            $number = 0;
            $names = [];
            $values = [];
            $example = (string) preg_replace_callback(
                $placeholder,
                static function (array $match) use (&$number, &$names, &$values): string {
                    if ($match[0] === '%%') {
                        return '%';
                    }
                    $number++;
                    $name = preg_match('/^%[1-9]$/', $match[0]) === 1 ? $match[0] : '%' . $number;
                    $value = preg_match('/[du]$/', $match[0]) === 1 ? (string) (40 + $number)
                        : (preg_match('/[fFgG]$/', $match[0]) === 1 ? '4.5' : 'V' . $number);
                    $names[] = $name;
                    $values[$name] = $value;
                    return $value;
                },
                $phrase
            );
            $dictionary[$phrase] = '«' . implode('|', array_merge([(string) $index], $names)) . '»';
            if ($names !== [] || preg_match('/[.;:!?)]$/', $phrase) === 1) {
                $examples[$phrase] = [$example, '«' . implode('|', array_merge([(string) $index], array_values($values))) . '»'];
            }
        }
        $text = $this->text($dictionary, $phrases);
        $failures = [];
        foreach ($examples as $phrase => [$example, $expected]) {
            $result = $text->translate($example);
            // Two phrases may read the same once filled in ("%s." and "Done."): any marker of the same values passes.
            if ($result !== $expected && preg_replace('/^«\d+/', '', $result) !== preg_replace('/^«\d+/', '', $expected)) {
                $failures[] = $phrase . ' => ' . $result;
            }
        }

        self::assertSame([], $failures);
    }
}
