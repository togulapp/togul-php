<?php

declare(strict_types=1);

namespace Togul;

enum FallbackMode
{
    case FailClosed;
    case FailOpen;
}
