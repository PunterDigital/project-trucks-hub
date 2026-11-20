<?php

// Facts checked against ProjectTrucks v0.11 and the Hub's Settings → Privacy (#5151).
return [
    'order' => 6,
    'updated' => '2026-11-20',
    'title' => 'How to export your ETS2 job history',
    'description' => 'Export every Euro Truck Simulator 2 job ProjectTrucks has recorded to a CSV spreadsheet, back up the whole database, or download your Hub data.',
    'answer' => 'In ProjectTrucks, open Settings and click "Export jobs to CSV" under "Your data". You get one row per job that Excel, LibreOffice Calc or Google Sheets can open. "Back up database" next to it saves a full copy of everything the app has recorded.',
    'body' => <<<'MD'
## Export to a spreadsheet

1. Open ProjectTrucks and go to **Settings**.
2. Under **Your data**, click **Export jobs to CSV**.
3. Choose where to save the file.

The CSV has one row per job, with columns for:

- the status (on time, late or cancelled), and when the job started and finished
- the route: start and destination city and company
- the cargo and its mass
- the job's income, distance, duration and average speed
- fuel used, truck damage and the truck
- the figures the game reports (revenue, XP earned, cargo damage, job distance)
- tolls, fines and fares, any notes you wrote, and whether the job was flagged

## Back up everything

**Back up database** in the same place saves a copy of the app's whole database: jobs, drives, trucks and settings. To restore it, close ProjectTrucks and copy the file over `projecttrucks.db` in the app's data folder.

## Your Hub data

If you have linked the app to the Hub, the website keeps its own copy of your synced jobs. [Sign in](/login), open **Settings → Privacy** and click **Download my data**. You get one JSON file with your account, your company, your achievements and every synced job.

The same page has **Delete all synced jobs**, which removes your jobs from the Hub only. They stay in ProjectTrucks on your PC.
MD,
    'faq' => [
        [
            'Which programs can open the CSV?',
            'Any spreadsheet program, such as Microsoft Excel, LibreOffice Calc or Google Sheets.',
        ],
        [
            'Does the export include jobs I logged by hand?',
            'Yes. The CSV has every job on your PC, including ones added with "Log a job". A column says whether a job was recorded from the game or logged by hand.',
        ],
        [
            'Can I move my jobs to another PC?',
            'Yes. Make a backup with "Back up database", install ProjectTrucks on the other PC, open and close it once, then copy the backup over projecttrucks.db in its data folder.',
        ],
        [
            'Is the Hub export a spreadsheet too?',
            'No. "Download my data" on the Hub gives a JSON file. For a spreadsheet, use "Export jobs to CSV" in the app.',
        ],
    ],
];
