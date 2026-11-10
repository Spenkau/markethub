<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public const CURRENCIES = ['RUB' => 'RUB', 'USD' => 'USD', 'EUR' => 'EUR', 'BYN' => 'BYN', 'KZT' => 'KZT', 'CNY' => 'CNY', 'GBP' => 'GBP'];

    public static function parse(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['price' => 'Введите неотрицательную цену с точностью до двух знаков.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, string $currency): string
    {
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', (string) intdiv($minor, 100));

        return $whole.','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).' '.$currency;
    }
}
