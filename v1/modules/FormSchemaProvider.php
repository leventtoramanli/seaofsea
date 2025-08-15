<?php

class FormSchemaProvider
{
    // Şimdilik sabit; ileride dosyadan/DB'den okunabilir.
    private static array $schemas = [
        'user_settings' => [
            'version' => 1,
            'title'   => 'User Settings',
            'fields'  => [
                ['key' => 'emergencyContactPhone', 'type' => 'phone', 'label' => 'Acil Durum Telefonu', 'icon' => 'phone', 'required' => false],
                ['key' => 'linkedinUrl', 'type' => 'url', 'label' => 'LinkedIn', 'icon' => 'linkedin', 'required' => false],
                ['key' => 'isOpenToJobs', 'type' => 'switch', 'label' => 'Tekliflere Açığım', 'icon' => 'work', 'required' => false],
                ['key' => 'preferredLang', 'type' => 'select', 'label' => 'Tercih Dili', 'options' => ['tr','en'], 'icon' => 'language', 'required' => false],
            ],
        ],
    ];

    public static function get(string $formId): ?array
    {
        return self::$schemas[$formId] ?? null;
    }
}
