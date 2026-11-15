# Architecture d'acces et securite - Orchestrateur (`/jobs`)

## 1. Controle d'acces applicatif (Symfony Security)
- Toutes les routes `^/jobs` exigent `IS_AUTHENTICATED_FULLY` et `ROLE_ORCHESTRATOR_OPERATOR` (`config/packages/security.yaml`).
- Les actions critiques (`POST /jobs/{id}/retry` et `POST /jobs/{id}/delete`) appliquent une double barriere :
  1. Attribut `#[IsGranted('ROLE_ORCHESTRATOR_OPERATOR')]` sur `JobsPageController::retry()` et `delete()`.
  2. Validation stricte du jeton CSRF lie a la session (`QueueActionCsrfManager`).

## 2. Deploiement derriere un Reverse Proxy (Nginx / Caddy / IIS)
- L'interface d'orchestration ne doit jamais etre exposee directement sur Internet sans terminaison TLS ni filtrage IP.
- Configurer `TRUSTED_PROXIES` dans `.env.local` (ex. `127.0.0.1,10.0.0.0/8`) et restreindre l'acces reseau au reseau d'administration ou VPN.
- Definissez `ORCHESTRATOR_OPERATOR_PASSWORD_HASH` (genere via `php bin/console security:hash-password`) dans `.env.local`.
