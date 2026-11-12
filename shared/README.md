# Dossier `shared` pour EZStudio_plateforme

Ce dossier centralise la configuration, les templates Twig et les assets (CSS/JS) partagés entre vos 4 sites (`orchestrator`, `ezstudio_dev`, `ezs_lab`, `ezs_assistant`).

## Installation

1. Extrayez le dossier `shared` à la racine de `H:\EZStudio_plateforme\` (de sorte à avoir `H:\EZStudio_plateforme\shared`).
2. Mettez à jour `src/Kernel.php` pour charger `shared/config` avant `apps/<id>/config` (voir l'exemple fourni dans `Kernel.php.example`).
3. Pour exposer vos CSS/JS partagés dans le navigateur sans bundler complexe, vous pouvez copier `shared/assets` vers `public/shared` ou créer un lien symbolique sous Windows (en PowerShell Administrateur) :
   ```powershell
   New-Item -ItemType SymbolicLink -Path "H:\EZStudio_plateforme\public\shared" -Target "H:\EZStudio_plateforme\shared\assets"
   ```
4. Dans chacun de vos 4 sites (`apps/<nom>/templates/index.html.twig`), héritez du layout commun avec :
   ```twig
   {% extends '@shared/base.html.twig' %}
   ```
