<?php

namespace App\View\Components\Ui;

use App\Models\Currency;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * An amount in major units (the `decimal(12,2)` columns) with its currency
 * code, written as the invoices print it: `USD 2,000.00` — two decimals, a
 * comma between thousands and a dot before the cents.
 *
 * Props:
 * - `amount`: the value (decimal string, int or float).
 * - `currency`: a Currency model or an ISO 4217 code; null shows the number alone.
 *
 * Slots: none.
 */
class Money extends Component
{
    public ?string $code;

    public function __construct(
        public string|int|float $amount,
        Currency|string|null $currency = null,
    ) {
        $this->code = $currency instanceof Currency ? $currency->slug : $currency;
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2, '.', ',');
    }

    /**
     * The long currency name for assistive technology ("American dollar").
     */
    public function currencyLabel(): ?string
    {
        return $this->code !== null ? Currency::labelFor($this->code) : null;
    }

    public function render(): View
    {
        return view('components.ui.money');
    }
}
