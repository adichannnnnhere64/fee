<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Repay\Fee\DTO\CreateFee;
use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Enums\FeeType;
use Repay\Fee\Facades\Fee;

test('play ground', function () {

    $this->travelTo(now());
    $merchant = createMerchant('adi');

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 5000,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->addDays(30),
        applyToExistingEntity: true,
        reason: 'nochoice'
    ));

    $this->travelTo(now()->addDays(31));

    /* $active = Fee::getActiveFeeFor($merchant, 'product'); */

    $new = Fee::setFeeForEntity(new CreateFee(
        itemType: 'service',
        feeType: FeeType::COMMISSION,
        value: 20,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->subDays(5),
        reason: 'nochoice'
    ), $merchant);

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 69,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->subDays(3),
        reason: 'nochoice'
    ));

    $new = Fee::setFeeForEntity(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 30,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->subDays(4),
        reason: 'nochoice'
    ), $merchant);

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 9999,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->subDays(2),
        reason: 'nochoice'
    ));

    $fee = Fee::createGlobalFee(new CreateFee(
        itemType: 'product',
        feeType: FeeType::MARKUP,
        value: 3030,
        calculationType: CalculationType::PERCENTAGE,
        effectiveFrom: now()->addDays(30),
        reason: 'nochoice',
        applyToExistingEntity: true,
    ));


    Schema::create('custom_merchants', function (Blueprint $table) {
        $table->id();
        $table->timestamp('custom_created_at')->nullable();
        $table->timestamps();
    });

    $merchantCustom = new class extends Model
    {
        protected $table = 'custom_merchants';

        protected $fillable = ['custom_created_at'];
    };

    $merchantCustom->custom_created_at = now()->addDays(1);
    $isSave = $merchantCustom->save();

    $this->travelTo(now()->addDays(31));

    config()->set('fee.entity_date_column', 'custom_created_at');
    /* $active = Fee::getActiveFeeFor($merchant, 'product'); */

    /* $active->deactivateWithRevert(now()->addDays(30), 'wala ito be'); */

    $this->travelTo(now()->addDays(31));
    $merchant2 = createMerchant('adi');

    /* $active = Fee::getActiveFeeFor($merchant, 'product'); */


    /* $activeForMerchant2 = Fee::getActiveFeeFor($merchant2, 'product'); */
    $custom = Fee::getActiveFeeFor($merchantCustom, 'product');

    $history = Fee::getGlobalHistory();
	/* dd($history); */

});
