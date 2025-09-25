<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CurrencyRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            ['USD','US Dollar',1],
            ['TRY','Turkish Lira',41.47],
            ['AED','UAE Dirham',3.672500],
            ['SAR','Saudi Riyal',3.750000],
            ['EUR','Euro',0.920000],
        ];
        foreach ($rows as [$code,$name,$rate]) {
            \App\Models\CurrencyRate::updateOrCreate(['code'=>$code], ['name'=>$name, 'usd_to_currency'=>$rate]);
        }
    }
}
