<?php

function dcvsValidationAttachmentType(string $mimeType, string $extension): ?string {
    $rules = [
        'image/jpeg' => ['extensions' => ['jpg', 'jpeg'], 'type' => 'photo'],
        'image/png' => ['extensions' => ['png'], 'type' => 'photo'],
        'video/mp4' => ['extensions' => ['mp4'], 'type' => 'video'],
    ];
    $rule = $rules[$mimeType] ?? null;
    if ($rule === null || !in_array(strtolower($extension), $rule['extensions'], true)) {
        return null;
    }
    return $rule['type'];
}

function dcvsValidationFieldStatuses(): array {
    return [
        'site_visit_pending',
        'confirmed_on_site',
        'urgent_response',
        'animal_already_gone',
        'wrong_or_unclear_location',
    ];
}
