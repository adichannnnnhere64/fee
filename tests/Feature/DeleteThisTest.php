<?php

use Repay\Fee\DTO\CreateFee;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Facades\Fee;

test('play ground', function () {

    $merchant = createMerchant('adi');

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 5000,
        calculationType: 'percentage',
        effectiveFrom: now()->subDays(5),
        reason: 'nochoice'
    ));

    $new = Fee::setFeeForEntity(new CreateFee(
        itemType: 'service',
        feeType: FeeType::COMMISSION,
        value: 20,
        calculationType: 'percentage',
        effectiveFrom: now()->subDays(5),
        reason: 'nochoice'
    ), $merchant);

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 69,
        calculationType: 'percentage',
        effectiveFrom: now()->subDays(3),
        reason: 'nochoice'
    ));


    $new = Fee::setFeeForEntity(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 600,
        calculationType: 'percentage',
        effectiveFrom: now()->subDays(4),
        reason: 'nochoice'
    ), $merchant);


    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 9999,
        calculationType: 'percentage',
        effectiveFrom: now()->subDays(2),
        reason: 'nochoice'
    ));

    $active = Fee::getActiveFeeFor($merchant, 'product');
    /* dd($active); */

});
