#!/usr/bin/env python3
"""Merge completed HireHi XML files by vacancy URL before creating a TAR."""

import argparse
import json
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from xml.etree import ElementTree as ET


def merge(source_dir: Path, output_name: str, backup_dir: Path) -> dict:
    source_dir = source_dir.resolve()
    backup_dir = backup_dir.resolve()
    if Path(output_name).name != output_name or not output_name.endswith('.xml'):
        raise ValueError('Output name must be a single XML filename')
    if backup_dir.is_relative_to(source_dir):
        raise ValueError('Backup directory must be outside the feed source directory')

    output_path = source_dir / output_name
    if output_path.exists() or backup_dir.exists():
        raise FileExistsError('Output or backup directory already exists')

    sources = sorted(source_dir.glob('*.xml'), key=lambda path: path.stat().st_mtime, reverse=True)
    if not sources:
        raise ValueError('No HireHi XML files to merge')

    vacancies = {}
    source_counts = {}
    host = None
    for path in sources:
        root = ET.parse(path).getroot()
        if root.tag != 'source' or root.find('vacancies') is None:
            raise ValueError(f'Invalid feed structure: {path.name}')
        if host is None:
            host = root.get('host')
        elif root.get('host') != host:
            raise ValueError(f'Host differs in {path.name}')

        source_counts[path.name] = 0
        for vacancy in root.findall('./vacancies/vacancy'):
            source_counts[path.name] += 1
            url = (vacancy.findtext('url') or '').strip()
            if not all((url, vacancy.findtext('description'), vacancy.findtext('job-name'), vacancy.findtext('./company/name'))):
                raise ValueError(f'Incomplete vacancy in {path.name}')
            vacancies.setdefault(url, vacancy)

    if not host or not vacancies:
        raise ValueError('Merged feed would be empty')

    msk = timezone(timedelta(hours=3))
    merged_root = ET.Element('source', {
        'creation-time': datetime.now(msk).strftime('%Y-%m-%d %H:%M:%S GMT+3'),
        'host': host,
    })
    vacancy_parent = ET.SubElement(merged_root, 'vacancies')
    for vacancy in sorted(vacancies.values(), key=lambda item: item.findtext('creation-date') or '', reverse=True):
        vacancy_parent.append(vacancy)

    with tempfile.NamedTemporaryFile(dir=source_dir, prefix=f'.{output_name}.', suffix='.tmp', delete=False) as handle:
        temp_path = Path(handle.name)
    try:
        ET.ElementTree(merged_root).write(temp_path, encoding='utf-8', xml_declaration=True)
        verified_root = ET.parse(temp_path).getroot()
        if len(verified_root.findall('./vacancies/vacancy')) != len(vacancies):
            raise ValueError('Merged XML verification failed')

        backup_dir.mkdir(parents=True)
        for path in sources:
            path.replace(backup_dir / path.name)
        temp_path.replace(output_path)
    finally:
        temp_path.unlink(missing_ok=True)

    return {
        'output': str(output_path),
        'records': len(vacancies),
        'input_records': sum(source_counts.values()),
        'sources': source_counts,
        'backup_dir': str(backup_dir),
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-dir', type=Path, required=True)
    parser.add_argument('--output-name', required=True)
    parser.add_argument('--backup-dir', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(merge(args.source_dir, args.output_name, args.backup_dir), ensure_ascii=False))
