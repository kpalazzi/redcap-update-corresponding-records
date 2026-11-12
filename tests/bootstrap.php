<?php
/**
 * PHPUnit bootstrap: a minimal stand-in for the REDCap External Module framework, so the
 * module's pure logic can be tested without REDCap or its database. Methods that call
 * REDCap (getData, saveData, settings, rights) are covered by the manual test checklist.
 */

namespace ExternalModules;

abstract class AbstractExternalModule
{
    public $PREFIX = 'update_corresponding_records';

    /** @var array Project settings returned by getProjectSetting(), set per test */
    public $testSettings = [];

    /** @var array[] Rows returned by successive query() calls, one list of rows per call, set per test */
    public $testQueryResults = [];

    /** @var array[] The [sql, parameters] of each query() call, for tests to check */
    public $testQueries = [];

    /** @var int|null Returned by getProjectId(), set per test */
    public $testProjectId = null;

    /** @var array All of the project's saved settings (key => value), set per test */
    public $testProjectSettings = [];

    /** @var bool Returned by isSuperUser(), set per test */
    public $testSuperUser = false;

    public function getProjectSetting($key, $pid = null)
    {
        return $this->testSettings[$key] ?? null;
    }

    public function getProjectSettings($pid = null)
    {
        return $this->testProjectSettings;
    }

    public function getProjectId()
    {
        return $this->testProjectId;
    }

    public function isSuperUser()
    {
        return $this->testSuperUser;
    }

    /** The framework's parameterised query(): records the call and returns the next set of test rows. */
    public function query($sql, $parameters = [])
    {
        $this->testQueries[] = [$sql, $parameters];
        return new TestQueryResult(array_shift($this->testQueryResults) ?? []);
    }
}

/** Stand-in for the mysqli result query() returns: only fetch_assoc() is used. */
class TestQueryResult
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_assoc()
    {
        return array_shift($this->rows);
    }
}

require_once __DIR__ . '/../UpdateCorrespondingRecords.php';
