<?php

namespace App\Services\Benchmark;

class SpeechTranscriptNormalizer
{
    /**
     * @var array<string, int>
     */
    private const ENGLISH_NUMBERS = [
        'zero' => 0,
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
        'thirteen' => 13,
        'fourteen' => 14,
        'fifteen' => 15,
        'sixteen' => 16,
        'seventeen' => 17,
        'eighteen' => 18,
        'nineteen' => 19,
        'twenty' => 20,
        'thirty' => 30,
        'forty' => 40,
        'fifty' => 50,
        'sixty' => 60,
        'seventy' => 70,
        'eighty' => 80,
        'ninety' => 90,
    ];

    /**
     * @var array<string, int>
     */
    private const RUSSIAN_NUMBERS = [
        'ноль' => 0,
        'один' => 1,
        'одна' => 1,
        'два' => 2,
        'две' => 2,
        'двумя' => 2,
        'три' => 3,
        'четыре' => 4,
        'пять' => 5,
        'шесть' => 6,
        'шестью' => 6,
        'семь' => 7,
        'восемь' => 8,
        'восьми' => 8,
        'девять' => 9,
        'десять' => 10,
        'одиннадцать' => 11,
        'двенадцать' => 12,
        'тринадцать' => 13,
        'четырнадцать' => 14,
        'пятнадцать' => 15,
        'шестнадцать' => 16,
        'семнадцать' => 17,
        'восемнадцать' => 18,
        'девятнадцать' => 19,
        'двадцать' => 20,
        'тридцать' => 30,
        'сорок' => 40,
        'пятьдесят' => 50,
        'шестьдесят' => 60,
        'семьдесят' => 70,
        'восемьдесят' => 80,
        'девяносто' => 90,
        'сто' => 100,
        'двести' => 200,
        'триста' => 300,
        'четыреста' => 400,
    ];

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $text = str_replace('ё', 'е', $text);

        $text = $this->normalizeEnglishContractions($text);
        $text = $this->normalizeTimes($text);
        $text = $this->normalizeUnits($text);

        $text = preg_replace(
            '/[^\p{L}\p{N}\s]/u',
            ' ',
            $text,
        ) ?? $text;

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text,
        ) ?? $text;

        $tokens = preg_split(
            '/\s+/u',
            trim($text),
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $tokens = $this->normalizeEnglishNumbers($tokens);
        $tokens = $this->normalizeRussianNumbers($tokens);

        return implode(' ', $tokens);
    }

    private function normalizeEnglishContractions(string $text): string
    {
        return preg_replace(
            "/\bi['’]m\b/u",
            'i am',
            $text,
        ) ?? $text;
    }

    private function normalizeTimes(string $text): string
    {
        $text = preg_replace(
            '/\b(\d{1,2}):00\b/u',
            '$1',
            $text,
        ) ?? $text;

        return preg_replace(
            '/\b(\d{1,2}):(\d{2})\b/u',
            '$1 $2',
            $text,
        ) ?? $text;
    }

    private function normalizeUnits(string $text): string
    {
        $replacements = [
            '/\bkilometers?\b/u' => 'km',
            '/\bkilometres?\b/u' => 'km',

            '/\bкилометр(?:а|ов|ы)?\b/u' => 'км',

            '/\bdegrees?\b/u' => 'degree',
            '/\bградус(?:а|ов|ами|ах)?\b/u' => 'degree',

            '/°/u' => ' degree ',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace(
                $pattern,
                $replacement,
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function normalizeEnglishNumbers(array $tokens): array
    {
        $result = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! array_key_exists($token, self::ENGLISH_NUMBERS)) {
                $result[] = $token;

                continue;
            }

            $currentValue = self::ENGLISH_NUMBERS[$token];

            $nextToken = $tokens[$index + 1] ?? null;
            $nextValue = is_string($nextToken)
                ? (self::ENGLISH_NUMBERS[$nextToken] ?? null)
                : null;

            /*
             * Spoken digit sequences:
             * "five four six" -> "5 4 6", not "15".
             */
            if (
                $currentValue < 20
                && is_int($nextValue)
                && $nextValue < 20
            ) {
                $result[] = (string) $currentValue;

                continue;
            }

            /*
             * Spoken time:
             * "seven thirty" -> "7 30", not "37".
             */
            if (
                $currentValue < 20
                && is_int($nextValue)
                && $nextValue >= 20
            ) {
                $result[] = (string) $currentValue;

                continue;
            }

            $value = 0;
            $consumed = false;

            while ($index < $count) {
                $token = $tokens[$index];

                if ($token === 'hundred') {
                    $value = max(1, $value) * 100;
                    $consumed = true;
                    $index++;

                    if (
                        ($tokens[$index] ?? null) === 'and'
                        && isset($tokens[$index + 1])
                        && array_key_exists(
                            $tokens[$index + 1],
                            self::ENGLISH_NUMBERS,
                        )
                    ) {
                        $index++;
                    }

                    continue;
                }

                if (! array_key_exists($token, self::ENGLISH_NUMBERS)) {
                    break;
                }

                $value += self::ENGLISH_NUMBERS[$token];
                $consumed = true;
                $index++;
            }

            if ($consumed) {
                $result[] = (string) $value;
                $index--;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function normalizeRussianNumbers(array $tokens): array
    {
        $result = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! array_key_exists($token, self::RUSSIAN_NUMBERS)) {
                $result[] = $token;

                continue;
            }

            $currentValue = self::RUSSIAN_NUMBERS[$token];

            $nextToken = $tokens[$index + 1] ?? null;
            $nextValue = is_string($nextToken)
                ? (self::RUSSIAN_NUMBERS[$nextToken] ?? null)
                : null;

            /*
             * Spoken digit sequences:
             * "пять четыре шесть" -> "5 4 6", not "15".
             */
            if (
                $currentValue < 20
                && is_int($nextValue)
                && $nextValue < 20
            ) {
                $result[] = (string) $currentValue;

                continue;
            }

            /*
             * Spoken time:
             * "семь тридцать" -> "7 30", not "37".
             */
            if (
                $currentValue < 20
                && is_int($nextValue)
                && $nextValue >= 20
                && $nextValue < 100
            ) {
                $result[] = (string) $currentValue;

                continue;
            }

            $value = 0;

            while (
                $index < $count
                && array_key_exists(
                    $tokens[$index],
                    self::RUSSIAN_NUMBERS,
                )
            ) {
                $value += self::RUSSIAN_NUMBERS[
                    $tokens[$index]
                ];

                $index++;
            }

            $result[] = (string) $value;
            $index--;
        }

        return $result;
    }
}
