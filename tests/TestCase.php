<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Worktree-bootstrap-fix: vendor/ is symlinked naar de main repo. Zonder
        // expliciete APP_BASE_PATH valt Application::inferBasePath() terug op
        // de symlink-target (main repo) i.p.v. de worktree, waardoor
        // database_path('migrations') de verkeerde directory wijst en
        // recente migraties stilzwijgend genegeerd worden in tests. (#2070-pattern,
        // STATE.md "Worktree-bootstrap-pattern recurring").
        $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

        return parent::createApplication();
    }
}
