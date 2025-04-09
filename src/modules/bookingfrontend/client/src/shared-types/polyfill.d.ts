import {DateTimeMaybeValid, DateTimeOptions} from "luxon/src/datetime";
declare module "luxon" {
    interface DateTime<IsValid extends boolean = DefaultValidity> {
        /**
         * Returns an ISO 8601-compliant string representation of this DateTime
         *
         * @example
         * DateTime.utc(1982, 5, 25).toISO() //=> '1982-05-25T00:00:00.000Z'
         * @example
         * DateTime.now().toISO() //=> '2017-04-22T20:47:05.335-04:00'
         * @example
         * DateTime.now().toISO({ includeOffset: false }) //=> '2017-04-22T20:47:05.335'
         * @example
         * DateTime.now().toISO({ format: 'basic' }) //=> '20170422T204705.335-0400'
         */
        toISO(opts?: ToISOTimeOptions): IfValid<TDateISO, null, IsValid>;



        /**
         * Create a DateTime from an ISO 8601 string
         *
         * @param text - the ISO string
         * @param opts - options to affect the creation
         * @param opts.zone - use this zone if no offset is specified in the input string itself. Will also convert the time to this zone. Defaults to 'local'.
         * @param opts.setZone - override the zone with a fixed-offset zone specified in the string itself, if it specifies one. Defaults to false.
         * @param opts.locale - a locale to set on the resulting DateTime instance. Defaults to 'system's locale'.
         * @param opts.outputCalendar - the output calendar to set on the resulting DateTime instance
         * @param opts.numberingSystem - the numbering system to set on the resulting DateTime instance
         *
         * @example
         * DateTime.fromISO('2016-05-25T09:08:34.123')
         * @example
         * DateTime.fromISO('2016-05-25T09:08:34.123+06:00')
         * @example
         * DateTime.fromISO('2016-05-25T09:08:34.123+06:00', {setZone: true})
         * @example
         * DateTime.fromISO('2016-05-25T09:08:34.123', {zone: 'utc'})
         * @example
         * DateTime.fromISO('2016-W05-4')
         */
        fromISO(text: TDateISO, opts?: DateTimeOptions): DateTimeMaybeValid;
    }

}
