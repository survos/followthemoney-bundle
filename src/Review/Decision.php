<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Review;

enum Decision: string
{
    case Same = 'same';
    case Different = 'different';
    case Unresolved = 'unresolved';
}
