<?php

namespace WhatsappBridge\Utils;

class Formatter
{
    /**
     * Formats a phone number to E.164 standard without the leading '+'.
     * Example: +55 (31) 99999-8888 -> 5531999998888
     * Ensures Brazilian numbers have 13 digits total (55 + DDD + Number). Handles cases with or without 9th digit.
     *
     * @param string|null $phone Input phone number
     * @return string|null Formatted phone number (e.g., 5531999998888) or null if input is invalid.
     */
    public static function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remove all non-numeric characters
        $numericPhone = preg_replace('/[^0-9]/', '', $phone);

        // Basic length check after cleaning
        if (strlen($numericPhone) < 10) { // Minimum for BR: DD+8 digits
            Logger::log('warning', 'Phone number too short after cleaning', ['original' => $phone, 'cleaned' => $numericPhone]);
            return null; // Or return as is, depending on desired strictness
        }

        // Check if it already starts with 55 (Brazil country code)
        if (str_starts_with($numericPhone, '55')) {
            // Check length for Brazil: 55 + 2 (DDD) + 8 or 9 (Number) = 12 or 13 digits
            if (strlen($numericPhone) === 12 || strlen($numericPhone) === 13) {
                return $numericPhone;
            } else {
                // If starts with 55 but has wrong length, it's likely invalid, remove 55 and re-evaluate
                Logger::log('debug', 'Phone starts with 55 but has invalid length, reprocessing without 55', ['phone' => $numericPhone]);
                $numericPhone = substr($numericPhone, 2);
            }
        }

        // At this point, $numericPhone should be DDD + Number (e.g., 31999998888 or 3133334444)
        $length = strlen($numericPhone);
        if ($length === 10 || $length === 11) { // 2 (DDD) + 8 or 9 (Number)
            return '55' . $numericPhone; // Prepend Brazil country code
        }

        Logger::log('warning', 'Could not format phone number to expected Brazilian E.164 format', ['original' => $phone, 'cleaned' => $numericPhone]);
        // Fallback: return the cleaned numeric string if it looks somewhat like a phone number
        return $numericPhone; // Or return null for stricter validation
    }
}

// Adiciona uma classe Logger stub se não estiver no contexto global (útil para testes unitários ou execução fora do fluxo principal)
if (!class_exists('WhatsappBridge\Utils\Logger')) {
    class Logger
    {
        public static function log(string $level, string $message, array $context = []): void
        {
            // No-op ou echo simples para ambientes sem o Logger principal
            // echo "LOG [$level]: $message " . json_encode($context) . "\n";
        }
    }
}
