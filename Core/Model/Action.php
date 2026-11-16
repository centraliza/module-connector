<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

enum Action: string
{
    /** Done. */
    case Delivered = 'delivered';
    /** Try again after a delay; the attempt counts. */
    case Retry = 'retry';
    /** Wait without spending an attempt: the problem is the key or the route, not the event. */
    case Postpone = 'postpone';
    /** Give up; an admin has to look at it. */
    case Dead = 'dead';
}
