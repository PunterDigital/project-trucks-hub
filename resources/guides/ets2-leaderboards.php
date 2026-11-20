<?php

// Facts checked against LeaderboardController, App\Support\Leaderboard and config/anticheat.php (#5151).
return [
    'order' => 4,
    'updated' => '2026-11-20',
    'title' => 'How the ETS2 leaderboards work',
    'description' => 'How ProjectTrucks Hub ranks Euro Truck Simulator 2 drivers and VTCs by distance, deliveries and income each week, month and all time, and how to get on them.',
    'answer' => 'The Hub ranks drivers and companies by distance, deliveries or income for this week, this month or all time, from the jobs ProjectTrucks records and syncs. To appear, link the app to a free Hub account and deliver jobs. Weeks start on Monday at 00:00 UTC.',
    'body' => <<<'MD'
## What the leaderboards show

Open [Leaderboards](/leaderboards) and pick:

- **Drivers** or **Companies**
- **This week**, **This month** or **All time**
- **Distance**, **Deliveries** or **Income**

Each board shows the top 50. Weeks start on Monday at 00:00 UTC, and months on the 1st (UTC).

## How to get on them

1. [Download ProjectTrucks](/download) and set it up to [record your jobs](/guides/track-ets2-jobs-and-earnings).
2. [Create a free Hub account](/register).
3. In ProjectTrucks, go to **Settings → ProjectTrucks Hub**, click **Link account** and approve the code on the Hub. Jobs you drove before linking go up with **Sync history**.
4. Deliver jobs. Each one syncs to your profile and counts once it is there.

To climb the company board, [start or join a company](/guides/start-or-join-a-vtc): its members' jobs add up.

## What counts

Only delivered jobs count, on time or late. These are left out:

- cancelled jobs
- jobs flagged as implausible, until staff have reviewed and approved them
- jobs you have hidden with **Hide from others**
- drivers with a private profile (on the driver board; their jobs still count for their company)

Jobs logged by hand in the app never leave your PC, so they never count.

## Checks against impossible jobs

Every synced job is checked. A job is flagged for review if, for example, it is under 1 km, its average speed is over 150 km/h in game time, or it covers more than 3,000 km per real hour. Drivers who sync a lot of jobs in a short time are also held for review. A flagged job shows as **Pending review** on your own job page with the reason, and counts once staff approve it.
MD,
    'faq' => [
        [
            'When does the weekly ETS2 leaderboard reset?',
            'Every Monday at 00:00 UTC. The monthly board resets on the 1st of each month, UTC.',
        ],
        [
            'Why is my job not on the leaderboard?',
            'It may have been cancelled, hidden, logged by hand, or flagged and waiting for review. Open the job on your Hub profile to see its status.',
        ],
        [
            'Does a private profile stop me appearing?',
            'Yes, on the driver board. Your jobs still count towards your company\'s totals.',
        ],
        [
            'Do the leaderboards include American Truck Simulator?',
            'No. ProjectTrucks records Euro Truck Simulator 2 jobs only.',
        ],
    ],
];
