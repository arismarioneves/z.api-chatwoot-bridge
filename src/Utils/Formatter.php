<?php

namespace ZapiWoot\Utils;

class Formatter
{
    /**
     * Verifica se o valor é um LID do WhatsApp
     */
    public static function isLid(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        return str_contains($value, '@lid');
    }

    public static function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Se for LID, não é possível formatar como telefone
        if (self::isLid($phone)) {
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
