"""Offline checks for the actual CI PHP-version guard."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

GUARD = Path(__file__).resolve().parents[1] / 'check-php-runtime.sh'


class PhpRuntimeGuard(unittest.TestCase):
    def test_supported_branches_fixed_boundaries_and_invalid_versions(self):
        with tempfile.TemporaryDirectory() as folder:
            php = Path(folder) / 'php'
            php.write_text('#!/bin/sh\nprintf "PHP %s (fixture)\\n" "$FIXTURE_VERSION"\nexit "${FIXTURE_EXIT:-0}"\n')
            php.chmod(0o755)
            for branch, version, accepted in [
                ('8.2', '8.2.34', False), ('8.2', '8.2.35', True), ('8.2', '8.2.36', True),
                ('8.3', '8.3.35', False), ('8.3', '8.3.36', True), ('8.3', '8.3.37', True),
                ('8.2', '8.3.36', False), ('8.3', '8.2.35', False), ('8.4', '8.4.27', False),
                ('8.2', '8.2.35RC1', False), ('8.3', '8.3.36-dev', False),
                ('8.2', '8.2.34-999+vendor', False), ('8.3', '', False),
            ]:
                with self.subTest(branch=branch, version=version):
                    result = subprocess.run(['sh', str(GUARD), branch], capture_output=True,
                                            env=dict(os.environ, PATH=folder + ':' + os.environ['PATH'],
                                                     FIXTURE_VERSION=version))
                    self.assertEqual(result.returncode == 0, accepted, result.stderr)
            result = subprocess.run(['sh', str(GUARD), '8.2'], capture_output=True,
                                    env=dict(os.environ, PATH=folder + ':' + os.environ['PATH'],
                                             FIXTURE_VERSION='8.2.35', FIXTURE_EXIT='1'))
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
