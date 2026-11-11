<?php

return [

    // სად ჩაიწეროს დაგენერირებული .ts ფაილები
    'output_path' => resource_path('js/types/api'),

    'routes' => [
        // მხოლოდ ამ პრეფიქსით დაწყებული URI-ები (ცარიელი = ყველა route)
        'include_prefixes' => ['api'],

        // ამ პრეფიქსებით დაწყებული URI-ები გამოირიცხება
        'exclude_prefixes' => ['_ignition', 'sanctum', 'telescope', 'horizon'],

        // route-ის სახელები, რომლებიც გამოირიცხება (wildcard-ები: 'admin.*')
        'exclude_names' => [],
    ],

    'enums' => [
        // 'enum'  -> export enum Status { Active = 'active' }
        // 'union' -> export type Status = 'active' | 'inactive'
        // 'const' -> export const Status = {...} as const + type
        'style' => 'enum',

        // ამ დირექტორიებში არსებული ყველა PHP enum დაგენერირდება,
        // მიუხედავად იმისა, გამოიყენება თუ არა რომელიმე FormRequest-ში
        'paths' => [app_path('Enums')],
    ],

    'responses' => [
        // Resource-ის მოდელის ძებნა კონვენციით: UserResource -> App\Models\User
        // (ან Resource კლასზე /** @mixin \App\Models\User */ docblock-ით)
        'model_namespaces' => ['App\\Models\\'],
    ],

    // TS ტიპი, როცა წესებიდან ტიპის დადგენა ვერ ხერხდება
    'fallback_type' => 'unknown',

    // TS ტიპი file/image/mimes ველებისთვის
    'file_type' => 'File',
];
