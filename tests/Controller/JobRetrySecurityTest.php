<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\LabRetryClient;
use App\Service\JobRetryCoordinator;
use App\Service\QueueManager;
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
        $old=$_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']??null;
        $oldWorker=$_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']??null;
        try {
            unset($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN'],$_SERVER['EZSTUDIOLAB_ASSISTANT_TOKEN'],$_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN'],$_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN']);
            putenv('EZSTUDIOLAB_ASSISTANT_TOKEN');putenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN');
            $svc=new LabRetryClient(new MockHttpClient());
            $this->assertSame('lab_retry_token_not_configured',$svc->request('prepare',368,1,'lab',['job'=>'profile','analysis_job_id'=>1])['reason']);
        } finally {
            if ($old!==null) $_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']=$old;
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
        $_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']='test_token';
        $calls=[];
        try {
            $job=$qm->dispatch('lab',['job'=>'profile','analysis_job_id'=>201,'scientific_run_id'=>20,'song_id'=>601]);
            $qm->claimNextWaiting();$qm->markFailed((int)$job->getId(),'failed');
            $http=new MockHttpClient(function(string $method,string $url,array $options)use(&$calls):MockResponse {
                $action=basename($url);$calls[]=$action;
                return new MockResponse('{"schema":"ezstudio.lab-job-retry.v1","ok":true,"reconciled":false}',['http_code'=>200]);
            });
            $stub=$this->createStub(QueueManager::class);
            $stub->method('isProcessAlive')->willReturn(false);
            $stub->method('findActiveDuplicate')->willReturn(null);
            $stub->method('retry')->willThrowException(new \RuntimeException('global failure'));
            $coord=new JobRetryCoordinator($em,$stub,$lf,new NullLogger(),new LabRetryClient($http));
            $result=$coord->retry((int)$job->getId());
            $this->assertFalse($result['ok']);
            $this->assertSame('echec_rearmement_central',$result['reason']);
            $this->assertSame(['prepare','abort'],$calls);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM job_queue');
            unset($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']);
        }
    }

    public function testIdempotentPreparedRemoteStateThenGlobalRetry(): void
    {
        static::ensureKernelShutdown();static::bootKernel();
        $container=static::getContainer();
        $em=$container->get(EntityManagerInterface::class);
        $qm=$container->get(QueueManager::class);
        $lf=$container->get(LockFactory::class);
        $_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']='test_token';
        $calls=[];
        try {
            $job=$qm->dispatch('lab',['job'=>'profile','analysis_job_id'=>202,'scientific_run_id'=>20,'song_id'=>602]);
            $qm->claimNextWaiting();$qm->markFailed((int)$job->getId(),'failed');
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
            $em->getConnection()->executeStatement('DELETE FROM job_queue');
            unset($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']);
        }
    }

    public function testLabRestRetryClientContract(): void
    {
        $_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']='test_token';
        try {
            $http=new MockHttpClient(function(string $method,string $url,array $options): MockResponse {
                $this->assertSame('POST',$method);
                $this->assertStringContainsString('/internal/analysis/desktop/jobs/retry/prepare',$url);
                return new MockResponse('{"schema":"ezstudio.lab-job-retry.v1","ok":true,"reconciled":false}', ['http_code'=>200]);
            });
            $svc=new LabRetryClient($http);
            $this->assertTrue($svc->request('prepare',368,1,'lab',['job'=>'profile','analysis_job_id'=>1])['ok']);
        } finally { unset($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN']); }
    }
}
