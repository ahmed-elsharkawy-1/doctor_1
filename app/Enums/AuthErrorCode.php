<?php

namespace App\Enums;

/**
 * Auth and access failures for the response envelope's `error.code` — SPEC §6.2.
 *
 * The Flutter app branches on these values. They are part of the public
 * contract: never rename a case, only add.
 */
enum AuthErrorCode: string
{
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case ACCESS_TOKEN_MISSING = 'ACCESS_TOKEN_MISSING';
    case ACCESS_TOKEN_INVALID = 'ACCESS_TOKEN_INVALID';
    case ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';
    case ACCOUNT_NOT_ALLOWED = 'ACCOUNT_NOT_ALLOWED';
    case FORBIDDEN_ROLE = 'FORBIDDEN_ROLE';
    case CLINIC_NOT_ASSIGNED = 'CLINIC_NOT_ASSIGNED';
    case CLINIC_INACTIVE = 'CLINIC_INACTIVE';
    case INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
}
