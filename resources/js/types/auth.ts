/** Contrato con App\Http\Middleware\HandleInertiaRequests::share(). */

export type Role = 'admin' | 'department_manager' | 'employee' | 'client';

export type ThemePreference = 'light' | 'dark' | 'system';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    theme_preference: ThemePreference;
    two_factor_enabled: boolean;
    roles: Role[];
    is_client: boolean;
};

export type Abilities = {
    viewHourBanks: boolean;
    viewAdmin: boolean;
    viewFinancials: boolean;
};

export type Auth = {
    user: User | null;
    can: Abilities;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
