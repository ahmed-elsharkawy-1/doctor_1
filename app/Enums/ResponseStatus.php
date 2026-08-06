<?php

namespace App\Enums;

/**
 * The `status` key of every API response envelope — see SPEC §6.1.
 */
enum ResponseStatus: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
}
