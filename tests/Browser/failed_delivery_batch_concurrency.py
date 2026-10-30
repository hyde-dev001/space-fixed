"""Independent process races, only on the existing guarded disposable MariaDB."""
import json
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/failed-delivery-recovery/batch-interruption'
WORKER = 'tests/Browser/failed_delivery_batch_worker.php'


def call(*args):
    value = subprocess.run(['php', WORKER, *map(str, args)], cwd=ROOT, capture_output=True, text=True, timeout=90)
    assert value.returncode == 0, (value.stdout[:1500], value.stderr[:1500])
    return json.loads(value.stdout)


results = []
for scenario in (sys.argv[1:] or ['report', 'start', 'pickup', 'transit', 'arrival', 'proof', 'cod', 'failure', 'decision', 'receipt', 'release_decision', 'notification']):
    data = call('seed', scenario)
    path = OUT / ('race-' + scenario + '.json')
    path.write_text(json.dumps(data), encoding='utf-8')
    operations = {'decision': ['resume', 'escalate'], 'receipt': ['receipt', 'replacement_start'],
        'release_decision': ['release', 'cancel'], 'notification': ['notification', 'notification']}.get(scenario, ['report', scenario])
    start = str(time.time() + 1)
    workers = [subprocess.Popen(['php', WORKER, 'run', str(path), operation, start], cwd=ROOT,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for operation in operations]
    rows = []
    for worker in workers:
        stdout, stderr = worker.communicate(timeout=60)
        assert worker.returncode == 0, (scenario, stdout[:1500], stderr[:1500])
        rows.append(json.loads(stdout))
    state = call('state', path)
    assert not state['duplicate_active_assignments'], state
    assert state['incidents'] == 1, state
    if scenario == 'report':
        assert all(row['result'] == 'ok' for row in rows) and rows[0]['id'] == rows[1]['id'], rows
    if scenario == 'decision':
        assert sorted(row['result'] for row in rows) == ['conflict', 'ok'] and state['revision'] == 1, (rows, state)
    if scenario == 'receipt':
        assert rows[0]['result'] == 'ok' and state['all_received'], (rows, state)
    if scenario == 'release_decision':
        assert sorted(row['result'] for row in rows) == ['conflict', 'ok'], rows
    if scenario == 'notification':
        assert state['notifications'] == 3, state  # one dispatcher and two affected customers
    if scenario not in ['decision', 'receipt', 'release_decision', 'notification']:
        assert rows[0]['result'] == 'ok' and state['batch_status'] == 'interrupted', (rows, state)
        if scenario != 'failure':
            assert state['attempts'] == 0, state
        else:
            assert state['attempts'] == (1 if rows[1]['result'] == 'ok' else 0), (rows, state)
        # After the hold commits, a fresh/stale movement call cannot execute.
        assert call('run', path, 'transit', 0)['result'] == 'conflict'
    results.append({'scenario': scenario, 'workers': rows, 'state': state})
    (OUT / 'concurrency-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
    print(scenario + ' passed', flush=True)
