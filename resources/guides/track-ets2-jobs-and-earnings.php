<?php

// Facts checked against ProjectTrucks v0.11 (#5151). Keep in step with the app's labels.
return [
    'order' => 1,
    'updated' => '2026-11-20',
    'title' => 'How to track your ETS2 jobs and earnings',
    'description' => 'Log every Euro Truck Simulator 2 delivery automatically: route, cargo, distance, income and time, with monthly totals. Free with ProjectTrucks.',
    'answer' => 'Install ProjectTrucks (free, for Windows), let it add the telemetry plugin to your ETS2 folder, and tick "Record drives and deliveries from ETS2 automatically" in Settings. From then on every job you drive is logged with its route, cargo, distance, income and time, and the Dashboard adds up your totals.',
    'body' => <<<'MD'
## What gets recorded

ProjectTrucks reads the game while you play, through a small telemetry plugin. For each job it saves:

- the route: the start and destination city and company
- the cargo and its mass
- the job's **Income**, the **Distance**, how long it took and your average speed
- fuel used, truck damage and the truck you drove
- whether it was delivered on time, late or cancelled

The game also reports some figures of its own, which the job page shows under **Reported by the game**: revenue, XP earned, cargo damage and the job distance. Tolls, fines and ferry or train fares go under **Costs** on the same page. Free-roam drives without a job are recorded too.

## Set it up

1. [Download ProjectTrucks](/download) and install it (Windows 10 or 11, 64-bit).
2. Open ProjectTrucks. On first launch it asks **Set up live telemetry?** with your ETS2 folder found through Steam. Click **Install**. If you skipped that, open **Settings**, click **Detect** under **ETS2 folder**, then **Install plugin** under **Telemetry plugin**. See [the telemetry plugin guide](/guides/ets2-telemetry-plugin) for details.
3. In **Settings**, under **Auto-capture**, tick **Record drives and deliveries from ETS2 automatically**. It is off until you turn it on.
4. Start Euro Truck Simulator 2. The first time after the plugin goes in, the game asks about "advanced SDK features": click **OK**. Take a job and drive.

When you deliver, the job appears on the **Jobs** page.

## Where to see your earnings

- **Dashboard**: lifetime totals (**Total km driven**, **Deliveries**, **Income**, **On time**), the same figures for this month next to last month, and charts of your distance over the last 30 days and your on-time deliveries. The Income card also says how much went on tolls, fines and fares.
- **Jobs**: every job, newest first, with a filter for on time, late and cancelled. Open one to see all its details.
- **Trucks**: distance, jobs and on-time share for each truck you have driven.

## Share your jobs on the Hub (optional)

Your jobs stay on your PC unless you link the app to a free Hub account. In **Settings**, under **ProjectTrucks Hub**, click **Link account** and approve the code on the website. New jobs then sync to your Hub profile, count on the [leaderboards](/guides/ets2-leaderboards) and, if you are in one, [your company](/guides/start-or-join-a-vtc). Jobs you drove before linking go up when you click **Sync history**.
MD,
    'faq' => [
        [
            'Does ProjectTrucks work with American Truck Simulator?',
            'No. ProjectTrucks supports Euro Truck Simulator 2 only.',
        ],
        [
            'Does ProjectTrucks need to be open while I drive?',
            'Yes. The app reads the game\'s data while you play, so keep it running with "Record drives and deliveries from ETS2 automatically" ticked.',
        ],
        [
            'Why is a job marked late?',
            'A job is late when the in-game clock passed its deadline before you delivered. Jobs without a deadline are never late.',
        ],
        [
            'Why does a job say Flagged?',
            'ProjectTrucks flags a job that is under 1 km or whose average speed is over 150 km/h in game time. If it was a mistake, untick "Flag this job for review" on the job page.',
        ],
        [
            'Can I add a job by hand?',
            'Yes, with "Log a job" on the Jobs page. Jobs logged by hand stay on your PC and are never sent to the Hub.',
        ],
        [
            'Is ProjectTrucks free?',
            'Yes. It is free and open source under the MIT licence.',
        ],
    ],
];
