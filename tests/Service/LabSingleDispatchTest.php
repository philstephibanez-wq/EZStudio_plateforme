<?php
declare(strict_types=1);
namespace App\Tests\Service;
use App\Service\OrchestratorQueueClient;
use EZSLab\Service\AnalysisRunRegistry;
use EZSLab\Service\ChordsExecutionService;
use EZSLab\Service\Database;
use EZSLab\Service\StemsExecutionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
final class LabSingleDispatchTest extends TestCase {
    public function testStemsAndChordsEmitExactlyOneRestDispatchPostEach(): void {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ezs_single_disp_" . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);
        $dbPath = $tmpDir . DIRECTORY_SEPARATOR . "lab.sqlite";
        $audioHash = hash('sha256', 'RIFF0000WAVEfmt ');
        $audioPath = $tmpDir . DIRECTORY_SEPARATOR . $audioHash . '.wav';
        $requestRoot = $tmpDir . DIRECTORY_SEPARATOR . 'requests';
        $settings = ['EZSTUDIO_IMPORTS_ROOT'=>$tmpDir, 'EZSTUDIO_CHORDS_REQUESTS_ROOT'=>$requestRoot, 'EZSTUDIO_STORAGE_ROOT'=>$tmpDir . DIRECTORY_SEPARATOR . 'storage'];
        $previous = [];
        foreach ($settings as $key=>$value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            file_put_contents($audioPath, 'RIFF0000WAVEfmt ');
            $db = new Database($dbPath);
            $pdo = $db->pdo();
            $stmt = $pdo->prepare('INSERT INTO songs (id,title,artist,audio_sha256,source_filename,created_at) VALUES (?,?,?,?,?,?)');
            $stmt->execute([10,'Test Song','Artist',$audioHash,'master.wav',gmdate('c')]);
            unset($stmt);
            $registry = new AnalysisRunRegistry($db);
            $stemsRun = $registry->createRun(10, "stems", ["name" => "demucs", "version" => "v1"], []);
            $chordsRun = $registry->createRun(10, "chords", ["name" => "madmom", "version" => "v1"], []);
            $reqPath = (new \EZSLab\Service\LabResourceResolver(new \EZSLab\Service\AudioSourceResolver()))->chordsRequest((int)$chordsRun['id']);
            mkdir(dirname($reqPath), 0777, true);
            file_put_contents($reqPath, '{"selection":"auto"}');
            $registry->setState((int) $chordsRun["id"], "configured");
            $captured = [];
            $seq = 500;
            $mockHttp = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, &$seq): MockResponse {
                $captured[] = ["method" => $method, "url" => $url, "body" => $options["body"] ?? ""];
                $seq++;
                return new MockResponse((string) json_encode(["ok" => true, "queue_id" => $seq, "source" => "lab", "status" => "waiting", "progress" => 0, "created_at" => gmdate("c")]), ["http_code" => 201]);
            });
            $client = new OrchestratorQueueClient($mockHttp, "http://ezstudio.local", "tok");
            $stemsSvc = new StemsExecutionService($db, $registry, $client);
            $resStems = $stemsSvc->queue((int) $stemsRun["id"], $audioPath,  $audioHash, false, "default");
            $this->assertCount(1, $captured);
            $this->assertSame("POST", $captured[0]["method"]);
            $this->assertSame("http://ezstudio.local/api/queue/dispatch", $captured[0]["url"]);
            $this->assertSame(["scientific_run_id", "job_id", "queue_id"], array_keys($resStems));
            $this->assertSame(501, $resStems["queue_id"]);
            $chordsSvc = new ChordsExecutionService($db, $registry, $client);
            $resChords = $chordsSvc->queue((int) $chordsRun["id"]);
            $this->assertCount(2, $captured);
            $this->assertSame("POST", $captured[1]["method"]);
            $this->assertSame("http://ezstudio.local/api/queue/dispatch", $captured[1]["url"]);
            $this->assertSame(["scientific_run_id", "benchmark_run_id", "job_id", "queue_id"], array_keys($resChords));
            $this->assertSame(502, $resChords["queue_id"]);
        } finally {
            unset($stemsSvc, $chordsSvc, $registry, $db, $pdo, $stmt);
            foreach ($previous as $key=>[$process,$env,$server]) {
                putenv($process===false ? $key : $key.'='.$process);
                if ($env===null) unset($_ENV[$key]); else $_ENV[$key]=$env;
                if ($server===null) unset($_SERVER[$key]); else $_SERVER[$key]=$server;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
            }
            rmdir($tmpDir);
        }
    }
}
