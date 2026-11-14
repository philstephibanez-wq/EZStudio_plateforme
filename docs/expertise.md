# PARADIGME D'EXPERTISE SCIENTIFIQUE — EZSTUDIO

## 1. Principe fondamental

L'expertise est une **contre-expertise indépendante des résultats scientifiques**, et non un audit des moteurs qui les ont produits.

**LAB analyse. L'Assistant contre-expertise. L'humain arbitre.**

L'Assistant doit chercher à vérifier ou à réfuter les assertions finales de LAB, jamais à les confirmer par principe.

## 2. Séparation des responsabilités

- **EZStudio LAB** : produit les analyses et les assertions musicales finales ; conserve les analyses, les jobs et les révisions métier.
- **EZS Assistant** : recherche, identifie les références pertinentes, confronte les résultats aux preuves documentaires indépendantes et produit une expertise critique.
- **Orchestrator** : ordonnance les analyses et expertises, assure leur exécution séquentielle, leur suivi et leur cycle de vie.
- **EZStudio Run Reports** : archive les rapports, preuves, traces, références et informations nécessaires à l'audit.
- **Interface LAB** : restitue les résultats et permet leur révision humaine, sans recalculer les verdicts.

## 3. Processus d'expertise

Pour chaque assertion musicale finale transmise par LAB :

1. **Identifier précisément l'enregistrement** : titre, interprète, version et autres informations discriminantes. Une identification proposée n'est pas une identification confirmée.
2. **Décomposer les résultats en assertions vérifiables** : chaque information doit pouvoir recevoir une évaluation individuelle.
3. **Effectuer une recherche documentaire ouverte** : l'Assistant explore librement les sources pertinentes, sans se limiter à une liste fermée de sites.
4. **Recueillir et qualifier les preuves** : distinguer sources favorables, contradictoires et non concluantes ; vérifier leur provenance et leur indépendance.
5. **Chercher activement la contradiction** : tester les premières conclusions, rechercher des éléments susceptibles de les réfuter et identifier les conflits.
6. **Établir un verdict par assertion** :
   - **BON** : assertion suffisamment corroborée par des preuves pertinentes.
   - **FAUX** : assertion contredite par des preuves suffisamment solides.
   - **NON TROUVÉ** : preuves absentes, insuffisantes, ambiguës ou contradictoires sans arbitrage fiable.
7. **Persister l'expertise** : verdicts, justifications, références, niveaux de confiance, incertitudes, contradictions et historique de révision.

## 4. Rôle respectif de l'IA et du code

**L'intelligence artificielle :**
- Recherche et comprend les documents.
- Identifie les informations et leur contexte.
- Compare les assertions.
- Explore les hypothèses contradictoires.
- Explique les accords, désaccords et incertitudes.

**Le code déterministe :**
- Contrôle l'intégrité et la provenance des preuves.
- Vérifie les contrats de données.
- Applique les règles d'admissibilité des verdicts.
- Empêche la validation d'une affirmation sans justification suffisante.
- Assure la persistance et la traçabilité.

L'IA ne doit jamais inventer une référence, une citation, une preuve ou une certitude.

## 5. Indépendance et convergence

**La convergence entre plusieurs sources ne constitue pas automatiquement une preuve indépendante.**

Plusieurs pages peuvent reproduire une même information erronée ou dériver d'une source commune.

L'expertise doit donc :
- Identifier les dépendances entre sources.
- Distinguer quantité de sources et indépendance réelle.
- Évaluer les contradictions au lieu de les masquer.
- Rechercher activement la réfutation.
- Privilégier l'abstention à une conclusion artificiellement certaine.

Un verdict NON TROUVÉ est un résultat d'expertise légitime, pas un échec à dissimuler.

## 6. Nature des preuves

Ne jamais confondre :
- **Preuve documentaire** : information issue d'une référence identifiable.
- **Observation auditive** : constat réalisé à l'écoute.
- **Mesure instrumentale** : résultat d'une analyse technique.
- **Inférence** : interprétation ou hypothèse produite à partir des éléments disponibles.

Une preuve appartenant à une catégorie ne doit pas être présentée comme appartenant à une autre.

Les résultats scientifiques de LAB ne constituent pas, à eux seuls, une validation indépendante de ces mêmes résultats.

## 7. Traçabilité obligatoire

Chaque expertise doit permettre de retrouver :

- L'assertion initiale exacte de LAB.
- L'identité de l'enregistrement étudié et son degré de certitude.
- Les recherches effectuées.
- Les documents consultés et les références retenues.
- Les sources écartées et leurs motifs d'exclusion.
- Les éléments favorables, contradictoires et non concluants.
- Les décisions d'interprétation de l'IA.
- Les règles déterministes appliquées.
- Le verdict final, sa justification et son niveau de confiance.
- Les erreurs techniques distinctes des incertitudes documentaires.
- L'historique des révisions humaines.

**La donnée brute, sa provenance et son interprétation doivent rester distinctes.**

Aucune preuve ne doit être artificiellement reconstruite, tronquée ou remplacée par une synthèse présentée comme une donnée originale.

## 8. Contraintes d'exécution

- L'expertise s'exécute sur MAIN, sous le contrôle de l'ordonnanceur commun.
- Les analyses et expertises respectent la même discipline de file d'attente.
- Chaque job doit aboutir à un état terminal explicite : réussite, échec ou annulation.
- Une expertise doit pouvoir être annulée avant ou pendant son exécution.
- Une erreur technique ne doit pas être convertie en verdict scientifique.
- Les résultats déjà produits doivent être conservés : pas de réanalyse scientifique inutile.
- La publication des rapports est déclenchée par un événement métier pertinent, jamais par une publication périodique indifférenciée.
- Un échec de publication doit pouvoir être diagnostiqué et repris sans relancer l'analyse ou l'expertise.

## 9. Invariants contractuels

1. L'Assistant expertise les **assertions musicales finales**, jamais les moteurs, modules ou presets scientifiques de LAB.
2. Aucune conclusion sans preuve suffisante.
3. Aucune source inventée.
4. Aucune convergence considérée automatiquement comme indépendante.
5. Aucune certitude artificielle.
6. Toute contradiction pertinente est conservée.
7. Toute incertitude significative est explicitée.
8. Tout verdict est explicable, traçable et révisable.
9. Toute validation technique est distincte de la validation scientifique.
10. L'humain conserve la possibilité de réviser les conclusions.

## 10. Formule de synthèse

**LAB produit des assertions.**

**L'Assistant recherche des preuves indépendantes, cherche la contradiction et formule une contre-expertise.**

**Le code déterministe garantit l'admissibilité, la cohérence et la traçabilité des verdicts.**

**L'humain reste l'arbitre final.**

**L'objectif n'est pas d'obtenir un maximum de BON, mais un maximum de conclusions justifiées, y compris FAUX et NON TROUVÉ.**