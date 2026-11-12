# EZStudio Plateforme (`v5.0a1`)

Plateforme unifiée multi-noyaux **Symfony 7.4 / 8.1** regroupant l'ensemble de l'écosystème musical et d'ingénierie audio **EZStudio** au sein d'un monorepo unique, avec isolation stricte des données, des sessions et des cycles de vie.

---

## Architecture du Monorepo

```text
H:\EZStudio_plateforme\
├── apps/
│   ├── orchestrator/     # Supervision, télémétrie GPU, files d'exécution & contrôle de cycle de vie
│   ├── ezs_lab/          # Laboratoire audio, benchmarks, extraction de stems/paroles/accords
│   ├── ezs_assistant/    # Assistant d'ingénierie, historique & backoffice
│   ├── ezstudio_dev/     # Application principale EZStudio (branche DEV active)
│   └── ezstudio/         # Application principale EZStudio (PROD figée jusqu'au prochain déploiement)
├── src/                  # Socle PHP mutualisé (App\Gpu, App\Version, services partagés)
├── shared/               # Configurations Symfony mutualisées (framework, twig, monolog, profiler)
├── data/                 # Bases de données SQLite isolées (benchmark, ezstudio, ezstudio_dev)
├── public/               # Point d'entrée HTTP unique avec routage par sous-domaine (Host)
├── var/                  # Caches isolés par noyau (var/cache/<app_id>/) & états d'exécution
└── VERSION               # Version globale de la plateforme (v5.0a1)
```

---

## Cartographie des 5 Noyaux

| Application (`APP_ID`) | URL locale (`:8000`) | Namespace PHP | Base de données / État | Statut |
| :--- | :--- | :--- | :--- | :--- |
| **Orchestrator** (`orchestrator`) | `http://ezstudio.local` | `Ezs\Web\` | `var/ezstudio_state/orchestrator/` | Actif |
| **EZS_Lab** (`ezs_lab`) | `http://ezstudio.local` | `EZSLab\` | `data/benchmark.sqlite` | Actif |
| **EZS_Assistant** (`ezs_assistant`) | `http://ezstudio.local` | `EZSAssistant\` | `var/ezstudio_state/assistant/` | Actif |
| **EZStudio DEV** (`ezstudio_dev`) | `http://ezstudio.local` | `EZStudioDev\` | `data/ezstudio_dev.sqlite` | DEV active |
| **EZStudio PROD** (`ezstudio`) | `http://ezstudio.local:8000` | `EZStudio\` | `data/ezstudio.sqlite` | **PROD figée** |

---

## Versioning & Gouvernance

* **Double niveau conjugué :** chaque application conserve son fichier `apps/<app_id>/VERSION` indépendant, conjugué avec la version globale de la plateforme (`VERSION` racine) sous la forme **`<version_site>+pf<version_plateforme>`** (ex. `v4.3a24+pf5.0a1`).
* **Protection de la PROD :** le noyau `apps/ezstudio/` est verrouillé (`.prod-frozen`). Aucune modification directe n'y est effectuée ; toute évolution passe par `apps/ezstudio_dev/` puis par un déploiement validé.
* **Isolation des sessions :** cookies distincts par environnement (`EZS_PROD_SESSID`, `EZS_DEV_SESSID`) et variables OAuth2 dédiées dans `.env.local`.

---

## Démarrage rapide

```powershell
# Démarrer le serveur web multi-domaines sur le port 8000
h:\symfony\symfony.exe server:start --no-tls

# Exécuter une commande Symfony ciblée sur un noyau précis
\$env:APP_ID="ezs_lab"; php bin/console debug:router
```