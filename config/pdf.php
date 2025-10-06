<?php

return [
    // mPDF base
    'mode'         => 'utf-8',
    'format'       => 'A4',
    'orientation'  => 'P',
    'default_font' => 'amiri', // Arabic-friendly default

    // Where your .ttf files live (put them here)
    'font_path'    => public_path('fonts'),
    'font_data'    => [
        // Amiri family (recommended for Arabic)
        'amiri' => [
            'R'  => 'Amiri-Regular.ttf',
            'B'  => 'Amiri-Bold.ttf',
            'I'  => 'Amiri-Italic.ttf',
            'BI' => 'Amiri-BoldItalic.ttf',
        ],

        // (Optional) Cairo if you add it later
        // 'cairo' => [
        //     'R'  => 'Cairo-Regular.ttf',
        //     'B'  => 'Cairo-Bold.ttf',
        //     'I'  => 'Cairo-Italic.ttf',
        //     'BI' => 'Cairo-BoldItalic.ttf',
        // ],
    ],

    // tmp & rendering
    'temp_dir'     => storage_path('app/mpdf'),
    'dpi'          => 96,
    'img_dpi'      => 96,

    // SAFETY: never let mPDF fetch remote URLs (prevents timeouts)
    'enable_remote' => false,
    'enable_php'    => false,
];
