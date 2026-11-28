#!/usr/bin/env python3
"""Build an install ZIP and a cumulative incremental-update manifest, using stdlib only."""
import argparse
import hashlib
import json
import re
import subprocess
import zipfile
from pathlib import Path


def build(root: Path, version: str, commit: str, output: Path):
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('Version must have three numeric components')
    if not re.fullmatch(r'[a-f0-9]{40}', commit):
        raise ValueError('Commit must be a complete lowercase Git SHA')
    source = (root / 'Plugin.php').read_text(encoding='utf-8')
    if f"public const VERSION = '{version}';" not in source:
        raise ValueError('Release tag and Plugin::VERSION must match')
    paths = [p for p in root.rglob('*') if p.is_file() and (
        len(p.relative_to(root).parts) == 1 and p.suffix in {'.php', '.md'}
        or p.relative_to(root).parts[0] in {'Admin', 'Ai', 'Rule', 'Update', 'assets', 'migrations'}
    )]
    files = {}
    migrations = []
    for path in sorted(paths):
        name = path.relative_to(root).as_posix()
        content = path.read_bytes()
        files[name] = {'sha256': hashlib.sha256(content).hexdigest(), 'size': len(content)}
        if name.startswith('migrations/'):
            migration_version = path.stem
            if not re.fullmatch(r'\d+\.\d+\.\d+', migration_version) or path.suffix != '.json':
                raise ValueError('Migration names must be migrations/X.Y.Z.json')
            document = json.loads(content)
            if document.get('version') != migration_version or not isinstance(document.get('operations'), list):
                raise ValueError('Invalid migration document: ' + name)
            if tuple(map(int, migration_version.split('.'))) > tuple(map(int, version.split('.'))):
                raise ValueError('Migration is newer than the release')
            migrations.append({'version': migration_version, 'file': name})
    migrations.sort(key=lambda m: tuple(map(int, m['version'].split('.'))))
    manifest = {'format': 1, 'version': version, 'php': '8.0', 'commit': commit,
                'migrationBase': '1.0.29', 'files': files, 'migrations': migrations}
    content = (json.dumps(manifest, indent=2, ensure_ascii=False) + '\n').encode()
    output.mkdir(parents=True, exist_ok=True)
    (output / 'commentaudit-update.json').write_bytes(content)
    (root / 'update-manifest.json').write_bytes(content)
    with zipfile.ZipFile(output / f'CommentAudit-{version}.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in paths:
            archive.write(path, 'CommentAudit/' + path.relative_to(root).as_posix())
        archive.writestr('CommentAudit/update-manifest.json', content)
    print(f'Built {len(files)} managed files, {len(migrations)} cumulative migrations: {output}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--version', required=True)
    parser.add_argument('--commit', help='Defaults to git rev-parse HEAD')
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    commit = args.commit or subprocess.check_output(['git', '-C', str(args.root), 'rev-parse', 'HEAD'], text=True).strip()
    build(args.root, args.version, commit, args.out)
