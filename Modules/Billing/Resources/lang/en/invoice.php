<?php

declare(strict_types=1);

return [

    'title' => 'Invoice',
    'billed_to' => 'Billed to',
    'issued_at' => 'Issued on',
    'due_at' => 'Due',
    'period' => 'Period',

    'description' => 'Description',
    'quantity' => 'Qty',
    'unit_price' => 'Unit price',
    'amount' => 'Amount',

    'subtotal' => 'Subtotal',
    'tax' => 'VAT :rate',
    'credit' => 'Credit applied',
    'total' => 'Total',

    'status' => [
        'open' => 'Due',
        'paid' => 'Paid',
        'void' => 'Void',
        'uncollectible' => 'Uncollectible',
    ],

    'footer_note' => 'Issued electronically. Amounts in CFA francs, no decimal unit.',

];
