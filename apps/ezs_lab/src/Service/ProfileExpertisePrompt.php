<?php

declare(strict_types=1);
namespace EZSLab\Service;
use Doctrine\DBAL\Connection;
final class ProfileExpertisePrompt
{
    public const INITIAL = 'Expertiser indépendamment chaque ITEM PROFILE pour la version exacte de l’enregistrement. Rechercher des références vérifiables, distinguer les données documentaires des mesures scientifiques et comparer les tempos à unité métrique identique. Pour chaque ITEM : BON, FAUX ou NON TROUVÉ (référence indépendante indisponible), avec justification et sources. Signaler les voix et instruments manquants, surnuméraires ou incertains. Ne pas inventer de tolérance, de référence ni de paramètre. Proposer un preset candidat uniquement à partir du preset réellement exécuté et de paramètres autorisés ; aucune activation automatique.';
    public function __construct(private readonly Connection $connection) {}
    public function active(): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT r.* FROM profile_expertise_prompt_revision r JOIN profile_expertise_prompt_active a ON a.revision_id=r.id WHERE a.id=1');
        return $row === false ? null : $row;
    }
    public function history(): array { return $this->connection->fetchAllAssociative('SELECT * FROM profile_expertise_prompt_revision ORDER BY id DESC'); }
    public function create(string $content): int
    {
        if (trim($content)==='' || strlen($content)>65536 || !preg_match('//u',$content)) throw new \InvalidArgumentException('Invalid prompt content');
        $this->connection->insert('profile_expertise_prompt_revision',['content'=>$content,'sha256'=>hash('sha256',$content),'created_at'=>gmdate('c')]);
        return (int)$this->connection->lastInsertId();
    }
    public function activate(int $id): void
    {
        $this->connection->executeStatement('INSERT INTO profile_expertise_prompt_active(id,revision_id) SELECT 1,id FROM profile_expertise_prompt_revision WHERE id=? ON CONFLICT(id) DO UPDATE SET revision_id=excluded.revision_id',[$id]) ?: throw new \InvalidArgumentException('Unknown revision');
    }
    public function initialize(): int
    {
        return $this->connection->transactional(function(): int {
            // Acquire SQLite write lock before reading, so concurrent initialization cannot duplicate revisions.
            $this->connection->executeStatement('UPDATE profile_expertise_prompt_active SET revision_id=revision_id WHERE id=1');
            $active=$this->active(); if ($active!==null) return (int)$active['id'];
            if ($this->history()!==[]) throw new \LogicException('Existing history without active revision');
            $id=$this->create(self::INITIAL); $this->activate($id); return $id;
        });
    }
}
