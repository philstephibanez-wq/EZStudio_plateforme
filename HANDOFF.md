# HANDOFF TECHNIQUE — Clôture Issue #2 (`EZStudio_plateforme`)

**Contexte :** Suite de l'audit du 15/11/2026 sur le commit `2ca3cdb28ed0` (`[P1][Queue] Relance coordonnée et CSRF implémentés — sécurisation et recette de bout en bout à terminer`).  
**Objectif :** Lever les 5 points bloquants restants pour valider les critères de clôture et fermer l'issue #2.

---

## 1. Chantiers d'implémentation attendus

### A. Cohérence inter-bases & Concurrence (`src/Service/JobRetryCoordinator.php`)
* **Verrou applicatif :** Injecter `LockFactory` et poser un verrou exclusif `job_retry_{id}` dès l'entrée dans la méthode de relance pour bloquer les requêtes concurrentes (double-clic simultané).
* **Rollback compensatoire (Pattern Saga) :**
  1. Capturer un snapshot complet des lignes locales avant mutation (`analysis_jobs` et `scientific_runs` si applicable : statut, timestamps, erreurs).
  2. Commiter le passage à `queued` dans SQLite (pour libérer le verrou fichier).
  3. Exécuter `QueueManager::retry()` dans un bloc `try/catch`.
  4. En cas d'exception ou de retour `false`, restaurer l'état exact du snapshot SQLite via une transaction compensatoire et journaliser l'incident.

### B. Granularité des états & Cas `profile.direct_research` (`src/Service/JobRetryCoordinator.php`)
* **Liste blanche stricte :** Remplacer l'exclusion (`completed/cancelled/running/cancelling`) dans `rearmLocalActorState()` par une liste positive explicite d'états relançables (ex. `['failed', 'error', 'aborted', 'stalled']`). Rejeter tout autre état.
* **Validation `(source, kind)` :** Vérifier systématiquement la cohérence entre le `kind` du job central, le type d'acteur et la présence d'un `scientific_run_id` valide pour LAB.
* **Support `source=assistant` + `profile.direct_research` :** Implémenter la logique de réarmement métier dédiée à ce couple (nettoyage/réinitialisation des étapes intermédiaires avant repassage en `queued`).

### C. Autorisation métier & Sécurité d'accès (`security.yaml` & `JobsPageController.php`)
* **Configuration Symfony :** Activer des règles `access_control` dans `config/packages/security.yaml` protégeant les routes `/jobs` (exiger `IS_AUTHENTICATED_FULLY` ou un rôle dédié `ROLE_ORCHESTRATOR_OPERATOR`). Si l'authentification est déléguée à un reverse proxy, implémenter l'Authenticator correspondant et documenter l'architecture réseau/proxy.
* **Contrôleur :** Ajouter `#[IsGranted('ROLE_ORCHESTRATOR_OPERATOR')]` sur `JobsPageController::retry()` et `delete()` (en complément du jeton CSRF existant).

---

## 2. Tests, Isolation & Preuves de recette attendues

### A. Isolation des bases de test
* Vérifier et garantir que la base `orchestrator` utilisée par PHPUnit est strictement isolée de l'environnement de production (variables d'environnement `.env.test`, chemins SQLite temporaires dédiés).

### B. Enrichissement des tests d'intégration (`tests/Controller/JobRetrySecurityTest.php`)
* Tester l'échec simulé de `QueueManager::retry()` et vérifier le rollback effectif de la base SQLite vers son état initial.
* Tester le refus HTTP **401/403** sur `/retry` et `/delete` pour un utilisateur non authentifié ou sans rôle (même avec un jeton CSRF valide).
* Tester la concurrence (verrou actif sur relance simultanée) et le rejet d'états hors liste blanche.
* Couvrir le réarmement spécifique du couple `source=assistant` + `profile.direct_research`.

