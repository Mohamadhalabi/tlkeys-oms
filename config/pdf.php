<?php

return [
    'mode'                  => 'utf-8',
    'format'                => 'A4',
    'orientation'           => 'P',
    'default_font_size'     => '12',
    'default_font'          => 'amiri',   // <- use our Arabic font
    'margin_left'           => 14,
    'margin_right'          => 14,
    'margin_top'            => 16,
    'margin_bottom'         => 20,

    'custom_font_dir'       => resource_path('fonts/Amiri/'), // <- where TTFs live
    'custom_font_data'      => [
        'amiri' => [
            'R'  => 'Amiri-Regular.ttf',
            'B'  => 'Amiri-Bold.ttf',
            'I'  => 'Amiri-Italic.ttf',
            'BI' => 'Amiri-BoldItalic.ttf',
        ],
    ],

    // Critical for Arabic shaping:
    'autoScriptToLang'      => true,
    'autoLangToFont'        => true,
    'useOTL'                => 0xFF,   // enable OpenType layout (joins letters)
    'useKashida'            => 75,     // nicer justification in Arabic
];
