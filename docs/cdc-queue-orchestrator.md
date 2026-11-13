# CAHIER DES CHARGES — Module Queue Séquentielle (Orchestrator v5.0a3)

## 1. Objectif & Contexte
- Centraliser l'ordonnancement des tâches asynchrones pour l'ensemble des sous-applications (`ezs_lab` et `ezs_assistant`) au sein de l'Orchestrator Symfony (`apps/orchestrator`).
- Garantir une exclusion mutuelle absolue : **1 seul job actif (`running`) globalement sur la plateforme**, afin de protéger la ressource GPU et d'éviter les conflits d'exécution des workers Python.

## 2. Spécifications Fonctionnelles
- **Agnosticisme métier :** L'Orchestrator manipule des charges utiles (`payload`) JSON opaques sans interpréter leur contenu scientifique (PaSST, PANNs, LLM).
- **Cycle de vie des tâches :**
  1. `pending` : En attente dans la file.
  2. `running` : En cours d'exécution (le verrou global est pris).
  3. `completed` / `failed` : Tâche terminée, libérant instantanément le verrou pour le job suivant.
