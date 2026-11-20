<?php

// E2E fixture for repo post drafts (#5153): playwright.config.ts points BLOG_DRAFTS_PATH here.
return [
    'title' => 'Written in the repo',
    'excerpt' => 'A draft that arrived from a file in the repository.',
    'body' => <<<'MD'
This post was written as a **file** and imported as a draft.

## Next

Staff read it and publish it.
MD,
];
