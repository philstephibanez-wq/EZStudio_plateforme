# Recette Ciblee — Issue #1 (Suppression doublons queue_id & unicite du POST dispatch)

- **Date :** 2026-11-15
- **Jalon :** 5.1.0.O0a9 (pps/orchestrator 4.3a37)
- **Modifications :**
  * pps/ezs_lab/src/Service/StemsExecutionService.php : suppression de la cle queue_id en double dans le tableau de retour de queue().
  * pps/ezs_lab/src/Service/ChordsExecutionService.php : suppression de la cle queue_id en double dans le tableau de retour de queue().
  * 	ests/Service/LabSingleDispatchTest.php : test PHPUnit avec Symfony\\Component\\HttpClient\\MockHttpClient confirmant exactement **1 seule requete HTTP POST /api/queue/dispatch** par appel a StemsExecutionService::queue() et **1 seule requete HTTP POST /api/queue/dispatch** par appel a ChordsExecutionService::queue().
- **Validation :** php bin/phpunit --testdox (OK (8 tests, 71 assertions)), php -l et lint:container [OK].
