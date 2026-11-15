# Recette & Validation Technique — Issue #2 (v5.1.0.O0a5)

- **Date :** 2026-11-15
- **Branche :** master (jalon 5.1.0.O0a5 / pps/orchestrator 4.3a33)
- **Isolation :** ar/orchestrator_test.sqlite et ar/benchmark_test.sqlite (0 residu sur data/orchestrator.sqlite et pps/ezs_lab/data/benchmark.sqlite).

## 1. Tests unitaires & d'integration automatises (php bin/phpunit --testdox)
- **Resultat :** OK (7 tests, 57 assertions).
- **Couverture :**
  * JobRetrySecurityTest::testRemoteAccessControlAndLocalBypass : rejet HTTP 401/403 pour IP distante (10.99.0.5), acces local transparent sans popup (127.0.0.1 / ::1).
  * JobRetrySecurityTest::testDirectResearchHexFileAndKindRunSongIntegrity : rejet des incoherences kind (incoherence_kind_metier), scientific_run_id (incoherence_scientific_run_id) et song_id (incoherence_song_id) ; rearmement atomique des fichiers hex32 untime/state/profile-direct/{job_id}.result.json.
  * JobRetrySecurityTest::testSagaRollbackIdempotentReconciliationAndConsumeCommand : verrouillage exclusif LockFactory (errou_relance_actif), rollback compensatoire SQLite (echec_rearmement_central), reconciliation idempotente apres interruption inter-bases (elance_reconciliee_idempotente) et execution de orchestrator:queue:consume --drain.

## 2. Recette operationnelle reelle sur Windows (orchestrator:queue:consume --drain --env=test -vv)
- **Reconciliation au demarrage :** [Reconcile] 1 job(s) divergents reconcilies. -> execution reelle de profile_direct_job.py --run <hex32> jusqu'a [Job #88] ended (OK) (ttempt=2).
- **Enveloppes validees :** alignement strict sur QueueConsumeCommand::dispatchToActor() (source='lab' pour profile, stems, chords ; source='assistant' pour profile.review, profile.direct_research).
- **Schema reel scientific_runs :** mise a jour des colonnes reelles state = 'queued', finished_at = NULL (et autorisation d'un run frere deja queued).

## 3. Verification d'integrite production
- php bin/console lint:yaml config/ apps/orchestrator/config/ apps/ezs_lab/config/ : [OK].
- php bin/console lint:container --env=dev et --env=test : [OK].
- Bases de production data/orchestrator.sqlite et pps/ezs_lab/data/benchmark.sqlite : intactes.
