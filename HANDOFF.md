# ================================================================================
# HANDOFF : CENTRALISATION DE LA FILE D'ATTENTE (REST + SQLITE WAL) & CONSOMMATION
# ================================================================================

## Contexte & Architecture (EZStudio Plateforme v5.1.0)
- **Monorepo multi-noyaux Symfony 7.4 / PHP 8.5** (`H:\EZStudio_plateforme\`).
- **5 noyaux isolés** via `APP_ID` (`orchestrator`, `ezs_lab`, `ezs_assistant`, `ezstudio_dev`, `ezstudio` en PROD figée `.prod-frozen`), servis par **FrankenPHP** sur le port **`8000`**.
- **Socle mutualisé** à la racine : `src/` (namespace `App\`), `config/services.yaml`, `shared/`.
- **Règle d'isolation stricte & R2 :** Seul **`orchestrator`** lit et écrit dans sa base SQLite dédiée **`data/orchestrator.sqlite`**, et **`Orchestrator Queue Consumer`** (`orchestrator:queue:consume --loop`) est l'unique démon consommateur supervisé par `bin/ezs.ps1`.

---

## Ce qui est FAIT & VALIDÉ (Acquis complets — Étapes 1 à 4)

### 1. Base dédiée `data/orchestrator.sqlite` & Noyau `orchestrator`
- **Entité & Service central :** `App\Entity\JobQueue` (`src/Entity/JobQueue.php`) et `App\Service\QueueManager` (`src/Service/QueueManager.php`) avec `dispatch()`, `claimNextPending()` (transaction atomique `pending -> running`), `markCompleted()` et `markFailed()`.
- **Initialisation SQLite WAL & Index :** `App\Service\QueueDatabase` (`src/Service/QueueDatabase.php`, `PRAGMA journal_mode = WAL`, `busy_timeout = 5000`, `foreign_keys = ON`, index sur `(status, created_at, id)` et `(source, status)`).
- **Endpoints REST de coordination :** `Ezs\Web\Module\Coordination\Controller\QueueDispatchController` (`apps/orchestrator/src/Module/Coordination/Controller/QueueDispatchController.php`) :
  - `POST /api/queue/dispatch` (`orchestrator_queue_dispatch`) : enregistre le job (`HTTP 201`) et déclenche le réveil non bloquant.
  - `POST /api/queue/claim`, `POST /api/queue/{id}/complete`, `POST /api/queue/{id}/fail`.

### 2. Routage Multi-Noyaux FrankenPHP (`:8000`) & Client REST Partagé
- **Aiguillage HTTP par sous-domaine :** `src/Kernel.php` et `public/index.php` (`orchestrator.ezstudio.local:8000`, `lab.ezstudio.local:8000`, `assistant.ezstudio.local:8000`, `dev.ezstudio.local:8000`, `ezstudio.local:8000`).
- **Client REST mutualisé :** `App\Service\OrchestratorQueueClient` (`src/Service/OrchestratorQueueClient.php`) configuré sur `ORCHESTRATOR_BASE_URL=http://ezstudio.local:8000`.

### 3. Branchement complet `ezs_lab` & `ezs_assistant` + Suppression du legacy local
- **`ezs_lab` :**
  - `apps/ezs_lab/src/Service/LocalProfileQueue.php`, `StemsExecutionService.php`, `ChordsExecutionService.php` : dispatchent `'profile'`, `'stems'` et `'chords'` vers `orchestrator`.
  - Nettoyage complet du legacy local (`LocalProfileQueue::wake()`, appels `wake()` dans `ProfileController` / `WorkbenchController` / `ProfileAiRequestService`, spawners détachés dans `BenchmarkLauncher`).
- **`ezs_assistant` :**
  - `apps/ezs_lab/src/Service/ProfileAiRequestService.php` : dispatche `'profile.review'` (`assistant.profile.review`) vers `orchestrator`.
  - `apps/ezs_assistant/src/Controller/DashboardController.php` : dispatche `'profile.direct_research'` vers `orchestrator` (`HTTP 202`).
  - Suppression de l'ancien démon de polling `profile_ai_requests_worker.py` dans `bin/ezs.ps1`.

### 4. Consommation centralisée, Supervision `ezs` & Publication `EZStudio_Run_Reports`
- **Commande console :** `Ezs\Web\Command\QueueConsumeCommand` (`apps/orchestrator/src/Command/QueueConsumeCommand.php`, `orchestrator:queue:consume --drain` / `--loop`) avec verrou exclusif `var/orchestrator_queue_consumer.lock` et publication automatique vers `EZStudio_Run_Reports` (`publishReports` pour `lab` et `assistant`).
- **Routage des workers :**
  - `assistant` / `profile.review` -> `apps/ezs_assistant/runtime/profile_ai_requests_worker.py --once --job-id <id> --run-id <rid>`
  - `assistant` / `profile.direct_research` -> `apps/ezs_assistant/runtime/profile_direct_job.py --run <job_id>`
  - `lab` / `profile` -> `APP_ID=ezs_lab php bin/console lab:profile:drain`
  - `lab` / `stems` & `chords` -> `apps/ezs_lab/python/worker.py`
- **Supervision unifiée (`bin/ezs.ps1`) :** `ezs start / stop / restart / status` démarre et supervise FrankenPHP (`:8000`) et `Orchestrator Queue Consumer` (`--loop`).

### 5. Validation Bout en Bout effectuée
- `lint:container` : **`[OK]`** sur `orchestrator`, `ezs_lab` et `ezs_assistant`.
- `php vendor/bin/phpunit` : **`OK (3 tests, 30 assertions)`** (`QueueManagerTest` + `QueueDispatchControllerTest`).
- **Tests réels validés de bout en bout :**
  - Analyse scientifique `profile` (`run #204` et `run #206` sur *Le Sud*) déclenchée via UI LAB -> `job #15` / `job #17` consommés automatiquement par `orchestrator` -> rapport `lab/profile/run-000206.json` publié sur `EZStudio_Run_Reports`.
  - Expertise IA Assistant (`run #206`, `Révision IA #88`) déclenchée via UI -> `job #18` (`source = assistant`) consommé automatiquement par `orchestrator` -> rapport `assistant/profile/run-000206/revision-000088.json` publié sur `EZStudio_Run_Reports`.

---

## Prochaine étape
- Pousser la branche `master` vers `origin/master` et poursuivre le déploiement des workers `stems` et `chords` en conditions réelles.