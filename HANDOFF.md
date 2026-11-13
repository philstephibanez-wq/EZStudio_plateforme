================================================================================
HANDOFF : TEST RÉEL ET EXTENSIBILITÉ DE LA FILE D'ATTENTE (ezs_lab & ezs_assistant)
================================================================================

OBJECTIF GLOBAL :
Valider et étendre le système de file d'attente asynchrone (QueueManager & JobQueue) en conditions réelles, en se concentrant exclusivement sur le 
périmètre des profile pour ezs_lab et ezs_assistant, avec une ouverture préparée pour les traitements audio lourds (stems et chords).

--------------------------------------------------------------------------------
Phase 1 : Consolidation du Périmètre Actuel (Profile)
--------------------------------------------------------------------------------
1. Cibler strictement les services et profile de ezs_lab et ezs_assistant via l'entité JobQueue et le QueueManager.
2. Valider le cycle de vie de base (pending -> running -> completed/failed/cancelled) avec les scripts et workers dédiés.

--------------------------------------------------------------------------------
Phase 2 : Extension du Payload pour les Traitements Avancés (Stems & Chords)
--------------------------------------------------------------------------------
1. Structurer les payloads normalisés pour intégrer les futurs cas d'usage lourds de ezs_lab :
   - Extraction de stems audio (séparation par IA sous var/runtime).
   - Détection et génération de grilles d'accords (chords).
2. S'assurer que le mécanisme d'interruption et de suivi d'état gère ces tâches longue durée sans blocage.

--------------------------------------------------------------------------------
Phase 3 : Validation Bout en Bout (PHPUnit & Python)
--------------------------------------------------------------------------------
1. Exécuter les tests unitaires/d'intégration PHPUnit pour valider l'enfilade des requêtes de profile et des nouveaux payloads audio.
2. Vérifier l'exécution réelle par les scripts Python et la conformité des artefacts de sortie générés dans l'écosystème du module.

================================================================================