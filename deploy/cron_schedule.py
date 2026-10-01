#!/usr/bin/python3
"""Root-owned, schedule-only editor for registered RivetIT cron jobs."""

import hashlib
import json
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

CRON_DIR = Path('/etc/cron.d')
CONFIG_DIR = Path('/etc/rivetit')
NAME = re.compile(r'^[A-Za-z0-9_-]+$')
INSTANCE = re.compile(r'^[A-Za-z0-9-]+$')
FIELD_LIMITS = ((0, 59), (0, 23), (1, 31), (1, 12), (0, 7))
JOB = re.compile(r'^(\s*)((?:\S+\s+){4}\S+)(\s+)(\S+)(\s+)(.+?)(\r?\n?)$')


def fail(message):
    raise ValueError(message)


def root_file(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        fail('Refusing a cron or configuration file that is not root-owned and protected')
    return info


def validate_schedule(schedule):
    fields = schedule.split()
    if len(fields) != 5 or schedule != ' '.join(fields):
        fail('Enter exactly five cron timing fields separated by spaces')
    for field, (minimum, maximum) in zip(fields, FIELD_LIMITS):
        for part in field.split(','):
            match = re.fullmatch(r'(\*|\d+|\d+-\d+)(?:/(\d+))?', part)
            if not match:
                fail('Invalid cron timing field')
            base, step = match.groups()
            if step is not None and int(step) == 0:
                fail('Cron step must be greater than zero')
            if base != '*':
                numbers = [int(number) for number in base.split('-')]
                if any(number < minimum or number > maximum for number in numbers):
                    fail('Cron timing value is out of range')
                if len(numbers) == 2 and numbers[0] > numbers[1]:
                    fail('Cron timing range is reversed')
                if step is not None and len(numbers) == 1:
                    fail('Cron steps require * or a range')
    return schedule


def registered_root(instance, config_dir=CONFIG_DIR):
    if not INSTANCE.fullmatch(instance):
        fail('Invalid installation name')
    config = config_dir / f'cron-manager-{instance}.json'
    root_file(config)
    data = json.loads(config.read_text(encoding='utf-8'))
    app_root = data.get('app_root')
    if not isinstance(app_root, str) or not app_root.startswith('/') or Path(app_root).resolve() != Path(app_root):
        fail('Invalid installation registration')
    return app_root


def prepare_change(app_root, filename, line_number, command_hash, schedule, cron_dir=CRON_DIR):
    validate_schedule(schedule)
    if not NAME.fullmatch(filename) or not re.fullmatch(r'[a-f0-9]{64}', command_hash):
        fail('Invalid cron job identity')
    if line_number < 1:
        fail('Invalid cron line number')
    path = cron_dir / filename
    info = root_file(path)
    lines = path.read_text(encoding='utf-8').splitlines(keepends=True)
    if line_number > len(lines):
        fail('Cron job has changed; reload the page')
    line = lines[line_number - 1]
    match = JOB.fullmatch(line)
    if not match:
        fail('Cron job has changed; reload the page')
    spaces, old_schedule, between, user, after_user, command, ending = match.groups()
    if user != 'www-data' or hashlib.sha256(command.strip().encode()).hexdigest() != command_hash:
        fail('Cron job has changed; reload the page')
    prefix = re.escape(app_root.rstrip('/') + '/cron/')
    if not re.match(r'^/usr/bin/php(?:[0-9.]+)?\s+' + prefix + r'[A-Za-z0-9_.-]+\.php(?:\s|$)', command):
        fail('Cron job does not belong to this installation')
    lines[line_number - 1] = spaces + schedule + between + user + after_user + command + ending
    return path, info, ''.join(lines), old_schedule


def write_change(path, content, info):
    descriptor, temporary = tempfile.mkstemp(prefix='.rivetit-cron-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w', encoding='utf-8') as output:
            output.write(content)
            output.flush()
            os.fchmod(output.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(output.fileno())
        if path.lstat().st_ino != info.st_ino:
            fail('Cron file changed during update; reload the page')
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main(argv):
    if len(argv) != 7 or argv[1] not in ('--check', '--set'):
        fail('Usage: rivetit-cron-schedule --check|--set INSTANCE FILE LINE HASH "SCHEDULE"')
    _, action, instance, filename, line, command_hash, schedule = argv
    if not re.fullmatch(r'[1-9]\d*', line):
        fail('Invalid cron line number')
    app_root = registered_root(instance)
    path, info, content, old_schedule = prepare_change(
        app_root, filename, int(line), command_hash, schedule
    )
    if action == '--set' and schedule != old_schedule:
        write_change(path, content, info)
    print('Cron schedule is ready' if action == '--check' else 'Cron schedule saved')


if __name__ == '__main__':
    try:
        main(sys.argv)
    except (ValueError, OSError, UnicodeError, json.JSONDecodeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
