<?php

declare(strict_types=1);

namespace Nori;

enum FallbackMode
{
    case FailClosed;
    case FailOpen;
}
