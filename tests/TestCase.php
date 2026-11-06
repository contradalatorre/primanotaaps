<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Gli stili si compilano a parte (npm run build, in CI): i test HTTP non ne hanno bisogno.
        $this->withoutVite();
    }
}
