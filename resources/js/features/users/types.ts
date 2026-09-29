export interface ManagedUser {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
    role?: string | null;
    permissions?: string[];
}
