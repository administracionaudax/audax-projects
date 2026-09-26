// @vitest-environment jsdom
import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { Toaster } from '@/components/ui/sonner';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { on: () => () => {} },
}));

describe('Toaster', () => {
    it('anuncia la región de avisos en español (no «Notifications»)', () => {
        const { container } = render(<Toaster />);
        const region = container.querySelector('section[aria-label]');

        expect(region).not.toBeNull();
        expect(region?.getAttribute('aria-label')).toMatch(/^Avisos\b/);
        expect(region?.getAttribute('aria-label')).not.toMatch(/Notifications/);
    });
});
