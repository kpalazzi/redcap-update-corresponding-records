<?php
/**
 * Unit tests for Update Corresponding Records' pure logic: instruction parsing and checks
 * for both directions, key matching, which values get written, raw settings and CSV
 * conversion, and field checks.
 *
 * The priority is anything that could silently write to the wrong records, copy values the
 * wrong way, write the wrong values, or leave stale values behind.
 */

namespace HMRI\UpdateCorrespondingRecords\Tests;

use HMRI\UpdateCorrespondingRecords\UpdateCorrespondingRecords;
use HMRI\UpdateCorrespondingRecords\UpdateInstruction;
use PHPUnit\Framework\TestCase;

class UpdateCorrespondingRecordsTest extends TestCase
{
    /** PID 26's push: datasets -> requests. Setting keys: source-* = this project, dest-* = other project. */
    private function pushConfig(array $overrides = []): array
    {
        return array_merge([
            'config-description' => 'update steward',
            'config-enabled'     => true,
            'direction'          => 'push',
            'trigger-form'       => 'datsets',
            'trigger-logic'      => '',
            'source-key-field'   => 'dataset',
            'source-key-event'   => '',
            'dest-project'       => '27',
            'dest-key-field'     => 'dataset_change',
            'dest-event'         => '',
            'copy-fields'        => [
                ['source-field' => 'dsname',  'dest-field' => 'steward_name'],
                ['source-field' => 'dsemail', 'dest-field' => 'steward_email'],
            ],
        ], $overrides);
    }

    /** PID 27's pull: requests <- datasets. Field names differ between projects on purpose. */
    private function pullConfig(array $overrides = []): array
    {
        return array_merge([
            'config-description' => 'steward details for new requests',
            'config-enabled'     => true,
            'direction'          => 'pull',
            'trigger-form'       => 'aci_data_request_form',
            'trigger-logic'      => "[processed_steward] <> '1'",
            'source-key-field'   => 'dataset_change',
            'source-key-event'   => '',
            'dest-project'       => '26',
            'dest-key-field'     => 'dataset',
            'dest-event'         => '',
            'copy-fields'        => [
                ['source-field' => 'steward_name',  'dest-field' => 'dsname'],
                ['source-field' => 'steward_email', 'dest-field' => 'dsemail'],
            ],
        ], $overrides);
    }

    private function push(array $overrides = []): UpdateInstruction
    {
        return new UpdateInstruction($this->pushConfig($overrides), 0, 26);
    }

    private function pull(array $overrides = []): UpdateInstruction
    {
        return new UpdateInstruction($this->pullConfig($overrides), 0, 27);
    }

