<?php

namespace WhatsappBridge\Utils;

class Formatter
{
    public static function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $numericPhone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($numericPhone) < 10) {
            return null;
        }

        if (str_starts_with($numericPhone, '55')) {
            if (strlen($numericPhone) === 12 || strlen($numericPhone) === 13) {
                return $numericPhone;
            }
            $numericPhone = substr($numericPhone, 2);
        }

        $length = strlen($numericPhone);
        if ($length === 10 || $length === 11) {
            return '55' . $numericPhone;
        }

        return $numericPhone;
    }
}
