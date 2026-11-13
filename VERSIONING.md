================================================================================
RÈGLES DE VERSIONING & CONVENTION DE COMMITS (Monorepo EZStudio)
================================================================================

1. NOMENCLATURE DES JALONS (Version Hybride Plateforme / Module)
--------------------------------------------------------------------------------
Format : v[Majeure].[Mineure].[Fix].[Site][Indice]
- Site (O, L, A, E) :
  * O = Orchestrator
  * L = ezs_lab
  * A = ezs_assistant
  * E = EZScore
- Fix : 
  * "0" pour un début de release (ex: v5.1.0.L0)
  * "" (chaîne vide) pour une release stable (ex: v5.1.0.L)
- Suffixes de cycle de vie :
  * a : Alpha / Jalon de développement
  * rc : Release Candidate (stabilisation et tests réels)
  * [Stable] : Version validée et prête pour la production

--------------------------------------------------------------------------------
2. CONVENTION DE COMMIT OBLIGATOIRE
--------------------------------------------------------------------------------
Chaque message de commit doit obligatoirement respecter la structure suivante :

git commit -m "vX.Y.Z.[Site][Indice] type(scope): résumé court de l'avancée

- Contexte : Précision du besoin ou de la validation en cours.
- Modifications : Liste claire des actions techniques réalisées.
- Validation / Prochaine étape : État de la brique ou suite immédiate."

Types autorisés : feat, fix, refactor, docs, test, ci, chore.

--------------------------------------------------------------------------------
3. EXEMPLES PRATIQUES PAR ÉTAPE DU CYCLE DE VIE
--------------------------------------------------------------------------------

* Exemple 1 : Début de release / Jalon de développement (Alpha)
  Version cible : v5.1.0.L0a0
  
  git add .
  git commit -m "v5.1.0.L0a0 feat(queue): initialisation du gestionnaire de file d'attente ezs_lab

  - Contexte : Mise en place du pont asynchrone pour les traitements audio de ezs_lab.
  - Modifications : Création du service QueueManager et intégration de l'entité JobQueue.
  - Validation / Prochaine étape : Tests unitaires de la persistance en base."

* Exemple 2 : Phase de stabilisation (Release Candidate)
  Version cible : v5.1.0.Arc0
  
  git add .
  git commit -m "v5.1.0.Arc0 test(assistant): validation des flux de la queue en conditions réelles

  - Contexte : Vérification du cycle de vie des jobs (pending -> running -> completed) pour ezs_assistant.
  - Modifications : Ajout des scripts de test d'intégration pour les profils textuels.
  - Validation / Prochaine étape : Correction des derniers retours d'exécution du worker."

* Exemple 3 : Release Stable (Fix vide "")
  Version cible : v5.1.0.E
  
  git add .
  git commit -m "v5.1.0.E feat(ezscore): livraison stable du module de synchronisation des scores

  - Contexte : Clôture du jalon de développement et validation des artefacts finaux.
  - Modifications : Fusion de la branche de test et nettoyage des logs de débogage.
  - Validation / Prochaine étape : Passage au jalon de la version mineure suivante."
================================================================================