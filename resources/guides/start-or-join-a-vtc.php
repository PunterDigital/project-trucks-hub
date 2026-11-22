<?php

// Facts checked against the Hub's companies pages and CompanyMembershipController (#5151),
// invite links against CompanyInviteController (#5221), ranks against CompanyRanks (#5223), news against CompanyNewsController (#5224),
// the monthly minimum against CompanyActivity and hub:activity-check (#5225).
return [
    'order' => 3,
    'updated' => '2026-11-22',
    'title' => 'How to start or join a virtual trucking company (VTC) in ETS2',
    'description' => 'Start your own Euro Truck Simulator 2 virtual trucking company on ProjectTrucks Hub, or find one to join: recruitment, roles, goals and company stats.',
    'answer' => 'Sign in to ProjectTrucks Hub, open Companies and click "New company" to start one: pick a name, a 2 to 5 character tag and how drivers join. To join one instead, open its page and click "Join company", or "Apply to join" if it takes applications. Your synced jobs then count towards the company\'s stats.',
    'body' => <<<'MD'
## What a company on the Hub gives you

A virtual trucking company (VTC) is a group of drivers who truck under one name. On ProjectTrucks Hub every company gets a public page with:

- company stats for this week, this month and all time, and a chart of each driver's distance over the last 30 days
- the latest deliveries and who is on the road right now
- the members list with each driver's distance this month
- company news from the owner and managers, public or for members only
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

Owners and managers can then set monthly goals on the company page, and on its edit page add a logo and banner, ask up to 10 application questions (a short or long answer, yes or no, or one option from a list, each optional or required), or connect a Discord channel, so new jobs, members and events are posted there.

They can also post news from **Post news** on the company page: a title and a message (line breaks and web links are kept), for **Members only** or **Public**. Members get a notification under the bell (they can switch it off under Settings → Notifications), and a post can also go to the company's Discord. One post can be pinned to the top.

## Join a company

1. Find one on the [Companies](/companies) page.
2. On its page, click **Join company** if it is open, or answer the company's questions (if it asks any) and write an optional message under **Apply to join**, then click **Send application**. Your answers stay on the company page under **Your application** while you wait. Only the company's owner and managers see them. An owner or manager then accepts or declines it, and you get a notification.

You can be in one company at a time. To move, click **Leave company** first.

## Roles

- **Owner**: everything, including changing members' roles and handing over ownership.
- **Manager**: edits the company, its goals, Discord, news and events, decides applications and can remove drivers.
- **Driver**: drives, shows up in the company's stats, and can leave at any time.

## Ranks

Owners and managers can set up to 12 ranks under **Edit company** → **Ranks**, each with a name, a colour and a distance. A member gets the highest rank that the jobs they've driven for the company reach (jobs that count, as on the leaderboards; jobs from before they joined don't). The rank shows next to their name on the company page and on their profile, and the Hub tells them when they move up (and posts it to the company's Discord, if it has one). An owner or manager can also pin a rank on a member from the member list: the member never drops below it, and driving can still take them higher.

## Monthly minimum

Under **Edit company** → **Monthly minimum**, owners and managers can ask every member for a minimum distance, a minimum number of jobs, or both, each calendar month (UTC). Only jobs that count and that a member drove for the company after joining go towards it, and someone who joins mid-month owes only their share of the month. On the members list, owners and managers see each driver's progress this month, the date of their last job and an "Under the minimum last month" badge for anyone who missed it; drivers see only their own progress. On the 1st, owners and managers get one notification naming who missed the month before. Nobody is removed automatically: what happens next is up to the company.
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
            'Yes. On your company’s Edit page, under Invite links, an owner or manager can create a link that expires after a day, a week or never, and works once, 5 times, 25 times or any number of times. Anyone with the link joins straight away, even while recruitment is Applications or Closed. The link is shown once, and you can revoke it at any time. You can also share your company page: drivers join straight away if recruitment is Open, or apply if it is set to Applications.',
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
