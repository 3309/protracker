<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormatType;
use App\Models\Account;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'format' => ImportFormatType::Template,
            'original_filename' => 'activity.csv',
            'file_sha256' => hash('sha256', fake()->uuid()),
            'status' => ImportBatchStatus::Previewed,
        ];
    }
}
