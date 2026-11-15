# Handoff — Clôture de l'Issue #2 (`[P1][Queue] Sécuriser la relance des jobs et synchroniser les états métier LAB/Assistant`)

## 1. Contexte et acquis (Commit `00971a006c37` — `v5.1.0.O0a2`)
* **Dépôt** : `H:\EZStudio_plateforme` (Windows, PHP 8.5 à `H:\PHP\php-8.5-x64\php.exe`, Python 3.14 à `H:\Python\pythoncore-3.14-64\python.exe`).
* **Acquis validés en `v5.1.0.O0a2`** :
  * Preuve de fin de processus avant reprise (`worker_pid` + `QueueManager::isProcessAlive()`).
  * Déduplication des admissions actives par `(source, job, song_id)` (`QueueManager::findActiveDuplicate()`) et suppression du double `dispatch()` dans `StemsExecutionService` et `ChordsExecutionService`.
  * Sécurisation des endpoints REST internes `/api/queue/*` via `X-EZS-Queue-Token` (PHP + `apps/ezs_lab/python/worker.py` + `apps/ezs_assistant/runtime/profile_direct_job.py`).
  * Interface `/jobs` dotée des boutons **Relancer** (sur le même identifiant) et **Supprimer** (avec modale de confirmation Bootstrap `#deleteJobModal`, sans dialogue natif du navigateur).
  * Suite PHPUnit au vert (`4 tests, 41 assertions`).

## 2. Défauts restants à corriger (Issue #2)
1. **Absence de synchronisation métier à la relance** : `QueueManager::retry(int $id)` remet uniquement la table centrale `job_queue` en `waiting`, sans réarmer l'enregistrement local dans **LAB** (`analysis_jobs` / `scientific_runs` en `failed`/`error`) ni dans **Assistant** (`profile_ai_requests` en `error`), ce qui provoque un refus au claim local (`ProfileOnceCommand` et `profile_ai_requests_worker.py` exigent `queued`).
2. **Absence de protection CSRF sur les actions UI** : `POST /jobs/{id}/retry` et `POST /jobs/{id}/delete` dans `JobsPageController.php` n'ont pas de jeton CSRF validé (`symfony/security-csrf` n'est pas installé dans `orchestrator`).

## 3. Contraintes impératives
* **Périmètre** : Ordonnanceur (`orchestrator`) et protocole métier de reprise uniquement. Ne pas toucher aux moteurs scientifiques ni à la PROD figée.
* **Langue et format** : Répondre **uniquement en français**. Fournir des blocs PowerShell **courts (< 25 lignes)** écrits en **UTF-8 sans BOM** (`[System.Text.UTF8Encoding]::new($false)`).
* **Versioning** : Respecter `VERSIONING.md` avec un descriptif détaillé fichier par fichier dans le commit final.

## 4. Plan d'exécution étape par étape

### Étape 1 — Inspection des schémas locaux LAB et Assistant (Lecture seule)
Identifier les fichiers et colonnes SQLite qui gèrent les statuts locaux (`queued`, `running`, `failed`, `error`) dans `ezs_lab` et `ezs_assistant` :
```powershell
Get-ChildItem "apps\ezs_lab\src" -Recurse -Filter "*.php" | Select-String -Pattern "analysis_jobs|scientific_runs" -List | Select-Object -First 4 Path
Get-ChildItem "apps\ezs_assistant" -Recurse -Filter "*.py" | Select-String -Pattern "queued" -List | Select-Object -First 4 Path
```

### Étape 2 — Protection CSRF sur `/jobs/{id}/retry` et `/jobs/{id}/delete`
* Implémenter un gestionnaire de jeton CSRF (via `symfony/security-csrf` ou HMAC SHA-256 signé avec `APP_SECRET` + session + action + `job_id`).
* Injecter le champ caché `_token` dans les formulaires de `apps/orchestrator/templates/jobs/index.html.twig` (relance + modale Bootstrap `#deleteJobModal`).
* Rejeter en `HTTP 403` toute requête POST sans jeton valide dans `JobsPageController.php`.

### Étape 3 — Contrat de réarmement métier coordonné (`JobRetryCoordinator`)
* **LAB (`profile`)** : vérifier l'entrée locale (`analysis_jobs` / `scientific_runs`), la repasser en `queued` (erreur réinitialisée) avant de remettre `job_queue` en `waiting`.
* **LAB (`stems` / `chords`)** : vérifier l'intégrité du `run_id` et des artefacts partiels avant de réarmer l'état local et central.
* **Assistant (`profile.review` / `direct`)** : repasser la requête locale (`profile_ai_requests`) en `queued` (`error = NULL`) avant la remise en file centrale.
* **Garde d'éligibilité** : refuser explicitement la relance (avec message d'alerte dans `/jobs`) si l'entrée métier est introuvable, verrouillée par un `worker_pid` actif ou déjà terminée avec succès.

### Étape 4 — Tests PHPUnit, recette en direct et commit
* Ajouter les tests automatiques dans `tests/Controller/` : rejet `403` sans CSRF, refus de relance sur job non réadmissible, relance réussie coordonnée (`PROFILE`, `Assistant`, `STEMS`, `CHORDS`) et résistance au double clic.
* Valider sur des jobs réellement échoués puis commiter selon `VERSIONING.md`.
