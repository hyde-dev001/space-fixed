"""Run only after the guarded disposable MariaDB environment has been migrated."""
import json
import subprocess
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/failed-delivery-recovery'
WORKER = 'tests/Browser/failed_delivery_worker.php'


def call(*args):
    result = subprocess.run(['php', WORKER, *map(str, args)], cwd=ROOT, capture_output=True, text=True, timeout=90)
    assert result.returncode == 0, result.stderr
    try:
        return json.loads(result.stdout)
    except ValueError:
        raise AssertionError(result.stdout[:1200])


results = []
for scenario in ['failure', 'retry', 'handoff', 'receipt', 'batch', 'notification', 'return_retry', 'replace_receipt', 'batch_failure', 'cod_reassign']:
    data = call('seed', 'retail_delivery')
    data['request_key'] = str(uuid.uuid4())
    path = OUT / f'concurrency-{scenario}.json'
    path.write_text(json.dumps(data), encoding='utf-8')
    data = call('prepare', path, scenario)
    path.write_text(json.dumps(data), encoding='utf-8')
    start = str(time.time() + 2)
    operations = {'return_retry': ['return', 'retry'], 'replace_receipt': ['receipt', 'retry'], 'batch_failure': ['batch_start', 'failure'], 'cod_reassign': ['remittance', 'retry_new']}.get(scenario, [scenario, scenario])
    workers = [subprocess.Popen(['php', WORKER, 'run', str(path), operation, start], cwd=ROOT,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for operation in operations]
    observations = []
    for worker in workers:
        stdout, stderr = worker.communicate(timeout=60)
        assert worker.returncode == 0, (scenario, stderr, stdout[:1200])
        observations.append(json.loads(stdout))
    state = call('state', path)
    assert all(row['result'] in ['ok', 'conflict'] for row in observations)
    if scenario == 'failure':
        assert state['attempts'] == 1 and state['active_assignments'] == 1, state
    if scenario == 'retry':
        assert state['assignments'] == 2 and state['active_assignments'] == 1, state
    if scenario == 'handoff':
        assert state['transfers'] == 1 and sorted(row['result'] for row in observations) == ['conflict', 'ok'], state
    if scenario == 'receipt':
        assert state['proofs'] == 2 and state['custody_rider'] == data['actors']['b']['rider_id'], state
    if scenario == 'batch':
        assert state['batch_id'] and sorted(row['result'] for row in observations) == ['conflict', 'ok'], state
    if scenario == 'notification':
        assert state['notifications'] == 2, state  # customer and this shop's dispatcher, once each
    if scenario == 'return_retry':
        assert sorted(row['result'] for row in observations) == ['conflict', 'ok'], observations
        assert (state['resolution'] == 'retry' and state['return_legs'] == 0) or (state['resolution'] == 'return_required' and state['return_legs'] == 1), state
    if scenario == 'replace_receipt':
        assert observations[0]['result'] == 'ok' and observations[1]['result'] == 'conflict' and state['proofs'] == 2, (observations, state)
    if scenario == 'batch_failure':
        assert observations[0]['result'] == 'ok' and observations[1]['result'] == 'conflict' and state['batch_id'] == data['batch_id'] and state['attempts'] == 0, (observations, state)
    if scenario == 'cod_reassign':
        assert all(row['result'] == 'ok' for row in observations) and state['remittances'] == 1 and state['collection_rider'] == data['actors']['a']['id'], (observations, state)
    results.append({'scenario': scenario, 'workers': observations, 'state': state})
    (OUT / 'concurrency-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
    print(scenario, 'passed', flush=True)
(OUT / 'concurrency-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
