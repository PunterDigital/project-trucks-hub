<?php

// Facts checked against the Hub's companies pages and CompanyMembershipController (#5151).
return [
    'order' => 3,
    'updated' => '2026-11-20',
    'title' => 'How to start or join a virtual trucking company (VTC) in ETS2',
    'description' => 'Start your own Euro Truck Simulator 2 virtual trucking company on ProjectTrucks Hub, or find one to join: recruitment, roles, goals and company stats.',
    'answer' => 'Sign in to ProjectTrucks Hub, open Companies and click "New company" to start one: pick a name, a 2 to 5 character tag and how drivers join. To join one instead, open its page and click "Join company", or "Apply to join" if it takes applications. Your synced jobs then count towards the company\'s stats.',
    'body' => <<<'MD'
## What a company on the Hub gives you

A virtual trucking company (VTC) is a group of drivers who truck under one name. On ProjectTrucks Hub every company gets a public page with:

- company stats for this week, this month and all time, and a chart of each driver's distance over the last 30 days
- the latest deliveries and who is on the road right now
- the members list with each driver's distance this month
- monthly goals for distance, deliveries or income
- upcoming [convoys and events](/guides/ets2-convoys-and-events)
- a place on the [company leaderboard](/guides/ets2-leaderboards)

The jobs come from [ProjectTrucks](/download): each member links the app to their Hub account and their jobs sync on their own.

## Start a company

1. [Create a free account](/register) (or sign in with Steam) and confirm your email address.
2. Open [Companies](/companies) and click **New company**.
3. Fill in:
   - **Company name**: 3 to 60 characters, not already taken.
   - **Tag**: 2 to 5 letters or numbers, shown next to members' names.
   - **About**: optional.
   - **Recruitment**: **Applications** (drivers apply and an owner or manager decides), **Open** (anyone can join straight away) or **Closed** (no new members).
4. Click **Create company**. You are its owner.

Owners and managers can then set monthly goals on the company page, and on its edit page add a logo and banner or connect a Discord channel, so new jobs, members and events are posted there.

## Join a company

1. Find one on the [Companies](/companies) page.
2. On its page, click **Join company** if it is open, or write an optional message under **Apply to join** and click **Send application**. An owner or manager then accepts or declines it, and you get a notification.

You can be in one company at a time. To move, click **Leave company** first.

## Roles

- **Owner**: everything, including changing members' roles and handing over ownership.
- **Manager**: edits the company, its goals, Discord and events, decides applications and can remove drivers.
- **Driver**: drives, shows up in the company's stats, and can leave at any time.
MD,
    'faq' => [
        [
            'How much does it cost to start a VTC on ProjectTrucks Hub?',
            'Nothing. Accounts and companies on ProjectTrucks Hub are free, and so is the ProjectTrucks app.',
        ],
        [
            'Can I be in two companies at once?',
            'No. A driver can be in one company at a time. Leave your current company before you join or start another.',
        ],
        [
            'Can I invite drivers to my company?',
            'There are no invites. Share your company page: drivers join straight away if recruitment is Open, or apply if it is set to Applications.',
        ],
        [
            'How do I leave a company I own?',
            'Hand ownership over to another member first. If you are the only member, delete the company instead. Members keep their jobs either way.',
        ],
        [
            'Do private profiles count towards the company?',
            'Yes. A driver with a private profile is hidden from people outside the company, but their jobs still count in the company\'s totals.',
        ],
    ],
];
