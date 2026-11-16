# PROCÉDURE DE COLLABORATION, LIVRAISON ET TESTS (EZStudio_plateforme)

## 1. Règles strictes d'interaction & de transfert de fichiers
- **Zéro archive `.zip` à lire** : ne jamais demander à l'utilisateur de téléverser un `.zip` (les archives compressées sont illisibles par l'outil de lecture).
- **Transfert via `H:\temp\` en texte brut** : pour récupérer des fichiers sources du projet (`H:\EZStudio_plateforme`), fournir **une seule commande PowerShell courte (1 à 3 lignes max)** qui copie tous les fichiers nécessaires d'un seul coup vers `H:\temp\` (avec extension `.txt` si ce sont des fichiers `.twig`, `.php` ou `.yaml`).
- **Zéro supposition sur le code** : toujours lire le code réel des fichiers avant de proposer un patch ou un test (ne jamais deviner le nom d'une fonction ou d'une classe).

## 2. Règles de livraison du code (PowerShell Windows)
- **Zéro bloc géant à coller dans le terminal** : PowerShell interactif bloque au-delà de ~10 lignes collées.
- **Mini-blocs de 3 à 5 lignes maximum** : découper chaque modification en commandes courtes, atomiques et en **ASCII pur** (utiliser des séquences d'échappement `\u00e9` en Python ou éviter les caractères spéciaux dans les chaînes collées pour prévenir les blocages d'invite `>>`).
- **Encodage obligatoire** : toujours écrire les fichiers en **UTF-8 sans BOM** via `[IO.File]::WriteAllText(..., [Text.UTF8Encoding]::new($false))`.
- **Ordre des imports Python** : ne jamais insérer d'import avant `from __future__ import annotations`.

## 3. Périmètre & Non-régression
- **Une étape à la fois** : étude d'impacts avant modification, validation avant de passer à l'étape suivante.
- **Isolation stricte** : ne jamais modifier les moteurs d'analyse scientifique (`LAB`), les presets scientifiques, ni la logique de file d'attente (`queue`) d'Orchestrator sauf demande explicite.

## 4. Procédure standard de validation & tests (chemins exacts du dépôt)
Avant tout commit ou clôture d'issue, exécuter systématiquement dans cet ordre :
1. **Syntaxe Python** :
   `python -m py_compile <fichiers_modifies.py>`
2. **Tests unitaires Python ciblés** :
   `python -c "import sys; sys.path.insert(0, 'apps/ezs_assistant/app'); ..."`
3. **Syntaxe PHP & Linters Symfony (racine projet)** :
   `php -l <fichier.php>`
   `php bin\console lint:twig apps\ezs_lab\templates`
   `php bin\console lint:container`
4. **Tests PHPUnit (racine projet)** :
   `php bin\phpunit` (ou `-c <chemin_phpunit.xml>`)
5. **Redémarrage & Recette réelle** :
   `.\bin\ezs.ps1 restart` (purge le cache Symfony/Twig et arrête les workers Python résiduels), puis relancer le job cible et comparer les verdicts avec la révision de référence.

Et toujour UTF 8 sans BOM