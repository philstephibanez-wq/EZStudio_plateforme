# HANDOFF — EZStudio Plateforme (Chantiers P0, P1.1, P1.2 & P1.3 — `v5.1.0.O0a2`)

## 1. Contexte & État du dépôt
- **Répertoire racine** : `H:\EZStudio_plateforme`
- **Branche** : `master`
- **Dernier commit** : `70d9c9d` (`v5.1.0.O0a1 feat(workflow,queue): migration du cycle de vie des jobs vers symfony/workflow (job_lifecycle)`)
- **Tests PHPUnit actuels** : `OK (4 tests, 53 assertions)` (`H:\PHP\php-8.5-x64\php.exe vendor/bin/phpunit`)
- **Objectif stratégique** : Conserver l'architecture actuelle (Symfony multi-site + `symfony/workflow` `job_lifecycle` + workers PHP/Python) sans refactorisation générale, et verrouiller les **4 failles opérationnelles identifiées lors de l'audit du 14 novembre 2026**.

---

## 2. Acquis validés (Commits `044eda6` & `70d9c9d`)
1. **Machine à états finis `job_lifecycle` (`symfony/workflow` 7.4)** :
   - **Places** : `waiting`, `progressing`, `cancelling`, `cancelled`, `ended`.
   - **Transitions** :
     - `start` (`waiting` -> `progressing`)
     - `request_cancel` (`progressing` -> `cancelling`)
     - `cancel` (`waiting` -> `cancelled`)
     - `confirm_cancel` (`cancelling` -> `cancelled`)
     - `complete` (`progressing` / `cancelling` -> `ended`)
     - `fail` (`progressing` -> `ended`)
   - **Configuration** : déployée dans `config/packages/workflow.yaml`, `apps/orchestrator/config/packages/workflow.yaml` et `apps/ezs_lab/config/packages/workflow.yaml`. Diagramme dans `docs/job_lifecycle.md`.
2. **Gouvernance & Verrouillage terminal** :
   - `App\Entity\JobQueue`, `App\Service\QueueManager` et `QueueDispatchController` pilotés par `WorkflowInterface` (`can()` / `apply()`).
   - Aucun état terminal (`cancelled` / `ended`) ne peut être écrasé par une clôture tardive de worker.
   - `claimNextWaiting()` garantit l'exclusivité stricte (aucun job lancé tant qu'un job est `progressing` ou `cancelling`).
