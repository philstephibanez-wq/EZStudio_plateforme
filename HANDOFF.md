# ================================================================================
# HANDOFF : ORDONNANCEUR AGNOSTIQUE (WAITING / PROGRESSING % / ENDED)
# ================================================================================

## 1. Contexte & Architecture Cible (EZStudio Plateforme v5.1.0)
- **Monorepo multi-noyaux Symfony 7.4 / PHP 8.5** (`H:\EZStudio_plateforme\`).
- **5 noyaux isolés** via `APP_ID` (`orchestrator`, `ezs_lab`, `ezs_assistant`, `ezstudio_dev`, `ezstudio` en PROD figée `.prod-frozen`), servis par **FrankenPHP** sur le port **`8000`**.
- **Règles d'isolation strictes :**
  1. Seul **`orchestrator`** lit et écrit dans **`data/orchestrator.sqlite`** (mode WAL).
  2. **`Orchestrator Queue Consumer`** (`orchestrator:queue:consume --loop`), supervisé par `bin\ezs.ps1`, est l'unique démon consommateur de la plateforme.
  3. **Découplage métier total :** L'Ordonnanceur est **100 % agnostique** des métiers des appelants (`ezs_lab`, `ezs_assistant`). Il ne connaît que **3 états génériques** :
     - **`waiting`** : Enregistré (en attente).
     - **`progressing`** (`0..100 %`) : En cours d'exécution, avec remontée du pourcentage d'avancement en temps réel par l'acteur.
     - **`ended`** : Terminé (avec indicateur de réussite ou d'erreur).
  4. Les appelants (`ezs_lab`, `ezs_assistant`) soumettent leurs demandes via REST, reçoivent la main un par un lorsque l'Ordonnanceur les réveille, gèrent leurs étapes métier en interne et remontent uniquement leur état et leur `%` d'avancement à l'Ordonnanceur.

---

## 2. Ce qui est FAIT & VALIDÉ (Commits `308d882` -> `6043316`)
- **Socle SQLite WAL & REST (`orchestrator`) :**
  - `src/Service/QueueDatabase.php`, `src/Entity/JobQueue.php`, `src/Service/QueueManager.php`.
  - `apps/orchestrator/src/Module/Coordination/Controller/QueueDispatchController.php` (`POST /api/queue/dispatch`, `/claim`, `/{id}/complete`, `/{id}/fail`).
  - `src/Service/OrchestratorQueueClient.php` (`ORCHESTRATOR_BASE_URL=http://ezstudio.local:8000`).
- **Branchements REST & Suppression du legacy local :**
  - `ezs_lab` : `LocalProfileQueue` (`profile`), `StemsExecutionService` (`stems`), `ChordsExecutionService` (`chords`), `ProfileAiRequestService` (`profile.review`) dispatchent vers `orchestrator`. Anciens spawners locaux supprimés.
  - `ezs_assistant` : `DashboardController` (`profile.direct_research`) dispatche vers `orchestrator`. Ancien démon de polling `profile_ai_requests_worker.py` retiré de `bin\ezs.ps1`.
- **Démon permanent & Publication `EZStudio_Run_Reports` :**
  - `bin\ezs.ps1` : `ezs start / stop / restart / status` supervise FrankenPHP (`:8000`) et `Orchestrator Queue Consumer` (`--loop`).
  - `apps/orchestrator/src/Command/QueueConsumeCommand.php` : exécute les jobs et déclenche `publish_run_reports.py` pour `lab` et `assistant`.
- **Tests validés :**
  - `php vendor/bin/phpunit` : **OK (3 tests, 30 assertions)**.
  - Exécutions réelles validées : `profile` (`run #204`, `run #206`), expertise Assistant (`run #206`, `Révision IA #88`) et publication vers `EZStudio_Run_Reports`.

---

## 3. Modifications locales en cours (non committées)
- `apps/orchestrator/src/Command/QueueConsumeCommand.php` : passage de `lab:profile:drain` à l'exécution unitaire `lab:profile:once --job-id=<id> --apply` (1 job Orchestrator = 1 morceau).
- `apps/orchestrator/src/Module/Coordination/GlobalQueueDashboard.php` : lecture branchée sur `data/orchestrator.sqlite` (`job_queue`).
- `apps/orchestrator/templates/layout/base.html.twig`, `jobs/index.html.twig`, `infrastructure/index.html.twig` : onglet renommé **Ordonnanceur** et actualisation de la table passée à **4 secondes**.

---

## 4. Actions immédiates à la reprise

### Étape A — Débloquer les 36 tâches `queued` restantes dans `apps/ezs_lab/data/benchmark.sqlite`
Le statut `running` a déjà été nettoyé. Pour annuler proprement les 36 lignes `queued` protégées par le déclencheur `trg_global_queue_admission_fence_status`, lire d'abord le schéma exact de `global_queue_terminal_receipts` via `PRAGMA table_info(global_queue_terminal_receipts)`, puis insérer le reçu terminal avec les colonnes exactes et passer les 36 lignes `queued` à `cancelled`.

### Étape B — Implémenter le contrat agnostique `waiting` / `progressing (%)` / `ended`
1. **`QueueDatabase.php`, `JobQueue.php`, `QueueManager.php` :**
   - Ajouter `progress` (`INTEGER DEFAULT 0`) et `has_error` (`INTEGER DEFAULT 0`) dans `job_queue`.
   - Standardiser les 3 états : **`waiting`**, **`progressing`**, **`ended`**.
2. **`QueueDispatchController.php` & `OrchestratorQueueClient.php` :**
   - Ajouter `POST /api/queue/{id}/progress` (`{"progress": 0..100}`) et la méthode `OrchestratorQueueClient::reportProgress(int $jobId, int $progress)`.
3. **Découplage total de `QueueConsumeCommand.php` & `GlobalQueueDashboard.php` :**
   - `QueueConsumeCommand` ne lit plus le métier du payload : il délègue à l'acteur (`lab` ou `assistant`) en passant l'ID du job Orchestrator.
   - `GlobalQueueDashboard` n'accède plus à `benchmark.sqlite` (`QueueSongLabels` retiré) et affiche uniquement les états génériques (`Enregistré (en attente)`, `En progression (%)`, `Terminé`).
4. **Remontée d'avancement côté `ezs_lab` & `ezs_assistant` :**
   - Appeler `POST /api/queue/{id}/progress` à chaque palier d'avancement pendant l'exécution.
5. **Redémarrer la plateforme (`ezs start`), valider via PHPUnit + test réel, puis committer selon `VERSIONING.md`.**