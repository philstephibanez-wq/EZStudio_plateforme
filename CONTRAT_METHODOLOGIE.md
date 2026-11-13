# CONTRAT DE MÉTHODOLOGIE DE DÉVELOPPEMENT — Plateforme EZStudio

## 1. Principes Directeurs & Règles Permanentes
- **Principe directeur :** Traiter la cause racine, préserver l'existant, réutiliser les solutions éprouvées et démontrer le résultat par des faits tangibles.
- **Progression séquentielle :** Une seule étape opérationnelle à la fois. Valider chaque jalon avant d'envisager le suivant ; aucun enchaînement automatique après un échec.
- **Intégrité de l'architecture multi-site :** Respect strict des frontières applicatives (`apps/orchestrator`, `apps/ezs_lab`, `apps/ezs_assistant`, `apps/ezstudio` PROD figée) et mutualisation via `Shared/`.

## 2. Audits, Traçabilité & Étude d'Impacts Obligatoire
- **Audit GitHub transversal :** Consultation systématique des sources, des branches, de `AGENTS.md` et des contrats avant toute modification. Préservation absolue des modifications locales non synchronisées.
- **Étude d'impacts préalable :** Analyse obligatoire avant tout code (technique, dépendances, ports, exploitation, ordre de boot, isolation DEV/PROD, consommation CPU/GPU et plan de restauration/retour arrière).
- **Traçabilité documentaire :** Mise à jour systématique des issues, des handoffs et des contrats de développement. Aucune issue ne peut être close sans preuve de validation.

## 3. Architecture, Sécurité & Hygiène du Code
- **Isolation stricte des ressources :** 
  - Modèles IA obligatoirement centralisés dans `H:\AIModels` (`AI_MODELS_ROOT`).
  - Caches d'exécution centralisés dans `H:\PythonCache`.
  - Runtimes Python isolés dans `var/runtime/`.
- **Hygiène des fichiers :** Encodage **UTF-8 sans BOM** obligatoire pour tous les fichiers sources, scripts et livrables.
- **Anti-régression :** Interdiction des doublons fonctionnels, des correctifs temporaires durcis, ou de la modification de composants indépendants non demandés.

## 4. Recette, Tests, Standards d'Interface & Livrables
- **Recette réelle et *stateless* :** Un test unitaire ne suffit pas. Validation obligatoire en conditions réelles (disponibilité HTTP, reprise sur panne Caddy/FastCGI, protection de la PROD, absence de conflits de verrous).
- **Format de livrable obligatoire :** Tout livrable de code, de correctif ou de module doit impérativement être fourni sous la forme d'un **archive ZIP** accompagnée des **commandes complètes de décompression et d'installation** prêtes à l'emploi.
- **Format de réponse technique en 9 points :** *Constat, Cause, Audit, Décision, Modifications, Livrable, Commandes, Validation, Suite*.
- **Ergonomie et dialogues transversaux :** Interdiction absolue d'utiliser les modales natives (`window.alert`, `window.confirm`, `window.prompt`, `MsgBox`). Utilisation exclusive de modales HTML accessibles.
