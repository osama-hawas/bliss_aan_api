<?php

namespace App\Services\Chat;

/**
 * S5 → D246: finds contact details in a chat message body (phone number, email address, URL). Warning only — the
 * caller stores `messages.flagged`; nothing is blocked. Rules: slice-close-leftovers-contract.md §1.1. The Flutter
 * `ContactInfoDetector` mirrors these rules; keep both in step.
 */
class ContactInfoDetector
{
    public const PHONE = 'phone';

    public const EMAIL = 'email';

    public const URL = 'url';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';

    private const URL_PATTERN = '~(?:https?://\S+|\bwww\.\S+|\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.(?:com|net|org|sa|me|io|co|app|link|ly|info|biz|store|shop)\b)~iu';

    /** A dash with a space on either side separates two values (a range): a number never spans it. */
    private const SPACED_DASH = '/\s+[-–—]\s*|\s*[-–—]\s+/u';

    /** A run of digit groups joined by spaces, `-`, `.`, `(`, `)`, optionally led by `+`. */
    private const RUN_PATTERN = '/\+?\(?\d+(?:[\s\-.()]+\d+)*/u';

    /** Each candidate is the digits of consecutive groups, with a leading `+` kept when the run had one. */
    private const PHONE_PATTERNS = [
        '/^(?:\+?966|00966|0)?5\d{8}$/',   // Saudi mobile
        '/^(?:\+?966|00966|0)1\d{8}$/',    // Saudi landline (01X + 7 digits)
        '/^(?:9200|800)\d{5,6}$/',         // unified / toll-free
        '/^(?:\+|00)\d{8,15}$/',           // international
    ];

    /**
     * @return list<string> the kinds found (`phone`, `email`, `url`), empty when clean
     */
    public function detect(?string $body): array
    {
        if ($body === null || trim($body) === '') {
            return [];
        }
        $text = self::normalize($body);
        $kinds = [];

        if (preg_match(self::EMAIL_PATTERN, $text)) {
            $kinds[] = self::EMAIL;
        }
        // Emails also look like domains: test URLs on the text without them.
        if (preg_match(self::URL_PATTERN, (string) preg_replace(self::EMAIL_PATTERN, ' ', $text))) {
            $kinds[] = self::URL;
        }
        if ($this->hasPhone($text)) {
            $kinds[] = self::PHONE;
        }

        return $kinds;
    }

    public function flags(?string $body): bool
    {
        return $this->detect($body) !== [];
    }

    /**
     * A candidate (contract §1.1): never spans a spaced dash; starts at a group beginning with `+`, `0` (incl. `00`),
     * `5`, `9200` or `800`; has at least 9 digits; never crosses a year-like group (4 digits from 19/20) or a dash
     * joining two groups of 4+ digits (a range such as 5000-10000).
     */
    private function hasPhone(string $text): bool
    {
        preg_match_all(self::RUN_PATTERN, (string) preg_replace(self::SPACED_DASH, ' | ', $text), $runs);
        foreach ($runs[0] as $run) {
            $plus = str_starts_with(ltrim($run, '('), '+');
            preg_match_all('/(\D*)(\d+)/u', $run, $m);
            [$seps, $groups] = [$m[1], $m[2]];
            $count = count($groups);
            for ($i = 0; $i < $count; $i++) {
                $prefix = $plus && $i === 0 ? '+' : '';
                if (! self::canStart($groups[$i], $prefix)) {
                    continue;
                }
                $digits = '';
                for ($j = $i; $j < $count; $j++) {
                    if (self::isYear($groups[$j])) {
                        break;
                    }
                    if ($j > $i && str_contains($seps[$j], '-') && strlen($groups[$j - 1]) >= 4 && strlen($groups[$j]) >= 4) {
                        break;
                    }
                    $digits .= $groups[$j];
                    if (strlen($digits) > 16) {
                        break;
                    }
                    if (strlen($digits) < 9) {
                        continue;
                    }
                    foreach (self::PHONE_PATTERNS as $pattern) {
                        if (preg_match($pattern, $prefix.$digits)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private static function canStart(string $group, string $prefix): bool
    {
        return $prefix === '+' || preg_match('/^(?:0|5|9200|800)/', $group) === 1;
    }

    private static function isYear(string $group): bool
    {
        return preg_match('/^(?:19|20)\d{2}$/', $group) === 1;
    }

    /** Arabic-Indic (٠–٩) and Persian (۰–۹) digits → ASCII; no-break spaces (U+00A0, U+202F) → plain spaces. */
    private static function normalize(string $text): string
    {
        return strtr($text, [
            "\u{00A0}" => ' ', "\u{202F}" => ' ',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
