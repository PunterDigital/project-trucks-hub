<?php

// Facts checked against ProjectTrucks v0.11 (#5151): the bundled plugin is RenCloud's
// scs-telemetry.dll (MIT). Keep in step with the app's labels and plugin version.
return [
    'order' => 2,
    'updated' => '2026-11-20',
    'title' => 'The ETS2 telemetry plugin: what it is and how to install it',
    'description' => 'What the Euro Truck Simulator 2 telemetry plugin does, how ProjectTrucks installs it in one click, what it changes in your game folder, and how to remove it.',
    'answer' => 'The telemetry plugin is a small open-source file (scs-telemetry.dll) that ETS2 loads through its official SDK. It shares live game data such as speed, cargo and job details so a tracker can read it. ProjectTrucks installs it for you: click Install on the "Set up live telemetry?" prompt, or Install plugin in Settings, then restart the game.',
    'body' => <<<'MD'
## What the plugin is

Euro Truck Simulator 2 has an official SDK for telemetry plugins: files that the game loads from its `plugins` folder and hands live data to, such as your speed, your truck, and the job you are on. ProjectTrucks ships the MIT-licensed [scs-sdk-plugin](https://github.com/RenCloud/scs-sdk-plugin) by RenCloud (`scs-telemetry.dll`), the same plugin other trackers use.

The plugin puts that data in shared memory on your PC. ProjectTrucks opens it **read-only** and turns it into jobs and drives.

## Install it

1. [Download ProjectTrucks](/download) and open it.
2. On first launch it asks **Set up live telemetry?** with your ETS2 folder, found through your Steam libraries. Click **Install**.
3. If you clicked **Not now**, or Steam didn't find the game, open **Settings**:
   - Under **ETS2 folder**, click **Detect**, or enter the folder that holds `bin\win_x64\eurotrucks2.exe` by hand.
   - Under **Telemetry plugin**, click **Install plugin**. The badge changes to **Installed**.
4. Restart Euro Truck Simulator 2 if it was running. The first launch with the plugin shows a one-time "advanced SDK features" message from the game: click **OK**.

When a newer plugin ships with an app update, the badge says **Outdated** and the button reads **Update plugin**.

## What it changes in your game folder

- It copies one file to `<ETS2 folder>\bin\win_x64\plugins\scs-telemetry.dll`.
- If a different `scs-telemetry.dll` is already there, it is kept next to it as a `.bak` file.
- Plugins from other trackers are left alone. The only other file it ever removes is the older plugin that earlier ProjectTrucks versions installed.

It does not touch your saves, your profile or any other game file.

## Remove it

ProjectTrucks has no uninstall button for the plugin. To remove it, close the game and delete `scs-telemetry.dll` from `<ETS2 folder>\bin\win_x64\plugins` (rename a `.bak` back if you want the earlier plugin again).
MD,
    'faq' => [
        [
            'Is the ETS2 telemetry plugin safe?',
            'It uses the game\'s official telemetry SDK, it is open source under the MIT licence, and ProjectTrucks only reads the data it shares. Installing it adds one file to the game\'s plugins folder and changes nothing else.',
        ],
        [
            'Do I need to run ProjectTrucks as administrator?',
            'Usually not. If Windows refuses to let it write to the game folder, run ProjectTrucks as administrator once, or move ETS2 out of Program Files.',
        ],
        [
            'Why does the game ask about advanced SDK features?',
            'ETS2 shows that message the first time it loads a telemetry plugin. Click OK to let it load the plugin.',
        ],
        [
            'Can I use it alongside another tracker?',
            'Yes. Many trackers use the same scs-telemetry.dll, and ProjectTrucks never removes another tracker\'s plugin. If a different scs-telemetry.dll was already installed, it is kept as a .bak file.',
        ],
    ],
];