### C. Recette de bout en bout (Conditions réelles)
Exécuter et documenter la reprise réelle avec workers actifs pour les 4 types :
1. `PROFILE` (LAB)
2. `Assistant review` et `Assistant direct` (`profile.direct_research`)
3. `STEMS`
4. `CHORDS`
* *Points à vérifier :* conservation de l'historique, gestion des artefacts scientifiques partiels, reprise après crash simulé d'un worker.

---

## 3. Livrables pour clôture de l'issue #2
- [ ] Commit(s) contenant les correctifs de code, la configuration sécurité et la documentation proxy/accès.
- [ ] Sortie brute complète des commandes `phpunit` et `lint` (sans manipulation résiduelle en base de prod).
- [ ] Logs / preuves d'exécution de la recette bout en bout sur les 4 types de jobs.

## Cloture Issue #2 — v5.1.0.O0a4 (2026-11-15)
- **Coherence inter-bases & concurrence :** ajout d'un verrou exclusif LockFactory (job_retry_{id}) et d'un pattern Saga avec snapshot et rollback compensatoire SQLite dans src/Service/JobRetryCoordinator.php.
- **Eligibilite & Assistant direct :** passage a une liste blanche stricte (ailed, error, borted, stalled), validation de la matrice (source, kind) et prise en charge explicite de source=assistant + profile.direct_research.
- **Autorisation RBAC /jobs :** activation de SecurityBundle dans pps/orchestrator, protection ccess_control sur ^/jobs et attribut #[IsGranted('ROLE_ORCHESTRATOR_OPERATOR')] sur JobsPageController::retry() et delete().
- **Isolation des tests & recette E2E :** redirection de when@test vers ar/orchestrator_test.sqlite (0 residu sur data/orchestrator.sqlite), 7/7 tests PHPUnit (70 assertions) et preuve horodatee dans docs/RECETTE_ISSUE_2_E2E.md.

## Cloture definitive Issue #2 — v5.1.0.O0a5 (2026-11-15)
- Alignement complet de JobRetryCoordinator sur QueueConsumeCommand (source=lab pour profile/stems/chords, source=assistant pour profile.review et fichiers hex32 profile.direct_research).
- Validation stricte de concordance kind, scientific_run_id et song_id, schema reel scientific_runs (inished_at = NULL), reconciliation idempotente au demarrage de QueueConsumeCommand et bypass IP local (127.0.0.1/::1) sans popup.
- PHPUnit 7/7 tests, 57 assertions (OK), linters YAML/Container [OK], 0 residu en base prod.

## Correctif compatibilite CHORDS (chords_scientific) & workflow 4 items — v5.1.0.O0a6 (2026-11-15)
- Alignement de JobRetryCoordinator::rearmLocalActorState() sur Database::queueScientificChordsJob() (nalysis_jobs.kind = 'chords_scientific') et couverture des 4 items analyse/expertise (profile, stems, chords, lyrics).
- Validation PHPUnit (7/7 tests, 61 assertions) avec relance reelle de jobs chords_scientific et lyrics_scientific.

## Mutualisation des design tokens (Items, Phases, Etats, Onglets) — v5.1.0.O0a8 (2026-11-15)
- Creation de shared/assets/css/ezs-tokens.css (public/shared/css/ezs-tokens.css) et shared/templates/macros/ezs_ui.html.twig partages sur toute la plateforme (shared, orchestrator, ezs_lab, ezs_assistant).
- Harmonisation du style des onglets d'Orchestrator sur celui d'EZS_Lab avec contraste doux ardoise/teinte sur theme sombre.

## Cloture Issue #1 (Unicite queue_id & recette MockHttpClient STEMS/CHORDS) — v5.1.0.O0a9 (2026-11-15)
- Suppression des cles queue_id en double dans StemsExecutionService::queue() et ChordsExecutionService::queue().
- Ajout de 	ests/Service/LabSingleDispatchTest.php (MockHttpClient prouvant 1 seul POST /api/queue/dispatch par appel) et docs/RECETTE_ISSUE_1_DISPATCH.md. PHPUnit 8/8 tests, 69 assertions (OK).
