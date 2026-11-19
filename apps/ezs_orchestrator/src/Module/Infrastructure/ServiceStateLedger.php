<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Durable, append-only service intent and action ledger. No process side effects. */
final class ServiceStateLedger
{
    public const PATH = 'H:/EZS_orchestrator/runtime/supervision/service-state.sqlite';
    private \PDO $db;

    public function __construct(?string $path = null)
    {
        if (!extension_loaded('pdo_sqlite')) throw new \RuntimeException('EZS_SQLITE_EXTENSION_MISSING');
        $path ??= self::PATH;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('EZS_LEDGER_DIRECTORY_FAILED');
        $this->db = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('CREATE TABLE IF NOT EXISTS intents (service TEXT PRIMARY KEY, desired TEXT NOT NULL CHECK(desired IN (\'RUNNING\',\'STOPPED\',\'ON\',\'OFF\')), revision INTEGER NOT NULL DEFAULT 0, changed_at TEXT NOT NULL, origin TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS operations (id INTEGER PRIMARY KEY AUTOINCREMENT, service TEXT NOT NULL, action TEXT NOT NULL, origin TEXT NOT NULL, actor TEXT, requested_at TEXT NOT NULL, finished_at TEXT, desired_before TEXT, desired_after TEXT, observed TEXT, result TEXT NOT NULL CHECK(result IN (\'REQUESTED\',\'SUCCEEDED\',\'FAILED\')), error_code TEXT, error_detail TEXT)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_operations_service_time ON operations(service,id DESC)');
    }

    public function intents():array
    {
        $rows=$this->db->query('SELECT service,desired FROM intents ORDER BY service')->fetchAll(\PDO::FETCH_ASSOC);
        $out=[];
        foreach($rows as $row)$out[$row['service']]=$row['desired'];
        return $out;
    }

    /** Atomic one-time import. Existing DB values are never overwritten. */
    public function import(array $services, string $origin='legacy-import'):void
    {
        $allowed=['root','dev','lab','assistant','prod','prod_maintenance'];
        foreach($services as $service=>$state){
            if(!in_array($service,$allowed,true))throw new \InvalidArgumentException('EZS_LEDGER_UNKNOWN_SERVICE');
            $states=$service==='prod_maintenance'?['ON','OFF']:['RUNNING','STOPPED'];
            if(!in_array($state,$states,true))throw new \InvalidArgumentException('EZS_LEDGER_BAD_STATE');
        }
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('INSERT OR IGNORE INTO intents(service,desired,revision,changed_at,origin) VALUES(?,?,0,?,?)');
            foreach($services as $service=>$state)$stmt->execute([$service,$state,gmdate('c'),$origin]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    /** Only user-origin actions may change desired states; never invoked by probes. */
    public function request(string $service,string $desired,string $action,string $actor):int
    {
        if($actor==='')throw new \InvalidArgumentException('EZS_LEDGER_ACTOR_REQUIRED');
        $states=$service==='prod_maintenance'?['ON','OFF']:['RUNNING','STOPPED'];
        if(!in_array($service,['root','dev','lab','assistant','prod','prod_maintenance'],true)||!in_array($desired,$states,true))throw new \InvalidArgumentException('EZS_LEDGER_REQUEST_INVALID');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT desired FROM intents WHERE service=?');$q->execute([$service]);
            $old=$q->fetchColumn();if($old===false)throw new \RuntimeException('EZS_LEDGER_NOT_MIGRATED');
            $q=$this->db->prepare('UPDATE intents SET desired=?,revision=revision+1,changed_at=?,origin=? WHERE service=?');
            $q->execute([$desired,gmdate('c'),'user',$service]);
            $q=$this->db->prepare('INSERT INTO operations(service,action,origin,actor,requested_at,desired_before,desired_after,result) VALUES(?,?,?,?,?,?,?,\'REQUESTED\')');
            $q->execute([$service,$action,'user',$actor,gmdate('c'),$old,$desired]);
            $id=(int)$this->db->lastInsertId();$this->db->commit();return $id;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function complete(int $id,bool $ok,string $observed,?string $errorCode=null,?string $detail=null):void
    {
        $q=$this->db->prepare('UPDATE operations SET finished_at=?,observed=?,result=?,error_code=?,error_detail=? WHERE id=? AND result=\'REQUESTED\'');
        $q->execute([gmdate('c'),$observed,$ok?'SUCCEEDED':'FAILED',$errorCode,$detail,$id]);
        if($q->rowCount()!==1)throw new \RuntimeException('EZS_LEDGER_OPERATION_NOT_PENDING');
    }
}
