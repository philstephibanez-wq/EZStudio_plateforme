<?php
namespace EZSLab\Service;

final class ResultsPublisher
{
    public function __construct(
        private readonly string $projectDir,
        private readonly Database $database,
    ) {}

    public function publishAll(): array
    {
        $root = $this->projectDir.DIRECTORY_SEPARATOR.'results';

        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $index = [
            'schema_version' => 2,
            'generated_at' => gmdate('c'),
            'global_scores' => $this->database->globalScores(),
            'runs' => [],
        ];

        foreach ($this->database->allRuns() as $run) {
            if (($run['status'] ?? '') !== 'done') {
                continue;
            }

            $runId = (int)$run['id'];
            $folder = sprintf(
                'run-%06d-%s',
                $runId,
                $this->slug((string)$run['title'])
            );

            $runDir = $root.DIRECTORY_SEPARATOR.$folder;

            if (!is_dir($runDir)) {
                mkdir($runDir, 0777, true);
            }

            $fullRun = $this->database->run($runId) ?: [];
            $algorithms = $this->database->algorithms($runId);
            $review = $this->database->runReview($runId);

            $result = null;
            if (!empty($fullRun['result_json'])) {
                $result = json_decode((string)$fullRun['result_json'], true);
            }

            $payload = [
                'schema_version' => 2,
                'published_at' => gmdate('c'),
                'run' => [
                    'id' => $runId,
                    'title' => $fullRun['title'] ?? '',
                    'artist' => $fullRun['artist'] ?? '',
                    'source_filename' => $fullRun['source_filename'] ?? '',
                    'audio_sha256' => $fullRun['audio_sha256'] ?? '',
                    'requested_signature' => $fullRun['requested_signature'] ?? '',
                    'detected_signature' => $fullRun['detected_signature'] ?? '',
                    'tempo' => $fullRun['tempo'] ?? null,
                    'engine_version' => $fullRun['engine_version'] ?? '',
                    'created_at' => $fullRun['created_at'] ?? '',
                ],
                'analysis' => $result,
                'algorithms' => $algorithms,
                'review' => $review,
            ];

            file_put_contents(
                $runDir.DIRECTORY_SEPARATOR.'result.json',
                json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            file_put_contents(
                $runDir.DIRECTORY_SEPARATOR.'timeline.json',
                json_encode(
                    [
                        'run_id' => $runId,
                        'algorithms' => array_map(
                            static fn(array $a): array => [
                                'algorithm' => $a['algorithm'],
                                'phase' => (int)$a['phase'],
                                'score' => (float)$a['score'],
                                'margin' => (float)$a['margin'],
                                'review_status' => $a['review_status'],
                                'grid' => $a['grid'],
                            ],
                            $algorithms
                        ),
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            file_put_contents(
                $runDir.DIRECTORY_SEPARATOR.'benchmark.html',
                $this->renderHtml($payload)
            );

            $index['runs'][] = [
                'id' => $runId,
                'folder' => $folder,
                'title' => $fullRun['title'] ?? '',
                'artist' => $fullRun['artist'] ?? '',
                'audio_sha256' => $fullRun['audio_sha256'] ?? '',
                'signature' => $fullRun['detected_signature'] ?? '',
                'tempo' => $fullRun['tempo'] ?? null,
                'reviewed' => $review !== null,
            ];
        }

        file_put_contents(
            $root.DIRECTORY_SEPARATOR.'index.json',
            json_encode(
                $index,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );

        return $index;
    }

    private function renderHtml(array $payload): string
    {
        $run = $payload['run'] ?? [];
        $algorithms = $payload['algorithms'] ?? [];

        $rows = '';

        foreach ($algorithms as $algorithm) {
            $timeline = '';

            foreach (($algorithm['grid'] ?? []) as $measure) {
                $label = htmlspecialchars((string)($measure['text'] ?? ''), ENT_QUOTES);
                $number = (int)($measure['measure'] ?? 0);
                $timeline .= '<div class="m"><span>M'.sprintf('%02d', $number).'</span>'.$label.'</div>';
            }

            $status = (string)($algorithm['review_status'] ?? 'unreviewed');

            $rows .= '<section>'
                .'<h2>'.htmlspecialchars((string)$algorithm['algorithm'], ENT_QUOTES)
                .' — P'.(int)$algorithm['phase']
                .' — '.htmlspecialchars($status, ENT_QUOTES)
                .'</h2>'
                .'<div class="timeline">'.$timeline.'</div>'
                .'</section>';
        }

        $css = 'body{font-family:Arial;margin:24px;background:#f6f6f6}'
            .'section{background:#fff;border:1px solid #bbb;padding:10px;margin:14px 0}'
            .'.timeline{display:flex;overflow-x:auto;border:1px solid #555}'
            .'.m{min-width:92px;height:38px;border-right:1px solid #999;padding:4px 6px;'
            .'font:14px Consolas,monospace;white-space:nowrap}'
            .'.m span{display:block;font:10px Arial;color:#666}';

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            .'<title>EZChords Benchmark</title><style>'.$css.'</style></head><body>'
            .'<h1>'.htmlspecialchars((string)($run['title'] ?? ''), ENT_QUOTES).'</h1>'
            .'<p>Signature '.htmlspecialchars((string)($run['detected_signature'] ?? ''), ENT_QUOTES)
            .' · Tempo '.htmlspecialchars((string)($run['tempo'] ?? ''), ENT_QUOTES)
            .' · SHA256 '.htmlspecialchars((string)($run['audio_sha256'] ?? ''), ENT_QUOTES)
            .'</p>'
            .$rows
            .'</body></html>';
    }

    private function slug(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'song';

        return trim($value, '_');
    }
}
