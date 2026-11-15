# HANDOFF — Finalisation de l'expertise PROFILE, puis généralisation multi-items

**Date :** 2026-11-15  
**Dépôt :** `EZStudio_plateforme` — `master`  
**Issue de pilotage :** [#3](https://github.com/philstephibanez-wq/EZStudio_plateforme/issues/3)  
**Statut :** spécification de travail, non recette exécutée  
**Référence scientifique impérative :** [`docs/expertise.md`](docs/expertise.md)

## 0. But à atteindre et ordre impératif

**OBJECTIF PRIORITAIRE : terminer l'expertise scientifique de PROFILE pour permettre de finaliser l'amélioration de l'analyse scientifique PROFILE.**

L'expertise n'est pas une fin en soi ni un simple service générique à déployer : elle doit fournir des constats fiables et traçables pour corriger les faiblesses de l'analyse PROFILE, comparer les résultats avant/après et stabiliser la configuration gagnante.

**Deux jalons séquentiels :**

- **Jalon A — PROFILE, prioritaire et bloquant :** achever la chaîne d'expertise PROFILE ; établir les verdicts par assertion, collecter des preuves indépendantes, rechercher les contradictions, permettre la révision humaine ; exploiter les divergences pour améliorer les moteurs/paramètres/presets **d'analyse PROFILE**, avec réanalyse comparative contrôlée, métriques et non-régression. **Valider ce cycle réel avant d'étendre l'expertise.**
- **Jalon B — Généralisation, conditionnée au jalon A :** appliquer le dispositif éprouvé à STEMS, CHORDS et LYRICS, sans refondre PROFILE ni ses moteurs scientifiques.

**Ordre interdit :** ne pas commencer par un nouveau schéma générique, une migration PROFILE, trois workers supplémentaires ou les interfaces multi-items tant que le jalon A n'a pas été validé.

### 0.1. Socle déjà disponible

- Files et dispatch REST STEMS/CHORDS stabilisés dans le périmètre des issues #1 et #2 ; mécanismes de reprise, annulation et contrôle de cohérence dans Orchestrator.
- Services PROFILE existants : `ProfileAiRequestService`, `ProfileExternalReviewService`, `profile_ai_requests_worker.py`, assertions finales PROFILE, historique et formulaires.
- Moteurs et presets déclaratifs ; design tokens CSS et macros Twig mutualisés.
- Vue Orchestrator `/jobs` avec colonne et filtre Item.

**La fermeture des issues #1/#2 ne valide ni la qualité scientifique de PROFILE, ni une recette de reprise réelle sur les quatre items.**

## 1. Paradigme scientifique et responsabilités

**LAB analyse. L'Assistant contre-expertise. Le code déterministe contrôle. L'humain arbitre.**

- **LAB** produit, versionne et conserve les assertions musicales finales et leurs données scientifiques, dans `benchmark.sqlite`. Les résultats LAB ne sont jamais écrasés par une expertise automatique.
- **Assistant** examine **les assertions finales**, pas les moteurs/presets qui les ont produites. Il identifie précisément l'enregistrement, effectue une recherche documentaire ouverte, recherche activement la réfutation, évalue l'indépendance des sources, justifie chaque verdict et propose éventuellement une correction. Il ne modifie pas directement la base LAB.
- **Code déterministe** valide identifiants, schémas, provenance, règles d'admissibilité et persistance ; il ne fabrique pas une validation par concordance de valeurs.
- **Orchestrator** admet et exécute séquentiellement les jobs, suit progression et PID, gère annulation, reprise et états terminaux ; il ne calcule aucun verdict.
- **EZStudio Run Reports** conserve traces, références, preuves originales, contradictions, versions/configuration et historique ; la publication est événementielle, idempotente et reprenable **sans réexécuter analyse ou expertise**.
- **Humain** arbitre les révisions de l'expertise et décide des modifications scientifiques proposées.

### 1.1. Séparer strictement les trois opérations

1. **Analyse scientifique PROFILE :** moteur/preset d'analyse, mesures, assertions originales.
2. **Contre-expertise PROFILE :** moteur/preset **d'expertise**, preuves indépendantes, verdicts proposés. **Ne change pas** les résultats d'analyse.
3. **Amélioration scientifique PROFILE :** l'équipe utilise les divergences vérifiées pour régler **explicitement** moteurs/paramètres/presets d'analyse, exécuter une **nouvelle analyse comparative ciblée** et mesurer le gain sans écraser le run de référence.

Ne jamais modifier le preset scientifique pendant un job d'expertise ; ne jamais réanalyser lors d'une simple consultation, soumission, relance de publication ou révision humaine.

### 1.2. Contrat de preuve et verdict

Pour chaque assertion exacte `claim_id` issue du run scientifique PROFILE :

- Préserver `original_value`, champ, unité, intervalle temporel, confidence initiale, `song_id`, `scientific_run_id`, version et contexte.
- Consigner `recording_identity` (titre/interprète/version, identifiants discriminants, incertitudes).
- Stocker preuves avec références originales, URL/identifiant, auteur, dates, extrait/observation vérifiable, provenance, type (documentaire / observation auditive / mesure instrumentale indépendante / inférence), indépendance, limites et sources écartées motivées.
- Chercher éléments **favorables, contradictoires et non concluants** ; plusieurs copies d'une même source ne constituent pas des preuves indépendantes.
- Statuer **par assertion** : `BON`, `FAUX`, `NON TROUVÉ` ; ce dernier couvre absence, insuffisance, ambiguïté ou contradictions sans arbitrage fiable. Conserver justification, confiance, références de preuve, contradictions, incertitudes et correction **proposée** si défendable.
- Séparer statut administratif (`draft/submitted/reviewed/published`, à aligner sur l'existant), erreur technique (réseau/IA/contrat/publication), incertitude documentaire et verdict scientifique.
- Jamais de source/citation/preuve inventée, de confirmation par principe ni de verdict `BON` sans justification suffisante. Les sorties LLM et les mesures LAB ne sont pas en elles-mêmes des preuves externes indépendantes.

Les valeurs `accepted/adjusted/rejected/needs_human_review` ne sont **pas** des verdicts scientifiques.

## 2. JALON A — Terminer PROFILE et améliorer l'analyse scientifique

### A1. Audit du parcours PROFILE existant (avant tout refactoring)
- [ ] Inventorier les routes, tables SQLite, contrats, assertions finales, services, templates, workers, configurations d'expertise, presets d'analyse et historiques réels.
- [ ] Vérifier une demande PROFILE existante de bout en bout : admission → Orchestrator → `profile_ai_requests_worker.py` → recherche → preuves/verdicts → révision → affichage LAB → Run Reports.
- [ ] Identifier les ruptures exactes : erreurs techniques, assertions absentes, ambiguïtés d'identité, fausses validations, manque de preuves, progression bloquée, problèmes de persistance.
- [ ] Produire un diagnostic reproductible avec logs et identifiants, sans toucher aux moteurs scientifiques.

**Livrable A1 :** état réel de PROFILE et liste priorisée des défauts, sans régression.

### A2. Finaliser le contrat et l'exécution de contre-expertise PROFILE
- [ ] Stabiliser le contrat historique PROFILE autour des **assertions finales** et des verdicts `BON/FAUX/NON TROUVÉ`, avec preuves originales, indépendance, contradictions, confiance et abstention.
- [ ] Renforcer l'identification de l'enregistrement, la recherche contradictoire, les règles d'admissibilité déterministes et la traçabilité ; conserver toutes les données historiques.
- [ ] Rendre explicite le moteur/preset **d'expertise** : configuration figée, versionnée, sans fallback silencieux ni confusion avec les presets scientifiques.
- [ ] Vérifier persistance des révisions, affichage du statut « Révisé », arbitrage humain, conservation des assertions LAB et absence de validation automatique.
- [ ] Vérifier progression, annulation, reprise, publication Run Reports, et reprise de publication **sans réexécuter** le calcul ou la recherche.
- [ ] Tester cas `BON`, `FAUX`, `NON TROUVÉ`, ambiguïté de version, preuves dépendantes/contradictoires, erreur réseau/LLM, annulation et crash.

**Livrable A2 :** expertise PROFILE complète, exécutable et traçable sur de vrais runs.

### A3. Boucle de feedback scientifique PROFILE (objectif métier central)
- [ ] Constituer un **jeu de référence versionné** de morceaux/versions et assertions à contrôler ; enregistrer la référence, ses preuves et le niveau de confiance de l'identité.
- [ ] Pour chaque assertion `FAUX` ou `NON TROUVÉ`, distinguer erreur d'analyse, erreur d'identification, preuve insuffisante et incertitude non arbitrable ; aucun ajustement automatique à partir d'un simple verdict.
- [ ] Après contrôle humain, relier les divergences justifiées aux modules/paramètres/presets **d'analyse PROFILE** concernés, sans faire de l'expertise un audit automatique des moteurs.
- [ ] Proposer un changement scientifique **isolé**, versionné et réversible ; conserver run, moteur, preset, paramètres et assertions **avant** modification.
- [ ] Exécuter, **uniquement sur décision explicite et sur le périmètre de validation**, une analyse comparative du même enregistrement : référence vs nouveau preset/moteur, avec mêmes conditions et métriques homogènes.
- [ ] Mesurer améliorations et régressions **par assertion** et globalement : taux d'assertions corroborées, réfutées, non conclues, confiance/admissibilité, couverture, erreurs, répétabilité ; ne jamais optimiser artificiellement le seul nombre de `BON`.
- [ ] Appliquer non-régression sur les cas déjà corrects et le socle scientifique existant ; conserver les résultats précédents et documenter toute régression.
- [ ] Valider humainement le réglage gagnant, le nommer clairement et le stabiliser en **preset PROFILE de référence**, sans substitution silencieuse.

**Livrable A3 :** rapport comparatif reproductible « avant/après », améliorations mesurées, défauts restants et preset scientifique PROFILE retenu.

### A4. Recette et GO / NO-GO PROFILE
- [ ] Exécution **réelle** sur MAIN : analyse PROFILE historique déjà disponible → assertions finales → job Assistant → recherche de preuves indépendantes → verdicts par assertion → révision humaine → Run Reports → amélioration scientifique contrôlée → réanalyse comparative → nouvelle expertise/évaluation si nécessaire.
- [ ] Vérifier erreurs, annulation, crash, réconciliation, idempotence et absence de nouvelle analyse lors de la publication.
- [ ] Conserver sorties brutes des tests et logs des processus réels ; distinguer simulations et exécution scientifique effective.
- [ ] Confirmer absence de régression de PROFILE et intégrité des données de production.

**GO JALON B UNIQUEMENT SI :** expertise PROFILE opérationnelle, audit de preuves satisfaisant, cycle d'amélioration scientifique réel démontré, amélioration ou maintien mesuré sur jeu de référence, régressions maîtrisées, révision humaine et validation explicite de passage. Sinon **NO-GO : rester sur PROFILE**.

## 3. JALON B — Généraliser le dispositif validé (après GO du jalon A)

**Le jalon B est une seconde phase ; ne pas commencer les développements multi-items avant validation du jalon A.**

### B1. Contrat universel `ezstudio.item-review.v1`
- [ ] Extraire **du PROFILE éprouvé**, et non d'une spécification théorique, le JSON Schema versionné partagé.
- [ ] Champs minimaux : `schema_version`, `item`, `song_id`, `scientific_run_id`, `review_job_id`, `orchestrator_queue_id`, `recording_identity`, moteur/profil/preset **d'expertise** et version ; `claims`, `facts`, `context`, preuves, verdicts par assertion, justification, confiance, révision et traces.
- [ ] Préserver l'assertion LAB originale ; ne jamais publier des `final_assertions` de l'Assistant comme des résultats scientifiques LAB remplacés.
- [ ] Distinguer verdicts `BON/FAUX/NON TROUVÉ`, statut administratif et échec technique.
- [ ] Construire des adaptateurs PROFILE rétrocompatibles ; versionner toute incompatibilité.

### B2. Persistance LAB additive, sans migration historique destructrice
- [ ] Inventorier schémas/consommateurs historiques PROFILE avant évolution.
- [ ] Concevoir si pertinent `item_reviews`, `item_review_revisions`, `item_final_assertions`, `item_review_dispatches` et les entités de provenance des preuves ; noms à valider contre l'existant.
- [ ] Migration **explicite, additive, idempotente, contrôlée**, sur base de test ; **pas** de migration automatique globale au démarrage, de suppression de tables PROFILE ou de duplication de révisions.
- [ ] Maintenir `ProfileAiRequestService`, `ProfileExternalReviewService` et routes historiques comme façades/adaptateurs.
- [ ] Tests de migration répétée, intégrité FK, comparaison historique, non-régression.

### B3. Services et API LAB
- [ ] Introduire services génériques `ItemExternalReviewService` / `ItemAiRequestService` uniquement si utiles au contrat validé.
- [ ] Routes internes versionnées par item (`/api/internal/reviews/{item}/...` proposé) : queue, statut, assertions finales, contexte, preuves/révisions, soumission idempotente.
- [ ] Valider liste blanche `profile|stems|chords|lyrics`, sécurité REST et corrélation `song/run/review/queue`.

### B4. Workers Assistant et Orchestrator
- [ ] Étendre le worker PROFILE de façon rétrocompatible ou créer `item_reviews_worker.py`, avec résolution explicite des moteurs/presets d'expertise pour chaque item.
- [ ] Router réellement `profile.review`, `stems.review`, `chords.review`, `lyrics.review` dans `QueueConsumeCommand::dispatchToActor()`; conserver `profile.direct_research`.
- [ ] Valider matrice `source/job/analysis_jobs.kind` ; attention `lab:chords` ↔ `chords_scientific`, types STEMS et futures données LYRICS.
- [ ] Tester admission, claim, PID, worker **réel**, progression, persistance, annulation, reprise et réconciliation ; le support dans `JobRetryCoordinator` ne prouve pas l'exécution par le consommateur.

### B5. UI et Run Reports
- [ ] Étendre demandes d'expertise, verdicts individuels, preuves, contradictions, révision humaine et historique aux onglets STEMS/CHORDS/LYRICS.
- [ ] Étendre badges/filtres par item Assistant et supervision agnostique Orchestrator.
- [ ] Utiliser les composants `shared` sans régression visuelle.
- [ ] Archivage Run Reports avec preuve d'origine, empreintes, accusés, clé idempotente, publication événementielle et relance sans expertise supplémentaire.

### B6. Recette multi-items et clôture
- [ ] `phpunit` sur bases isolées ; `lint:yaml`, `lint:container`, `lint:twig`, `debug:router` selon les noyaux.
- [ ] Pour **chacun** des quatre items, recette avec workers réellement exécutés et assertions finales LAB, preuves indépendantes, verdicts, révisions, publication Run Reports.
- [ ] Tests négatifs : ambiguïtés, preuves insuffisantes, sources dépendantes/contradictoires, réseau/IA, annulation, crash, accès non autorisé, doubles soumissions et publication échouée.
- [ ] Conserver logs, commits, versions, IDs, configurations, résultats et limites. Tests simulés ≠ recette scientifique réelle.

## 4. Matrice d'identité et non-régression

| Source | Job Orchestrator | Kind métier attendu |
|---|---|---|
| `lab` | `profile` | `profile` |
| `lab` | `stems` | `stems` / `stems_scientific` |
| `lab` | `chords` | `chords_scientific` / `chords` |
| `lab` | `lyrics` | `lyrics_scientific` / `lyrics` |
| `assistant` | `profile.review` | `assistant.profile.review` |
| `assistant` | `stems.review` | `assistant.stems.review` |
| `assistant` | `chords.review` | `assistant.chords.review` |
| `assistant` | `lyrics.review` | `assistant.lyrics.review` |

Les lignes LYRICS et les expertises non-PROFILE sont des **cibles contractuelles**, pas une preuve de prise en charge actuelle.

## 5. Critères d'acceptation de l'issue #3

### Jalon A — PROFILE (bloquant)
- [ ] Expertise PROFILE achevée, réelle et traçable sur MAIN.
- [ ] Assertions finales exactes préservées ; verdict par assertion `BON / FAUX / NON TROUVÉ` avec preuves et contradictions admissibles.
- [ ] Séparation moteur/preset d'analyse vs d'expertise ; aucune substitution ou réanalyse implicite.
- [ ] Révisions humaines et Run Reports opérationnels ; publication idempotente.
- [ ] **Boucle complète démontrée : analyse → contre-expertise → arbitrage humain → ajustement scientifique contrôlé → comparaison avant/après → stabilisation du preset PROFILE.**
- [ ] Gains/absence de régression mesurés sur jeu de référence, historique conservé, GO explicite vers B.

### Jalon B — Multi-items (seulement après A)
- [ ] Contrat générique dérivé de PROFILE validé, versionné, avec adaptateurs rétrocompatibles.
- [ ] STEMS, CHORDS, LYRICS expertisés via workers réels et Orchestrator.
- [ ] Verdicts, preuves, contradictions, incertitudes et révisions humaines par assertion ; Run Reports archivés et republiables sans réanalyse.
- [ ] Recettes E2E réelles, annulation/reprise, sécurité et non-régression vérifiées.

## 6. Discipline d'exécution

- **Un jalon et une livraison cohérente à la fois** ; commencer par **A1**, pas B1.
- Ne pas modifier l'analyse scientifique durant le développement de l'expertise ; un ajustement d'analyse ne peut intervenir qu'en **A3**, après expertise et décision de validation.
- Tests et artefacts temporaires hors dépôt ; bases de test isolées, aucun secret commité ; UTF-8 et hygiène Git strictes.
- Préserver l'historique et les analyses déjà produites, pas de recalcul automatique.
- Chaque livraison : fichiers changés, risques, tests réellement exécutés, résultats, commit/version, preuve de non-régression.
- Respecter `VERSIONING.md` et obtenir validation explicite avant développement scientifique ou publication de versions.

**La présente spécification n'atteste aucune exécution ni amélioration scientifique. La priorité absolue demeure la finalisation de PROFILE.**
