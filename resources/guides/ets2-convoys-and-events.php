<?php

// Facts checked against CompanyEventController and events/*.vue (#5151). The Hub schedules
// events and collects answers; it does not run or track the convoy itself.
return [
    'order' => 5,
    'updated' => '2026-11-20',
    'title' => 'How to plan an ETS2 convoy or company event',
    'description' => 'Plan a Euro Truck Simulator 2 convoy on ProjectTrucks Hub: set the route, server and start time, see who is coming, and post it to your Discord.',
    'answer' => 'Owners and managers of a company on ProjectTrucks Hub click "New event" on their company page, fill in the title, start time, route and server, and save. The event appears on the public Events page, members answer Going, Maybe or Not going, and everyone can add it to their calendar.',
    'body' => <<<'MD'
## Create an event

You need to be the owner or a manager of a company. If you don't have one yet, see [how to start a VTC](/guides/start-or-join-a-vtc).

1. Open your company's page and click **New event** under **Upcoming events**.
2. Fill in:
   - **Title** and **Start time** (in your own time zone; it must be in the future).
   - **Game**: Euro Truck Simulator 2 or American Truck Simulator.
   - **Server**: optional, for example a TruckersMP server.
   - **Start city** and **Destination city**, and optionally the start and destination company.
   - **Route notes**: optional, for meeting points, rest stops or rules.
   - **Max drivers**: optional, from 1 to 500.
   - **Open event**: tick it to let drivers outside your company say they're coming too.
3. Save it. If your company has a Discord channel connected, the event is posted there.

## Who is coming

Every event has its own page, listed on [Events](/events). Under **Are you coming?** drivers answer **Going**, **Maybe** or **Not going**. Company members can always answer; for an open event any signed-in driver can. Once **Max drivers** have said Going, new drivers can still answer Maybe.

**Add to calendar** on the event page downloads it for Google Calendar, Outlook or Apple Calendar, at the right time for wherever you are.

## Change or cancel it

Owners and managers can edit an event or click **Cancel event**. Everyone who answered Going or Maybe gets a notification. Answers close three hours after the start time.

## On the day

The Hub plans the convoy; you drive it in the game as usual, for example on the server you named. If the drivers have [ProjectTrucks](/download) running, the jobs they deliver show up on their profiles and the company page.
MD,
    'faq' => [
        [
            'Who can create a convoy on ProjectTrucks Hub?',
            'The owner and managers of a company. Drivers can answer events but not create them.',
        ],
        [
            'Can drivers from other companies join my convoy?',
            'Yes, if you tick "Open event". Then any signed-in driver can answer Going or Maybe. Without it, only your company\'s members can.',
        ],
        [
            'Does the Hub run the convoy or track who drove in it?',
            'No. The Hub schedules the event and collects answers. The convoy itself happens in the game, and each driver\'s jobs are recorded by ProjectTrucks as usual.',
        ],
        [
            'Which time zone is the start time in?',
            'You enter it in your own time zone, and everyone sees it in theirs, on the event page and in the calendar file.',
        ],
    ],
];
