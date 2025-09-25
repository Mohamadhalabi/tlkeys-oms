<?php

// app/Http/Controllers/ProductImportController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ProductsImport;

class ProductImportController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt',
            'branch_id' => 'required|exists:branches,id',
        ]);

        Excel::queueImport(new ProductsImport((int) $data['branch_id']), $data['file']);

        return back()->with('status', 'Import queued');
    }
}