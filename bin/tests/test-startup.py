#!/usr/bin/env python3
"""Exercise startup failure/recovery without Docker or real development data."""

import json
import os
from pathlib import Path
import subprocess
import tempfile


PROJECT = Path(__file__).resolve().parents[2]
MOCK_DOCKER = r'''#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys

root = Path(os.environ['EVA_STARTUP_TEST'])
state = json.loads((root / 'state.json').read_text())
args = sys.argv[1:]
def finish(code=0, output=''):
    (root / 'state.json').write_text(json.dumps(state))
    if output:
        print(output)
    sys.exit(code)

if args[:2] == ['compose', 'up']:
    finish(int(os.environ.get('FAIL_COMPOSE', '0')))
if args[:4] == ['compose', 'exec', '-T', 'wordpress']:
    finish(int(os.environ.get('FAIL_HTTP', '0')))
assert args[:5] == ['compose', 'exec', '-T', 'wpcli', 'wp'], args
args = args[5:]
command = args[:2]

if command == ['core', 'is-installed']:
    finish(0 if state['core'] else 1)
if command == ['core', 'install']:
    assert not state['core'], 'Existing WordPress must not be reinstalled'
    state['core'] = True
    finish()
if command == ['plugin', 'is-installed']:
    finish(0 if args[2] in state['installed'] else 1)
if command == ['plugin', 'install']:
    assert args[2:] == ['woocommerce', '--version=10.4.0'], args
    if os.environ.get('FAIL_DOWNLOAD'):
        finish(1, 'Error: Download failed.')
    state['installed'].append('woocommerce')
    finish()
if command == ['plugin', 'activate']:
    if args[2] == os.environ.get('FAIL_ACTIVATION'):
        finish(1, 'Error: Plugin could not be activated.')
    assert args[2] in state['installed'], args
    state['active'].append(args[2])
    finish()
if command == ['plugin', 'is-active']:
    finish(0 if args[2] in state['active'] else 1)
if command == ['option', 'get']:
    finish(0 if args[2] in state['options'] else 1)
if command == ['option', 'update']:
    state['options'][args[2]] = args[3]
    finish()
if command == ['option', 'delete']:
    del state['options'][args[2]]
    finish()
if command == ['user', 'list']:
    finish(output='7')
if command == ['post', 'list']:
    slug = next(arg[7:] for arg in args if arg.startswith('--name='))
    finish(output='42' if slug in state['products'] else '')
if command == ['wc', 'product'] and args[2] == 'create':
    assert '--user=7' in args, 'Use the existing administrator account'
    slug = next(arg[7:] for arg in args if arg.startswith('--slug='))
    if slug == os.environ.get('FAIL_PRODUCT'):
        finish(1, 'Error: Product creation failed.')
    assert slug not in state['products'], 'Duplicate sample product'
    state['products'].append(slug)
    finish()
raise AssertionError(args)
'''


with tempfile.TemporaryDirectory(prefix="eva-startup-test-") as directory:
    root = Path(directory)
    docker = root / "docker"
    docker.write_text(MOCK_DOCKER)
    docker.chmod(0o755)
    sleeper = root / "sleep"
    sleeper.write_text("#!/bin/sh\nexit 0\n")
    sleeper.chmod(0o755)
    environment = dict(os.environ, EVA_STARTUP_TEST=directory,
                       PATH=f"{root}:{os.environ['PATH']}")

    def reset(core=False, woo=False, pending=False):
        state = dict(core=core, installed=["eva-course-bookings"],
                     active=[], products=[], options={})
        if woo:
            state["installed"].append("woocommerce")
        if pending:
            state["options"]["eva_dev_setup_pending"] = "yes"
        (root / "state.json").write_text(json.dumps(state))

    def state():
        return json.loads((root / "state.json").read_text())

    def run(success=True, **failures):
        result = subprocess.run(["bash", str(PROJECT / "bin/up.sh")],
                                env=dict(environment, **failures),
                                capture_output=True, text=True, timeout=20)
        output = result.stdout + result.stderr
        assert (result.returncode == 0) == success, output
        assert ("development environment is ready!" in output) == success, output
        return output

    reset()
    run()
    assert len(state()["products"]) == 3
    assert state()["options"]["woocommerce_currency"] == "EUR"
    assert "eva_dev_setup_pending" not in state()["options"]
    saved = state()
    saved["options"]["woocommerce_currency"] = "GBP"
    (root / "state.json").write_text(json.dumps(saved))
    run()
    assert state()["products"] == saved["products"]
    assert state()["options"] == saved["options"], "Restart changed store settings"

    reset(core=True)
    run(success=False, FAIL_DOWNLOAD="1")
    assert "eva_dev_setup_pending" in state()["options"]
    run()
    assert len(state()["products"]) == 3

    reset(core=True, woo=True, pending=True)
    run(success=False, FAIL_PRODUCT="workshop-di-cucina-italiana")
    assert state()["products"] == ["corso-di-fotografia-base"]
    run()
    assert len(state()["products"]) == 3, "Resume duplicated sample products"

    for plugin in ["woocommerce", "eva-course-bookings"]:
        reset(core=True, woo=True)
        output = run(success=False, FAIL_ACTIVATION=plugin)
        assert "Plugin could not be activated" in output, "Activation error hidden"

    for failure in ["FAIL_COMPOSE", "FAIL_HTTP"]:
        reset()
        run(success=False, **{failure: "1"})
        assert not state()["core"], "Setup continued after a startup failure"

print("Startup checks passed: fresh install, restart, recovery, and visible failures.")
