<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

enum EventVerb: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
