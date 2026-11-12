# CONTRAT TRANSVERSAL — Assistant scientifique, modules, presets et rapports
Date : 2026-10-21. Statut : contrat d'architecture ; fonctionnalités non indiquées comme livrées restent à développer.

## Vocabulaire obligatoire
- Pipeline : PROFILE → STEMS → CHORDS/N → LYRICS.
- Phase : étape fonctionnelle du pipeline.
- Module : composant d'analyse scientifique et ses paramètres. Moteur : algorithme ou modèle exécuté par le module.
- Preset : configuration déclarative, versionnée et traçable de modules ; preset candidat : variante expérimentale sans effet sur la configuration active.
- Assistant scientifique : composant scientifique d'évaluation critique, confrontation intermodules, recherche de références, hypothèses et expérimentation. Une hypothèse scientifique n'est pas une conclusion validée.
- Révision IA : résultat persisté d'une consultation. Validation : vérification documentée avec références indépendantes ou expertise humaine, et non simple statut « completed ».
Ne pas réintroduire les analogies historiques (« pédales », « ADN », « chromosome ») dans les nouveaux contrats et interfaces.

## Contrats de séparation
1. Les modules scientifiques produisent observations, scores, candidats, incertitudes et provenance ; les presets configurent leurs comportements.
2. L'Assistant peut être scientifique : critique intermodules, contrôle des preuves, hypothèses réfutables, recherche documentaire et propositions de réglages **réellement exposés** par le runtime. Il ne se substitue pas automatiquement aux mesures ni à la vérité terrain.
3. Toute proposition de preset candidat comprend : phase, modules, version et empreinte du preset source, paramètres autorisés avant/après, observation, preuve, contradiction, incertitude, hypothèse, métrique, corpus d'essai et critère de rejet.
4. L'Assistant ne modifie pas de preset actif. Une promotion exige une comparaison A/B reproductible sur un corpus indépendant, vérification des régressions et décision autorisée. Pas de modification d'EZScore dans ce chantier.
5. Les confiances hétérogènes ne sont pas automatiquement comparables. L'absence de contradictions dans une réponse Qwen ne prouve pas leur inexistence. Les abstentions sont légitimes ; ne jamais inventer une écoute audio ou une preuve extérieure.
6. Un seul job canonique par révision ; évaluations de modules séquentielles dans ce job ; progression mesurée, gestion des erreurs et persistance déterministe.

## Rapport privé et preuve de couverture
- Dépôt : philstephibanez-wq/EZStudio_Run_Reports. MAIN conserve l'autorité métier ; GitHub conserve les copies expurgées et auditables.
- Provenance obligatoire : run/revision/phase/module, modèle, preset et version si disponibles, statuts, observations, avis, réserves, sources, empreintes et couverture vérifiable. Aucun écrêtage silencieux.
- Jamais de secret, audio, stems, poids de modèles ou chemin sensible. Les traces brutes Qwen restent privées sur XPS tant que leur export complet n'a pas passé l'audit de confidentialité.
- Publication vérifiée par relecture distante ; échec observable et relançable. Distinction stricte entre résultats normalisés, empreintes cryptographiques et contenu brut.

## État attesté de référence (Aline / Christophe, PROFILE run #153)
- Révision LAB #52 : 12/12 consultations enregistrées, 12 « completed », chacune avec résumé, incertitude et empreinte source ; synthèse globale 0/5 dimensions évaluées, aucune validation extérieure.
- Archive MAIN vérifiée : diagnostics/assistant/profile/run-000153/revision-000052/main-db.json.
- Archive XPS vérifiée : diagnostics/profile/run-000153/trace-189e148d2ad8.json, 12/12 avis et 12 empreintes de requête + 12 empreintes de réponse Qwen.
- Worker Guardian rechargé 11808 → 13228 ; maintenance LAB levée. Affichage LAB détaillé développé (commit fa4e455), tests Twig sur MAIN non confirmés.
- Les requêtes/réponses Qwen **brutes** ne sont pas archivées dans GitHub ; elles restent sur XPS. Le niveau scientifique des avis reste insuffisant : surinterprétations et contradictions non détectées.
- Aucun changement scientifique, aucun preset promu, aucun changement de VERSION.

## Suite prioritaire et acceptation
R1 — Évaluateur scientifique critique PROFILE : lire les 12 résultats de #52 sans nouvelle inférence ; contrôle déterministe des preuves/valeurs, désaccords et surinterprétations, restitution auditable.
R2 — Presets candidats : propositions déclaratives uniquement sur paramètres existants, non appliquées.
R3 — Banc A/B : corpus de référence, métriques de gain et régression, résultats archivés et réfutables.
R4 — Validation et extension du contrat à STEMS, CHORDS/N, LYRICS.
Statut « réalisé » réservé aux opérations dont les tests, exécutions et artefacts sont effectivement observés.
