<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * R8 : qualification conservatrice de processus Windows déjà observés.
 * Aucun appel système et aucune terminaison : la collecte et le contrôle natifs
 * restent à intégrer au propriétaire du cycle de vie Symfony existant.
 * Un PID seul n'autorise jamais une terminaison, même s'il figure au registre.
 */
final class EcosystemProcessEvidence
{
    /** @var array<string,array{env:string,exe:string,needle:string}> */
    private const SERVICES = [
        'orchestrator-fastcgi'=>['env'=>'DEV','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8791'],
        'ezscore-dev-fastcgi'=>['env'=>'DEV','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8602'],
        'ezscore-preview-fastcgi'=>['env'=>'DEV','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8862'],
        'lab-fastcgi'=>['env'=>'DEV','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8701'],
        'assistant-fastcgi'=>['env'=>'DEV','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8713'],
        'ezscore-prod-fastcgi'=>['env'=>'PROD','exe'=>'php-cgi.exe','needle'=>'-b 127.0.0.1:8512'],
        'dev-caddy'=>['env'=>'DEV','exe'=>'caddy.exe','needle'=>'Caddyfile.dev-lab-assistant-candidate'],
        'prod-caddy-internal'=>['env'=>'PROD','exe'=>'caddy.exe','needle'=>'Caddyfile.prod-internal-candidate'],
        'prod-caddy-edge'=>['env'=>'PROD','exe'=>'caddy.exe','needle'=>'Caddyfile.prod'],
        'assistant-llama'=>['env'=>'DEV','exe'=>'llama-server.exe','needle'=>'--port 8720'],
    ];

    /**
     * Le statut « candidat » ne permet pas l'arrêt : une ressemblance de
     * commande ne remplace pas l'identité exacte d'un processus.
     * @param array<string,mixed> $process Observation issue d'un collecteur attesté.
     * @return array{service:string,environment:string,proof:string}|null
     */
    public static function classify(array $process): ?array
    {
        $pid = (int)($process['pid'] ?? 0);
        $path = str_replace('/', '\\', (string)($process['executable'] ?? ''));
        $name = strtolower((string)basename(str_replace('\\', '/', $path)));
        $cmd = str_replace('/', '\\', (string)($process['command'] ?? ''));
        if ($pid <= 0 || $name === '' || $cmd === '') return null;
        foreach (self::SERVICES as $service=>$rule) {
            if ($name !== $rule['exe'] || !preg_match('/'.preg_quote($rule['needle'], '/').'(?=\s|$)/i', $cmd)) continue;
            // Pour les exécutables partagés, la signature seule ne prouve pas la propriété.
            return ['service'=>$service,'environment'=>$rule['env'],'proof'=>'candidate-signature'];
        }
        return null;
    }

    /**
     * Une preuve forte exige une correspondance stricte entre l'enregistrement
     * et la nouvelle observation, y compris la date de création (PID réutilisés).
     * @param array<string,mixed> $record
     * @param array<string,mixed> $live
     */
    public static function sameIdentity(array $record, array $live): bool
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid <= 0 || $pid !== (int)($live['pid'] ?? 0)) return false;
        foreach (['created','executable','command'] as $field) {
            $a = (string)($record[$field] ?? '');
            $b = (string)($live[$field] ?? '');
            if ($a === '' || $b === '') return false;
            if ($field === 'executable') {
                if (strcasecmp(str_replace('/', '\\', $a),str_replace('/', '\\', $b)) !== 0) return false;
            } elseif ($a !== $b) return false;
        }
        return true;
    }

    /**
     * Compare un instantané consigné avec les observations du collecteur.
     * L'identification repose sur PID, date de creation, binaire et commande.
     * Aucun etat issu de ce calcul ne vaut autorisation de terminaison.
     *
     * @param list<array<string,mixed>> $recorded
     * @param list<array<string,mixed>> $observed
     * @return array{matched:list<array<string,mixed>>,missing:list<array<string,mixed>>,changed:list<array<string,mixed>>,untracked:list<array<string,mixed>>,ambiguous:list<int>}
     */
    public static function reconcile(array $recorded, array $observed): array
    {
        $byPid = [];
        foreach ($observed as $entry) {
            $pid = (int)($entry['pid'] ?? 0);
            if ($pid > 0) $byPid[$pid][] = $entry;
        }
        $result = ['matched'=>[], 'missing'=>[], 'changed'=>[], 'untracked'=>[], 'ambiguous'=>[]];
        $known = [];
        foreach ($recorded as $entry) {
            $pid = (int)($entry['pid'] ?? 0);
            if ($pid <= 0 || isset($known[$pid])) {
                $result['ambiguous'][] = $pid;
                continue;
            }
            $known[$pid] = true;
            $live = $byPid[$pid] ?? [];
            if (count($live) > 1) {
                $result['ambiguous'][] = $pid;
            } elseif ($live === []) {
                $result['missing'][] = $entry;
            } elseif (self::sameIdentity($entry, $live[0])) {
                $result['matched'][] = $entry;
            } else {
                $result['changed'][] = $entry;
            }
        }
        foreach ($byPid as $pid => $entries) {
            if (count($entries) > 1) {
                if (!in_array((int)$pid, $result['ambiguous'], true)) $result['ambiguous'][] = (int)$pid;
            } elseif (!isset($known[$pid])) {
                $result['untracked'][] = $entries[0];
            }
        }
        sort($result['ambiguous'], SORT_NUMERIC);
        return $result;
    }

    /** @return list<string> */
    public static function services(): array { return array_keys(self::SERVICES); }
}
