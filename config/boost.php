<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Overrides
    |--------------------------------------------------------------------------
    |
    | Boost writes its guidelines block to one file per configured agent. Left
    | at its default, the claude_code agent targets CLAUDE.md while cursor and
    | junie target AGENTS.md — so `boost:update` (which runs on every composer
    | update) appended a second, byte-identical copy of the same ~220 lines to
    | CLAUDE.md, and Claude Code loaded them twice per session. Pointing
    | claude_code at AGENTS.md too keeps one copy; CLAUDE.md imports it with
    | `@AGENTS.md`.
    |
    */

    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'AGENTS.md',
        ],
    ],

];
