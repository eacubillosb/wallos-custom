<?php

final class CurrencyFormatter
{
    private static $instance;

    private static function getInstance()
    {
        if (self::$instance === null) {
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                self::$instance = new NumberFormatter(Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']), NumberFormatter::CURRENCY);
            } else {
                self::$instance = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
            }
        }

        return self::$instance;
    }

    public static function format($amount, $currency)
    {
        return self::getInstance()->formatCurrency((float) $amount, $currency);
    }

    // "COP 30,000": the ISO code always leads, whichever side the locale puts
    // its symbol on. The digits (grouping, decimals) still follow the locale.
    public static function formatCodeFirst($amount, $currency)
    {
        $amount = (float) $amount;
        $formatted = self::format(abs($amount), $currency);
        if (!preg_match('/\d(?:[\d.,\'\x{2019}\p{Zs}]*\d)?/u', $formatted, $match)) {
            return $formatted;
        }

        return $currency . "\u{00A0}" . ($amount < 0 ? '-' : '') . $match[0];
    }
}
