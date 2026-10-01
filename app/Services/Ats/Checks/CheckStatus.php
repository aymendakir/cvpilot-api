<?php

namespace App\Services\Ats\Checks;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unverified = 'unverified';
}
