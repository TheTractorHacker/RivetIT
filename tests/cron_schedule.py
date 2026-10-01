#!/usr/bin/python3
"""Run with python3 -m unittest discover -s tests -p cron_schedule.py."""

import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'deploy/cron_schedule.py'
SPEC = importlib.util.spec_from_file_location('cron_schedule', SCRIPT)
cron_schedule = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(cron_schedule)


class CronScheduleTests(unittest.TestCase):
    def test_schedule_validation(self):
        for schedule in ('*/5 * * * *', '0-59/10 * * * *', '30 4 * * 1,3,5'):
            self.assertEqual(cron_schedule.validate_schedule(schedule), schedule)
        for schedule in ('* * * *', '* * * * *; id', '60 * * * *', '*/0 * * * *',
                         '1-0 * * * *', '* 24 * * *', '@hourly'):
            with self.subTest(schedule=schedule), self.assertRaises(ValueError):
                cron_schedule.validate_schedule(schedule)

    def test_only_selected_job_schedule_changes(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'rivetit-example'
            app_root = '/var/www/example'
            command = f'/usr/bin/php {app_root}/cron/cron.php >> /var/log/rivetit.log 2>&1'
            original = ('# header\n' + f'*/5 * * * * www-data {command}\n' +
                        '* * * * * www-data /usr/bin/php /var/www/other/cron/cron.php\n')
            path.write_text(original)
            digest = hashlib.sha256(command.encode()).hexdigest()
            with patch.object(cron_schedule, 'root_file', side_effect=lambda file: file.stat()):
                changed_path, info, content, old_schedule = cron_schedule.prepare_change(
                    app_root, path.name, 2, digest, '0 * * * *', Path(folder))
                self.assertEqual(old_schedule, '*/5 * * * *')
                cron_schedule.write_change(changed_path, content, info)
                self.assertEqual(path.read_text(), original.replace('*/5 * * * *', '0 * * * *'))
                with self.assertRaises(ValueError):
                    cron_schedule.prepare_change(app_root, path.name, 3, digest,
                                                 '0 * * * *', Path(folder))
                with self.assertRaises(ValueError):
                    cron_schedule.prepare_change('/var/www/other', path.name, 2, digest,
                                                 '0 * * * *', Path(folder))


if __name__ == '__main__':
    unittest.main()
