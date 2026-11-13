# ================================================================================
# HANDOFF : CENTRALISATION DE LA FILE D'ATTENTE (REST + SQLITE WAL) & CONSOMMATION
# ================================================================================

## Contexte & Architecture (EZStudio Plateforme v5.1.0)
- **Monorepo multi-noyaux Symfony 7.4 / PHP 8.5** (`H:\EZStudio_plateforme\`).
- **5 noyaux isolés** via `APP_ID` (`orchestrator`, `ezs_lab`, `ezs_assistant`, `ezstudio_dev`, `ezstudio` en PROD figée `.prod-frozen`), servis par **FrankenPHP** sur le port **`8000`**.
- **Socle mutualisé** à la racine : `src/` (namespace `App\`), `config/services.yaml`, `shared/`.
- **Règle d'isolation stricte :** Seul **`orchestrator`** lit et écrit dans sa base SQLite dédiée **`data/orchestrator.sqlite`**. Les autres noyaux (`ezs_lab`, `ezs_assistant`) communiquent **exclusivement en HTTP REST** avec `orchestrator` (aucun accès direct inter-bases).

---

## Ce qui est FAIT & VALIDÉ (Acquis complets — Étapes 1, 2 & 3)

### 1. Base dédiée `data/orchestrator.sqlite` & Noyau `orchestrator`
- **Entité & Service central :** `App\Entity\JobQueue` (`src/Entity/JobQueue.php`) et `App\Service\QueueManager` (`src/Service/QueueManager.php`) avec `dispatch()`, `claimNextPending()` (transaction atomique `pending -> running`), `markCompleted()` et `markFailed()`.
- **Initialisation SQLite WAL & Index :** `App\Service\QueueDatabase` (`src/Service/QueueDatabase.php`, `PRAGMA journal_mode = WAL`, `busy_timeout = 5000`, `foreign_keys = ON`, index sur `(status, created_at, id)` et `(source, status)`).
- **Endpoints REST de coordination :** `Ezs\Web\Module\Coordination\Controller\QueueDispatchController` (`apps/orchestrator/src/Module/Coordination/Controller/QueueDispatchController.php`) :
  - `POST /api/queue/dispatch` (`orchestrator_queue_dispatch`) : enregistre le job (`HTTP 201`) et réveille le consommateur (`--drain`).
  - `POST /api/queue/claim`, `POST /api/queue/{id}/complete`, `POST /api/queue/{id}/fail`.

### 2. Routage Multi-Noyaux FrankenPHP (`:8000`) & Client REST Partagé
- **Aiguillage HTTP par sous-domaine :** `src/Kernel.php` et `public/index.php` (`orchestrator.ezstudio.local:8000`, `lab.ezstudio.local:8000`, `assistant.ezstudio.local:8000`, `dev.ezstudio.local:8000`, `ezstudio.local:8000`).
- **Client REST mutualisé :** `App\Service\OrchestratorQueueClient` (`src/Service/OrchestratorQueueClient.php`) configuré sur `ORCHESTRATOR_BASE_URL=http://ezstudio.local`.

### 3. Branchement complet `ezs_lab` (`profile`, `stems`, `chords`) & `ezs_assistant` (`profile.direct_research`)
- **`ezs_lab` :**
  - `apps/ezs_lab/src/Service/LocalProfileQueue.php` : dispatche `'profile'` vers `orchestrator`.
  - `apps/ezs_lab/src/Service/StemsExecutionService.php` : dispatche `'stems'` vers `orchestrator`.
  - `apps/ezs_lab/src/Service/ChordsExecutionService.php` : dispatche `'chords'` vers `orchestrator`.
- **`ezs_assistant` :**
  - `apps/ezs_assistant/src/Controller/DashboardController.php` (`directResearch`) : dispatche `'profile.direct_research'` vers `orchestrator` (`HTTP 202`).

### 4. Consommation & Exécution centralisée par `orchestrator`
- **Commande console :** `Ezs\Web\Command\QueueConsumeCommand` (`apps/orchestrator/src/Command/QueueConsumeCommand.php`, `orchestrator:queue:consume --drain` / `--loop`) avec verrou exclusif `var/orchestrator_queue_consumer.lock`.
- **Routage des workers :**
  - `assistant` / `profile.direct_research` -> `apps/ezs_assistant/runtime/profile_direct_job.py --run <job_id>`
  - `lab` / `profile` -> `APP_ID=ezs_lab php bin/console lab:profile:drain`
  - `lab` / `stems` & `chords` -> `apps/ezs_lab/python/worker.py`

### 5. Validation Bout en Bout effectuée
- `lint:container` : **`[OK]`** sur `orchestrator`, `ezs_lab` et `ezs_assistant`.
- `php vendor/bin/phpunit` : **`OK (1 test, 4 assertions)`** (`APP_ID=orchestrator`, `APP_ENV=test`).
- Flux REST réel `POST /api/queue/dispatch` -> `orchestrator:queue:consume --drain` -> transition `pending -> running -> completed` vérifiée dans `data/orchestrator.sqlite`.

---

## Prochaines étapes (Production & Supervision)
- Intégrer `php bin/console orchestrator:queue:consume --loop` (avec `APP_ID=orchestrator`) dans la supervision permanente des services `ezs` si un démon continu est souhaité en complément du réveil à la demande (`--drain`).
- Ajouter des tests d'intégration PHPUnit couvrant `claimNextPending()`, `markCompleted()` et `markFailed()`.

---

## Commandes rapides de reprise
```powershell
# Vérifier l'état des services (FrankenPHP :8000, Worker Assistant, Llama :8720)
ezs status

# Dépiler manuellement la file centralisée
\$env:APP_ID = "orchestrator"; php bin/console orchestrator:queue:consume --drain; Remove-Item Env:APP_ID

# Inspecter les derniers jobs enregistrés dans data/orchestrator.sqlite
php -r "require 'vendor/autoload.php'; `$pdo = (new App\Service\QueueDatabase('data/orchestrator.sqlite'))->pdo(); print_r(`\$pdo->query('SELECT id, source, status, payload, created_at, updated_at FROM job_queue ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));"
```
================================================================================