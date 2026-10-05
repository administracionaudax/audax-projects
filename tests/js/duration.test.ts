import { describe, expect, it } from 'vitest';
import {
    parseDuration,
    roundToNearest,
    timeRangeMinutes,
} from '@/lib/duration';
import fixture from '../fixtures/duration-cases.json';

// Mismos casos que tests/Unit/DurationTest.php (App\Support\Duration).
const cases = fixture.cases as [string, number | null][];

describe('parseDuration (gemelo de App\\Support\\Duration)', () => {
    it.each(cases)('"%s" → %s', (input, expected) => {
        expect(parseDuration(input)).toBe(expected);
    });

    it.each(fixture.with_max as [string, number, number | null][])(
        '"%s" con máximo %i → %s',
        (input, max, expected) => {
            expect(parseDuration(input, max)).toBe(expected);
        },
    );

    it('trata null y undefined como vacío', () => {
        expect(parseDuration(null)).toBeNull();
        expect(parseDuration(undefined)).toBeNull();
    });
});

describe('roundToNearest', () => {
    it.each([
        [7, 1, 7],
        [7, 5, 5],
        [8, 5, 10],
        [2, 15, 0],
        [52, 15, 45],
        [53, 15, 60],
    ])('%i min con paso %i → %i', (minutes, step, expected) => {
        expect(roundToNearest(minutes, step)).toBe(expected);
    });
});

describe('timeRangeMinutes (gemelo de TimeRange, D-162)', () => {
    it.each([
        ['09:00', '11:30', 150],
        ['22:00', '00:00', 120],
        ['00:00', '00:00', 1440],
        ['00:00', '23:59', 1439],
    ])('%s–%s → %i min', (start, end, minutes) => {
        expect(timeRangeMinutes(start, end)).toEqual({ minutes });
    });

    it.each([
        ['10:00', '10:00', 'empty'],
        ['22:00', '02:00', 'midnight'],
        ['9:00', '10:00', 'format'],
        ['09:00', '24:00', 'format'],
    ])('%s–%s → %s', (start, end, error) => {
        expect(timeRangeMinutes(start, end)).toEqual({ error });
    });
});
