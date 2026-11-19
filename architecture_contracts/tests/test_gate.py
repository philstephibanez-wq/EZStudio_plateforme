import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'validate.py'
PACKAGE = SCRIPT.parent
WORKFLOW = Path(__file__).resolve().parents[2] / '.github/workflows/architecture-contracts.yml'

class GateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.git('init', '-q')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'EZS Tests')
        for rel in ('validate.py', 'rules.json', 'hooks/pre-commit', 'hooks/pre-push', 'hook_runner.py'):
            target = self.root / 'architecture_contracts' / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(PACKAGE / rel, target)
        target = self.root / '.github/workflows/architecture-contracts.yml'
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(WORKFLOW, target)
        policy = self.root / 'architecture_contracts/rules.json'
        content = json.loads(policy.read_text())
        content['coverage'] = {k: 'complete' for k in content['coverage']}
        policy.write_text(json.dumps(content), encoding='utf-8')
        self.add_file('apps/ezs_lab/src/Service/Example.php', '<?php echo 1;')
        self.git('add', '-A')

    def git(self, *args):
        p = subprocess.run(['git', '-C', str(self.root), *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if p.returncode:
            raise RuntimeError(f'{args}: {p.stderr}')
        return p.stdout

    def add_file(self, name, content, stage=False):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding='utf-8')
        if stage:
            self.git('add', name)

    def check(self):
        return subprocess.run([sys.executable, str(SCRIPT), '--root', str(self.root), '--mode', 'index'],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def test_clean_fixture_is_blocked_until_architecture_complete(self):
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_staged_private_audio_blocks(self):
        self.add_file('apps/ezs_lab/data/imports/test.mp3', 'fake', stage=True)
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-002', result.stdout)

    def test_staged_old_php_blocks_even_if_worktree_fixed(self):
        file = 'apps/orchestrator/src/Bad.php'
        self.add_file(file, "<?php $php='H:/PHP/php-8.5-x64/php.exe';", stage=True)
        self.add_file(file, '<?php echo 1;')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-003', result.stdout)

    def test_unstaged_violation_not_committed(self):
        self.add_file('apps/orchestrator/src/OnlyWorking.php', "<?php $x='H:/PHP/php-8.5-x64/php.exe';")
        result = self.check()
        self.assertNotIn('EZS-GATE-003', result.stdout)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_changed_unstaged_validator_blocks(self):
        path = self.root / 'architecture_contracts/validate.py'
        path.write_text('print("fake success")', encoding='utf-8')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-007', result.stderr)

    def test_missing_policy_blocks(self):
        self.git('rm', '-q', '-f', 'architecture_contracts/rules.json')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-006', result.stdout)

    def test_cross_app_db_reference_blocks(self):
        self.add_file('apps/orchestrator/src/Bad.php', "<?php $db='H:/data/databases/EZS_Lab.sqlite';", stage=True)
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-004', result.stdout)

    def test_incomplete_contract_coverage_blocks(self):
        policy = self.root / 'architecture_contracts/rules.json'
        content = json.loads(policy.read_text())
        content['coverage']['EZS-ARCH-009'] = 'not-implemented'
        policy.write_text(json.dumps(content), encoding='utf-8')
        self.git('add', 'architecture_contracts/rules.json')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_unproven_coverage_cannot_be_forged(self):
        # The R1 fixture already marks coverage as "complete"; R2 must reject
        # this unsupported claim regardless of the textual registry contents.
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_local_profile_self_dispatch_blocks(self):
        # A known historical LAB bypass: local drain starts its own profile:once.
        path = 'apps/ezs_lab/src/Command/LocalProfileDrainCommand.php'
        self.add_file(path, "<?php $child = new Process([$php, 'bin/console', 'lab:profile:once', '--apply']);", stage=True)
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-011', result.stdout)

    def test_local_profile_self_dispatch_multiline_blocks(self):
        path = 'apps/ezs_lab/src/Command/LocalProfileDrainCommand.php'
        self.add_file(path, "<?php\n$child = new Process([\n $php,\n 'lab:profile:once'\n]);", stage=True)
        result = self.check()
        self.assertIn('EZS-GATE-011', result.stdout)

    def test_orchestrator_dispatch_does_not_trigger_local_drain_rule(self):
        path = 'apps/orchestrator/src/Command/QueueConsumeCommand.php'
        self.add_file(path, "<?php $child = new Process([$php, 'lab:profile:once']);", stage=True)
        result = self.check()
        self.assertNotIn('EZS-GATE-011', result.stdout)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_unstaged_local_drain_violation_not_in_index(self):
        path = 'apps/ezs_lab/src/Command/LocalProfileDrainCommand.php'
        self.add_file(path, "<?php $child = new Process([$php, 'lab:profile:once']);")
        result = self.check()
        self.assertNotIn('EZS-GATE-011', result.stdout)
        self.assertIn('EZS-GATE-008', result.stdout)

    def test_other_lab_command_self_dispatch_blocks(self):
        path = 'apps/ezs_lab/src/Command/AnotherDrainCommand.php'
        self.add_file(path, "<?php $child = new Process([$php, 'bin/console', 'lab:profile:once']);", stage=True)
        result = self.check()
        self.assertIn('EZS-GATE-012', result.stdout)

    def test_orchestrator_direct_pdo_analysis_jobs_blocks(self):
        path = 'apps/orchestrator/src/Command/QueueConsumeCommand.php'
        self.add_file(path, "<?php $db=new \\PDO('sqlite:' . $labDb); $db->query('SELECT status FROM analysis_jobs');", stage=True)
        result = self.check()
        self.assertIn('EZS-GATE-013', result.stdout)

    def test_orchestrator_passes_db_path_to_worker_not_direct_sql(self):
        path = 'apps/orchestrator/src/Command/QueueConsumeCommand.php'
        self.add_file(path, "<?php $cmd = [$python, 'worker.py', '--db', $labRoot.'/data/benchmark.sqlite'];", stage=True)
        result = self.check()
        self.assertNotIn('EZS-GATE-013', result.stdout)

    def test_lab_local_running_claim_blocks(self):
        path = 'apps/ezs_lab/src/Command/ProfileOnceCommand.php'
        self.add_file(path, "<?php $pdo->prepare(\"UPDATE analysis_jobs SET status='running' WHERE dispatch_owner='lab'\");", stage=True)
        result = self.check()
        self.assertIn('EZS-GATE-014', result.stdout)

    def test_other_lab_command_violation_unstaged_ignored(self):
        path = 'apps/ezs_lab/src/Command/AnotherDrainCommand.php'
        self.add_file(path, "<?php $child = new Process([$php, 'lab:profile:once']);")
        result = self.check()
        self.assertNotIn('EZS-GATE-012', result.stdout)

    def test_profile_worker_without_local_claim_is_not_flagged(self):
        path = 'apps/ezs_lab/src/Command/ProfileOnceCommand.php'
        self.add_file(path, "<?php $job = getenv('ORCHESTRATOR_JOB_ID');", stage=True)
        result = self.check()
        self.assertNotIn('EZS-GATE-014', result.stdout)

    def test_staged_symlink_blocks(self):
        # Git stores a symlink blob; it must not be interpreted as executable source.
        # Stage a Git mode-120000 blob without creating an OS symlink.
        # Windows users need no SeCreateSymbolicLinkPrivilege or Developer Mode.
        target = '../../../architecture_contracts/validate.py'
        blob = subprocess.run(
            ['git', '-C', str(self.root), 'hash-object', '-w', '--stdin'],
            input=target, text=True, capture_output=True, check=True,
        ).stdout.strip()
        self.git('update-index', '--add', '--cacheinfo',
                 f'120000,{blob},apps/ezs_lab/src/symlink.php')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-009', result.stdout)

    def test_staged_gitlink_blocks(self):
        # Synthesize an index entry of mode 160000 without requiring a real submodule.
        self.git('update-index', '--add', '--cacheinfo', '160000,0123456789012345678901234567890123456789,apps/ezs_lab/foreign')
        result = self.check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('EZS-GATE-009', result.stdout)

if __name__ == '__main__':
    unittest.main()
