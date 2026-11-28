<?php
declare(strict_types=1);
namespace EZSLab\Command;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'lab:queue:song-labels')]
final class QueueSongLabelsCommand extends Command
{
    public function __construct(private readonly string $databasePath) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('ids', InputArgument::REQUIRED); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = explode(',', (string)$input->getArgument('ids'));
        if (count($ids)>100 || array_filter($ids, static fn($v)=>!ctype_digit($v) || (int)$v<1)) return Command::INVALID;
        if (!is_file($this->databasePath)) return Command::FAILURE;
        $uri = 'file:'.str_replace(['%','?','#'], ['%25','%3F','%23'], str_replace('\\','/',$this->databasePath)).'?mode=ro';
        $db = new PDO('sqlite:'.$uri, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>1]);
        $db->exec('PRAGMA query_only=ON');
        $q = $db->prepare('SELECT id,title,artist FROM songs WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($ids);
        $labels=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $title=trim((string)$row['title']); $artist=trim((string)$row['artist']);
            if ($title!=='') $labels[(int)$row['id']]=mb_substr($title.($artist!==''?' — '.$artist:''),0,300);
        }
        $output->writeln(json_encode(['schema'=>'ezstudio.lab.song-labels.v2','labels'=>(object)$labels],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
