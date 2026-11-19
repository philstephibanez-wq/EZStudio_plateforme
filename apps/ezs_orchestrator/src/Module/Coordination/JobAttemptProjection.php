<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

/** Side-effect-free projection of legacy job attempt states. */
final class JobAttemptProjection
{
    public static function project(array $attempt): array
    {
        $jobId=filter_var($attempt['job_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $target=strtolower(trim((string)($attempt['target']??'')));
        if($jobId===false || !in_array($target,['dev','prod','lab'],true))throw new \InvalidArgumentException('invalid_job_identity');
        $state=strtolower(trim((string)($attempt['state']??'')));
        if(!in_array($state,['queued','running','completed','failed','finalize_error'],true))throw new \InvalidArgumentException('unknown_attempt_state');
        $rc=$attempt['analysis_returncode']??null;
        if($rc!==null && (!is_int($rc)||$rc<0))throw new \InvalidArgumentException('invalid_analysis_returncode');
        $finalize=strtolower(trim((string)($attempt['finalize_status']??'not_attempted')));
        if(!in_array($finalize,['not_attempted','pending','ok','error'],true))throw new \InvalidArgumentException('invalid_finalize_status');
        if($state==='queued' && ($rc!==null||$finalize!=='not_attempted'))throw new \DomainException('queued_has_execution_result');
        if($state==='completed' && ($rc!==0||$finalize!=='ok'))throw new \DomainException('completion_requires_callback_confirmation');
        if($state==='finalize_error' && ($rc!==0||$finalize!=='error'))throw new \DomainException('finalize_error_requires_successful_worker');
        if($state==='failed' && ($rc===null||$rc===0||$finalize!=='not_attempted'))throw new \DomainException('failed_requires_worker_error');
        if($state==='running' && $rc!==null&&$rc!==0)throw new \DomainException('running_has_failure_returncode');
        return [
            'job_id'=>(int)$jobId,'target'=>$target,'state'=>$state,
            'analysis_returncode'=>$rc,'finalize_status'=>$finalize,
            'started_at'=>isset($attempt['started_at'])?(string)$attempt['started_at']:null,
            'finished_at'=>isset($attempt['finished_at'])?(string)$attempt['finished_at']:null,
            'error'=>isset($attempt['error'])?substr((string)$attempt['error'],0,300):null,
        ];
    }
}
