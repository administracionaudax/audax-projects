// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';

const QR =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#ffffff"/><rect width="5" height="5" fill="#2d3748"/></svg>';

describe('QR de la verificación en dos pasos (UI-11)', () => {
    it.each(['light', 'dark'])(
        'en tema %s se pinta sin invertir, oscuro sobre blanco',
        (theme) => {
            localStorage.setItem('appearance', theme);
            document.documentElement.classList.toggle('dark', theme === 'dark');

            render(
                <TwoFactorSetupModal
                    isOpen
                    onClose={() => {}}
                    requiresConfirmation={false}
                    twoFactorEnabled={false}
                    qrCodeSvg={QR}
                    manualSetupKey="ABCDEF"
                    clearSetupData={() => {}}
                    fetchSetupData={async () => {}}
                    errors={[]}
                />,
            );

            const qr = screen.getByRole('img', {
                name: 'Código QR para configurar la verificación en dos pasos',
            });
            expect(qr.getAttribute('style') ?? '').not.toMatch(/filter|invert/);
            expect(qr.className).toContain('bg-white');
            expect(qr.querySelector('svg')).not.toBeNull();
        },
    );
});
