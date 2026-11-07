"""Stage reviewed immutable module bytes. No source execution or credential access."""
import hashlib
import json
import pathlib
import subprocess
import sys


def stage(source, destination, config):
    source, destination = pathlib.Path(source), pathlib.Path(destination)
    actual = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
    if actual != config['commit']:
        raise ValueError('Source commit mismatch')
    if destination.exists():
        raise ValueError('Destination must not exist')
    inputs = []
    for relative, digest in config['files'].items():
        path = pathlib.PurePosixPath(relative)
        if path.is_absolute() or '..' in path.parts or '\\' in relative:
            raise ValueError('Unsafe source path')
        full = source / 'modules' / config['module'] / relative
        if any(p.is_symlink() for p in [full, *full.parents]) or not full.is_file():
            raise ValueError('Non-regular source input')
        content = full.read_bytes()
        if hashlib.sha256(content).hexdigest() != digest:
            raise ValueError('Source digest mismatch: ' + relative)
        inputs.append((relative, content))
    manifest = json.loads(dict(inputs)['module.json'])
    for key in ['publisher', 'version']:
        if manifest[key] != config[key]:
            raise ValueError('Manifest identity mismatch')
    if manifest['id'] != config['module']:
        raise ValueError('Wrong module id')
    for relative, content in inputs:
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)


if __name__ == '__main__':
    config = json.loads((pathlib.Path(__file__).parent / 'builder-agent-source.json').read_text())
    stage(sys.argv[1], sys.argv[2], config)
