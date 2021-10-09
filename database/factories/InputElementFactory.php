<?php

namespace Database\Factories;

use App\Models\InputElement;
use Illuminate\Database\Eloquent\Factories\Factory;

class InputElementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = InputElement::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'header' => $this->faker->sentence(),
            'description' =>  $this->faker->sentence(12),
            'is_mandatory' => true,
            'form_element_id' => 1
        ];
    }
}
