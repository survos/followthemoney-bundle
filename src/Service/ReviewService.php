<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Service;

use Survos\FollowTheMoneyBundle\Review\ReviewStore;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\{AsCommand, Argument};
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ReviewService
{
    public function __construct(private ReviewStore $store)
    {
    }
    #[AsCommand('ftm:review:install', 'Create the resolution source/evidence and decision tables')]
    public function install(SymfonyStyle $io): int
    {
        $this->store->install();
        $io->success('Review storage ready');
        return 0;
    }
    #[AsCommand('ftm:review:import', 'Import a versioned FtM export and its evidence; never match or merge')]
    public function import(SymfonyStyle $io, #[Argument] string $dataset, #[Argument] string $version, #[Argument] string $entities, #[Argument] string $evidence): int
    {
        $count = $this->store->import($dataset, $version, $entities, $evidence);
        $io->success($count.' entities imported (0 means unchanged)');
        return 0;
    }
    #[AsCommand('ftm:review:export', 'Export the append-only decision history with evidence snapshots')]
    public function export(SymfonyStyle $io, #[Argument] string $dataset, #[Argument] string $output): int
    {
        $writer = JsonlWriter::open($output);
        try {
            foreach ($this->store->decisions($dataset) as $row) {
                $writer->write($row);
            } $writer->finish();
        } finally {
            $writer->close();
        }
        $io->success('Exported decision history');
        return 0;
    }
}
