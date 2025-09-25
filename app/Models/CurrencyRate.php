<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code', 'name', 'usd_to_currency'];

    public static function getRate(string $code): float
    {
        if (strtoupper($code) === 'USD') return 1.0;
        return (float) static::query()->where('code', strtoupper($code))->value('usd_to_currency') ?: 1.0;
    }

    public static function options(): array
    {
        return static::query()->orderBy('code')->pluck('name', 'code')->toArray();
    }
}
