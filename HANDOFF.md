# HANDOFF — Généralisation de l'Expertise Multi-Items (`PROFILE`, `STEMS`, `CHORDS`, `LYRICS`)

- **Date :** 2026-11-15
- **Dépôt :** `EZStudio_plateforme` (`master`)
- **Socle acquis :**
  - Clôture des Issues #1 et #2 (unicité des dispatches REST `STEMS`/`CHORDS`, réconciliation idempotente, relance coordonnée avec contrôle d'intégrité `kind`/`scientific_run_id`/`song_id` et préservation des moteurs/presets déclaratifs).
  - Mutualisation des design tokens CSS (`shared/assets/css/ezs-tokens.css`, `public/shared/css/ezs-tokens.css`) et macros Twig (`shared/templates/macros/ezs_ui.html.twig`) sur `shared`, `orchestrator`, `ezs_lab` et `ezs_assistant`.
  - Colonne & filtre `Item` (`Profile`, `Stems`, `Chords`, `Lyrics`) actifs sur `/jobs`.

---

## 1. Principes directeurs & Gouvernance

1. **Séparation stricte Analyse vs Expertise :**
   - **LAB (`source = 'lab'`) :** produit et persiste les faits scientifiques calculés (`profile`, `stems`, `chords`, `lyrics`). Autorité unique de vérité sur la donnée musicale et la base `benchmark.sqlite`.
   - **Assistant (`source = 'assistant'`) :** exécute la contre-expertise externe (`profile.review`, `stems.review`, `chords.review`, `lyrics.review`) et publie des révisions et assertions proposées. Ne modifie jamais directement `benchmark.sqlite`.
   - **Orchestrator :** pilote uniquement le cycle de vie (`waiting` -> `progressing` -> `ended`) via l'API REST `/api/queue/*` et le consommateur unique (`1 job actif maximum` pour protéger le GPU).
2. **Moteurs déclaratifs & presets propres à chaque étape :**
   - Chaque étape (`analyse` côté LAB et `expertise` côté Assistant) déclare explicitement son moteur (`engine` / `engine_profile`) et son `preset` dans `request_json` et dans l'enveloppe `job_queue.payload`.
   - Aucune étape ne réutilise implicitement le moteur ou le preset d'une autre étape ; `JobRetryCoordinator` vérifie et préserve ces paramètres lors de toute relance.
3. **Convention canonique des enveloppes Orchestrator :**
   - **Analyse (`source = 'lab'`) :** `job` parmi `profile`, `stems`, `chords`, `lyrics`.
   - **Expertise (`source = 'assistant'`) :** `job` parmi `profile.review`, `stems.review`, `chords.review`, `lyrics.review` (plus `profile.direct_research` pour la recherche directe).
   - **Types SQLite LAB (`analysis_jobs.kind`) :** `profile`, `stems` / `stems_scientific`, `chords_scientific` / `chords`, `lyrics_scientific` / `lyrics`, et `assistant.<item>.review`.

---

## 2. Contrat universel d'expertise (`ezstudio.item-review.v1`)

