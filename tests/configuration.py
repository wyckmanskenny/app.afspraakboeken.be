"""Check configuration path precedence and guards without a database or secrets."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
php = os.environ.get('PHP_BIN', 'php')
runtime = root / 'tests/.runtime'
runtime.mkdir(exist_ok=True)
environment = {k: v for k, v in os.environ.items() if k != 'AFSPRAAKBOEKEN_CONFIG'}

with tempfile.TemporaryDirectory(dir=runtime) as temporary:
    base = Path(temporary)
    api = base / 'public_html/api'
    private = api / 'private'
    private.mkdir(parents=True)
    shutil.copy(root / 'api/bootstrap.php', api / 'bootstrap.php')
    example = (root / 'api/private/config.example.php').read_text().replace(
        "'app_key' => ''", "'app_key' => 'test-only-key-with-at-least-32-characters'")
    (private / 'config.php').write_text(example)
    code = "require $argv[1]; try { echo configuration()['db']['name']; } catch (ApiError $e) { echo $e->status; }"

    def load(extra=None):
        result = subprocess.run([php, '-n', '-r', code, str(api / 'bootstrap.php')],
                                env={**environment, **(extra or {})}, text=True, capture_output=True, check=True)
        assert not result.stderr, result.stderr
        return result.stdout

    assert load() == 'HOSTINGER_DATABASE_NAME'
    print('PASS protected hosting fallback loads configuration')
    assert load({'AFSPRAAKBOEKEN_CONFIG': str(base / 'missing.php')}) == '503'
    print('PASS explicit missing path does not silently fall back')
    (private / 'config.php').unlink()
    assert load() == '503'
    (private / 'config.php').write_text(example)
    print('PASS missing configuration fails closed')
    outside = base / 'afspraakboeken-private'
    outside.mkdir()
    (outside / 'config.php').write_text(example.replace('HOSTINGER_DATABASE_NAME', 'EXTERNAL_CONFIG'))
    assert load() == 'EXTERNAL_CONFIG'
    print('PASS external configuration keeps priority')
    assert load({'AFSPRAAKBOEKEN_CONFIG': str(private / 'config.php')}) == 'HOSTINGER_DATABASE_NAME'
    print('PASS explicit configuration path is supported')
    for template in [private / 'config.php', root / 'api/config.example.php']:
        for defined_false in [False, True]:
            direct_code = ("define('AFSPRAAKBOEKEN_CONFIG_LOAD', false);" if defined_false else '') + "require $argv[1]; echo 'GUARD_FAILED';"
            direct = subprocess.run([php, '-n', '-r', direct_code, str(template)],
                                    env=environment, text=True, capture_output=True, check=True)
            assert direct.stdout == '' and direct.stderr == ''
    print('PASS both PHP templates stop direct execution before returning values')
