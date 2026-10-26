import importlib.util
import tempfile
import unittest
from pathlib import Path
from xml.etree import ElementTree as ET


SCRIPT = Path(__file__).resolve().parents[1] / 'scripts' / 'merge_hirehi_xml.py'
SPEC = importlib.util.spec_from_file_location('merge_hirehi_xml', SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class MergeHireHiXmlTest(unittest.TestCase):
    def test_deduplicates_urls_and_preserves_inputs(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            feed = root / 'feed'
            feed.mkdir()
            old = feed / 'old.xml'
            new = feed / 'new.xml'
            old.write_text(self.xml([('https://hirehi.ru/development/job-1', 'Old description')]), encoding='utf-8')
            new.write_text(self.xml([
                ('https://hirehi.ru/development/job-1', 'Updated description'),
                ('https://hirehi.ru/development/job-2', 'Second description'),
            ]), encoding='utf-8')
            old.touch()
            new.touch()
            old_time = old.stat().st_mtime - 60
            import os
            os.utime(old, (old_time, old_time))

            backup = root / 'backup'
            result = MODULE.merge(feed, 'reconciled.xml', backup)

            self.assertEqual(result['input_records'], 3)
            self.assertEqual(result['records'], 2)
            self.assertEqual(sorted(path.name for path in feed.glob('*.xml')), ['reconciled.xml'])
            self.assertEqual(sorted(path.name for path in backup.glob('*.xml')), ['new.xml', 'old.xml'])
            merged = ET.parse(feed / 'reconciled.xml').getroot()
            vacancies = merged.findall('./vacancies/vacancy')
            self.assertEqual(len(vacancies), 2)
            self.assertEqual(vacancies[0].findtext('description'), 'Updated description')
            self.assertEqual(merged.get('host'), 'https://hirehi.ru/')

    @staticmethod
    def xml(rows):
        root = ET.Element('source', {'host': 'https://hirehi.ru/'})
        parent = ET.SubElement(root, 'vacancies')
        for url, description in rows:
            vacancy = ET.SubElement(parent, 'vacancy')
            ET.SubElement(vacancy, 'url').text = url
            ET.SubElement(vacancy, 'description').text = description
            ET.SubElement(vacancy, 'job-name').text = 'Developer'
            company = ET.SubElement(vacancy, 'company')
            ET.SubElement(company, 'name').text = 'Company'
        return ET.tostring(root, encoding='unicode')


if __name__ == '__main__':
    unittest.main()
