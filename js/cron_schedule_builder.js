// Visual editor for the five timing fields of an existing RivetIT cron job.
// The server's Cron Manager helper remains the authority for validation and writes.
(function () {
  'use strict';

  const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

  function validate(schedule) {
    const fields = schedule.split(' ');
    if (schedule.length > 100 || fields.length !== 5 || fields.some(field => field === '')) {
      return 'Enter five timing fields separated by single spaces.';
    }
    for (let index = 0; index < fields.length; index++) {
      for (const part of fields[index].split(',')) {
        const match = /^(\*|\d+|\d+-\d+)(?:\/(\d+))?$/.exec(part);
        if (!match) return 'Use numbers, *, ranges, steps, or comma-separated values.';
        const base = match[1];
        const step = match[2];
        if (step !== undefined && Number(step) < 1) return 'A step must be greater than zero.';
        if (base !== '*') {
          const numbers = base.split('-').map(Number);
          if (numbers.some(number => number < limits[index][0] || number > limits[index][1])) {
            return 'A timing value is outside its allowed range.';
          }
          if (numbers.length === 2 && numbers[0] > numbers[1]) return 'A range must start before it ends.';
          if (step !== undefined && numbers.length === 1) return 'Use a step with * or a range.';
        }
      }
    }
    return '';
  }

  function parse(schedule) {
    if (validate(schedule)) return { mode: 'custom' };
    if (schedule === '* * * * *') return { mode: 'minutes', every: 1 };
    let match = /^\*\/(\d+) \* \* \* \*$/.exec(schedule);
    if (match && Number(match[1]) >= 1 && Number(match[1]) <= 59) {
      return { mode: 'minutes', every: Number(match[1]) };
    }
    match = /^0-59\/(\d+) \* \* \* \*$/.exec(schedule);
    if (match && Number(match[1]) >= 1 && Number(match[1]) <= 59) {
      return { mode: 'minutes', every: Number(match[1]) };
    }
    match = /^(\d+) \* \* \* \*$/.exec(schedule);
    if (match) return { mode: 'hourly', minute: Number(match[1]) };
    match = /^(\d+) (\d+) \* \* \*$/.exec(schedule);
    if (match) return { mode: 'daily', minute: Number(match[1]), hour: Number(match[2]) };
    match = /^(\d+) (\d+) \* \* ([0-6](?:,[0-6])*)$/.exec(schedule);
    if (match) return { mode: 'weekly', minute: Number(match[1]), hour: Number(match[2]), days: match[3].split(',').map(Number) };
    match = /^(\d+) (\d+) (\d+) \* \*$/.exec(schedule);
    if (match) return { mode: 'monthly', minute: Number(match[1]), hour: Number(match[2]), day: Number(match[3]) };
    return { mode: 'custom' };
  }

  function time(hour, minute) {
    return String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
  }

  function describe(schedule) {
    const parsed = parse(schedule);
    if (parsed.mode === 'minutes') {
      if (parsed.every === 1) return 'Every minute';
      if (60 % parsed.every === 0) return `Every ${parsed.every} minutes`;
      const marks = [];
      for (let minute = 0; minute < 60; minute += parsed.every) marks.push(minute);
      return `At minutes ${marks.slice(0, 3).join(', ')}${marks.length > 3 ? ', …' : ''} each hour`;
    }
    if (parsed.mode === 'hourly') return `Every hour at minute ${parsed.minute}`;
    if (parsed.mode === 'daily') return `Every day at ${time(parsed.hour, parsed.minute)}`;
    if (parsed.mode === 'weekly') return `${parsed.days.map(day => dayNames[day]).join(', ')} at ${time(parsed.hour, parsed.minute)}`;
    if (parsed.mode === 'monthly') return `Day ${parsed.day} of each month at ${time(parsed.hour, parsed.minute)}`;
    return 'Custom schedule';
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-cron-schedule]').forEach(function (code) {
      const description = code.parentElement.querySelector('[data-cron-description]');
      if (description) description.textContent = describe(code.dataset.cronSchedule);
    });

    const modal = document.getElementById('cronScheduleModal');
    if (!modal) return;

    const form = document.getElementById('cronScheduleForm');
    const type = document.getElementById('cronScheduleType');
    const expression = document.getElementById('cronScheduleExpression');
    const everyMinutes = document.getElementById('cronEveryMinutes');
    const minute = document.getElementById('cronMinute');
    const clock = document.getElementById('cronTime');
    const monthDay = document.getElementById('cronMonthDay');
    const weekdayInputs = Array.from(modal.querySelectorAll('[name="cron_weekday"]'));
    const summary = document.getElementById('cronScheduleSummary');
    const error = document.getElementById('cronScheduleError');
    const save = document.getElementById('cronScheduleSave');
    let original = '';

    function integer(input, minimum, maximum) {
      const value = Number(input.value);
      return Number.isInteger(value) && value >= minimum && value <= maximum ? value : null;
    }

    function showControls() {
      const mode = type.value;
      modal.querySelectorAll('[data-cron-control]').forEach(function (control) {
        control.classList.toggle('d-none', control.dataset.cronControl !== mode && !(control.dataset.cronControl === 'time' && ['daily', 'weekly', 'monthly'].includes(mode)));
      });
      expression.readOnly = mode !== 'custom';
    }

    function generate() {
      const mode = type.value;
      if (mode === 'custom') return expression.value.trim();
      if (mode === 'minutes') {
        const value = integer(everyMinutes, 1, 59);
        return value === null ? '' : (value === 1 ? '* * * * *' : `*/${value} * * * *`);
      }
      if (mode === 'hourly') {
        const value = integer(minute, 0, 59);
        return value === null ? '' : `${value} * * * *`;
      }
      const selectedTime = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(clock.value);
      if (!selectedTime) return '';
      const start = `${Number(selectedTime[2])} ${Number(selectedTime[1])}`;
      if (mode === 'daily') return `${start} * * *`;
      if (mode === 'weekly') {
        const days = weekdayInputs.filter(input => input.checked).map(input => input.value);
        return days.length ? `${start} * * ${days.join(',')}` : '';
      }
      if (mode === 'monthly') {
        const day = integer(monthDay, 1, 31);
        return day === null ? '' : `${start} ${day} * *`;
      }
      return '';
    }

    function update() {
      showControls();
      const schedule = generate();
      if (type.value !== 'custom') expression.value = schedule;
      const problem = schedule ? validate(schedule) : (type.value === 'weekly' ? 'Choose at least one day.' : 'Enter a valid time or number.');
      error.textContent = problem;
      error.classList.toggle('d-none', !problem);
      summary.textContent = problem ? '' : describe(schedule);
      save.disabled = Boolean(problem) || schedule === original;
    }

    modal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      if (!button) return;
      original = button.dataset.cronCurrent;
      document.getElementById('cronScheduleFile').value = button.dataset.cronFile;
      document.getElementById('cronScheduleLine').value = button.dataset.cronLine;
      document.getElementById('cronScheduleHash').value = button.dataset.cronHash;
      document.getElementById('cronScheduleJob').textContent = button.dataset.cronScript;
      document.getElementById('cronScheduleCurrent').textContent = original;
      document.getElementById('cronScheduleTitle').textContent = `Edit ${button.dataset.cronScript} schedule`;

      const parsed = parse(original);
      everyMinutes.value = 5;
      minute.value = 0;
      clock.value = '09:00';
      monthDay.value = 1;
      weekdayInputs.forEach(input => { input.checked = Number(input.value) >= 1 && Number(input.value) <= 5; });
      type.value = parsed.mode;
      if (parsed.mode === 'minutes') everyMinutes.value = parsed.every;
      if (parsed.mode === 'hourly') minute.value = parsed.minute;
      if (['daily', 'weekly', 'monthly'].includes(parsed.mode)) clock.value = time(parsed.hour, parsed.minute);
      if (parsed.mode === 'weekly') weekdayInputs.forEach(input => { input.checked = parsed.days.includes(Number(input.value)); });
      if (parsed.mode === 'monthly') monthDay.value = parsed.day;
      expression.value = original;
      showControls();
      error.classList.add('d-none');
      error.textContent = '';
      summary.textContent = describe(original);
      save.disabled = true;
    });

    type.addEventListener('change', update);
    [everyMinutes, minute, clock, monthDay, expression, ...weekdayInputs].forEach(function (control) {
      control.addEventListener('input', update);
      control.addEventListener('change', update);
    });
    form.addEventListener('submit', function (event) {
      update();
      if (save.disabled) event.preventDefault();
    });
  });
})();
