# Blog post drafts (#5153)

Each `<Y-m-d>-<address>.php` file here becomes a **draft** in Admin → Blog posts once it is
deployed (`hub:import-post-drafts`, hourly, and whenever staff open the Blog posts list).
Staff read it and publish it there. The address is the file name without the date:
`2026-11-23-hub-release-notes.php` → `/blog/hub-release-notes`.

```php
<?php

return [
    'title' => 'At most 160 characters',
    'excerpt' => 'At most 300 characters: shown on /blog, in the feed and in search results.',
    'body' => <<<'MD'
Markdown. Raw HTML is stripped when the post is shown.
MD,
];
```

A file is imported once. Editing it later changes nothing on the site (edit the post in the
admin), and a draft deleted in the admin stays deleted. A file whose address is already taken
is skipped for good. Only `.php` files are read, so this README is ignored.
