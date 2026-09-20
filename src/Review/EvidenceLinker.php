<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Review;

interface EvidenceLinker
{
    public function link(string $dataset, string $source): ?string;
}
