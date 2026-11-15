export type CompanySummary = {
    id?: number;
    name: string;
    slug: string;
    tag: string;
    logo?: string | null;
};

export type DriverSummary = {
    id?: number;
    name: string;
    username: string | null;
    avatar: string | null;
    company?: CompanySummary | null;
};

export type JobStatus = 'DELIVERED' | 'LATE' | 'CANCELLED';

export type JobSummary = {
    id: number;
    source_city: string;
    dest_city: string;
    cargo_name: string;
    cargo_mass_kg: number;
    income: number;
    distance_km: number;
    truck: string | null;
    status: JobStatus;
    flagged: boolean;
    hidden: boolean;
    staff_hidden?: boolean;
    completed_at: string;
    driver: DriverSummary | null;
};

export type LiveSummary = {
    truck: string | null;
    on_job: boolean;
    cargo_name: string | null;
    cargo_mass_kg: number | null;
    source_city: string | null;
    dest_city: string | null;
    income: number | null;
    driven_km: number | null;
    distance_left_km: number | null;
    progress_pct: number | null;
    parked: boolean;
    updated_at: string;
    driver: DriverSummary | null;
};

export type Totals = {
    distance_km: number;
    deliveries: number;
    income: number;
};

export type PeriodTotals = {
    week: Totals;
    month: Totals;
    all: Totals;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
};

export type Recruitment = 'open' | 'apply' | 'closed';
export type CompanyRole = 'owner' | 'manager' | 'driver';

export type LeaderboardRow = {
    rank: number;
    subject: (DriverSummary | (CompanySummary & { members: number })) & {
        name: string;
    };
    deliveries: number;
    distance_km: number;
    income: number;
};

/** The header bell's shared prop (#4643); null for guests. */
export type HubNotifications = {
    unread: number;
    items: {
        id: string;
        type: string;
        message: string;
        read: boolean;
        at: string | null;
    }[];
};