Chaque contre-expertise (`profile`, `stems`, `chords`, `lyrics`) repose sur un contrat JSON commun :
- **En-tête :** `schema_version`, `item` (`profile|stems|chords|lyrics`), `song_id`, `scientific_run_id`, `review_job_id`, `engine`, `engine_profile`, `preset`.
- **Entrées (LAB -> Assistant) :** `facts` (artefacts et métriques produits par l'analyse), `claims` (hypothèses/assertions à vérifier), `context` (métadonnées morceau & historique).
- **Sorties (Assistant -> LAB) :** `proposed_verdict` (`accepted|adjusted|rejected|needs_human_review`), `final_assertions` (valeurs retenues + confiance + justification), `revision` (horodatage, modèle, durée, empreinte).

---

## 3. Plan d'exécution complet — Étape par Étape

### Étape 1 — Schéma SQLite générique & Migration rétrocompatible (`apps/ezs_lab`)
- **Objectif :** Généraliser le stockage des expertises sans casser `PROFILE`.
- **Actions :**
  1. Créer les tables génériques dans `apps/ezs_lab/src/Service/Database.php` :
     - `item_reviews` (`id`, `item`, `song_id`, `scientific_run_id`, `status`, `engine`, `engine_profile`, `preset`, `created_at`, `updated_at`)
     - `item_review_revisions` (`id`, `review_id`, `revision_no`, `verdict`, `payload_json`, `created_at`)
     - `item_final_assertions` (`id`, `review_id`, `item`, `scientific_run_id`, `assertions_json`, `updated_at`)
     - `item_review_dispatches` (`id`, `review_id`, `analysis_job_id`, `orchestrator_queue_id`, `dispatched_at`)
  2. Migrer les données existantes des tables historiques `profile_*` vers `item_*` avec `item = 'profile'` (migration idempotente au démarrage).
  3. Ajouter la création des jobs d'expertise génériques dans `analysis_jobs` avec `kind = 'assistant.<item>.review'` et conservation des paramètres déclaratifs (`engine`, `engine_profile`, `preset`) dans `request_json`.
- **Validation :** Tests PHPUnit d'intégrité SQLite sur base temporaire + vérification rétrocompatibilité `PROFILE`.

### Étape 2 — Service générique & API interne LAB (`apps/ezs_lab`)
- **Objectif :** Exposer un contrat REST uniforme pour les 4 items.
- **Actions :**
  1. Créer `ItemExternalReviewService` et `ItemAiRequestService` paramétrés par `$item` (`profile`, `stems`, `chords`, `lyrics`), chaque item résolvant son moteur déclaratif et son preset configurés.
  2. Exposer les routes internes sous `/api/internal/reviews/{item}/...` (`queue`, `latest`, `run/{id}`, `run/{id}/final-assertions`, `submit`) avec validation stricte `{item}` dans `<profile|stems|chords|lyrics>`.
  3. Conserver les routes existantes `/api/internal/profile/reviews/...` comme délégation directe vers `item = 'profile'`.
- **Validation :** `php bin/console debug:router` (`APP_ID=ezs_lab`), `lint:container` et tests fonctionnels des endpoints REST.

### Étape 3 — Généralisation du Worker Assistant (`apps/ezs_assistant`)
- **Objectif :** Permettre au worker Python d'exécuter les expertises des 4 items avec leurs moteurs/presets dédiés.
- **Actions :**
  1. Étendre `apps/ezs_assistant/runtime/profile_ai_requests_worker.py` (ou créer un point d'entrée unifié `item_reviews_worker.py` rétrocompatible) avec l'option `--item <profile|stems|chords|lyrics>` (défaut `profile`).
  2. Charger la configuration déclarative du moteur et du preset correspondant au couple `(expertise, item)` avant d'interroger le backend IA/LLM.
  3. Publier la révision et les `final_assertions` sur `/api/internal/reviews/{item}` et reporter la progression (`0..100 %`) et l'état final (`complete` / `fail`) auprès d'Orchestrator.
- **Validation :** Exécution `--once --item <item>` en dry-run/test sur les 4 items.

### Étape 4 — Routage dans le Consommateur Orchestrator (`apps/orchestrator`)
- **Objectif :** Exécuter `stems.review`, `chords.review` et `lyrics.review` via `QueueConsumeCommand`.
- **Actions :**
  1. Mettre à jour `apps/orchestrator/src/Command/QueueConsumeCommand.php` (`dispatchToActor` pour `source === 'assistant'`) afin de router `profile.review`, `stems.review`, `chords.review` et `lyrics.review` vers le worker Assistant avec `--item=<item>`, `--job-id=<job_id>`, `--run-id=<run_id>`, ainsi que les variables déclaratives `EZS_ENGINE` / `EZS_PRESET` si fournies dans le payload.
  2. Vérifier que `JobRetryCoordinator` accepte et réarme bien les 8 couples (`lab:<item>` et `assistant:<item>.review`) tout en préservant `engine`, `engine_profile` et `preset`.
- **Validation :** `php bin/phpunit --testdox` (incluant un test de routage `QueueConsumeCommand` sur les 4 items d'expertise).

### Étape 5 — Intégration UI dans `EZS_Lab`, `EZS_Assistant` et `Orchestrator`
- **Objectif :** Offrir le déclenchement, le suivi et la lecture des contre-expertises sur les 4 onglets du Lab.
- **Actions :**
  1. **EZS_Lab :** Ajouter le composant d'expertise (`Demander une expertise` + consultation du verdict et des `final_assertions`) sur les vues `Stems`, `Chords` et `Lyrics` (sur le modèle de `workbench/profile_ai_button.html.twig`), en utilisant les design tokens `ezs-tokens.css` (`.ezs-badge--<item>`, `.ezs-badge--expertise`).
  2. **EZS_Assistant :** Ajouter le filtre et les badges d'item (`Profile`, `Stems`, `Chords`, `Lyrics`) dans `apps/ezs_assistant/templates/dashboard/index.html.twig` et `backoffice/main.html.twig` en s'appuyant sur `@shared/macros/ezs_ui.html.twig`.
  3. **Orchestrator :** Vérifier l'affichage temps réel sur `/jobs` (colonnes `Type = Expertise`, `Item = Profile|Stems|Chords|Lyrics`, `État`, `Progression`).
- **Validation :** `lint:twig` sur `orchestrator`, `ezs_lab` et `ezs_assistant`, puis vérification visuelle navigateur (`Ctrl+F5`).

### Étape 6 — Recette E2E & Clôture
- **Actions :**
  1. Exécuter `php bin/phpunit --testdox` complet sur bases isolées (`var/orchestrator_test.sqlite`, `var/benchmark_test.sqlite`) avec zéro résidu en production.
  2. Vérifier `lint:yaml`, `lint:container`, `lint:twig` sur l'ensemble du monorepo.
  3. Incrémenter les fichiers `VERSION` des noyaux impactés (`apps/ezs_lab/VERSION`, `apps/ezs_assistant/VERSION`, `apps/orchestrator/VERSION`), mettre à jour `HANDOFF.md` et commiter après validation utilisateur.
