<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Review;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Survos\FollowTheMoney\Model;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Uid\Uuid;

final readonly class ReviewStore
{
    public function __construct(private Connection $db, private Model $model)
    {
    }

    /** Explicit installation: creates only this bundle's tables; never alters app tables. */
    public function install(): void
    {
        $schema = new Schema();
        $sources = $schema->createTable('ftm_review_source');
        foreach (['source_key' => 64,'dataset' => 190,'version' => 64,'entity_id' => 190] as $name => $length) {
            $sources->addColumn($name, 'string', ['length' => $length]);
        }
        $sources->addColumn('payload', 'text');
        $sources->addColumn('evidence', 'text');
        $sources->setPrimaryKey(['source_key']);
        $sources->addIndex(['dataset','version']);
        $datasets = $schema->createTable('ftm_review_dataset');
        foreach (['dataset' => 190,'version' => 64,'checksum' => 64] as $name => $length) {
            $datasets->addColumn($name, 'string', ['length' => $length]);
        }
        $datasets->setPrimaryKey(['dataset']);
        $decisions = $schema->createTable('ftm_review_decision');
        foreach (['id' => 36,'pair_key' => 64,'dataset' => 190,'source_id' => 190,'target_id' => 190,'decision' => 16,'actor' => 190,'created_at' => 40] as $name => $length) {
            $decisions->addColumn($name, 'string', ['length' => $length]);
        }
        $decisions->addColumn('revision', 'integer');
        $decisions->addColumn('reason', 'text');
        $decisions->addColumn('snapshot', 'text');
        $decisions->setPrimaryKey(['id']);
        $decisions->addUniqueIndex(['pair_key','revision']);
        $decisions->addIndex(['dataset']);
        $configuration = $this->db->getConfiguration();
        $filter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (string $name): bool => true);
        try {
            $manager = $this->db->createSchemaManager();
            foreach ($schema->getTables() as $table) {
                if (!$manager->tablesExist([$table->getName()])) {
                    $manager->createTable($table);
                }
            }
        } finally {
            $configuration->setSchemaAssetsFilter($filter);
        }
    }
    public function version(string $dataset): ?string
    {
        $v = $this->db->fetchOne('SELECT version FROM ftm_review_dataset WHERE dataset = ?', [$dataset]);
        return $v === false ? null : $v;
    }
    public function source(string $dataset, string $id, ?string $version = null): ?array
    {
        $version ??= $this->version($dataset);
        if ($version === null) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT payload, evidence FROM ftm_review_source WHERE source_key = ?', [$this->key($dataset, $version, $id)]);
        return $row === false ? null : ['entity' => json_decode($row['payload'], true, flags:JSON_THROW_ON_ERROR),'evidence' => json_decode($row['evidence'], true, flags:JSON_THROW_ON_ERROR),'version' => $version,'validationErrors' => $this->model->fromArray(json_decode($row['payload'], true, flags:JSON_THROW_ON_ERROR))->validate()];
    }
    private function key(string ...$parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
    public function pair(string $dataset, string $source, string $target): string
    {
        $ids = [$source,$target];
        sort($ids, SORT_STRING);
        return $this->key($dataset, ...$ids);
    }
    public function history(string $dataset, string $source, string $target): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ftm_review_decision WHERE pair_key = ? ORDER BY revision DESC', [$this->pair($dataset, $source, $target)]);
    }
    public function record(string $dataset, string $source, string $target, Decision $decision, string $actor, string $reason, int $expectedRevision, array $snapshot): string
    {
        if ($source === $target || trim($actor) === '' || trim($reason) === '' || mb_strlen($reason) > 4000) {
            throw new \InvalidArgumentException('Distinct entities, reviewer and a reason are required');
        }
        $pair = $this->pair($dataset, $source, $target);
        $revision = (int)$this->db->fetchOne('SELECT COALESCE(MAX(revision),0) FROM ftm_review_decision WHERE pair_key = ?', [$pair]);
        if ($revision !== $expectedRevision) {
            throw new ReviewConflict('Another reviewer changed this pair. Reload before deciding.');
        }
        $id = Uuid::v7()->toRfc4122();
        try {
            $this->db->insert('ftm_review_decision', ['id' => $id,'pair_key' => $pair,'dataset' => $dataset,'source_id' => $source,'target_id' => $target,'decision' => $decision->value,'actor' => $actor,'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),'revision' => $revision + 1,'reason' => trim($reason),'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        } catch (UniqueConstraintViolationException $e) {
            throw new ReviewConflict('Concurrent decision: reload this pair.', previous:$e);
        }
        return $id;
    }
    public function currentDecisions(string $dataset): array
    {
        return $this->db->fetchAllAssociative('SELECT d.* FROM ftm_review_decision d WHERE d.dataset = ? AND NOT EXISTS (SELECT 1 FROM ftm_review_decision n WHERE n.pair_key = d.pair_key AND n.revision > d.revision) ORDER BY d.created_at DESC LIMIT 200', [$dataset]);
    }
    public function decisions(string $dataset): iterable
    {
        foreach ($this->db->iterateAssociative('SELECT * FROM ftm_review_decision WHERE dataset = ? ORDER BY created_at, id', [$dataset]) as $row) {
            yield ['id' => $row['id'],'dataset' => $row['dataset'],'sourceId' => $row['source_id'],'targetId' => $row['target_id'],'decision' => $row['decision'],'reviewer' => $row['actor'],'createdAt' => $row['created_at'],'revision' => (int)$row['revision'],'reason' => $row['reason'],'snapshot' => json_decode($row['snapshot'], true, flags:JSON_THROW_ON_ERROR)];
        }
    }
    /** Imports immutable generations atomically; the active pointer changes only on success. */
    public function import(string $dataset, string $version, string $entities, string $evidence): int
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $dataset) || !preg_match('/^[0-9]+$/D', $version)) {
            throw new \InvalidArgumentException('Expected dataset name and numeric-string version');
        }
        if (!is_readable($entities) || !is_readable($evidence)) {
            throw new \InvalidArgumentException('Both export files must be readable');
        }
        $checksum = $this->key(hash_file('sha256', $entities), hash_file('sha256', $evidence));
        $active = $this->db->fetchAssociative('SELECT * FROM ftm_review_dataset WHERE dataset = ?', [$dataset]);
        if ($active && $active['version'] === $version && $active['checksum'] === $checksum) {
            return 0;
        }
        if ($this->db->fetchOne('SELECT COUNT(*) FROM ftm_review_source WHERE dataset = ? AND version = ?', [$dataset,$version])) {
            throw new ReviewConflict('Generation already exists. Publish a new version.');
        }
        return $this->db->transactional(function () use ($dataset, $version, $entities, $evidence, $checksum, $active): int {
            $count = 0;
            foreach (JsonlReader::open($entities) as $wire) {
                $entity = $this->model->fromArray($wire);
                // Preserve incomplete extraction fragments; validation is displayed during review.
                $this->db->insert('ftm_review_source', ['source_key' => $this->key($dataset, $version, $entity->id),'dataset' => $dataset,'version' => $version,'entity_id' => $entity->id,'payload' => json_encode($entity, JSON_THROW_ON_ERROR),'evidence' => '[]']);
                ++$count;
            }
            if (!$count) {
                throw new \InvalidArgumentException('Empty entity export');
            }
            foreach (JsonlReader::open($evidence) as $row) {
                $envelope = $row['evidence'] ?? $row;
                $body = $envelope['annotation']['body']['id'] ?? '';
                if (!str_starts_with($body, 'urn:ftm:') || !isset($envelope['annotation']['target']['source'],$envelope['provenance']['sourceSha256'])) {
                    throw new \InvalidArgumentException('Malformed evidence envelope');
                }
                $id = rawurldecode(substr($body, 8));
                $source = $this->source($dataset, $id, $version);
                if ($source === null) {
                    throw new \InvalidArgumentException('Evidence refers to absent entity '.$id);
                }
                $annotations = $source['evidence'];
                $annotations[] = $envelope;
                $this->db->update('ftm_review_source', ['evidence' => json_encode($annotations, JSON_THROW_ON_ERROR)], ['source_key' => $this->key($dataset, $version, $id)]);
            }
            $row = ['version' => $version,'checksum' => $checksum];
            if ($active) {
                $this->db->update('ftm_review_dataset',$row,['dataset' => $dataset]);
            } else {
                $this->db->insert('ftm_review_dataset',['dataset' => $dataset] + $row);
            }
            return $count;
        });
    }
}
