<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\LabRetryClient;
use App\Service\JobRetryCoordinator;
use App\Service\QueueManager;
use App\Service\QueueBarrierPaths;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JobRetrySecurityTest extends WebTestCase
{
    public function testRemoteAccessControlAndLocalBypass(): void
    {
        $h=password_hash('operator_pass',PASSWORD_BCRYPT);
        putenv('ORCHESTRATOR_OPERATOR_PASSWORD_HASH='.$h);
        $_ENV['ORCHESTRATOR_OPERATOR_PASSWORD_HASH']=$_SERVER['ORCHESTRATOR_OPERATOR_PASSWORD_HASH']=$h;
        $client=static::createClient([],['ORCHESTRATOR_OPERATOR_PASSWORD_HASH'=>$h]);
        $client->request('POST','/jobs/999999/retry',[],[],['REMOTE_ADDR'=>'10.99.0.5']);
        $this->assertResponseStatusCodeSame(401);
        $client->request('POST','/jobs/999999/delete',[],[],['REMOTE_ADDR'=>'10.99.0.5','PHP_AUTH_USER'=>'viewer','PHP_AUTH_PW'=>'viewer_pass']);
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST','/jobs/999999/retry',[],[],['REMOTE_ADDR'=>'127.0.0.1']);
        $this->assertResponseStatusCodeSame(403);
        $this->assertStringContainsString('CSRF',(string)$client->getResponse()->getContent());
        $client->request('POST','/jobs/999999/retry',[],[],['REMOTE_ADDR'=>'10.99.0.5','PHP_AUTH_USER'=>'operator','PHP_AUTH_PW'=>'operator_pass']);
        $this->assertResponseStatusCodeSame(403);
        $this->assertStringContainsString('Action locale uniquement.',(string)$client->getResponse()->getContent());
        $client->request('GET','/jobs',[],[],['REMOTE_ADDR'=>'10.99.0.5','PHP_AUTH_USER'=>'operator','PHP_AUTH_PW'=>'operator_pass']);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testLabRestRetryClientRequiresConfiguredToken(): void
    {
        // The retry must fail closed; it must never create or read LAB SQLite.
        $old=$_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']??null;
        $oldWorker=$_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']??null;
        try {
            unset($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN'],$_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN'],$_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN'],$_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN']);
            putenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN');putenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN');
            $svc=new LabRetryClient(new MockHttpClient());
            $this->assertSame('lab_retry_token_not_configured',$svc->request('prepare',368,1,'lab',['job'=>'profile','analysis_job_id'=>1])['reason']);
        } finally {
            if ($old!==null) $_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']=$old;
            if ($oldWorker!==null) $_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']=$oldWorker;
        }
    }

    public function testCentralFailureCallsRemoteCompensation(): void
    {
        static::ensureKernelShutdown();static::bootKernel();
        $container=static::getContainer();
        $em=$container->get(EntityManagerInterface::class);
        $qm=$container->get(QueueManager::class);
        $lf=$container->get(LockFactory::class);
        $_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']='test_token';
        $calls=[];
        $pauseFile=QueueBarrierPaths::pause(dirname(__DIR__, 2));
        $this->assertTrue(QueueBarrierPaths::isTest());
        $this->assertStringEndsWith('_test.json',$pauseFile);
        $this->assertFileDoesNotExist($pauseFile);
        $this->assertSame('orchestrator_test.sqlite',basename(str_replace('\\','/',(string)($em->getConnection()->getParams()['path'] ?? ''))));
        $job=null;
        $ownsPause=false;
        $conn=$em->getConnection();
        $prefix='__n2_'.bin2hex(random_bytes(6)).'_';
        try {
            $conn->executeStatement("UPDATE job_queue SET status = ? || status WHERE status IN ('waiting','progressing','cancelling')",[$prefix]);
            $job=$qm->dispatch('lab',['job'=>'profile','analysis_job_id'=>201,'scientific_run_id'=>20,'song_id'=>601]);
            $claimed=$qm->claimNextWaiting();
            $this->assertSame($job->getId(),$claimed?->getId());
            $qm->markFailed((int)$job->getId(),'failed');
            $handle=fopen($pauseFile,'xb');
            $this->assertIsResource($handle);
            $ownsPause=true;
            try {
                $record=json_encode(['schema'=>'ezs.queue.pause_on_ko.v1','global_job_id'=>(int)$job->getId(),'reason'=>'test_failure','at_utc'=>gmdate('c')],JSON_THROW_ON_ERROR);
                $this->assertSame(strlen($record),fwrite($handle,$record));
            } finally { fclose($handle); }
            $http=new MockHttpClient(function(string $method,string $url,array $options)use(&$calls):MockResponse {
                $action=basename($url);$calls[]=$action;
                return new MockResponse('{"schema":"ezstudio.lab-job-retry.v1","ok":true,"reconciled":false}',['http_code'=>200]);
            });
            $stub=$this->createStub(QueueManager::class);
            $stub->method('isProcessAlive')->willReturn(false);
            $stub->method('findActiveDuplicate')->willReturn(null);
            $stub->method('retry')->willThrowException(new \RuntimeException('global failure'));
            $coord=new JobRetryCoordinator($em,$stub,$lf,new NullLogger(),new LabRetryClient($http));
            $foreign=json_encode(['schema'=>'ezs.queue.pause_on_ko.v1','global_job_id'=>(int)$job->getId()+1],JSON_THROW_ON_ERROR);
            $this->assertSame(strlen($foreign),file_put_contents($pauseFile,$foreign,LOCK_EX));
            $denied=$coord->retry((int)$job->getId());
            $this->assertFalse($denied['ok']);
            $this->assertSame('job_non_bloquant_de_la_fifo',$denied['reason']);
            $this->assertSame([],$calls);
            $this->assertSame(strlen($record),file_put_contents($pauseFile,$record,LOCK_EX));
            $result=$coord->retry((int)$job->getId());
            $this->assertFalse($result['ok']);
            $this->assertSame('echec_rearmement_central',$result['reason']);
            $this->assertSame(['prepare','abort'],$calls);
        } finally {
            if ($ownsPause) unlink($pauseFile);
            if ($job!==null) $conn->executeStatement('DELETE FROM job_queue WHERE id=?',[(int)$job->getId()]);
            $conn->executeStatement('UPDATE job_queue SET status=substr(status,?) WHERE substr(status,1,?)=?',[strlen($prefix)+1,strlen($prefix),$prefix]);
            unset($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']);
        }
    }

    public function testIdempotentPreparedRemoteStateThenGlobalRetry(): void
    {
        static::ensureKernelShutdown();static::bootKernel();
        $container=static::getContainer();
        $em=$container->get(EntityManagerInterface::class);
        $qm=$container->get(QueueManager::class);
        $lf=$container->get(LockFactory::class);
        $_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']='test_token';
        $calls=[];
        $pauseFile=QueueBarrierPaths::pause(dirname(__DIR__, 2));
        $this->assertTrue(QueueBarrierPaths::isTest());
        $this->assertStringEndsWith('_test.json',$pauseFile);
        $this->assertFileDoesNotExist($pauseFile);
        $this->assertSame('orchestrator_test.sqlite',basename(str_replace('\\','/',(string)($em->getConnection()->getParams()['path'] ?? ''))));
        $job=null;
        $ownsPause=false;
        $conn=$em->getConnection();
        $prefix='__n2_'.bin2hex(random_bytes(6)).'_';
        try {
            $conn->executeStatement("UPDATE job_queue SET status = ? || status WHERE status IN ('waiting','progressing','cancelling')",[$prefix]);
            $job=$qm->dispatch('lab',['job'=>'profile','analysis_job_id'=>202,'scientific_run_id'=>20,'song_id'=>602]);
            $claimed=$qm->claimNextWaiting();
            $this->assertSame($job->getId(),$claimed?->getId());
            $qm->markFailed((int)$job->getId(),'failed');
            $handle=fopen($pauseFile,'xb');
            $this->assertIsResource($handle);
            $ownsPause=true;
            try {
                $record=json_encode(['schema'=>'ezs.queue.pause_on_ko.v1','global_job_id'=>(int)$job->getId(),'reason'=>'test_failure','at_utc'=>gmdate('c')],JSON_THROW_ON_ERROR);
                $this->assertSame(strlen($record),fwrite($handle,$record));
            } finally { fclose($handle); }
            $http=new MockHttpClient(function(string $method,string $url,array $options)use(&$calls):MockResponse {
                $action=basename($url);$calls[]=$action;
                $reconciled=$action==='prepare'?'true':'false';
                return new MockResponse('{"schema":"ezstudio.lab-job-retry.v1","ok":true,"reconciled":'.$reconciled.'}',['http_code'=>200]);
            });
            $coord=new JobRetryCoordinator($em,$qm,$lf,new NullLogger(),new LabRetryClient($http));
            $result=$coord->retry((int)$job->getId());
            $this->assertTrue($result['ok']);
            $this->assertSame('relance_reconciliee_idempotente',$result['reason']);
            $this->assertSame(['prepare','commit'],$calls);
            $this->assertSame('waiting',$em->getConnection()->fetchOne('SELECT status FROM job_queue WHERE id=?',[(int)$job->getId()]));
        } finally {
            if ($ownsPause) unlink($pauseFile);
            if ($job!==null) $conn->executeStatement('DELETE FROM job_queue WHERE id=?',[(int)$job->getId()]);
            $conn->executeStatement('UPDATE job_queue SET status=substr(status,?) WHERE substr(status,1,?)=?',[strlen($prefix)+1,strlen($prefix),$prefix]);
            unset($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']);
        }
    }

    public function testLabRestRetryClientContract(): void
    {
        $_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']='test_token';
        try {
            $http=new MockHttpClient(function(string $method,string $url,array $options): MockResponse {
                $this->assertSame('POST',$method);
                $this->assertStringContainsString('/internal/analysis/desktop/jobs/retry/prepare',$url);
                return new MockResponse('{"schema":"ezstudio.lab-job-retry.v1","ok":true,"reconciled":false}', ['http_code'=>200]);
            });
            $svc=new LabRetryClient($http);
            $this->assertTrue($svc->request('prepare',368,1,'lab',['job'=>'profile','analysis_job_id'=>1])['ok']);
        } finally { unset($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']); }
    }
}
