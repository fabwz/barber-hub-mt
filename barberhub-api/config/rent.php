<?php

declare(strict_types=1);

return [
    /*
     | Monthly rent every barbershop pays the platform, in whole colones with 13 % VAT included (PL-04).
     | PROVISIONAL demonstration value: PD-09 (amount and way of collecting the rent) is still pending.
     */
    'monthly_amount' => (int) env('RENT_MONTHLY_AMOUNT', 25000),
];
