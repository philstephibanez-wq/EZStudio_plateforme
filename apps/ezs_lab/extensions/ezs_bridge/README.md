# EZS Bridge LAB 1.0.1

Composant Manifest V3 propre au LAB, hors public/. Compatible avec E06-P3 R5 BRIDGE. Le correctif 1.0.1 remplace l'argument absent de executeScript par null. Aucun changement de bridge.js, contract.js, popup.html ni des permissions.

## Installation et recette

Dans brave://extensions/, désactiver les versions temporaires, puis charger ce dossier en mode développeur. Après modification des sources, recharger l'extension. Aucun serveur Python ni redémarrage EZS requis pour cette livraison additive.

1. LAB / PROFILE : Expertiser, puis extension : Charger la campagne depuis LAB. Attendu CAMPAGNE_OK.
2. Extension : Ouvrir ChatGPT. Dans le nouvel onglet vide, extension : Insérer le lot dans ChatGPT. Attendu INJECT_OK. Vérifier et envoyer manuellement.
3. Après la réponse complète, extension : Capturer le rapport dans ChatGPT. Attendu CAPTURE_OK.
4. Revenir au même onglet LAB, extension : Restituer dans LAB. Attendu RESTORE_OK ; revue visuelle seulement, aucune écriture SQLite.
5. Pour une campagne collective, traiter chaque lot séparément, dans une nouvelle conversation. Ne pas recharger la campagne avant la fin.

## Tests

Depuis la racine : `node apps/ezs_lab/tests/extensions/test_popup.cjs` (Node.js, sans dépendance npm ; API Chrome simulée). La recette réelle Brave reste obligatoire. La passerelle non officielle dépend du DOM de ChatGPT et doit respecter les conditions d'utilisation applicables. Ne jamais transmettre de secrets, cookies ou tokens ; aucun envoi automatique. Les données en transit sont conservées dans chrome.storage.session, pas dans Git. Le plafond de 200 000 octets est empirique. Les prototypes restent hors dépôt.

## Versionnement

Jalon LAB proposé : `v1.7.0.L0a6`, successeur de `v1.7.0.L0a5`, conformément à `docs/VERSIONING.md`. Le manifeste Chromium conserve la version numérique `1.0.1` ; E06-P3 R5 est la référence fonctionnelle. Le jalon reste alpha jusqu'à la recette réelle. Aucun tag, commit ou push automatique.

Message de commit proposé après validation (à adapter au résultat réel) :

```text
v1.7.0.L0a6 fix(lab-bridge): versionner la passerelle et corriger la sérialisation

- Contexte : intégration E06-P3 R5, erreur executeScript sur argument absent.
- Modifications : extension LAB 1.0.1, tests contractuels, documentation et VERSION.
- Validation / Prochaine étape : recette Brave réelle avant clôture E06-P3.
```
