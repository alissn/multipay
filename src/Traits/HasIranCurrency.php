<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\Constants\IranCurrency;

trait HasIranCurrency
{
    /**
     *  Normalize the price based on the selected currency ratio on config.
     *
     * @param int|float $price
     * @return int|float
     */
    protected function normalizeByCurrency(int|float $price): int|float
    {
        return $price * $this->getCurrencyRatio();
    }

    protected function getCurrencyRatio():int
    {
        /** @var IranCurrency $currency */
        $currency = $this->settings->currency;
        if (!($currency instanceof \BackedEnum)) {
            $currency = $currency === 'T' ? IranCurrency::TOMAN : IranCurrency::RIAL;
        }

        return $currency->ratio();
    }
}
