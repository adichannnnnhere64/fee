<?php

namespace Database\Factories\Repay\Fee\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Repay\Fee\Models\FeeTemplate;

class FeeTemplateFactory extends Factory
{
    protected $model = FeeTemplate::class;

    protected static $defaultAssigned = false;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        if (! static::$defaultAssigned) {
            static::$defaultAssigned = true;

            return $this->state([
                'is_default' => true,
                'name' => 'Default Fee Template',

            ]);
        }

        return $this->state([
            'is_default' => false,
        ]);
    }

    public function inactive(): self
    {
        return $this->state([
            'is_active' => false,
        ]);
    }

    public static function resetDefaultFlag(): void
    {
        static::$defaultAssigned = false;
    }
}
