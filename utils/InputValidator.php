<?php
namespace App\Utils;

class InputValidator {
    public static function sanitize($input) {
        return htmlspecialchars(strip_tags(trim($input)));
    }

    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validatePassword($password) {
        return strlen($password) >= 8;
    }
}
