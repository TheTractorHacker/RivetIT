/*
 * Training › device end times (2.6.94): the time helpers shared by Set up this device
 * (training_device_setup.js) and Devices & PINs (training_devices.js). Every time is shown and
 * entered in the APP's time zone (page data `timezone`, America/Chicago here), never the browser's,
 * and a time's zone name is the one in force AT that time ("CST" for a November date picked in
 * October). The presets mirror DeviceLifecycle::expiryFor(): 'keep', 'today' (23:59:59, refused with
 * under 5 minutes left), '4h' / '8h' / '24h' from now, 'until' a 'YYYY-MM-DDTHH:MM' wall-clock time
 * 5 minutes to MAX_DAYS away. The server re-checks everything; these only preview and prefill.
 *
 *   var T = TrainingDeviceTime.create(tz, maxDays);
 *   T.fmt(date, withZone)      "Sat, Sep 27, 11:59 PM CDT"
 *   T.time(date)               "11:59 PM CDT"
 *   T.wall(date)               'YYYY-MM-DDTHH:MM' in the app zone (datetime-local value / min / max)
 *   T.wallToDate(value)        the instant of an app-zone wall-clock value (a spring-forward gap time
 *                              moves forward, as PHP does: 02:30 -> 03:30 CDT); null when malformed
 *   T.endFor(preset, value)    {date|null, error|null} for a preset (null date = kept until removed)
 *   T.untilBounds(input)       sets min / max on a datetime-local input (5 minutes .. MAX_DAYS)
 */
(function () {
    'use strict';

    var MIN_LEAD_MS = 5 * 60000;
    var HOURS = { '4h': 4, '8h': 8, '24h': 24 };

    function create(tz, maxDays) {
        maxDays = maxDays || 30;

        /** Wall-clock parts of an instant in the app zone. */
        function parts(ms) {
            var p = {};
            new Intl.DateTimeFormat('en-US', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
                .formatToParts(new Date(ms)).forEach(function (x) { p[x.type] = x.value; });
            return p;
        }
        /** The app zone's UTC offset (ms) at an instant. */
        function offsetAt(ms) {
            var p = parts(ms);
            var asUtc = Date.UTC(+p.year, +p.month - 1, +p.day, p.hour === '24' ? 0 : +p.hour, +p.minute, +p.second);
            return asUtc - Math.floor(ms / 1000) * 1000;
        }

        function fmt(date, withZone) {
            try {
                return date.toLocaleString([], { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: withZone ? 'short' : undefined });
            } catch (e) {
                return date.toLocaleString();
            }
        }

        /** The time of day with its zone ("11:59 PM CDT"). */
        function time(date) {
            try {
                return date.toLocaleTimeString([], { timeZone: tz, hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
            } catch (e) {
                return date.toLocaleTimeString();
            }
        }

        function wall(date) {
            var p;
            try { p = parts(date.getTime()); } catch (e) { return ''; }
            return p.year + '-' + p.month + '-' + p.day + 'T' + (p.hour === '24' ? '00' : p.hour) + ':' + p.minute;
        }

        function wallToDate(v) {
            var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(v || '');
            if (!m) { return null; }
            var asUtc = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
            try {
                var before = offsetAt(asUtc - 43200000);   // the offsets either side of any change that day
                var after = offsetAt(asUtc + 43200000);
                var hits = [asUtc - before, asUtc - after].filter(function (t) { return offsetAt(t) === asUtc - t; });
                // Ambiguous (fall back): the first of the two; in a spring-forward gap: the old offset (moves forward).
                return new Date(hits.length ? Math.min.apply(null, hits) : asUtc - before);
            } catch (e) {
                return null;
            }
        }

        function endOfToday(now) {
            return wallToDate(wall(now).slice(0, 10) + 'T23:59:59');
        }

        function endFor(preset, value, now) {
            now = now || new Date();
            if (preset === 'keep') { return { date: null, error: null }; }
            if (HOURS[preset]) { return { date: new Date(now.getTime() + HOURS[preset] * 3600000), error: null }; }
            if (preset === 'today') {
                var eod = endOfToday(now);
                if (!eod) { return { date: null, error: '' }; }
                return eod.getTime() - now.getTime() < MIN_LEAD_MS
                    ? { date: eod, error: 'Less than 5 minutes are left today. Pick 4 hours, or a date and time.' }
                    : { date: eod, error: null };
            }
            if (preset === 'until') {
                var d = wallToDate(value);
                if (!d) { return { date: null, error: 'Pick a date and time.' }; }
                if (d.getTime() < now.getTime() + MIN_LEAD_MS) { return { date: d, error: 'Pick a time at least 5 minutes from now.' }; }
                if (d.getTime() > now.getTime() + maxDays * 86400000) { return { date: d, error: 'A temporary device can stay set up for at most ' + maxDays + ' days.' }; }
                return { date: d, error: null };
            }
            return { date: null, error: 'Pick how long the device stays set up.' };
        }

        function untilBounds(input) {
            input.min = wall(new Date(Date.now() + MIN_LEAD_MS));
            input.max = wall(new Date(Date.now() + maxDays * 86400000));
        }

        return { fmt: fmt, time: time, wall: wall, wallToDate: wallToDate, endFor: endFor, untilBounds: untilBounds, maxDays: maxDays };
    }

    window.TrainingDeviceTime = { create: create };
}());
