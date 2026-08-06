<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks a member from "changing" their password to the org-wide shared temp
 * password (config/member_import.php) — every bulk-imported member starts on
 * that same value, so letting anyone re-set it as their permanent password
 * would leave the account protected by a secret every other imported member,
 * and whoever handed out the import spreadsheet, also knows.
 */
class NotSharedTempPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tempPassword = config('member_import.temp_password');

        if ($tempPassword !== null && $tempPassword !== '' && hash_equals((string) $tempPassword, (string) $value)) {
            $fail('You cannot use the temporary password as your new password. Please choose a different one.');
        }
    }
}
