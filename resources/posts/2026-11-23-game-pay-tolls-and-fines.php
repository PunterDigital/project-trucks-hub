<?php

// Facts checked against ProjectTrucks PRs #85, #87, #88, #89, #95, #96 and #97 (merged after
// v0.11.0) and the Hub's #58 (job figures, Delivery::INCOME_SQL) and #32 (dark theme), Nov 23.

return [
    'title' => 'Coming to ProjectTrucks: what the game really paid, plus tolls, fines and a dark theme',
    'excerpt' => 'The next ProjectTrucks update records the figures Euro Truck Simulator 2 reports itself: the pay, XP, cargo damage, tolls, fines and ferry fares. They sync to the Hub too. It also adds a dark theme, automatic updates and a fix for deliveries finished on the results screen.',
    'body' => <<<'MD'
The next ProjectTrucks desktop update is mostly about one thing: using what Euro Truck Simulator 2 reports when a job ends, instead of working it out from the job's offer.

## The game's own figures

Since version 0.10, ProjectTrucks reads the game through RenCloud's scs-telemetry plugin (see [the telemetry plugin guide](/guides/ets2-telemetry-plugin)). Besides the truck and the job, that plugin reports gameplay events: a job delivered or cancelled, a toll paid, a fine, a ferry or train fare. The app used to ignore them. From the next update it records them:

- **What the game paid.** A recorded job's income is the amount the game actually paid. If that differs from the offer, the job page shows the offer as well.
- **XP, cargo damage and the job's distance** as the game counted them, and whether you used auto-park or auto-load. A cancelled job keeps its cancellation penalty.
- **Tolls, fines and fares.** Each one paid during a job is listed under Costs on the job page, fines with the offence. Ones paid while free-roaming are recorded too, on their own. The Dashboard's income cards say how much went on tolls, fines and fares.
- **CSV export** gets eight new columns at the end. The existing columns stay where they are.

These figures come from the scs-telemetry plugin, so jobs recorded before the update or with the old plugin, and jobs you add by hand, don't have them.

## On the Hub

When the app is linked to the Hub, the new figures go up with each job: what the game paid, XP, cargo damage, any cancellation penalty, and the total of the job's tolls, fines and fares. The Hub is already ready for them. A job page shows what the game paid with the offer under it, and lists the costs, XP and cargo damage when the app sends them.

Income on profiles, [leaderboards](/guides/ets2-leaderboards), company goals and the feed counts what the game paid whenever the app reports it, and the offered income for every other job. The check that flags a job for income that's too high for its distance looks at the same figure. Older versions of the app keep syncing as they do today.

## Deliveries finished on the results screen

The game's "delivered" event now ends the job the moment it arrives, even while the results screen is up and the game is paused. Before, closing the game from the results screen could leave the job open until the next time you started ProjectTrucks. The old rules stay as a backup for the old plugin, or in case an event is missed.

## A dark theme

Settings has a new **Theme** option: Light, Dark or System. System is the default and follows your computer's light or dark mode, also while the app is open. The window opens in the theme you picked. The Hub has its own Light / Dark / System switch under Settings → Appearance.

The Dashboard charts are easier to read in both themes. Free roam in the distance chart and Late in the on-time chart were drawn in the lightest grey, which was hard to see. They now use a darker shade, the tooltip and legend text are readable, and labels like "3,800 km" on the side of the chart stay on one line. The truck page uses the same chart.

## Install updates automatically

On Windows, Settings → Updates gets **Install updates automatically**. It's off by default. When it's on and a newer version is out, the installer downloads in the background and is checked against the checksum GitHub publishes. It installs when you close ProjectTrucks, and the app reopens on the new version.

It never installs while ETS2 is running or a job is being recorded. If you close the app then, the update waits and installs the next time you start ProjectTrucks, before its window opens (as long as the game isn't running then either). Leave the setting off and nothing changes: the update banner works as before and you choose when to update.

## One small fix

Changing two settings quickly, for example the ETS2 folder and then the distance units, could make the Settings page show an old value for one of them, although the change had been saved. Saves now go one at a time.

[Download ProjectTrucks](/download) to get the update when it's out.
MD,
];
