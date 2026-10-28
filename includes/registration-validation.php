<?php

function dcvsRegistrationPhoneError(string $phone): string
{
    if ($phone === '') {
        return 'Phone number is required.';
    }
    if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
        return 'Enter an 11-digit Philippine mobile number starting with 09.';
    }
    return '';
}

function dcvsRegistrationPasswordError(string $password): string
{
    if ($password === '') {
        return 'Password is required.';
    }
    $characterCount = preg_match_all('/./us', $password);
    if (($characterCount === false ? strlen($password) : $characterCount) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character.';
    }
    return '';
}
