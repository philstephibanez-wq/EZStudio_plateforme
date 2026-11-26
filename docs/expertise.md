# Expertise scientifique PROFILE — EZStudio

Statut : contrat de conception E02 R1, issue #27. Remplace le paradigme historique ; aucune nouvelle exécution d'expertise intégrée n'est autorisée par ce document.

## Mission et responsabilités
Documenter l'enregistrement, améliorer scientifiquement les analyses PROFILE, fournir des informations pertinentes à STEMS → CHORDS/N → LYRICS. LAB analyse ; ChatGPT externe expertise et propose des expérimentations ; LAB expérimente ; l'utilisateur arbitre. Orchestrator reste seul maître de la FIFO scientifique. Aucun job d'expertise historique, modèle local, publication automatique ou API IA obligatoire.

## Contrats
Le référentiel déclaratif [ITEMS](../apps/ezs_lab/contracts/PROFILE_ITEMS.json), son [schéma](../apps/ezs_lab/schemas/profile-items.v1.schema.json) et le [contrat de résultat candidat](../apps/ezs_lab/schemas/profile-result.v1.schema.json) et le [schéma documentaire candidat](../apps/ezs_lab/schemas/profile-documentary.v1.schema.json) sont les références E02. Voir [modèle physique et impacts](profile-data-model.md). Les tolérances restent non fixées ; le contrat ne qualifie pas automatiquement un run. EXPORT JSONL, PROMPT et FEEDBACK seront finalisés après la recette PROFILE, pas avant.

## Méthode scientifique
Identifier l'enregistrement exact ; rechercher des références indépendantes et contradictoires ; distinguer preuve documentaire, mesure indépendante, observation auditive et inférence. Ne jamais confondre une partition et une preuve acoustique, ni convergence et indépendance des sources. Conserver contradictions, incertitudes, provenance et données brutes. Ne pas inventer source, valeur, paramètre ou preuve. Les résultats LAB ne constituent pas leur propre validation indépendante.

Évaluer chaque ITEM selon son importance et sa couverture. Une valeur inconnue ou non analysée ne vaut ni absence ni erreur. Un score non calibré ne constitue pas une probabilité. Distinguer anomalies de moteur, preset, classification, persistance et affichage. Proposer uniquement des paramètres réellement existants et des tests reproductibles ; séparer causes démontrées et hypothèses. Aucun écrasement des résultats ni activation automatique d'un preset.

## Jalons et recette
E00 audit ; E01 modèle conceptuel ; E02 contrats et modèle physique ; E03 adaptation BDD seulement si justifiée ; E04 moteurs déclaratifs ; E05 UI ; E05A campagne intégrale déclenchée par l'utilisateur, comparaison baseline A/B ; E06 export ; E07 prompt et qualification ChatGPT ; E08 feedback ; E09 presets candidats et validation indépendante ; E10 contrats inter-phases ; E11 qualification globale. Préserver historiques, données scientifiques, UI, sharing, sécurité fail-closed et isolation applicative. Aucun fallback silencieux. Aucune réanalyse ou phase suivante déclenchée automatiquement. La qualification technique n'est pas la qualification scientifique.
