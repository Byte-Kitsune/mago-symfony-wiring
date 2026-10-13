#!/usr/bin/env python3
"""Run identical Mago fixtures against the PHP and Rust paths."""
import json
import os
from pathlib import Path
import subprocess
import sys

binary = os.environ.get('MAGO_NATIVE_ENGINE', '/engine')
root = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('/work')
results = []
for config in sorted((root / 'tests').glob('*/mago.toml')):
    if 'inspection-corpus' in str(config):
        continue
    command = [str(root / 'vendor/bin/mago'), 'analyze', '--reporting-format', 'json', '--minimum-report-level', 'note']
    reports = []
    for backend in ['php', 'rust']:
        env = dict(os.environ)
        env.pop('MAGO_NATIVE_ENGINE', None)
        env['MAGO_EXTENSION_BACKEND'] = backend
        if backend == 'rust':
            env['MAGO_NATIVE_ENGINE'] = binary
            env['MAGO_NATIVE_STRICT'] = '1'
        if os.environ.get('MAGO_TEST_DOCKER') == '1':
            invocation = ['docker', 'run', '--rm', '-v', f'{root}:/work', '-v', f'{binary}:/engine:ro', '-w', '/work/' + str(config.parent.relative_to(root))]
            invocation += ['-e', 'MAGO_EXTENSION_BACKEND=' + backend]
            if backend == 'rust': invocation += ['-e', 'MAGO_NATIVE_ENGINE=/engine', '-e', 'MAGO_NATIVE_STRICT=1']
            invocation += ['--entrypoint', '/work/vendor/bin/mago', 't3code-monolith-sandbox:php8.5-mago1.53', 'analyze', '--reporting-format', 'json', '--minimum-report-level', 'note']
        else:
            invocation = command
        result = subprocess.run(invocation, cwd=config.parent, env=env, capture_output=True, timeout=180)
        if result.returncode not in (0, 1):
            raise RuntimeError(f'{backend} failed: {config}: {result.stderr.decode(errors="replace")}')
        reports.append(json.loads(result.stdout))
    if reports[0] != reports[1]:
        (root / 'parity-php.json').write_text(json.dumps(reports[0], indent=2))
        (root / 'parity-rust.json').write_text(json.dumps(reports[1], indent=2))
        raise RuntimeError(f'Full report differs: {config}')
    results.append({'fixture': str(config.relative_to(root)), 'issues': len(reports[0]['issues'])})
print(json.dumps(results, indent=2))
