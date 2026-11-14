export type User = {
    id: number;
    name: string;
    email: string;
    username: string | null;
    avatar?: string | null;
    profile_private: boolean;
    company: { id: number; name: string; slug: string; tag: string } | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    is_staff?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
