# PROFILE E04 R3 — producteurs déclaratifs

Base publiée : `1b1159a94dfd57bb6ac9dd2fb653185f89897ce0` (LAB 1.7.0.L0a2). E04 R1 et R2 validés sur MAIN. Nouveau preset `profile-r3b35c-compat.v2c.json` généré de manière déterministe à partir du v2b vérifié. Le runner sélectionne v2c pour les nouveaux runs. v2a/v2b restent disponibles pour les appels explicitement historiques ; leurs fichiers et leurs décisions historiques ne sont pas modifiés. La nouvelle politique est obligatoire pour v2c, pas de substitution silencieuse en cas de configuration manquante/invalide.

## Producteurs

- Instrumentation : limites de classement CLAP (6), AudioSet (25), support minimal inter-moteurs (2), limites candidats (12) et faibles (20), alias canoniques sont désormais dans `settings.producer_policy`. Les valeurs et alias v2b sont repris sans modification. Le support compte les moteurs distincts. Les sorties originales restent inchangées, les hypothèses ne sont pas calibrées et ne pilotent pas STEMS.
- Voix : rôle direct distinct du chant générique et de la parole. `background music` n'est pas une preuve de voix d'accompagnement. `singing` et `vocal music` sont seulement des indices associés au rôle principal. Une étiquette explicite CLAP de voix principale dans le rang configuré permet une hypothèse `possible`, pas une confirmation. Les voix secondaires, harmonies et chœur sont distincts ; le chœur exige le nombre de moteurs directs configuré. Un accord AudioSet accepté ne contourne pas ce minimum dans v2c. Le seuil et le rang sont déclaratifs. Les scores bruts et diagnostics historiques restent disponibles ; le diagnostic `choirs` de compatibilité n'est pas requalifié.
- L'ITEM 8 ne conclut jamais à une narration secondaire sur le seul tag de parole. Langues, articulation, nombre de personnes et intervalles non démontrés restent inconnus. Les résultats R1/R2 ne sont ni réécrits ni rétroactivement recalculés.

## Traçabilité

La politique effective complète et son SHA-256 canonique sont présents dans le consensus et dans le manifeste du runner. Le `pipeline_revision` inclut le hash, pris en compte par le fingerprint PHP du preset scientifique. Les paramètres/modules acoustiques du preset v2b restent strictement identiques dans v2c ; aucune nouvelle dépendance/modèle ni modification des schémas E02, BDD, UI/shared, FIFO, Assistant ou Orchestrator. Aucun accès inter-app BDD. Aucune promotion automatique de preset ni autorisation downstream.

## Validation

Tests hors MAIN sur des extraits représentatifs des producteurs, avec doubles de test pour les dépendances d'exécution ; la recette MAIN exécute les tests sur les sources complètes, vérifiées par blobs Git avant transformation. Cette recette ne constitue pas une qualification acoustique. La campagne E05A après adaptation UI devra exécuter de nouveaux runs pour le catalogue, comparer les résultats et arbitrer les écarts. Le nombre de voix, les syllabes et la narration ne peuvent pas être déduits de simples tags. Aucun seuil de tolérance d'expertise n'est activé.
