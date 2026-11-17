<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

enum Action: string
{
    /** Done. */
    case Delivered = 'delivered';
    /** Nothing to send: the record no longer exists, and its delete is reported on its own. */
    case Skipped = 'skipped';
    /** Try again after a delay; the attempt counts. */
    case Retry = 'retry';
    /** Wait without spending an attempt: the problem is the key or the route, not the event. */
    case Postpone = 'postpone';
    /** Stop trying and set the event aside until a person reviews it. */
    case Quarantine = 'quarantine';
}
