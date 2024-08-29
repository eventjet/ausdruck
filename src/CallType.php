<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

enum CallType
{
    case Method;
    case Infix;
    case Prefix;
}
