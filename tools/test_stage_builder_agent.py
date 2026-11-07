import hashlib
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

from stage_builder_agent import stage


class ReviewedSourceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name)
        self.source = self.root / 'source'
        self.module = self.source / 'modules' / 'builder-agent'
        self.module.mkdir(parents=True)
        content = json.dumps({'id': 'builder-agent', 'version': '0.1.0', 'publisher': 'phpfasty'}).encode()
        (self.module / 'module.json').write_bytes(content)
        self.config = {'module': 'builder-agent', 'version': '0.1.0', 'publisher': 'phpfasty', 'commit': 'a' * 40,
                       'files': {'module.json': hashlib.sha256(content).hexdigest()}}
        self.output = self.root / 'output'
        self.mock = patch('stage_builder_agent.subprocess.check_output', return_value='a' * 40)
        self.mock.start()
        self.addCleanup(self.mock.stop)

    def test_exact_reviewed_bytes(self):
        stage(self.source, self.output, self.config)
        self.assertEqual((self.module / 'module.json').read_bytes(), (self.output / 'module.json').read_bytes())

    def test_tampering_fails_before_output(self):
        (self.module / 'module.json').write_text('{}')
        with self.assertRaises(ValueError): stage(self.source, self.output, self.config)
        self.assertFalse(self.output.exists())

    def test_wrong_commit_fails_closed(self):
        self.config['commit'] = 'b' * 40
        with self.assertRaises(ValueError): stage(self.source, self.output, self.config)

    def test_unsafe_paths_and_symlinks(self):
        for path in ['../module.json', '/module.json', 'bad\\path']:
            with self.subTest(path=path):
                config = dict(self.config, files={path: '0' * 64})
                with self.assertRaises(ValueError): stage(self.source, self.output, config)
        (self.module / 'module.json').unlink()
        (self.root / 'outside.json').write_text('{}')
        (self.module / 'module.json').symlink_to(self.root / 'outside.json')
        with self.assertRaises(ValueError): stage(self.source, self.output, self.config)

    def test_no_overwrite_or_wrong_identity(self):
        self.output.mkdir()
        with self.assertRaises(ValueError): stage(self.source, self.output, self.config)
        self.output.rmdir()
        self.config['publisher'] = 'other'
        with self.assertRaises(ValueError): stage(self.source, self.output, self.config)
        self.assertFalse(self.output.exists())


if __name__ == '__main__': unittest.main()
