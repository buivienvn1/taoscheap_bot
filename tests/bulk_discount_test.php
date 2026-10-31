<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/BulkDiscountService.php';

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = 'FAIL: ' . $name;
    } else {
        echo 'PASS: ' . $name . PHP_EOL;
    }
};

$rawTiers = "5:1\n10:1.5\n20:2\n10:3\n1:9\n25:0\n30:100\ninvalid";
$tiers = BulkDiscountService::parseTiers($rawTiers);
$assert(count($tiers) === 3, 'parseTiers ignores invalid rows');
$assert($tiers[0] === ['quantity' => 5, 'percent' => 1.0], 'parseTiers sorts and parses first tier');
$assert($tiers[1] === ['quantity' => 10, 'percent' => 3.0], 'parseTiers keeps the last valid duplicate quantity');
$assert(BulkDiscountService::parseTiers('') === [], 'empty tiers produce no discount tiers');

$tiers = BulkDiscountService::parseTiers("5:1\n10:1.5\n20:2");
$eligible = BulkDiscountService::eligibleTiersFor($tiers, 10);
$assert(count($eligible) === 2, 'eligibleTiersFor filters tiers above stock');
$assert($eligible[1]['quantity'] === 10, 'eligibleTiersFor keeps the highest available stock tier');

$pricing = BulkDiscountService::calculateFor($tiers, '19900', 5);
$assert($pricing['subtotal'] === '99500.00', 'calculateFor computes subtotal');
$assert($pricing['discount_percent'] === 1.0, 'calculateFor applies the matching tier');
$assert($pricing['discount_amount'] === '995.00', 'calculateFor computes discount amount');
$assert($pricing['total'] === '98505.00', 'calculateFor computes discounted total');

$pricing = BulkDiscountService::calculateFor($tiers, 19900, 20);
$assert($pricing['discount_percent'] === 2.0, 'calculateFor applies the highest matching tier');
$assert($pricing['discount_amount'] === '7960.00', 'calculateFor rounds discount amount to cents');
$assert($pricing['total'] === '390040.00', 'calculateFor returns total after highest tier discount');

$pricing = BulkDiscountService::calculateFor($tiers, '19.99', 3);
$assert($pricing['discount_amount'] === '0.00' && $pricing['total'] === '59.97', 'calculateFor leaves quantities below first tier undiscounted');
$assert($pricing['next_tier']['quantity'] === 5, 'calculateFor returns the next tier for upsell');
$assert(BulkDiscountService::calculateFor($tiers, 100, 3)['next_tier']['quantity'] === 5, 'next tier is available before the first threshold');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: All bulk discount tests passed successfully.\n";
