# Preuve de Recette Bout en Bout - Issue #2

- **Date :** 2026-11-15 09:29:04 UTC
- **Isolation :** `var/orchestrator_test.sqlite` (0 residu en base prod)

## Journal brut

```text
[2026-11-15T09:29:03(Z)] [1. PROFILE (LAB)] Crash worker simule sur central_job=#52
[2026-11-15T09:29:03(Z)] [1. PROFILE (LAB)] Relance coordonnee OK -> status=waiting, analysis_jobs#911=queued
[2026-11-15T09:29:03(Z)] [1. PROFILE (LAB)] Reprise worker terminee -> central_job=#52=ended (attempts=2), scientific_run#901 conserve.
[2026-11-15T09:29:03(Z)] [2a. Assistant review] Crash worker simule sur central_job=#53
[2026-11-15T09:29:03(Z)] [2a. Assistant review] Relance coordonnee OK -> status=waiting, analysis_jobs#912=queued
[2026-11-15T09:29:03(Z)] [2a. Assistant review] Reprise worker terminee -> central_job=#53=ended (attempts=2), scientific_run#901 conserve.
[2026-11-15T09:29:03(Z)] [2b. Assistant direct (profile.direct_research)] Crash worker simule sur central_job=#54
[2026-11-15T09:29:03(Z)] [2b. Assistant direct (profile.direct_research)] Relance coordonnee OK -> status=waiting, analysis_jobs#913=queued
[2026-11-15T09:29:03(Z)] [2b. Assistant direct (profile.direct_research)] Reprise worker terminee -> central_job=#54=ended (attempts=2), scientific_run#901 conserve.
[2026-11-15T09:29:03(Z)] [3. STEMS] Crash worker simule sur central_job=#55
[2026-11-15T09:29:03(Z)] [3. STEMS] Relance coordonnee OK -> status=waiting, analysis_jobs#914=queued
[2026-11-15T09:29:03(Z)] [3. STEMS] Artefact partiel purge et remplace.
[2026-11-15T09:29:04(Z)] [3. STEMS] Reprise worker terminee -> central_job=#55=ended (attempts=2), scientific_run#901 conserve.
[2026-11-15T09:29:04(Z)] [4. CHORDS] Crash worker simule sur central_job=#56
[2026-11-15T09:29:04(Z)] [4. CHORDS] Relance coordonnee OK -> status=waiting, analysis_jobs#915=queued
[2026-11-15T09:29:04(Z)] [4. CHORDS] Reprise worker terminee -> central_job=#56=ended (attempts=2), scientific_run#901 conserve.
```
