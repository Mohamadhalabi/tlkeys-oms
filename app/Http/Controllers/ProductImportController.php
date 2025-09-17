<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    public function __invoke(Request $request) 
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
            'branch_id' => 'required|exists:branches,id',
        ]);
        
        Excel::queueImport(new ProductsImport((int)$request->branch_id), $request->file('file'));
        return back()->with('status','Import queued');
    }
}
