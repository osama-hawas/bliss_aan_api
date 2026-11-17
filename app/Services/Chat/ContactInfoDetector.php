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

    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    private const URL_PATTERN = '~(?:https?://\S+|\bwww\.\S+|\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.(?:com|net|org|sa|me|io|co|app|link|ly|info|biz|store|shop)\b)~i';

    /** A run of digit groups joined by spaces, `-`, `.`, `(`, `)`, optionally led by `+`. */
    private const RUN_PATTERN = '/\+?\(?\d+(?:[\s\-.()]+\d+)*/';

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
        $text = self::normalizeDigits($body);
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

    private function hasPhone(string $text): bool
    {
        preg_match_all(self::RUN_PATTERN, $text, $runs);
        foreach ($runs[0] as $run) {
            $plus = str_starts_with(ltrim($run, '('), '+');
            preg_match_all('/\d+/', $run, $m);
            $groups = $m[0];
            $count = count($groups);
            for ($i = 0; $i < $count; $i++) {
                $digits = '';
                for ($j = $i; $j < $count; $j++) {
                    $digits .= $groups[$j];
                    if (strlen($digits) > 16) {
                        break;
                    }
                    $candidate = ($plus && $i === 0 ? '+' : '').$digits;
                    foreach (self::PHONE_PATTERNS as $pattern) {
                        if (preg_match($pattern, $candidate)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** Arabic-Indic (٠–٩) and Persian (۰–۹) digits → ASCII. */
    private static function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