3. **Architecture & Cartographie des fichiers audités** :
   - **Racine (`src/`)** :
     - `src/Entity/JobQueue.php` (entité Doctrine de la table `job_queue`)
     - `src/Service/QueueDatabase.php` (initialisation SQLite + `ensureSchema()` avec `ALTER TABLE` idempotents)
     - `src/Service/QueueManager.php` (logique d'ordonnancement, transitions workflow, `dispatch()`, `claimNextWaiting()`, `recoverInterruptedJobs()`, `confirmCancel()`)
     - `src/Service/OrchestratorQueueClient.php` (client HTTP vers les endpoints `/api/queue/*`)
   - **Orchestrator (`apps/orchestrator/src/`)** :
     - `apps/orchestrator/src/Command/QueueConsumeCommand.php` (consommateur worker qui réclame et exécute les jobs)
     - `apps/orchestrator/src/Module/Coordination/Controller/QueueDispatchController.php` (endpoints REST `/api/queue/*`)
     - `apps/orchestrator/src/Module/Coordination/WindowsExecutorObservation.php` (observation des processus Windows)
   - **Lab (`apps/ezs_lab/src/`)** :
     - Contrôleurs de dispatch `STEMS` et `CHORDS` + `LocalProfileQueue`.
   - **Tests (`tests/`)** :
     - `tests/Controller/QueueDispatchControllerTest.php`

---

## 3. Cahier des charges des 4 chantiers à exécuter (`v5.1.0.O0a2`)

### Chantier P0 — Preuve d'arrêt effectif du processus (`worker_pid`) avant reprise ou annulation définitive
- **Problème** : `QueueManager::recoverInterruptedJobs()` remet les jobs `progressing` en `waiting` (et `cancelling` en `cancelled`) sur critère purement temporel, sans vérifier si le sous-processus Python/PHP est réellement arrêté (risque d'exécution parallèle sur le même fichier audio).
- **Spécifications techniques** :
  1. Ajouter la propriété `workerPid` (`?int`, colonne `worker_pid INTEGER DEFAULT NULL`) dans `App\Entity\JobQueue` (getter/setter + sérialisation dans `toArray()`) et dans `App\Service\QueueDatabase::ensureSchema()`.
  2. Ajouter la méthode `QueueManager::attachWorkerPid(int $id, int $pid): ?JobQueue`, la route `POST /api/queue/{id}/attach-pid` dans `QueueDispatchController` et la méthode `OrchestratorQueueClient::attachPid(int $id, int $pid)` appelée depuis `QueueConsumeCommand` dès le démarrage du sous-processus.
  3. Implémenter un vérificateur d'activité process (`ProcessLivenessChecker` / intégration `WindowsExecutorObservation` utilisant `tasklist /FI "PID eq <pid>" /FO CSV /NH` sous Windows, injectable pour les tests unitaires) dans `QueueManager::recoverInterruptedJobs()` et `confirmCancel()` :
     - Si `worker_pid` est renseigné **et toujours actif**, interdire la remise en `waiting` (et bloquer `confirm_cancel` tant que le processus tourne).
     - N'appliquer la transition (`waiting` ou `cancelled`) **qu'avec preuve que le processus est terminé** (et remettre `worker_pid` à `null` lors du retour en `waiting`).

### Chantier P1.1 — Idempotence métier sur le dispatch `STEMS` / `CHORDS` (Issue #1)
- **Problème** : Un double déclenchement côté `ezs_lab` ou via l'API peut enfiler deux jobs identiques pour un même morceau et une même phase (`stems` ou `chords`).
- **Spécifications techniques** :
  1. Dans `QueueManager::dispatch(string $source, array $payload, int $priority = 100)`, calculer une signature métier déterministe basée sur `source` + `payload['job']` (ou `phase`/`type`) + `payload['song_id']`.
  2. Rechercher tout job existant en état non terminal (`waiting`, `progressing`, `cancelling`) portant cette même signature : s'il existe, le retourner directement (avec indicateur `deduplicated: true` dans la réponse API) sans créer de doublon.
  3. Vérifier les contrôleurs de dispatch `STEMS` et `CHORDS` dans `apps/ezs_lab/src/` pour s'assurer qu'ils réutilisent le `queue_id` retourné et alignent la déduplication locale sur `LocalProfileQueue::enqueue()`.

### Chantier P1.2 — Sécurisation des endpoints REST `/api/queue/*`
- **Problème** : Les routes `/api/queue/dispatch`, `/claim`, `/{id}/progress`, `/{id}/complete`, `/{id}/fail`, `/{id}/cancel`, `/{id}/attach-pid` ne vérifient pas de jeton interne.
- **Spécifications techniques** :
  1. Définir `EZS_QUEUE_INTERNAL_TOKEN` dans `.env` et `.env.test` (valeur dédiée pour les tests).
  2. Injecter systématiquement l'en-tête HTTP `X-EZS-Queue-Token` dans `App\Service\OrchestratorQueueClient`.
  3. Créer un `EventSubscriber` (`QueueApiTokenSubscriber` enregistré sur le noyau racine et `apps/orchestrator`) interceptant toute requête dont le chemin commence par `/api/queue` : rejeter en `401 Unauthorized` (JSON `{"error":"unauthorized"}`) si l'en-tête `X-EZS-Queue-Token` est absent ou ne correspond pas au jeton configuré (comparaison temps constant `hash_equals()`).

### Chantier P1.3 — Extension de la suite PHPUnit
- **Spécifications techniques** : Étendre `tests/Controller/QueueDispatchControllerTest.php` pour prouver :
  1. Le rejet HTTP `401` sur `/api/queue/*` sans `X-EZS-Queue-Token` ou avec un jeton invalide, et le succès `200`/`201` avec le bon jeton.
  2. L'idempotence du dispatch : double envoi `stems` puis `chords` sur le même `song_id` -> même `queue_id` retourné tant que le job est actif, puis nouveau job autorisé une fois passé en `ended` ou `cancelled`.
  3. Le verrouillage PID : `recoverInterruptedJobs()` refuse de remettre un job `progressing` périmé en `waiting` tant que son `worker_pid` est signalé actif, puis le remet en `waiting` dès que le processus est terminé.

---

## 4. Contraintes méthodologiques & règles strictes du dépôt
1. **Format de réponse (`CONTRAT_METHODOLOGIE.md`)** :
   - Structurer chaque livraison en 9 points : *1. Constat, 2. Cause, 3. Audit, 4. Décision, 5. Modifications, 6. Livrable (script PowerShell générant le patch ZIP + sauvegarde préalable), 7. Commandes, 8. Validation, 9. Suite*.
   - **Règle d'extraction** : L'assistant ne peut pas lire les archives `.zip` envoyées directement ; lui fournir uniquement des fichiers `.txt` ou `.php` individuels.
2. **Périmètre & Isolation (`REPERTOIRES.md`)** :
   - Ne jamais toucher à `apps/ezstudio` (PROD).
   - Respecter l'isolation entre `apps/orchestrator`, `apps/ezs_lab`, `apps/ezs_assistant` et `src/`.
3. **Encodage & Outillage Windows** :
   - PHP : `H:\PHP\php-8.5-x64\php.exe`
   - Python : `H:\Python\pythoncore-3.14-64\python.exe`
   - Écrire tous les fichiers PHP en **UTF-8 sans BOM** (`[System.Text.UTF8Encoding]::new($false)`) pour préserver `declare(strict_types=1);` en ligne 1.
   - Valider avec `H:\PHP\php-8.5-x64\php.exe vendor/bin/phpunit` avant commit.
4. **Format de commit obligatoire (`VERSIONING.md`)** :
   ```text
   v5.1.0.O0a2 feat(queue,security): preuve PID, idempotence dispatch et jeton REST interne

   - Contexte : verrouillage des 4 failles d'ordonnancement de l'audit du 14/11/2026
   - Modifications : worker_pid + garde liveness, déduplication STEMS/CHORDS, X-EZS-Queue-Token, tests PHPUnit
   - Validation / Prochaine étape : PHPUnit OK, recette orchestrator/ezs_lab
   ```

---

## 5. Action immédiate pour démarrer la prochaine session
1. Lancer dans `H:\EZStudio_plateforme` la commande PowerShell suivante pour exporter en un seul fichier texte léger (~60 Ko) les fichiers cibles de la base, de l'orchestrateur, du lab et des tests :
   ```powershell
   $root = "H:\EZStudio_plateforme"
   $out = "H:\EZStudio_plateforme\contexte_v5_1_0_O0a2.txt"
   $targets = @(
       ".env",
       ".env.test",
       "src\Entity\JobQueue.php",
       "src\Service\QueueDatabase.php",
       "src\Service\QueueManager.php",
       "src\Service\OrchestratorQueueClient.php",
       "apps\orchestrator\src\Command\QueueConsumeCommand.php",
       "apps\orchestrator\src\Module\Coordination\Controller\QueueDispatchController.php",
       "apps\orchestrator\src\Module\Coordination\WindowsExecutorObservation.php",
       "tests\Controller\QueueDispatchControllerTest.php"
   )
   $labQueueFiles = Get-ChildItem -Path (Join-Path $root "apps\ezs_lab\src") -Recurse -File -Filter "*.php" -ErrorAction SilentlyContinue |
       Where-Object { $_.Name -match "Stem|Chord|Queue|Profile" } |
