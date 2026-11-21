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
    level?: number;
    rank?: string;
    company?: CompanySummary | null;
};

export type DriverProgress = {
    level: number;
    rank: string;
    distance_km: number;
    next_level_km: number;
    level_progress: number;
    achievements: {
        key: string;
        title: string;
        description: string;
        unlocked_at: string | null;
    }[];
};

export type JobStatus = 'DELIVERED' | 'LATE' | 'CANCELLED';

export type JobReview = 'pending' | 'approved' | 'rejected' | null;

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
    // #4743: 'pending' / 'rejected' only ever reach the job's own driver.
    review: JobReview;
    hidden: boolean;
    staff_hidden?: boolean;
    completed_at: string;
    driver_id: number;
    driver: DriverSummary | null;
    kudos: number;
    kudos_given: boolean;
    comments: number;
};

export type RsvpStatus = 'going' | 'maybe' | 'not_going';

export type EventSummary = {
    id: number;
    title: string;
    starts_at: string;
    game: 'ets2' | 'ats';
    server: string | null;
    start_city: string;
    dest_city: string;
    open: boolean;
    max_drivers: number | null;
    cancelled: boolean;
    going: number;
    company: CompanySummary | null;
};

export type JobComment = {
    id: number;
    body: string;
    created_at: string;
    author: DriverSummary;
    can_delete: boolean;
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

/** Company goals (#4649). */
export type GoalMetric = keyof Totals;

export type CompanyGoals = {
    month: string;
    current: {
        metric: GoalMetric;
        target: number;
        progress: number;
        percent: number;
        contributors: {
            driver: {
                name: string;
                username: string | null;
                avatar: string | null;
            };
            value: number;
            percent: number;
        }[];
    }[];
    history: {
        month: string;
        goals: {
            metric: GoalMetric;
            target: number;
            achieved: number;
            hit: boolean;
        }[];
    }[];
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

/** Company ranks (#5223): reached at `min_km` counted km driven for the company. */
export type CompanyRank = {
    id: number;
    name: string;
    color: string;
    min_km: number;
};

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

/** Charts (#4650): `[unix seconds, km]`, one per 15-minute UTC bucket that has distance. */
export type ChartPoint = [number, number];

export type ChartSeries = {
    key: string;
    name: string;
    username: string | null;
    distance_km: number;
    points: ChartPoint[];
};

export type CargoRow = {
    name: string;
    deliveries: number;
    distance_km: number;
};

/** A blog post in a list (#5150). */
export type PostSummary = {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    cover: string | null;
    published_at: string | null;
};

/** A blog post's page: the summary plus the body, rendered (and sanitised) on the server. */
export type PostPage = PostSummary & {
    html: string;
    status: 'draft' | 'published';
    is_public: boolean;
};

/** A help guide on /guides (#5151). */
export type GuideSummary = {
    slug: string;
    title: string;
    description: string;
};

/** A guide's page: the body rendered (and sanitised) on the server, plus its FAQ. */
export type GuidePage = GuideSummary & {
    answer: string;
    html: string;
    faq: { question: string; answer: string }[];
    updated: string;
    updated_label: string;
};
