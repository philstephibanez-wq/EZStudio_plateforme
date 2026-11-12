<?php

declare(strict_types=1);

namespace EZSLab\Service;

/** The historical PROFILE Python environment, without any queue credentials. */
final class ProfileOnceEnvironment
{
    public static function build(string $project): array
    {
        $env = getenv();
        if (!is_array($env)) $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) $env[$key] = $value;
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'EZSTUDIO_')) $env[$key] = $value;
        }
        $root = rtrim(str_replace('\\','/',$project),'/');
        $models = 'H:/AIModels';
        $storage = $root.'/var/storage';
        $runtime = $root.'/var/runtime';
        $sharedRuntime = 'H:/EZStudio_plateforme/var/runtime';
        $defaults = [
            'AI_MODELS_ROOT'=>$models,
            'BS_ROFORMER_MODELS_PATH'=>$models.'/audio/separation/bs-roformer',
            'MELBAND_ROFORMER_MODELS_PATH'=>$models.'/audio/separation/melband-roformer',
            'EZSTUDIO_PROFILE_MODELS'=>$models.'/audio/profile',
            'EZSTUDIO_BEAT_THIS_CHECKPOINT'=>$models.'/audio/rhythm/beat-this/beat_this-final0.ckpt',
            'EZSTUDIO_STORAGE_ROOT'=>$storage,
            'EZSTUDIO_TMP_ROOT'=>$root.'/var/tmp',
            'EZSTUDIO_LOG_ROOT'=>$root.'/var/logs',
            'EZSTUDIO_RUNTIME_ROOT'=>$storage,
            'EZSTUDIO_DEP_ROOT'=>$sharedRuntime.'/deps',
            'EZSTUDIO_PROFILE_DEP_ROOT'=>$sharedRuntime.'/deps/profile-r3b12',
            'EZSTUDIO_PROFILE_R3B13_DEP_ROOT'=>$sharedRuntime.'/deps/profile-r3b13',
            'EZSTUDIO_PROFILE_R3B10_DEP_ROOT'=>$sharedRuntime.'/deps/profile-r3b10',
            'EZSTUDIO_PASST_PYTHON'=>$sharedRuntime.'/venvs/passt-r3b12/Scripts/python.exe',
            'EZSTUDIO_PASST_TORCH_HOME'=>$runtime.'/cache/passt-torch-home',
            'PANNS_LABELS_CSV'=>$sharedRuntime.'/deps/panns-data/class_labels_indices.csv',
            'EZSTUDIO_TORCH_HOME'=>$runtime.'/cache/torch-home',
            'EZSTUDIO_STEMS_CACHE_ROOT'=>$storage.'/stems',
            'EZSTUDIO_OBSERVABILITY_ROOT'=>$root.'/var/logs/observability',
            'EZSTUDIO_EXPORT_ROOT'=>$storage.'/exports',
            'EZSTUDIO_JOB_ROOT'=>$root.'/var/tmp/jobs',
            'KEEP_UPLOADS'=>'1',
        ];
        foreach ($defaults as $name=>$value) {
            if (!isset($env[$name]) || $env[$name]==='') $env[$name]=$value;
        }
        $env['EZS_TARGET']='lab';
        $env['EZS_EXPECTED_ROOT']=realpath($project) ?: $project;
        $env['PYTHONUTF8']='1';
        $env['PYTHONIOENCODING']='utf-8';
        foreach (['EZS_QUEUE_EXECUTOR_TOKEN','EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY','EZS_QUEUE_LAB_TOKEN'] as $key) unset($env[$key]);
        return $env;
    }
}
