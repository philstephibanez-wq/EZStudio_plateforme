<?php

declare(strict_types=1);

namespace EZSLab\Service;

final class SongIdentityEditor
{
    public function __construct(private readonly Database $db) {}

    public function update(int $songId, string $title, string $artist): void
    {
        $title = trim($title);
        $artist = trim($artist);
        if ($songId < 1 || $title === '' || $artist === ''
            || mb_strlen($title) > 250 || mb_strlen($artist) > 250
            || preg_match('/[\\x00-\\x1F\\x7F]/u', $title.$artist)) {
            throw new \InvalidArgumentException('Titre et interprète requis (250 caractères maximum).');
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('UPDATE songs SET title=?, artist=? WHERE id=?');
        $stmt->execute([$title,$artist,$songId]);
        if ($stmt->rowCount() === 0) {
            $check=$pdo->prepare('SELECT 1 FROM songs WHERE id=? AND title=? AND artist=?');
            $check->execute([$songId,$title,$artist]);
            if (!$check->fetchColumn()) throw new \RuntimeException('Chanson introuvable.');
        }
    }
}