    /** Calls a protected method on the module. */
    private function callModule(string $method, ...$args)
    {
        $ref = new \ReflectionMethod(UpdateCorrespondingRecords::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(new UpdateCorrespondingRecords(), ...$args);
    }

    // ---- Parsing and structural checks ------------------------------------

    public function testValidPushParses(): void
    {
        $ins = $this->push();
        $this->assertTrue($ins->isValid(), implode('; ', $ins->getConfigErrors()));
        $this->assertFalse($ins->isPull());
        $this->assertSame(26, $ins->project_id);
        $this->assertSame(27, $ins->other_project_id);
        $this->assertSame(['dsname', 'dsemail'], $ins->fromFields());
        $this->assertSame(['steward_name', 'steward_email'], $ins->toFields());
        $this->assertSame('#1 (update steward)', $ins->label());
    }

    /** A pull copies the other project's fields into this project's: the opposite of a push. */
    public function testValidPullCopiesTheOtherWay(): void
    {
        $ins = $this->pull();
        $this->assertTrue($ins->isValid(), implode('; ', $ins->getConfigErrors()));
        $this->assertTrue($ins->isPull());
        $this->assertSame(['dsname', 'dsemail'], $ins->fromFields());
        $this->assertSame(['steward_name', 'steward_email'], $ins->toFields());
    }

    /** Instructions saved before directions existed (or never touched) are pushes. */
    public function testMissingDirectionIsPush(): void
    {
        $ins = $this->push(['direction' => null]);
        $this->assertTrue($ins->isValid());
        $this->assertFalse($ins->isPull());
    }

    public function testUnknownDirectionIsReported(): void
    {
        $this->assertStringContainsString('must be push or pull', implode('; ', $this->push(['direction' => 'both'])->getConfigErrors()));
    }

    public function testMissingEssentialsAreReported(): void
    {
        $ins = new UpdateInstruction([], 0, 26);
        $this->assertFalse($ins->isValid());
        $errors = implode('; ', $ins->getConfigErrors());
        foreach (['trigger form', 'key field specified for this project', 'other project', 'key field specified for the other project', 'nothing to copy'] as $expected) {
            $this->assertStringContainsString($expected, $errors);
        }
    }

    /** Copying into the receiving side's key would re-link or unlink records. */
    public function testCopyingIntoTheReceivingKeyIsRejected(): void
    {
        // Push: the other project's key receives.
        $push = $this->push(['copy-fields' => [['source-field' => 'dataset', 'dest-field' => 'dataset_change']]]);
        $this->assertStringContainsString('"dataset_change" is the key field', implode('; ', $push->getConfigErrors()));
        // Pull: this project's key receives.
        $pull = $this->pull(['copy-fields' => [['source-field' => 'dataset_change', 'dest-field' => 'dataset']]]);
        $this->assertStringContainsString('"dataset_change" is the key field', implode('; ', $pull->getConfigErrors()));
        // A pull may READ the other project's key field into a normal field here.
        $this->assertTrue($this->pull(['copy-fields' => [['source-field' => 'dataset_copy', 'dest-field' => 'dataset']]])->isValid());
    }

    /** Two fields copied into one receiving field: the last one would silently win. */
    public function testDuplicateReceivingFieldIsRejected(): void
    {
        $push = $this->push(['copy-fields' => [
            ['source-field' => 'dsname', 'dest-field' => 'name'],
            ['source-field' => 'dcname', 'dest-field' => 'name'],
        ]]);
        $this->assertStringContainsString('copied into more than once', implode('; ', $push->getConfigErrors()));
        $pull = $this->pull(['copy-fields' => [
            ['source-field' => 'name', 'dest-field' => 'dsname'],
            ['source-field' => 'name', 'dest-field' => 'dcname'],
        ]]);
        $this->assertStringContainsString('copied into more than once', implode('; ', $pull->getConfigErrors()));
    }

    public function testBadNamesAreRejected(): void
    {
        $ins = $this->push(['dest-key-field' => '[dataset]', 'dest-event' => 'Baseline Arm 1']);
        $errors = implode('; ', $ins->getConfigErrors());
        $this->assertStringContainsString('key field in other project "[dataset]"', $errors);
        $this->assertStringContainsString('other project event "Baseline Arm 1"', $errors);
    }

    public function testEmptyCopyRowsLeftInTheDialogAreIgnored(): void
    {
        $ins = $this->push(['copy-fields' => [
            ['source-field' => 'dsname', 'dest-field' => 'dsname'],
            ['source-field' => null, 'dest-field' => null],
        ]]);
        $this->assertTrue($ins->isValid());
        $this->assertCount(1, $ins->copy_fields);
    }

    /** Checkbox settings: never saved = null, unticked = false. Enabled defaults to ticked; unticked must stay off. */
    public function testCheckboxValues(): void
    {
        foreach ([true, 'true', 'TRUE', '1', 1] as $on) $this->assertTrue(UpdateInstruction::isTrue($on), var_export($on, true));
        foreach ([false, 'false', '0', 0, null, '', 'yes'] as $off) $this->assertFalse(UpdateInstruction::isTrue($off), var_export($off, true));
        $this->assertTrue($this->push(['config-enabled' => null])->config_enabled);
        $config = $this->pushConfig();
        unset($config['config-enabled']);
        $this->assertTrue((new UpdateInstruction($config, 0, 26))->config_enabled);
        $this->assertFalse($this->push(['config-enabled' => false])->config_enabled);
        $this->assertFalse($this->push(['config-enabled' => 'false'])->config_enabled);
    }

    /**
     * Imports come in switched off with no other project: a PID from another server (e.g.
     * TEST) may be a different project here, and nothing must run until someone checks it.
     */
    public function testImportBlanksOtherProjectAndSwitchesOff(): void
    {
        $errors = [];
        $row = UpdateInstruction::toCsvRow($this->pushConfig(['dest-event' => 'requests_arm_1', 'other-condition' => "[closed] <> '1'"]));
        $this->assertSame('1', $row[$this->col('enabled')]);
        $this->assertSame('27', $row[$this->col('other_project_id')]);

        $ins = new UpdateInstruction(UpdateInstruction::forImport(UpdateInstruction::fromCsvRow($row, 2, $errors)), 0, 26);
        $this->assertSame([], $errors);
        $this->assertFalse($ins->config_enabled);
        $this->assertSame(0, $ins->other_project_id);
        $this->assertSame([UpdateInstruction::NO_OTHER_PROJECT], $ins->getConfigErrors());
        // Everything else about the other project is kept, ready for when it's chosen.
        $this->assertSame('dataset_change', $ins->other_key_field);
        $this->assertSame('requests_arm_1', $ins->other_event);
        $this->assertSame("[closed] <> '1'", $ins->other_condition);
        $this->assertSame(['steward_name', 'steward_email'], $ins->thereFields());

        // Saved as an empty dropdown and unticked. Null, not '' or "0": REDCap's framework
        // refuses a non-admin setting a project-id to '' (it checks Design rights in "project ''").
        $settings = $ins->getAsModuleSettings();
        $this->assertNull($settings['dest-project']);
        $this->assertFalse($settings['config-enabled']);
    }

    /**
     * "−" on the only instruction clears it (REDCap can't remove the last instance), and a
     * blank instruction counts as deleted. Anything filled in must keep it, so a half-set-up
     * instruction still shows its Issues instead of silently vanishing.
     */
    public function testBlankInstructionCountsAsDeleted(): void
    {
        // As the dialog saves it: '' becomes null; defaults (enabled, direction, boxes) don't count.
        $blank = [
            'config-description' => null, 'config-enabled' => false, 'direction' => 'push', 'trigger-form' => null,
            'trigger-logic' => null, 'source-key-field' => null, 'source-key-event' => null, 'dest-project' => null,
            'dest-key-field' => null, 'dest-event' => null, 'other-condition' => null, 'copy-instrument' => null,
            'instrument-only-if-empty' => false,
            'leave-out' => [['leave-out-field' => null]],
            'copy-fields' => [['source-field' => null, 'dest-field' => null, 'only-if-empty' => false]],
        ];
        $this->assertTrue((new UpdateInstruction($blank, 0, 34))->isBlank());
        $this->assertTrue((new UpdateInstruction([], 0, 34))->isBlank());
        $this->assertTrue((new UpdateInstruction(['config-enabled' => true, 'direction' => 'pull'], 0, 34))->isBlank());

        foreach ([
            'config-description' => 'note', 'trigger-form' => 'participants', 'trigger-logic' => "[x] = '1'",
            'source-key-field' => 'family_group', 'source-key-event' => 'baseline_arm_1', 'dest-project' => '35',
            'dest-key-field' => 'family_group', 'dest-event' => 'baseline_arm_1', 'other-condition' => "[y] <> ''",
            'copy-instrument' => 'participants',
        ] as $key => $value) {
            $this->assertFalse((new UpdateInstruction([$key => $value] + $blank, 0, 34))->isBlank(), $key);
        }
        $this->assertFalse((new UpdateInstruction(['leave-out' => [['leave-out-field' => 'x']]] + $blank, 0, 34))->isBlank(), 'leave-out');
        $this->assertFalse((new UpdateInstruction(['copy-fields' => [['source-field' => 'x']]] + $blank, 0, 34))->isBlank(), 'pair');
        // Only the other project's name filled in: not a valid pair, but not blank either.
        $this->assertFalse((new UpdateInstruction(['copy-fields' => [['source-field' => null, 'dest-field' => 'x']]] + $blank, 0, 34))->isBlank(), 'half pair');
        $this->assertFalse($this->push()->isBlank());
    }

    /** CSV enabled column: 1 = on, 0 = off, blank = the default (on). */
    public function testCsvEnabledColumn(): void
    {
        $row = UpdateInstruction::toCsvRow($this->pushConfig(['config-enabled' => false]));
        $this->assertSame('0', $row[$this->col('enabled')]);
        $errors = [];
        $this->assertFalse(UpdateInstruction::fromCsvRow($row, 2, $errors)['config-enabled']);
        $row[$this->col('enabled')] = '';
        $this->assertTrue(UpdateInstruction::fromCsvRow($row, 2, $errors)['config-enabled']);
        $this->assertSame([], $errors);
    }

    /** The Logging username must say where the data came from, and fit REDCap's 255-character column. */
    public function testLoggingUser(): void
    {
        $this->assertSame('Update Corresponding Records (#1 from PID 26 record 2)', $this->pull()->loggingUser(26, '2'));
        $second = new UpdateInstruction($this->pushConfig(), 1, 26);
        $this->assertSame('Update Corresponding Records (#2 from PID 26 record 1)', $second->loggingUser(26, '1'));
        $this->assertSame(255, mb_strlen($this->push()->loggingUser(26, str_repeat('x', 300))));
    }

    // ---- What gets written -------------------------------------------------

    /** A blank or missing value must map to '' so the receiving field is cleared. */
    public function testBlankValuesClearTheReceivingField(): void
    {
        $this->assertSame(['steward_name' => 'Wonder Woman', 'steward_email' => ''], $this->push()->buildValues(['dsname' => 'Wonder Woman']));
    }

    public function testPullMapsOtherFieldsOntoThisProjectsFields(): void
    {
        $values = $this->pull()->buildValues(['dsname' => 'Astro Boy', 'dsemail' => 'ab@test.com', 'dataset' => '2']);
        $this->assertSame(['steward_name' => 'Astro Boy', 'steward_email' => 'ab@test.com'], $values);
    }

    public function testOnlyChangedValuesAreWritten(): void
    {
        $wanted  = ['dsname' => 'Wonder Woman', 'dsemail' => '', 'ds_position' => '0'];
        $current = ['dsname' => 'Wonder Woman', 'ds_position' => ''];
        // dsemail: blank wanted, missing now = no change. ds_position: '0' isn't blank.
        $this->assertSame(['ds_position' => '0'], UpdateInstruction::changedValues($wanted, $current));
        $this->assertSame([], UpdateInstruction::changedValues(['a' => '1'], ['a' => 1]));
        $this->assertSame(['a' => '01'], UpdateInstruction::changedValues(['a' => '01'], ['a' => '1']));
    }

    // ---- Key matching ------------------------------------------------------

    public function testMatchingIsExactAndSkipsRepeatingData(): void
    {
        $data = [
            '1' => [77 => ['dataset_change' => '1']],
            '2' => [77 => ['dataset_change' => '01']],          // numeric-looking but not equal
            '3' => [77 => ['dataset_change' => '1 ']],          // trailing space
            '4' => [77 => ['dataset_change' => '2']],
            '5' => [77 => ['dataset_change' => '1'], 78 => ['dataset_change' => '1']], // two events
            '6' => ['repeat_instances' => [77 => ['form' => [1 => ['dataset_change' => '1']]]]],
            '7' => [77 => ['other' => '1']],
        ];
        $matches = UpdateInstruction::findMatches($data, 'dataset_change', '1');
        $this->assertSame([['1', 77], ['5', 77], ['5', 78]], array_map(function ($m) { return [$m[0], $m[1]]; }, $matches));
        $this->assertSame(['dataset_change' => '1'], $matches[0][2]);
    }

    /** For pushes, every repeating instance whose key matches is its own match (event and instance kept). */
    public function testMatchingRepeatingInstances(): void
    {
        $data = [
            '23' => [
                82 => ['dataset' => '1', 'dsname' => 'old'],                       // "Most Recent Request"
                'repeat_instances' => [78 => ['aci_data_request_form' => [
                    1 => ['dataset' => '3'],
                    2 => ['dataset' => '1', 'dsname' => 'old'],
                ]]],
            ],
            '24' => ['repeat_instances' => [90 => ['' => [1 => ['dataset' => '1']]]]], // repeating event: form key ''
        ];
        $matches = UpdateInstruction::findMatches($data, 'dataset', '1', true);
        $this->assertSame([
            ['23', 82, null, 1],
            ['23', 78, 'aci_data_request_form', 2],
            ['24', 90, '', 1],
        ], array_map(function ($m) { return [$m[0], $m[1], $m[3], $m[4]]; }, $matches));
        // Without instances (pulls), only the non-repeating match.
        $this->assertCount(1, UpdateInstruction::findMatches($data, 'dataset', '1'));
    }

    public function testBlankKeyMatchesNothing(): void
    {
        $this->assertSame([], UpdateInstruction::findMatches(['1' => [77 => ['k' => '']]], 'k', ''));
    }

    public function testKeyValuesWithQuotesMatch(): void
    {
        $value = "O'Brien \"BIRD\"";
        $data = ['3' => [77 => ['k' => $value]], '4' => [77 => ['k' => "O'Brien"]]];
        $this->assertCount(1, UpdateInstruction::findMatches($data, 'k', $value));
    }

    /** \' inside quoted logic fails in REDCap v15.9.3 and v17.0.0, so use the other quote type. */
    public function testPreFilterAvoidsBackslashQuotes(): void
    {
        $this->assertSame("[k] = '1'", UpdateCorrespondingRecords::equalsFilter('k', '1'));
        $this->assertSame("[k] = \"O'Brien\"", UpdateCorrespondingRecords::equalsFilter('k', "O'Brien"));
        $this->assertSame("[k] = 'say \"hi\"'", UpdateCorrespondingRecords::equalsFilter('k', 'say "hi"'));
        $this->assertNull(UpdateCorrespondingRecords::equalsFilter('k', "O'Brien \"BIRD\""));
    }

    // ---- Settings shapes ---------------------------------------------------

    /** The shape validateSettings() receives from ExternalModules::formatRawSettings(). */
    public function testConfigsFromRawSettings(): void
    {
        $raw = [
            'update-config'      => ['true', 'true'],
            'config-description' => ['update steward', null],
            'config-enabled'     => [true, false],
            'direction'          => ['push', 'pull'],
            'trigger-form'       => ['datsets', 'datsets'],
            'dest-project'       => ['27', '31'],
            'dest-key-field'     => ['dataset_change', 'dataset_id'],
            'source-key-field'   => ['dataset', 'dataset'],
            'copy-fields'        => [['true', 'true'], ['true']],
            'source-field'       => [['dsname', 'dsemail'], ['dcname']],
            'dest-field'         => [['dsname', 'dsemail'], ['dcname']],
        ];
        $configs = UpdateInstruction::configsFromRawSettings($raw);
        $this->assertCount(2, $configs);
        $this->assertSame('31', $configs[1]['dest-project']);
        $this->assertSame([['source-field' => 'dcname', 'dest-field' => 'dcname', 'only-if-empty' => null]], $configs[1]['copy-fields']);
        $second = new UpdateInstruction($configs[1], 1, 26);
        $this->assertFalse($second->config_enabled);
        $this->assertTrue($second->isPull());
        $this->assertSame(2, $second->sequence);
    }

    public function testConfigsFromRawSettingsWithNoInstructions(): void
    {
        $this->assertSame([], UpdateInstruction::configsFromRawSettings(['log-changes' => true]));
    }

    /** Import saves this shape; the project-id dropdown only shows a string value as selected. */
    public function testModuleSettingsShape(): void
    {
        $settings = $this->pull()->getAsModuleSettings();
        $this->assertSame('pull', $settings['direction']);
        $this->assertSame('26', $settings['dest-project']);
        $this->assertSame(['true', 'true'], $settings['copy-fields']);
        $this->assertSame(['steward_name', 'steward_email'], $settings['source-field']); // this project's fields
        $this->assertSame(['dsname', 'dsemail'], $settings['dest-field']);              // other project's fields
    }

    // ---- CSV ---------------------------------------------------------------

    /**
     * Writes a row as export() does and reads it back with parseCsv() (as import does), so
     * quoting of commas, quotes, backslashes and newlines is covered.
     */
    private function throughCsv(array $row): array
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $row, ',', '"', '');
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return UpdateInstruction::parseCsv($csv)[0];
    }

    /** Config pairs as they come back from a CSV: with their "only if empty" flag (default false). */
    private function withFlag(array $pairs, bool $onlyIfEmpty = false): array
    {
        return array_map(function ($pair) use ($onlyIfEmpty) { return $pair + ['only-if-empty' => $onlyIfEmpty]; }, $pairs);
    }

    /** Position of a CSV column, so tests don't break when columns are added. */
    private function col(string $name): int
    {
        return array_search($name, UpdateInstruction::CSV_COLUMNS, true);
    }

    public function testPushCsvRoundTrip(): void
    {
        // A backslash before a quote is where PHP's default CSV escaping would break the round trip.
        $config = $this->pushConfig(['config-description' => "Steward, \"main\"\nsecond line, C:\\\"temp\\\"", 'trigger-logic' => "[ds_status] = '1'"]);
        $row = UpdateInstruction::toCsvRow($config);
        $this->assertCount(count(UpdateInstruction::CSV_COLUMNS), $row);
        $this->assertSame('push', $row[$this->col('direction')]);
        $this->assertSame('dsname:steward_name;dsemail:steward_email', $row[$this->col('copy_fields')]); // from:to

        $errors = [];
        $back = UpdateInstruction::fromCsvRow($this->throughCsv($row), 2, $errors);
        $this->assertSame([], $errors);
        $this->assertSame($config['config-description'], $back['config-description']);
        $this->assertSame($config['trigger-logic'], $back['trigger-logic']);
        $this->assertSame($this->withFlag($config['copy-fields']), $back['copy-fields']);
        $this->assertSame('27', $back['dest-project']);
    }

    /** A pull's pairs read other:here in the CSV and must come back the right way round. */
    public function testPullCsvRoundTrip(): void
    {
        $config = $this->pullConfig();
        $row = UpdateInstruction::toCsvRow($config);
        $this->assertSame('pull', $row[$this->col('direction')]);
        $this->assertSame('dsname:steward_name;dsemail:steward_email', $row[$this->col('copy_fields')]); // from (other) : to (here)

        $errors = [];
        $back = UpdateInstruction::fromCsvRow($this->throughCsv($row), 2, $errors);
        $this->assertSame([], $errors);
        $this->assertSame('pull', $back['direction']);
        $this->assertSame($this->withFlag($config['copy-fields']), $back['copy-fields']);
        $this->assertSame($this->pull()->copy_fields, (new UpdateInstruction($back, 0, 27))->copy_fields);
    }

    /** Imported CSV text: Excel's byte order mark, doubled quotes, newlines in a value, blank lines, user delimiter. */
    public function testParseCsv(): void
    {
        $csv = "\xEF\xBB\xBFdescription,enabled\n\"Steward, \"\"main\"\"\nline 2\",1\n\n\"next\",0\n";
        $this->assertSame([
            ['description', 'enabled'],
            ["Steward, \"main\"\nline 2", '1'],
            ['next', '0'],
        ], UpdateInstruction::parseCsv($csv));
        $this->assertSame([['a', 'b c'], ['1', '2']], UpdateInstruction::parseCsv("a;b c\n1;2", ';'));
        $this->assertSame([], UpdateInstruction::parseCsv(''));
    }

    public function testCsvRowErrors(): void
    {
        $row = UpdateInstruction::toCsvRow($this->pushConfig());
        $row[$this->col('direction')] = 'sideways';
        $row[$this->col('copy_fields')] = 'dsname;dsemail:steward_email;:x';
        $errors = [];
        $config = UpdateInstruction::fromCsvRow($row, 4, $errors);
        $this->assertSame([
            'Row 4: direction must be push or pull.',
            'Row 4: invalid copy field pair ":x" (expected field or from:to).',
        ], $errors);
        // A field on its own means the same name in both projects.
        $this->assertSame($this->withFlag([
            ['source-field' => 'dsname', 'dest-field' => 'dsname'],
            ['source-field' => 'dsemail', 'dest-field' => 'steward_email'],
        ]), $config['copy-fields']);
    }

    // ---- Copying an entire instrument, leave-outs, same names ----------

    /** A blank "Field name in other project" means the same variable name there. */
    public function testBlankOtherFieldMeansSameName(): void
    {
        $ins = $this->push(['copy-fields' => [['source-field' => 'dsname', 'dest-field' => '']]]);
        $this->assertTrue($ins->isValid(), implode('; ', $ins->getConfigErrors()));
        $this->assertSame([['here' => 'dsname', 'there' => 'dsname', 'only_if_empty' => false]], $ins->copy_fields);
        // ...and is saved blank again, so the dialog keeps showing "same name".
        $this->assertSame([''], $ins->getAsModuleSettings()['dest-field']);
    }

    /** An instrument alone is enough to copy; nothing at all is reported (but doesn't block saving). */
    public function testInstrumentWithoutFieldsIsValid(): void
    {
        $this->assertTrue($this->push(['copy-instrument' => 'datsets', 'copy-fields' => []])->isValid());
        $nothing = $this->push(['copy-instrument' => '', 'copy-fields' => []]);
        $this->assertStringContainsString('nothing to copy', implode('; ', $nothing->getConfigErrors()));
    }

    // ---- Condition in the other project ----------------------------------

    /** Names in the other project's condition are checked against that project's fields and events. */
    public function testLogicNames(): void
    {
        $this->assertSame(['processed_steward'], UpdateInstruction::logicNames("[processed_steward] <> '1'"));
        $this->assertSame(['baseline_arm_1', 'status', 'regions'],
            UpdateInstruction::logicNames("[baseline_arm_1][status] = '2' and [regions(3)] = '1' and [record-name] <> ''"));
        // Text inside quotes isn't a field reference.
        $this->assertSame(['note'], UpdateInstruction::logicNames("[note] = 'see [other] field'"));
        $this->assertSame([], UpdateInstruction::logicNames(''));
    }

    public function testOtherConditionRoundTrips(): void
    {
        $config = $this->pushConfig(['other-condition' => "[processed_steward] <> '1'"]);
        $this->assertSame("[processed_steward] <> '1'", (new UpdateInstruction($config, 0, 26))->other_condition);
        $row = UpdateInstruction::toCsvRow($config);
        $this->assertSame("[processed_steward] <> '1'", $row[$this->col('other_condition')]);
        $errors = [];
        $back = UpdateInstruction::fromCsvRow($this->throughCsv($row), 2, $errors);
        $this->assertSame("[processed_steward] <> '1'", $back['other-condition']);
        $this->assertSame("[processed_steward] <> '1'", (new UpdateInstruction($back, 0, 26))->getAsModuleSettings()['other-condition']);
        $raw = ['update-config' => ['true'], 'other-condition' => ["[x] = '1'"]];
        $this->assertSame("[x] = '1'", UpdateInstruction::configsFromRawSettings($raw)[0]['other-condition']);
    }

    /**
     * getSubSettings() returns an EMPTY repeatable group as ['source-field' => [], ...] instead
     * of []. This crashed every save of an instrument-only instruction before it was handled.
     */
    public function testEmptyGroupsFromREDCapAreHandled(): void
    {
        $ins = $this->push([
            'copy-instrument' => 'datsets',
            'copy-fields'     => ['source-field' => [], 'dest-field' => []],
            'leave-out'       => ['leave-out-field' => []],
        ]);
        $this->assertTrue($ins->isValid(), implode('; ', $ins->getConfigErrors()));
        $this->assertSame([], $ins->copy_fields);
        $this->assertSame([], $ins->leave_out);
    }

    /** Empty groups are saved as one blank row; with none, REDCap's dialog can't add a row. */
    public function testEmptyGroupsSavedAsOneBlankRow(): void
    {
        $settings = $this->push(['copy-instrument' => 'datsets', 'copy-fields' => [], 'leave-out' => []])->getAsModuleSettings();
        $this->assertSame(['true'], $settings['leave-out']);
        $this->assertSame([null], $settings['leave-out-field']);
        $this->assertSame(['true'], $settings['copy-fields']);
        $this->assertSame([null], $settings['source-field']);
        $this->assertSame([null], $settings['dest-field']);
        $this->assertSame([null], $settings['only-if-empty']);
    }

    public function testLeaveOutList(): void
    {
        // From getSubSettings() and from CSV parsing; blanks and duplicates dropped.
        $this->assertSame(['a', 'b'], UpdateInstruction::leaveOutList([['leave-out-field' => 'a'], ['leave-out-field' => ''], ['leave-out-field' => 'b'], ['leave-out-field' => 'a']]));
        $this->assertSame(['a', 'b'], UpdateInstruction::leaveOutList(['a', ' b ', '']));
        $this->assertSame([], UpdateInstruction::leaveOutList(null));
    }

    /** Only the configured pairs are saved; the instrument's fields are worked out each run. */
    public function testResolvedInstrumentFieldsAreNotSaved(): void
    {
        $ins = $this->push(['copy-instrument' => 'datsets', 'leave-out' => ['date_of_proxy']]);
        $ins->setResolvedPairs(array_merge($ins->explicit_pairs, [['here' => 'ds_position', 'there' => 'ds_position']]));
        $this->assertCount(3, $ins->copy_fields);
        $settings = $ins->getAsModuleSettings();
        $this->assertSame(['dsname', 'dsemail'], $settings['source-field']);
        $this->assertSame('datsets', $settings['copy-instrument']);
        $this->assertSame(['true'], $settings['leave-out']);
        $this->assertSame(['date_of_proxy'], $settings['leave-out-field']);
    }

    public function testInstrumentCsvRoundTrip(): void
    {
        $config = $this->pushConfig([
            'copy-instrument' => 'datsets',
            'leave-out'       => [['leave-out-field' => 'date_of_proxy'], ['leave-out-field' => 'dsemail']],
            'copy-fields'     => [['source-field' => 'dataset_name', 'dest-field' => 'request_dataset_name'], ['source-field' => 'dsname', 'dest-field' => '']],
        ]);
        $row = UpdateInstruction::toCsvRow($config);
        $this->assertSame('datsets', $row[$this->col('instrument')]);
        $this->assertSame('date_of_proxy;dsemail', $row[$this->col('leave_out')]);
        $this->assertSame('dataset_name:request_dataset_name;dsname', $row[$this->col('copy_fields')]);

        $errors = [];
        $back = UpdateInstruction::fromCsvRow($this->throughCsv($row), 2, $errors);
        $this->assertSame([], $errors);
        $ins = new UpdateInstruction($back, 0, 26);
        $this->assertSame('datsets', $ins->copy_instrument);
        $this->assertSame(['date_of_proxy', 'dsemail'], $ins->leave_out);
        $this->assertSame([['here' => 'dataset_name', 'there' => 'request_dataset_name', 'only_if_empty' => false], ['here' => 'dsname', 'there' => 'dsname', 'only_if_empty' => false]], $ins->copy_fields);
    }

    public function testConfigsFromRawSettingsWithInstrument(): void
    {
        $raw = [
            'update-config'   => ['true'],
            'direction'       => ['push'],
            'copy-instrument' => ['datsets'],
            'leave-out'       => [['true', 'true']],
            'leave-out-field' => [['date_of_proxy', 'dsemail']],
            'copy-fields'     => [[]],
        ];
        $config = UpdateInstruction::configsFromRawSettings($raw)[0];
        $this->assertSame('datsets', $config['copy-instrument']);
        $this->assertSame(['date_of_proxy', 'dsemail'], $config['leave-out']);
    }

    /**
     * Which of an instrument's fields are copied, and why the rest aren't. Uses stand-ins for
     * REDCap's Project class: PID 26 (this project, instrument datsets) and PID 27.
     */
    public function testResolveInstrumentForPush(): void
    {
        $meta = function ($form, $type = 'text', $misc = '', $validation = '', $enum = '') {
            return ['element_type' => $type, 'form_name' => $form, 'misc' => $misc, 'element_validation_type' => $validation, 'element_enum' => $enum];
        };
        $regions = '1, North \n 2, South \n 3, East';
        $here = $this->project([
            'dataset'          => $meta('datsets'),
            'survey_link'      => $meta('datsets', 'descriptive'),
            'dsname'           => $meta('datsets'),
            'dsemail'          => $meta('datsets'),
            'ds_position'      => $meta('datsets'),
            'dcname'           => $meta('datsets'),
            'date_of_proxy'    => $meta('datsets'),
            'proxy_form'       => $meta('datsets', 'file'),
            'ds_signature'     => $meta('datsets', 'file', '', 'signature'),
            'regions'          => $meta('datsets', 'checkbox', '', '', $regions),
            'sites'            => $meta('datsets', 'checkbox', '', '', '1, Newcastle \n 2, Maitland'),
            'score'            => $meta('datsets', 'calc'),
            'label'            => $meta('datsets', 'text', '@CALCTEXT([x])'),
            'lookup'           => $meta('datsets', 'sql'),
            'only_here'        => $meta('datsets'),
            'datsets_complete' => $meta('datsets', 'select'),
        ], [77 => ['datsets']], [], 'dataset');
        $here->forms = ['datsets' => ['fields' => array_fill_keys(array_keys($here->metadata), '')]];
        $other = $this->project([
            'record_id'        => $meta('requests'),
            'dataset_change'   => $meta('requests', 'calc'),
            'dsname'           => $meta('requests'),
            'dsemail'          => $meta('requests'),
            'ds_position'      => $meta('requests', 'checkbox', '', '', '1, Yes'),
            'dcname'           => $meta('requests', 'calc'),
            'date_of_proxy'    => $meta('requests'),
            // Same choices plus an extra one: fine. Choice 2 relabelled: not copied.
            'regions'          => $meta('requests', 'checkbox', '', '', '1, north \n 2,  South \n 3, East \n 4, West'),
            'sites'            => $meta('requests', 'checkbox', '', '', '1, Newcastle \n 2, Tamworth'),
        ], [78 => ['requests']]);

        $ins = $this->push([
            'copy-instrument' => 'datsets',
            'leave-out'       => ['date_of_proxy'],
            'copy-fields'     => [['source-field' => 'dsemail', 'dest-field' => 'steward_email']],
        ]);
        $this->callModule('resolveInstrument', $ins, $here, $other);

        // dsemail: covered by its own pair (different name), not copied twice. date_of_proxy: left out.
        $this->assertSame([
            ['here' => 'dsemail', 'there' => 'steward_email', 'only_if_empty' => false],
            ['here' => 'dsname', 'there' => 'dsname', 'only_if_empty' => false],
            ['here' => 'regions', 'there' => 'regions', 'only_if_empty' => false],
        ], $ins->copy_fields);
        $this->assertSame([
            'dataset'          => 'record ID',
            'survey_link'      => 'descriptive',
            'ds_position'      => "checkbox doesn't match pid 27: only one side is a multiple-answer checkbox",
            'dcname'           => 'calculated in pid 27',
            'proxy_form'       => 'file upload',
            'ds_signature'     => 'signature',
            'sites'            => "checkbox doesn't match pid 27: choice 2 is \"Maitland\" in the copied-from field but \"Tamworth\" in the receiving field",
            'score'            => 'calculated',
            'label'            => '@CALCTEXT',
            'lookup'           => 'SQL',
            'only_here'        => 'no same name in pid 27',
            'datsets_complete' => 'Complete? status',
        ], $ins->not_copied);

        // The instrument's "only if empty" applies to its own fields, not to "Also copy" pairs.
        $onlyIfEmpty = $this->push([
            'copy-instrument'          => 'datsets',
            'instrument-only-if-empty' => true,
            'leave-out'                => ['date_of_proxy'],
            'copy-fields'              => [['source-field' => 'dsemail', 'dest-field' => 'steward_email']],
        ]);
        $this->callModule('resolveInstrument', $onlyIfEmpty, $here, $other);
        $this->assertSame([
            ['here' => 'dsemail', 'there' => 'steward_email', 'only_if_empty' => false],
            ['here' => 'dsname', 'there' => 'dsname', 'only_if_empty' => true],
            ['here' => 'regions', 'there' => 'regions', 'only_if_empty' => true],
        ], $onlyIfEmpty->copy_fields);
        $this->assertSame(['dsname', 'regions'], $onlyIfEmpty->onlyIfEmptyFields());

        // The receiving checkbox's codes (including its extra choice 4) are handed to the instruction.
        $this->callModule('setCheckboxCodes', $onlyIfEmpty, $here, $other);
        $this->assertSame(['regions' => ['1', '2', '3', '4']], $onlyIfEmpty->checkbox_codes);
    }

    /**
     * Pushing into a form that repeats in the other event, matched on the record ID: the
     * module can't tell which instance to update, so the fields aren't copied, and the
     * reason says so (not "multiple-answer checkbox" for a checkbox that's merely repeating).
     */
    public function testInstrumentFieldsRepeatingInOtherEvent(): void
    {
        $meta = function ($type = 'text', $enum = '') {
            return ['element_type' => $type, 'form_name' => 'visit', 'misc' => '', 'element_enum' => $enum];
        };
        $fields = ['record_id' => $meta(), 'visit_date' => $meta(), 'symptoms' => $meta('checkbox', '1, Cough \n 2, Fever'), 'scan' => $meta('file')];
        $proj = $this->project($fields, [92 => ['visit'], 93 => ['visit']], ['92:visit', '93:visit']);
        $proj->forms = ['visit' => ['fields' => array_fill_keys(array_keys($fields), '')]];
        $ins = $this->push(['trigger-form' => 'visit', 'source-key-field' => 'record_id', 'dest-project' => '26',
            'dest-key-field' => 'record_id', 'copy-instrument' => 'visit', 'copy-fields' => []]);
        $this->callModule('resolveInstrument', $ins, $proj, $proj);
        $this->assertSame([], $ins->copy_fields);
        $this->assertSame([
            'record_id'  => 'record ID',
            'visit_date' => 'on a repeating instrument or event in pid 26',
            'symptoms'   => 'on a repeating instrument or event in pid 26',
            'scan'       => 'file upload',
        ], $ins->not_copied);
    }

    // ---- Multiple-answer checkboxes ----------------------------------------

    public function testParseChoices(): void
    {
        $this->assertSame(['1' => 'Anxiety', '2' => 'Depression', '98' => 'Other, please specify'],
            UpdateInstruction::parseChoices('1, Anxiety \n 2, Depression \n 98, Other, please specify'));
        $this->assertSame(['0' => 'No', '1' => 'Yes'], UpdateInstruction::parseChoices("0, No\n1, Yes"));
        $this->assertSame([], UpdateInstruction::parseChoices(''));
    }

    /** Codes must mean the same answer on both sides, or a ticked box would change meaning. */
    public function testCheckboxProblem(): void
    {
        $from = ['1' => 'Anxiety', '2' => 'Depression'];
        $this->assertNull(UpdateInstruction::checkboxProblem(null, null));
        $this->assertNull(UpdateInstruction::checkboxProblem($from, $from));
        $this->assertNull(UpdateInstruction::checkboxProblem($from, ['1' => ' anxiety', '2' => '<b>Depression</b>', '3' => 'Other']));
        $this->assertSame('only one side is a multiple-answer checkbox', UpdateInstruction::checkboxProblem($from, null));
        $this->assertSame('only one side is a multiple-answer checkbox', UpdateInstruction::checkboxProblem(null, $from));
        $this->assertSame('choice 2 ("Depression") is missing from the receiving field', UpdateInstruction::checkboxProblem($from, ['1' => 'Anxiety']));
        $this->assertSame('choice 2 is "Depression" in the copied-from field but "Low mood" in the receiving field',
            UpdateInstruction::checkboxProblem($from, ['1' => 'Anxiety', '2' => 'Low mood']));
    }

    /**
     * A checkbox is copied as a whole set: the receiving field ends up exactly like the source,
     * including unticking, and only the choices that change are written.
     */
    public function testCheckboxValuesAsAWholeSet(): void
    {
        $ins = $this->push(['copy-fields' => [
            ['source-field' => 'comorb', 'dest-field' => 'comorb'],
            ['source-field' => 'dsname', 'dest-field' => 'steward_name'],
        ]]);
        $ins->setCheckboxCodes(['comorb' => ['1', '2', '3', '98']]); // receiving field has an extra choice 98
        $wanted = $ins->buildValues(['comorb' => ['1' => '1', '2' => '0', '3' => '1'], 'dsname' => 'A']);
        $this->assertSame(['comorb' => ['1' => '1', '2' => '0', '3' => '1', '98' => '0'], 'steward_name' => 'A'], $wanted);

        // Receiving: 2 and 98 ticked, 3 unticked -> tick 1 and 3, untick 2 and 98.
        $current = ['comorb' => ['1' => '0', '2' => '1', '3' => '0', '98' => '1'], 'steward_name' => 'A'];
        $this->assertSame(['comorb' => ['1' => '1', '2' => '0', '3' => '1', '98' => '0']], $ins->valuesToWrite($wanted, $current));
        // Already the same: nothing written.
        $this->assertSame([], $ins->valuesToWrite($wanted, ['comorb' => $wanted['comorb'], 'steward_name' => 'A']));
        // Only the choice that differs.
        $this->assertSame(['comorb' => ['3' => '1']],
            $ins->valuesToWrite($wanted, ['comorb' => ['1' => '1', '2' => '0', '3' => '0', '98' => '0'], 'steward_name' => 'A']));

        // Nothing ticked in the source unticks everything there (like a blank clearing a field).
        $none = $ins->buildValues(['comorb' => ['1' => '0', '2' => '0', '3' => '0'], 'dsname' => '']);
        $this->assertSame(['comorb' => ['2' => '0', '98' => '0'], 'steward_name' => ''], $ins->valuesToWrite($none, $current));
        // Missing from the source record at all: same as nothing ticked.
        $this->assertSame(['1' => '0', '2' => '0', '3' => '0', '98' => '0'], $ins->buildValues([])['comorb']);
    }

    /** "Only if empty" for a checkbox: empty = no choice ticked; otherwise the whole field is left alone. */
    public function testCheckboxOnlyIfEmpty(): void
    {
        $ins = $this->pull(['copy-fields' => [['source-field' => 'comorb', 'dest-field' => 'comorb', 'only-if-empty' => true]]]);
        $ins->setCheckboxCodes(['comorb' => ['1', '2']]);
        $wanted = $ins->buildValues(['comorb' => ['1' => '1', '2' => '0']]);
        $this->assertSame([], $ins->valuesToWrite($wanted, ['comorb' => ['1' => '0', '2' => '1']]));
        $this->assertSame(['comorb' => ['1' => '1']], $ins->valuesToWrite($wanted, ['comorb' => ['1' => '0', '2' => '0']]));
        $this->assertTrue(UpdateInstruction::isEmptyValue(['1' => '0', '2' => '0']));
        $this->assertFalse(UpdateInstruction::isEmptyValue(['1' => '0', '2' => '1']));
        $this->assertTrue(UpdateInstruction::isEmptyValue(''));
        $this->assertFalse(UpdateInstruction::isEmptyValue('0'));
    }

    // ---- Copy only if destination field empty ------------------------------

    /** Push: filled fields in the other project are left alone; 0 counts as a value; blanks never written over them. */
    public function testOnlyIfEmptyPush(): void
    {
        $ins = $this->push(['copy-fields' => [
            ['source-field' => 'dsname',  'dest-field' => 'steward_name', 'only-if-empty' => true],
            ['source-field' => 'dsemail', 'dest-field' => 'steward_email'],
            ['source-field' => 'dscount', 'dest-field' => 'steward_count', 'only-if-empty' => '1'],
            ['source-field' => 'dsnote',  'dest-field' => 'steward_note', 'only-if-empty' => true],
        ]]);
        $this->assertSame(['steward_name', 'steward_count', 'steward_note'], $ins->onlyIfEmptyFields());
        $wanted = $ins->buildValues(['dsname' => 'New', 'dsemail' => 'new@example.org', 'dscount' => '5', 'dsnote' => '']);
        $there = ['steward_name' => 'Old', 'steward_email' => 'old@example.org', 'steward_count' => '0', 'steward_note' => 'kept'];
        // Only the overwrite field changes; the filled "only if empty" fields (including 0) don't,
        // and a blank source doesn't clear a filled "only if empty" field.
        $this->assertSame(['steward_email' => 'new@example.org'], $ins->valuesToWrite($wanted, $there));
        // Empty (or missing) receiving fields are filled.
        $this->assertSame(
            ['steward_name' => 'New', 'steward_email' => 'new@example.org', 'steward_count' => '5'],
            $ins->valuesToWrite($wanted, ['steward_note' => ''])
        );
    }

    /** Pull: "only if empty" is checked in the saved record (this project's field). */
    public function testOnlyIfEmptyPull(): void
    {
        $ins = $this->pull(['copy-fields' => [
            ['source-field' => 'steward_name',  'dest-field' => 'dsname', 'only-if-empty' => true],
            ['source-field' => 'steward_email', 'dest-field' => 'dsemail'],
        ]]);
        $this->assertSame(['steward_name'], $ins->onlyIfEmptyFields());
        $wanted = $ins->buildValues(['dsname' => 'From other', 'dsemail' => 'other@example.org']);
        $this->assertSame(['steward_email' => 'other@example.org'],
            $ins->valuesToWrite($wanted, ['steward_name' => 'Typed here', 'steward_email' => '']));
        $this->assertSame(['steward_name' => 'From other', 'steward_email' => 'other@example.org'],
            $ins->valuesToWrite($wanted, []));
    }

    /** Unticked (never saved = null, or false) means overwrite, as before this option existed. */
    public function testOnlyIfEmptyDefaultsToOverwrite(): void
    {
        $ins = $this->push(['copy-fields' => [
            ['source-field' => 'dsname',  'dest-field' => 'steward_name', 'only-if-empty' => null],
            ['source-field' => 'dsemail', 'dest-field' => 'steward_email', 'only-if-empty' => false],
        ]]);
        $this->assertSame([], $ins->onlyIfEmptyFields());
        $this->assertSame(['steward_name' => '', 'steward_email' => 'b'],
            $ins->valuesToWrite(['steward_name' => '', 'steward_email' => 'b'], ['steward_name' => 'a', 'steward_email' => 'a']));
        // The instrument option is ignored with no instrument.
        $this->assertFalse($this->push(['instrument-only-if-empty' => true])->instrument_only_if_empty);
    }

    public function testOnlyIfEmptyCsvRoundTrip(): void
    {
        $config = $this->pushConfig([
            'copy-instrument'          => 'datsets',
            'instrument-only-if-empty' => true,
            'copy-fields'              => [
                ['source-field' => 'dsname',  'dest-field' => 'steward_name'],
                ['source-field' => 'dsemail', 'dest-field' => 'steward_email', 'only-if-empty' => true],
            ],
        ]);
        $row = UpdateInstruction::toCsvRow($config);
        $this->assertCount(count(UpdateInstruction::CSV_COLUMNS), $row);
        $this->assertSame('1', $row[$this->col('instrument_only_if_empty')]);
        $this->assertSame('0;1', $row[$this->col('copy_fields_only_if_empty')]);

        $errors = [];
        $back = UpdateInstruction::fromCsvRow($this->throughCsv($row), 2, $errors);
        $this->assertSame([], $errors);
        $ins = new UpdateInstruction($back, 0, 26);
        $this->assertTrue($ins->instrument_only_if_empty);
        $this->assertSame(['steward_email'], $ins->onlyIfEmptyFields());
        $settings = $ins->getAsModuleSettings();
        $this->assertTrue($settings['instrument-only-if-empty']);
        $this->assertSame([false, true], $settings['only-if-empty']);

        // All overwrite: both columns blank, as Copy Data on Save writes them.
        $plain = UpdateInstruction::toCsvRow($this->pushConfig());
        $this->assertSame('', $plain[$this->col('instrument_only_if_empty')]);
        $this->assertSame('', $plain[$this->col('copy_fields_only_if_empty')]);
    }

    /** A CSV exported before the two columns existed still imports, with every field overwriting. */
    public function testOlderCsvWithoutOnlyIfEmptyColumns(): void
    {
        $row = array_slice(UpdateInstruction::toCsvRow($this->pushConfig()), 0, UpdateInstruction::CSV_MIN_COLUMNS);
        $errors = [];
        $ins = new UpdateInstruction(UpdateInstruction::fromCsvRow($row, 2, $errors), 0, 26);
        $this->assertSame([], $errors);
        $this->assertTrue($ins->isValid());
        $this->assertSame([], $ins->onlyIfEmptyFields());
    }

    public function testOnlyIfEmptyCsvErrors(): void
    {
        $row = UpdateInstruction::toCsvRow($this->pushConfig()); // two pairs
        $row[$this->col('copy_fields_only_if_empty')] = '1';
        $row[$this->col('instrument_only_if_empty')] = 'yes';
        $errors = [];
        $config = UpdateInstruction::fromCsvRow($row, 3, $errors);
        $this->assertSame([
            'Row 3: copy_fields_only_if_empty has 1 values but copy_fields has 2 pairs.',
            'Row 3: instrument_only_if_empty must be 1, 0 or blank.',
        ], $errors);
        $this->assertSame([false, false], array_column($config['copy-fields'], 'only-if-empty'));

        $row[$this->col('copy_fields_only_if_empty')] = '1;y';
        $row[$this->col('instrument_only_if_empty')] = '';
        $errors = [];
        UpdateInstruction::fromCsvRow($row, 3, $errors);
        $this->assertSame(['Row 3: copy_fields_only_if_empty values must be 1 or 0.'], $errors);
    }

    /** Raw settings (validateSettings) carry both flags through. */
    public function testOnlyIfEmptyFromRawSettings(): void
    {
        $raw = [
            'update-config'            => ['true'],
            'direction'                => ['push'],
            'copy-instrument'          => ['datsets'],
            'instrument-only-if-empty' => [true],
            'copy-fields'              => [['true', 'true']],
            'source-field'             => [['dsname', 'dsemail']],
            'dest-field'               => [['', '']],
            'only-if-empty'            => [[false, true]],
        ];
        $config = UpdateInstruction::configsFromRawSettings($raw)[0];
        $this->assertTrue($config['instrument-only-if-empty']);
        $ins = new UpdateInstruction($config + $this->pushConfig(), 0, 26);
        $this->assertSame(['dsemail'], $ins->onlyIfEmptyFields());
    }

    // ---- Locked forms and records -----------------------------------------
    // Locked data is left alone (expected, not a failure) and the rest is still written.
    // REDCap's saveData() would reject the whole record's save, so getting this wrong either
    // overwrites locked data or silently drops unlocked changes.

    /** A project stand-in for locking: visit (repeating in event 92), contact (not repeating). */
    private function lockingProject(): object
    {
        $field = function ($form) { return ['element_type' => 'text', 'form_name' => $form]; };
        return $this->project(
            ['visit_date' => $field('visit'), 'symptoms' => ['element_type' => 'checkbox', 'form_name' => 'visit'], 'phone' => $field('contact')],
            [92 => ['visit', 'contact']],
            ['92:visit']
        );
    }

    public function testNothingLockedWritesEverything(): void
    {
        $plan = $this->callModule('buildSaveData', $this->lockingProject(), '7', 92, ['visit_date' => '2026-11-11', 'phone' => '0400'], [], null, 1);
        $this->assertSame(['7' => [92 => ['visit_date' => '2026-11-11', 'phone' => '0400']]], $plan['data']);
        $this->assertSame(['visit_date' => '2026-11-11', 'phone' => '0400'], $plan['written']);
        $this->assertSame([], $plan['locked']);
    }

    /** One locked form: its fields are left out, the other form's fields are still written. */
    public function testLockedFormLeftOutOthersWritten(): void
    {
        $values = ['visit_date' => '2026-11-11', 'symptoms' => ['1' => '1', '2' => '0'], 'phone' => '0400'];
        $plan = $this->callModule('buildSaveData', $this->lockingProject(), '7', 92, $values, ['contact:1' => true], null, 1);
        $this->assertSame(['phone'], $plan['locked']);
        $this->assertSame(['7' => [92 => ['visit_date' => '2026-11-11', 'symptoms' => ['1' => '1', '2' => '0']]]], $plan['data']);
        $this->assertArrayNotHasKey('phone', $plan['written']);
    }

    /** Record-level locking: nothing is written at all. */
    public function testLockedRecordWritesNothing(): void
    {
        $plan = $this->callModule('buildSaveData', $this->lockingProject(), '7', 92, ['visit_date' => '2026-11-11', 'phone' => '0400'], null, null, 1);
        $this->assertSame([], $plan['data']);
        $this->assertSame([], $plan['written']);
        $this->assertSame(['visit_date', 'phone'], $plan['locked']);
    }

    /**
     * Locking is per instance: instance 3 of a repeating form can be locked while instance 1
     * isn't (and the other way round). A non-repeating form in the same event is instance 1.
     */
    public function testLockingIsPerInstance(): void
    {
        $proj = $this->lockingProject();
        $values = ['visit_date' => '2026-11-11', 'phone' => '0400'];

        // Instance 3 locked: its field is left out; the non-repeating form still goes in normally.
        $plan = $this->callModule('buildSaveData', $proj, '7', 92, $values, ['visit:3' => true], 'visit', 3);
        $this->assertSame(['visit_date'], $plan['locked']);
        $this->assertSame(['7' => [92 => ['phone' => '0400']]], $plan['data']);

        // Only instance 1 locked: writing into instance 3 goes ahead, in the repeating branch.
        $plan = $this->callModule('buildSaveData', $proj, '7', 92, $values, ['visit:1' => true], 'visit', 3);
        $this->assertSame([], $plan['locked']);
        $this->assertSame([
            '7' => [
                'repeat_instances' => [92 => ['visit' => [3 => ['visit_date' => '2026-11-11']]]],
                92 => ['phone' => '0400'],
            ],
        ], $plan['data']);

        // A repeating event ('' key): every field is in the instance, so "contact:3" is what counts.
        $plan = $this->callModule('buildSaveData', $proj, '7', 92, $values, ['contact:1' => true], '', 3);
        $this->assertSame([], $plan['locked']);
        $plan = $this->callModule('buildSaveData', $proj, '7', 92, $values, ['contact:3' => true], '', 3);
        $this->assertSame(['phone'], $plan['locked']);
        $this->assertSame(['7' => ['repeat_instances' => [92 => ['' => [3 => ['visit_date' => '2026-11-11']]]]]], $plan['data']);
    }

    /**
     * A field whose form isn't used in the event is left out (not locked, not an error):
     * otherwise REDCap rejects the whole save, e.g. when a record-ID key matches every event.
     */
    public function testFieldsNotDesignatedForEventAreLeftOut(): void
    {
        $field = function ($form) { return ['element_type' => 'text', 'form_name' => $form]; };
        $proj = $this->project(['sex' => $field('demographics'), 'covid_19' => $field('survey')], [92 => ['demographics', 'survey'], 93 => ['visit']]);
        $plan = $this->callModule('buildSaveData', $proj, '1', 93, ['covid_19' => '1'], [], null, 1);
        $this->assertSame(['data' => [], 'written' => [], 'locked' => []], $plan);
        $plan = $this->callModule('buildSaveData', $proj, '1', 92, ['covid_19' => '1'], [], null, 1);
        $this->assertSame(['1' => [92 => ['covid_19' => '1']]], $plan['data']);
    }

    /** lockedForms() reads the record-level lock first (by the event's arm), then the form locks. */
    public function testLockedFormsQueries(): void
    {
        $module = new UpdateCorrespondingRecords();
        $ref = new \ReflectionMethod(UpdateCorrespondingRecords::class, 'lockedForms');
        $ref->setAccessible(true);

        // Whole record locked: null, and the form locks aren't even read.
        $module->testQueryResults = [[['1' => 1]]];
        $this->assertNull($ref->invoke($module, 27, '3', 78));
        $this->assertCount(1, $module->testQueries);
        $this->assertSame([27, '3', 78], $module->testQueries[0][1]);
        $this->assertStringContainsString('redcap_locking_records', $module->testQueries[0][0]);

        // Form locks, keyed form:instance (a missing or 0 instance counts as 1).
        $module->testQueries = [];
        $module->testQueryResults = [[], [
            ['form_name' => 'visit', 'instance' => '3'],
            ['form_name' => 'contact', 'instance' => '1'],
            ['form_name' => 'consent', 'instance' => null],
        ]];
        $this->assertSame(['visit:3' => true, 'contact:1' => true, 'consent:1' => true], $ref->invoke($module, 27, '3', 78));
        $this->assertSame([27, '3', 78], $module->testQueries[1][1]);
        $this->assertStringContainsString('redcap_locking_data', $module->testQueries[1][0]);

        // Nothing locked.
        $module->testQueryResults = [[], []];
        $this->assertSame([], $ref->invoke($module, 27, '3', 78));
    }

    // ---- Field checks (with a stand-in for REDCap's Project class) -------

    private function project(array $metadata, array $eventsForms, array $repeating = [], string $pk = 'record_id'): object
    {
        return new class($metadata, $eventsForms, $repeating, $pk) {
            public $metadata; public $eventsForms; public $table_pk; public $forms = []; private $repeating;
            public function __construct($m, $e, $r, $pk) { $this->metadata = $m; $this->eventsForms = $e; $this->repeating = $r; $this->table_pk = $pk; }
            public function isRepeatingFormOrEvent($eventId, $form) { return in_array("$eventId:$form", $this->repeating, true); }
        };
    }

    public function testFieldChecks(): void
    {
        $proj = $this->project([
            'dsname'  => ['element_type' => 'text', 'form_name' => 'requests'],
            'tick'    => ['element_type' => 'checkbox', 'form_name' => 'requests'],
            'visit'   => ['element_type' => 'text', 'form_name' => 'visits'],
        ], [78 => ['requests'], 79 => ['requests', 'visits']], ['79:visits']);

        $this->assertSame([], $this->callModule('fieldIssues', $proj, 'dsname', 'field'));
        $this->assertSame(['field "nope" not found in pid 27'], $this->callModule('fieldIssues', $proj, 'nope', 'field', 'pid 27'));
        $this->assertStringContainsString('multiple-answer checkbox field', $this->callModule('fieldIssues', $proj, 'tick', 'field')[0]);
        $this->assertStringContainsString('repeating instrument or event', $this->callModule('fieldIssues', $proj, 'visit', 'field')[0]);
    }

    /**
     * Repeating rules: this project's trigger form may repeat (the saved instance is used);
     * the other project's side may only repeat outside the "Other project event" in use.
     */
    public function testRepeatingFieldRules(): void
    {
        // PID 27-like: request form repeats in "Requests" (78), not in "Most Recent" (82).
        $proj = $this->project([
            'record_id' => ['element_type' => 'text', 'form_name' => 'request'],
            'dsname'    => ['element_type' => 'text', 'form_name' => 'request'],
            'note'      => ['element_type' => 'text', 'form_name' => 'notes'],
        ], [78 => ['request', 'notes'], 82 => ['request']], ['78:request', '78:notes'], 'record_id');

        // Trigger form fields may repeat; other repeating forms may not.
        $this->assertSame([], $this->callModule('fieldIssues', $proj, 'dsname', 'field', '', null, 'request'));
        $this->assertStringContainsString('repeating', $this->callModule('fieldIssues', $proj, 'note', 'field', '', null, 'request')[0]);
        // Other side: fine in the non-repeating event, an issue (with a hint) when no event is set.
        $this->assertSame([], $this->callModule('fieldIssues', $proj, 'dsname', 'field', 'pid 27', 82));
        $this->assertStringContainsString('repeating', $this->callModule('fieldIssues', $proj, 'dsname', 'field', 'pid 27', 78)[0]);
        // The record ID is never a repeating problem.
        $this->assertSame([], $this->callModule('fieldIssues', $proj, 'record_id', 'key field', 'pid 27', 78));
    }

    /** Copying into the record ID or a calculated field would corrupt records or be recalculated away. */
    public function testReceivingFieldChecks(): void
    {
        $proj = $this->project([
            'record_id'  => ['element_type' => 'text', 'form_name' => 'requests', 'misc' => ''],
            'score'      => ['element_type' => 'calc', 'form_name' => 'requests', 'misc' => ''],
            'label'      => ['element_type' => 'text', 'form_name' => 'requests', 'misc' => '@CALCTEXT([x])'],
            'dsname'     => ['element_type' => 'text', 'form_name' => 'requests', 'misc' => '@READONLY'],
        ], [78 => ['requests']]);

        $this->assertStringContainsString('record ID', $this->callModule('receivingFieldIssues', $proj, 'record_id', '')[0]);
        $this->assertStringContainsString('calculated field', $this->callModule('receivingFieldIssues', $proj, 'score', '')[0]);
        $this->assertStringContainsString('calculated field', $this->callModule('receivingFieldIssues', $proj, 'label', '')[0]);
        $this->assertSame([], $this->callModule('receivingFieldIssues', $proj, 'dsname', ''));
    }

    // ---- Instructions the user can't change (no Design rights in the other project) ----

    /** Instructions in the parallel-array shape REDCap saves and validateSettings() receives. */
    private function rawSettings(UpdateInstruction ...$instructions): array
    {
        $settings = ['update-config' => []];
        foreach ($instructions as $idx => $instruction) {
            $settings['update-config'][$idx] = 'true';
            foreach ($instruction->getAsModuleSettings() as $key => $value) $settings[$key][$idx] = $value;
        }
        return $settings;
    }

    /** PID 35 (Design rights) with instructions to itself and to PID 34 (no Design rights). */
    private function lockModule(array $saved): LockTestModule
    {
        $module = new LockTestModule();
        $module->testProjectId = 35;
        $module->testProjectSettings = $saved;
        return $module;
    }

    private function toSelf(array $overrides = []): UpdateInstruction
    {
        return new UpdateInstruction($this->pushConfig(array_merge(['config-description' => 'same project', 'dest-project' => '35'], $overrides)), 0, 35);
    }

    private function toParticipants(array $overrides = []): UpdateInstruction
    {
        return new UpdateInstruction($this->pushConfig(array_merge(['config-description' => 'to participants', 'dest-project' => '34'], $overrides)), 0, 35);
    }

    /** The dialog sends blanks as null and ticked boxes as true; the fingerprint must still match. */
    public function testFingerprintIgnoresHowValuesAreSent(): void
    {
        $saved = $this->pushConfig(['trigger-logic' => null, 'config-description' => "two\r\nlines"]);
        $sent  = $this->pushConfig(['trigger-logic' => '', 'config-description' => "two\nlines", 'dest-project' => 27]);
        $this->assertSame(UpdateInstruction::fingerprint($saved), UpdateInstruction::fingerprint($sent));
        // An empty "Also copy" row left in the dialog changes nothing.
        $sent['copy-fields'][] = ['source-field' => null, 'dest-field' => null, 'only-if-empty' => false];
        $this->assertSame(UpdateInstruction::fingerprint($saved), UpdateInstruction::fingerprint($sent));
    }

    /** Any change to what an instruction does must change the fingerprint. */
    public function testFingerprintChangesWithEverySetting(): void
    {
        $base = UpdateInstruction::fingerprint($this->pushConfig());
        $changes = [
            ['config-description' => 'renamed'], ['config-enabled' => false], ['direction' => 'pull'],
            ['trigger-form' => 'other'], ['trigger-logic' => "[x] = '1'"], ['source-key-field' => 'other'],
            ['source-key-event' => 'event_1_arm_1'], ['dest-project' => '28'], ['dest-key-field' => 'other'],
            ['dest-event' => 'event_1_arm_1'], ['other-condition' => "[y] = '1'"], ['copy-instrument' => 'form_1'],
            ['copy-fields' => [['source-field' => 'dsname', 'dest-field' => 'steward_name']]],
            ['copy-fields' => [['source-field' => 'dsname', 'dest-field' => 'steward_name', 'only-if-empty' => true],
                               ['source-field' => 'dsemail', 'dest-field' => 'steward_email']]],
        ];
        foreach ($changes as $change) {
            $this->assertNotSame($base, UpdateInstruction::fingerprint($this->pushConfig($change)), json_encode($change));
        }
        $withInstrument = $this->pushConfig(['copy-instrument' => 'form_1']);
        $this->assertNotSame(UpdateInstruction::fingerprint($withInstrument),
            UpdateInstruction::fingerprint($withInstrument + ['instrument-only-if-empty' => true]));
        $this->assertNotSame(UpdateInstruction::fingerprint($withInstrument),
            UpdateInstruction::fingerprint($withInstrument + ['leave-out' => ['dsname']]));
    }

    /** Only instructions whose other project exists and lacks Design rights are locked; never for admins. */
    public function testWhichInstructionsAreLocked(): void
    {
        $module = $this->lockModule([]);
        $this->assertFalse($module->isLockedForUser($this->toSelf()));
        $this->assertTrue($module->isLockedForUser($this->toParticipants()));
        $this->assertFalse($module->isLockedForUser($this->toSelf(['dest-project' => '36'])), 'Design rights there');
        $this->assertFalse($module->isLockedForUser($this->toSelf(['dest-project' => '99'])), 'deleted project: can be repointed');
        $this->assertFalse($module->isLockedForUser($this->toSelf(['dest-project' => ''])));
        $module->testSuperUser = true;
        $this->assertFalse($module->isLockedForUser($this->toParticipants()));
    }

    /** Saving the dialog with the read-only instruction sent back unchanged is allowed. */
    public function testUnchangedLockedInstructionSaves(): void
    {
        $saved = $this->rawSettings($this->toSelf(), $this->toParticipants());
        $module = $this->lockModule($saved);
        $this->assertNull($module->validateSettings($saved));
        // Editing the instruction the user can change is fine too.
        $this->assertNull($module->validateSettings($this->rawSettings($this->toSelf(['trigger-logic' => "[x] = '1'"]), $this->toParticipants())));
        // So is deleting it, which moves the read-only one up.
        $this->assertNull($module->validateSettings($this->rawSettings($this->toParticipants())));
        // And adding a new one after it.
        $this->assertNull($module->validateSettings($this->rawSettings($this->toSelf(), $this->toParticipants(), $this->toSelf(['config-description' => 'new']))));
    }

    /** Editing or deleting the read-only instruction, or pointing a new one at that project, is blocked. */
    public function testChangedOrDeletedLockedInstructionIsBlocked(): void
    {
        $module = $this->lockModule($this->rawSettings($this->toSelf(), $this->toParticipants()));

        $edited = $module->validateSettings($this->rawSettings($this->toSelf(), $this->toParticipants(['config-enabled' => false])));
        $this->assertStringContainsString('settings not saved', $edited);
        $this->assertStringContainsString("can't be changed or deleted", $edited);
        $this->assertStringContainsString('pid 34', $edited);

        $deleted = $module->validateSettings($this->rawSettings($this->toSelf()));
        $this->assertStringContainsString('#2 (to participants) can\'t be changed or deleted', $deleted);

        $copied = $module->validateSettings($this->rawSettings($this->toSelf(), $this->toParticipants(), $this->toParticipants(['config-description' => 'new'])));
        $this->assertStringContainsString('Update Configuration 3: you need Project Design rights in the other project', $copied);

        // Two identical read-only instructions: each saved one is matched once, so dropping one is caught.
        $twice = $this->lockModule($this->rawSettings($this->toParticipants(), $this->toParticipants()));
        $this->assertNull($twice->validateSettings($this->rawSettings($this->toParticipants(), $this->toParticipants())));
        $this->assertStringContainsString("can't be changed or deleted", $twice->validateSettings($this->rawSettings($this->toParticipants())));
    }

    /** Admins can change anything. */
    public function testAdminCanChangeLockedInstruction(): void
    {
        $module = $this->lockModule($this->rawSettings($this->toSelf(), $this->toParticipants()));
        $module->testSuperUser = true;
        $this->assertNull($module->validateSettings($this->rawSettings($this->toSelf())));
    }

    /** A never-saved Enabled is saved as ticked, so the dialog shows (and resaves) it as it runs. */
    public function testRepairTicksNeverSavedEnabled(): void
    {
        $saved = $this->rawSettings($this->toSelf(), $this->toParticipants());
        $saved['config-enabled'] = [null, false];
        $module = $this->lockModule($saved);
        $module->callRepair(35);
        $this->assertSame([true, false], $module->savedSettings['config-enabled']);

        // A blank (deleted) instruction is left alone; nothing to save means no write.
        $blank = $this->rawSettings($this->toSelf());
        $blank['config-enabled'] = [true];
        $module = $this->lockModule($blank);
        $module->callRepair(35);
        $this->assertNull($module->savedSettings);
    }
}

/**
 * The module with REDCap's lookups replaced: PIDs 34, 35 and 36 exist, and the user has
 * Project Design rights in 35 and 36 only. Saved settings are captured, not written.
 */
class LockTestModule extends UpdateCorrespondingRecords
{
    public $savedSettings = null;

    public function userHasDesignRights($pid): bool
    {
        return $this->isSuperUser() || in_array((int) $pid, [35, 36], true);
    }

    protected function getProj($pid)
    {
        return in_array((int) $pid, [34, 35, 36], true) ? new \stdClass() : null;
    }

    protected function projectTitle($pid): string
    {
        return "Project {$pid}";
    }

    public function setProjectSettings($settings, $pid = null)
    {
        $this->savedSettings = $settings;
    }

    public function callRepair(int $pid): void
    {
        $this->repairEmptyGroups($pid);
    }
}
