<?php

declare(strict_types=1);

namespace EZSLab\Service;

/** Compatibility view for the unchanged historic Assistant client.
 *  Input: published FINAL musical assertions only. Never scientific metrics, presets or diagnostics.
 */
final class LegacyProfileFinalProjection
{
    /** @param array<string,mixed> $final */
    public static function fromFinal(int $runId, array $final): array
    {
        $title = trim((string)($final['title'] ?? ''));
        $artist = trim((string)($final['artist'] ?? ''));
        if ($runId < 1 || $title === '' || $artist === '') {
            throw new \DomainException('legacy_assistant_recording_identity_incomplete');
        }

        $assertions = [];
        $seen = [];
        $genre = null;
        $instruments = [];
        $vocals = [];

        foreach (($final['final_assertions'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = $item['id'] ?? null;
            $dimension = $item['dimension'] ?? null;
            $value = $item['value'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/', $id)
                || isset($seen[$id]) || !is_string($dimension)
                || !is_scalar($value) || is_bool($value)
                || trim((string)$value) === '') continue;
            $seen[$id] = true;
            $assertions[] = ['id'=>$id,'dimension'=>$dimension,'value'=>$value];

            $dimension = mb_strtolower(trim($dimension));
            if ($dimension === 'genre' && $genre === null) {
                $genre = (string)$value;
            } elseif (in_array($dimension, ['instruments', 'instrumentation'], true)) {
                $instruments[] = ['label'=>(string)$value, 'decision'=>'accepted'];
            } elseif (in_array($dimension, ['voix_principale','voix_accompagnement','harmonies_vocales','choeurs'], true)) {
                $key = match ($dimension) {
                    'voix_principale'=>'lead_vocal',
                    'voix_accompagnement'=>'backing_vocals',
                    'harmonies_vocales'=>'vocal_harmonies',
                    'choeurs'=>'choir',
                };
                $label = mb_strtolower(trim((string)$value));
                $decision = match ($label) {
                    'présent','présents','present','yes','oui','détecté','détectés' => 'present',
                    'absent','absents','not_detected','no','non' => 'absent',
                    default => null,
                };
                if ($decision !== null) $vocals[$key] = ['decision'=>$decision];
            }
        }

        // Historic Assistant expects diagnostics.profile_view. This is a NEW,
        // compatibility-only representation made exclusively from public final values.
        // It contains no private diagnostic, model score, engine, preset or algorithm.
        $profileView = [
            'genre_hierarchy' => ['primary'=>['genre'=>$genre]],
            'instrumentation' => ['candidates'=>$instruments],
            'vocal_profile' => ['dimensions'=>$vocals],
        ];
        return [
            'schema'=>'ezstudio.profile-review-queue-item.v1',
            'run_id'=>$runId,
            'title'=>$title,
            'artist'=>$artist,
            'state'=>'done',
            'config'=>[],
            'metrics'=>[],
            'diagnostics'=>['profile_view'=>$profileView],
            'final_assertions_schema'=>'ezstudio.profile-final-assertions.v1',
            'final_assertions'=>$assertions,
            'final_assertions_count'=>count($assertions),
        ];
    }
}
