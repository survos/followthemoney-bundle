<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Survos\FollowTheMoney\Model;
use Survos\FollowTheMoneyBundle\Review\{ReviewStore,Decision,ReviewConflict};
use Survos\JsonlBundle\IO\JsonlWriter;
final class ReviewStoreTest extends TestCase
{
    private ReviewStore $store;
    protected function setUp(): void { $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);$this->store=new ReviewStore($db,Model::bundled());$this->store->install();$this->store->install(); }
    public function testDecisionsAreAppendOnlyAndSymmetricWithOptimisticConcurrency(): void {
        $this->store->record('paper','p1','p2',Decision::Different,'reviewer','Two people in one obituary',0,['evidence'=>'original']);
        $this->store->record('paper','p2','p1',Decision::Unresolved,'reviewer','Reconsider with more evidence',1,['evidence'=>'new']);
        $history=$this->store->history('paper','p1','p2');self::assertCount(2,$history);self::assertSame('different',$history[1]['decision']);self::assertStringContainsString('original',$history[1]['snapshot']);
        $this->expectException(ReviewConflict::class);$this->store->record('paper','p1','p2',Decision::Same,'other','Stale form',1,[]);
    }
    public function testSelfDecisionRejected(): void { $this->expectException(InvalidArgumentException::class);$this->store->record('paper','p1','p1',Decision::Same,'reviewer','Reason',0,[]); }
    public function testImportPreservesIncompleteClaimsAndRejectsRewritingGeneration(): void {
        $dir=sys_get_temp_dir().'/ftm-test-'.bin2hex(random_bytes(8));mkdir($dir);
        try {
            $write=static function($path,$rows) { $w=JsonlWriter::open($path);try { foreach($rows as $row) {$w->write($row);} $w->finish();}finally{$w->close();} };
            $write($dir.'/entities.jsonl',[['id'=>'p1','schema'=>'Person','properties'=>['name'=>['Alex']]],['id'=>'f1','schema'=>'Family','properties'=>['relative'=>['p1']]]]);
            $write($dir.'/evidence.jsonl',[]);
            self::assertSame(2,$this->store->import('paper','1',$dir.'/entities.jsonl',$dir.'/evidence.jsonl'));
            self::assertNotEmpty($this->store->source('paper','f1')['validationErrors']);
            self::assertSame(0,$this->store->import('paper','1',$dir.'/entities.jsonl',$dir.'/evidence.jsonl'));
            $write($dir.'/entities2.jsonl',[['id'=>'p2','schema'=>'Person','properties'=>['name'=>['Other']]]]);
            try { $this->store->import('paper','1',$dir.'/entities2.jsonl',$dir.'/evidence.jsonl'); self::fail('Expected immutable generation'); } catch(ReviewConflict) {}
            $write($dir.'/bad-evidence.jsonl',[['annotation'=>['body'=>['id'=>'urn:ftm:absent'],'target'=>['source'=>'block-1']],'provenance'=>['sourceSha256'=>str_repeat('a',64)]]]);
            try { $this->store->import('paper','2',$dir.'/entities2.jsonl',$dir.'/bad-evidence.jsonl');self::fail('Expected dangling evidence failure');}catch(InvalidArgumentException){}
            self::assertSame('1',$this->store->version('paper'));self::assertNull($this->store->source('paper','p2','2'));
        } finally { (new Symfony\Component\Filesystem\Filesystem())->remove($dir); }
    }
}
