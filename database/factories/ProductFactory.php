<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 100, 5000),
            'sale_price' => null,
            'stock_quantity' => fake()->numberBetween(10, 200),
            'in_stock' => true,
            'status' => 'active',
            'image' => 'products/default.jpg',
            'featured' => false,
        ];
    }
}
