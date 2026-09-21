<?php

namespace Database\Seeders;

use App\Services\PrepopulationRuleImportService;
use Illuminate\Database\Seeder;

class PrepopulationRuleSeeder extends Seeder
{
    public function run(PrepopulationRuleImportService $importer): void
    {
        $importer->import(database_path('seeders/data/Codex_Prepopulation_Rules_FINAL.csv'));
    }
}
