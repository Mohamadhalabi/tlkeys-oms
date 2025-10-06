<?php
// app/Support/CodeGenerator.php
namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class CodeGenerator
{
    /**
     * Generate a unique code like TLO123456.
     */
    public static function uniqueNumericCode(
        string $prefix,
        int $digits,
        string $modelClass,
        string $column = 'code'
    ): string {
        /** @var \Illuminate\Database\Eloquent\Model $modelClass */
        do {
            $num = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
            $code = $prefix . $num;
        } while ($modelClass::where($column, $code)->exists());

        return $code;
    }
}
