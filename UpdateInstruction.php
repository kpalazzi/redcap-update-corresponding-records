<?php
/**
 * REDCap External Module: Update Corresponding Records
 * Push field values to every matching record in another project, or pull them in from a
 * matching record in another project, when a form is saved.
 * @author Kerrin Palazzi, Hunter Medical Research Institute
 */
namespace HMRI\UpdateCorrespondingRecords;

/**
 * One "Update Configuration" instance from the module settings.
 *
 * Every instruction belongs to the project it's set up in ("this project") and runs when
 * its trigger form is saved there. A push copies this project's values to every matching
 * record in the other project; a pull copies the other project's values into the saved
 * record. Setting keys predate directions, so "source-*" settings hold this project's
 * values and "dest-*" settings hold the other project's, whichever way data flows.
 *
 * This class is deliberately free of REDCap calls so it can be unit tested without a
 * REDCap database. It checks the *structure* of an instruction; checks that need project
 * metadata or user rights live in the module class.
 */
class UpdateInstruction {
    const PUSH = 'push';
    const PULL = 'pull';

    /** CSV column order used by export and import - keep CSV_COLUMNS, toCsvRow() and fromCsvRow() in sync */
    const CSV_COLUMNS = array(
        'description','enabled','direction','trigger_form','trigger_logic',
        'key_field','key_event','other_project_id','other_key_field','other_event','other_condition',
        'instrument','leave_out','copy_fields',
        'instrument_only_if_empty','copy_fields_only_if_empty',
    );
    /** The structure error for an instruction with no other project (e.g. just imported). */
    const NO_OTHER_PROJECT = 'no other project specified';

    /** CSVs exported before the "only if empty" columns were added have just the first 14 columns. */
    const CSV_MIN_COLUMNS = 14;

    public $sequence;            // 1-based position in this project's list of instructions
    public $project_id;          // this project (null when unknown, e.g. CSV parsing)
    public $config_description;
    public $config_enabled;
    public $direction;           // 'push' or 'pull'
    public $trigger_form;
    public $trigger_logic;
    public $key_field;           // in this project          (setting: source-key-field)
    public $key_event;           // in this project          (setting: source-key-event)
    public $other_project_id;    //                          (setting: dest-project)
    public $other_key_field;     // in the other project     (setting: dest-key-field)
    public $other_event;         // in the other project     (setting: dest-event)
    public $other_condition;     // logic checked on each matching record in the other project (setting: other-condition)
    public $copy_instrument;     // instrument in this project whose fields are all copied ('' = none)
    public $instrument_only_if_empty; // the instrument's fields are only copied into empty fields
    public $leave_out;           // fields on that instrument not to copy
    public $explicit_pairs;      // "Also copy" pairs as configured: ['here' => ..., 'there' => ..., 'only_if_empty' => bool]
    public $copy_fields;         // pairs actually copied: the explicit pairs, plus the instrument's
                                 // fields once the module has resolved them (setResolvedPairs())
    public $not_copied = array();// instrument fields that aren't copied: field => reason
    public $checkbox_codes = array(); // receiving multiple-answer checkbox field => its choice codes (set by the module)
    protected $config_errors;

    public function __construct(array $config, int $index, $projectId = null) {
        $this->sequence = $index + 1;
        $this->project_id = $projectId === null ? null : (int) $projectId;
        $this->config_errors = array();

        $this->config_description = (string) ($config['config-description'] ?? '');
        $enabled                  = $config['config-enabled'] ?? null;
        $this->config_enabled     = $enabled === null || $enabled === '' || self::isTrue($enabled); // never saved = ticked (the dialog's default)
        $direction                = strtolower(trim((string) ($config['direction'] ?? '')));
        $this->direction          = $direction === self::PULL ? self::PULL : self::PUSH; // never saved = push (the dialog's default)
        $this->trigger_form       = trim((string) ($config['trigger-form'] ?? ''));
        $this->trigger_logic      = trim((string) ($config['trigger-logic'] ?? ''));
        $this->key_field          = trim((string) ($config['source-key-field'] ?? ''));
        $this->key_event          = trim((string) ($config['source-key-event'] ?? ''));
        $this->other_project_id   = (int) ($config['dest-project'] ?? 0);
        $this->other_key_field    = trim((string) ($config['dest-key-field'] ?? ''));
        $this->other_event        = trim((string) ($config['dest-event'] ?? ''));
        $this->other_condition    = trim((string) ($config['other-condition'] ?? ''));
        $this->copy_instrument    = trim((string) ($config['copy-instrument'] ?? ''));
        // Only means something with an instrument; a value left over after the instrument
        // was cleared is ignored.
        $this->instrument_only_if_empty = $this->copy_instrument !== '' && self::isTrue($config['instrument-only-if-empty'] ?? false);
        $this->leave_out         = self::leaveOutList($config['leave-out'] ?? array());
        $this->copy_fields        = array();

        if ($direction !== '' && $direction !== self::PUSH && $direction !== self::PULL) {
            $this->config_errors[] = "direction \"{$direction}\" must be push or pull";
        }
        if ($this->trigger_form === '') {
            $this->config_errors[] = "no trigger form specified";
        }
        if ($this->key_field === '') {
            $this->config_errors[] = "no key field specified for this project";
        }
        if ($this->other_project_id <= 0) {
            $this->config_errors[] = self::NO_OTHER_PROJECT;
        }
        if ($this->other_key_field === '') {
            $this->config_errors[] = "no key field specified for the other project";
        } elseif (!self::isFieldName($this->other_key_field)) {
            $this->config_errors[] = "key field in other project \"{$this->other_key_field}\" isn't a valid variable name";
        }
        foreach (array('key_event' => 'key field event', 'other_event' => 'other project event') as $prop => $label) {
            if ($this->$prop !== '' && !self::isFieldName($this->$prop)) {
                $this->config_errors[] = "$label \"{$this->$prop}\" isn't a valid unique event name";
            }
        }

        // The key on the receiving side must never be overwritten: that would re-link
        // (or unlink) records as a side effect.
        $receivingKey = $this->isPull() ? $this->key_field : $this->other_key_field;
        $receivingSeen = array();
        $number = 0;
        foreach ((array) ($config['copy-fields'] ?? array()) as $key => $pair) {
            // getSubSettings() returns an empty group as ['source-field' => [], 'dest-field' => []]
            // rather than an empty list, so only numbered rows are pairs.
            if (!is_int($key) || !is_array($pair)) continue;
            $number++;
            $here  = trim((string) ($pair['source-field'] ?? ''));
            $there = trim((string) ($pair['dest-field'] ?? ''));
            $label = "copy field #{$number}";

            if ($here === '' && $there === '') continue; // empty row left in the dialog
            if ($here === '')  { $this->config_errors[] = "$label: missing field in this project"; continue; }
            if ($there === '') $there = $here; // blank = the same variable name in the other project
            if (!self::isFieldName($there)) {
                $this->config_errors[] = "$label: field in other project \"{$there}\" isn't a valid variable name";
                continue;
            }
            $receiving = $this->isPull() ? $here : $there;
            if ($receiving === $receivingKey) {
                $this->config_errors[] = "$label: \"{$receiving}\" is the key field it would be copied into";
                continue;
            }
            if (isset($receivingSeen[$receiving])) {
                $this->config_errors[] = "$label: \"{$receiving}\" is copied into more than once";
                continue;
            }
            $receivingSeen[$receiving] = true;
            $this->copy_fields[] = array('here' => $here, 'there' => $there, 'only_if_empty' => self::isTrue($pair['only-if-empty'] ?? false));
        }
        $this->explicit_pairs = $this->copy_fields;

        if ($this->copy_instrument === '' && empty($this->copy_fields)) {
            $this->config_errors[] = "nothing to copy: choose an instrument to copy, or at least one field";
        }
    }

    /**
     * The pairs actually copied once the module has worked out which of the instrument's
     * fields qualify (it needs both projects' metadata to do that). Explicit pairs keep
     * priority: the module leaves out instrument fields an explicit pair already covers.
     */
    public function setResolvedPairs(array $pairs, array $notCopied = array()): void {
        $this->copy_fields = $pairs;
        $this->not_copied = $notCopied;
    }

    /**
     * Leave-out field names, from getSubSettings() ([['leave-out-field' => 'x'], ...]) or
     * from CSV parsing (['x', ...]); blanks and duplicates removed.
     */
    public static function leaveOutList($raw): array {
        $fields = array();
        foreach ((array) $raw as $key => $row) {
            if (!is_int($key)) continue; // an empty group comes back as ['leave-out-field' => []]
            $field = is_array($row) ? ($row['leave-out-field'] ?? '') : $row;
            if (is_string($field) && trim($field) !== '') $fields[] = trim($field);
        }
        return array_values(array_unique($fields));
    }

    /**
     * Variable and unique event names used in REDCap logic: [field], [field(code)],
     * [event_name][field]. Smart variables ([record-name], [user-name], ...) contain a
     * hyphen, so they're never returned. Quoted text is ignored.
     */
    public static function logicNames(string $logic): array {
        $withoutText = preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $logic);
        preg_match_all('/\[([a-z][a-z0-9_]*)(?:\([^)\]]*\))?\]/', (string) $withoutText, $m);
        return array_values(array_unique($m[1]));
    }

    /** Checkbox settings arrive as true/false, "true"/"false", 1/0 or null (never saved). */
    public static function isTrue($value): bool {
        return $value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower(trim($value)) === 'true');
    }

    /** REDCap variable names and unique event names: lowercase letters, digits and underscores. */
    public static function isFieldName(string $name): bool {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    /**
     * Nothing filled in, as after "−" on the only instruction (REDCap can't delete the last
     * instance of a repeating group, so the dialog clears it instead). A blank instruction
     * counts as deleted: it doesn't run, isn't listed and isn't exported. Enabled, direction
     * and the "only if empty" boxes don't count, since they have defaults.
     */
    public function isBlank(): bool {
        return $this->config_description === '' && $this->trigger_form === '' && $this->trigger_logic === ''
            && $this->key_field === '' && $this->key_event === '' && $this->other_project_id <= 0
            && $this->other_key_field === '' && $this->other_event === '' && $this->other_condition === ''
            && $this->copy_instrument === '' && empty($this->leave_out) && empty($this->explicit_pairs)
            && empty($this->pairErrors());
    }

    /**
     * Errors about "Also copy" rows. A row with only the other project's name filled in is
     * one of these (it isn't in explicit_pairs), so it stops the instruction counting as blank.
     */
    private function pairErrors(): array {
        return array_filter($this->config_errors, function ($e) { return strpos($e, 'copy field #') === 0; });
    }

    public function isPull(): bool {
        return $this->direction === self::PULL;
    }

    public function isValid(): bool {
        return empty($this->config_errors);
    }

    public function getConfigErrors(): array {
        return $this->config_errors;
    }

    public function triggersOnForm(string $instrument): bool {
        return $this->trigger_form === $instrument;
    }

    public function hereFields(): array {
        return array_column($this->copy_fields, 'here');
    }

    public function thereFields(): array {
        return array_column($this->copy_fields, 'there');
    }

    /** Fields values are copied from (this project's for a push, the other project's for a pull). */
    public function fromFields(): array {
        return $this->isPull() ? $this->thereFields() : $this->hereFields();
    }

    /** Fields values are copied into. */
    public function toFields(): array {
        return $this->isPull() ? $this->hereFields() : $this->thereFields();
    }

    /**
     * Everything an instruction does, as one string, so a saved instruction and the same
     * instruction sent back by the Configure dialog compare equal (used to check that an
     * instruction the user can't change wasn't changed). Values are compared as the module
     * reads them: trimmed, blank = not set, never-saved Enabled = ticked, line endings ignored.
     * Takes a config from configsFromRawSettings() or getSubSettings().
     */
    public static function fingerprint(array $config): string {
        $ins = new self($config, 0);
        $pairs = array();
        foreach ((array) ($config['copy-fields'] ?? array()) as $key => $pair) {
            if (!is_int($key) || !is_array($pair)) continue;
            $here  = trim((string) ($pair['source-field'] ?? ''));
            $there = trim((string) ($pair['dest-field'] ?? ''));
            if ($here === '' && $there === '') continue; // empty row left in the dialog
            $pairs[] = array($here, $there, self::isTrue($pair['only-if-empty'] ?? false));
        }
        return json_encode(array(
            str_replace("\r\n", "\n", $ins->config_description), $ins->config_enabled, $ins->direction,
            $ins->trigger_form, $ins->trigger_logic, $ins->key_field, $ins->key_event,
            $ins->other_project_id, $ins->other_key_field, $ins->other_event, $ins->other_condition,
            $ins->copy_instrument, $ins->instrument_only_if_empty, $ins->leave_out, $pairs,
        ));
    }

    /** "#1 (update steward)" - used in logs, alerts and the summary page */
    public function label(): string {
        $desc = trim(preg_replace('/\s+/', ' ', $this->config_description));
        return "#{$this->sequence}".($desc !== '' ? " ({$desc})" : '');
    }

    /**
     * Shown as the Username of the data change in REDCap's Logging and Data History, so a
     * change made by this module says where it came from, e.g.
     * "Update Corresponding Records (#1 from PID 26 record 2)". The number is the
     * instruction's position in the project it's set up in. Log usernames hold 255 characters.
     */
    public function loggingUser(int $fromProjectId, string $fromRecord): string {
        return mb_substr("Update Corresponding Records (#{$this->sequence} from PID {$fromProjectId} record {$fromRecord})", 0, 255);
    }

    /** Receiving multiple-answer checkbox fields and their choice codes, worked out by the module. */
    public function setCheckboxCodes(array $codes): void {
        $this->checkbox_codes = $codes;
    }

    /**
     * Map the copied-from values onto the copied-to field names.
     * A missing or blank value maps to '' so the receiving field is cleared too.
     * A multiple-answer checkbox (getData() gives code => '1'/'0') is copied as a whole set:
     * every choice of the receiving field is ticked if it's ticked where it's copied from,
     * and unticked otherwise, so the receiving field ends up exactly the same.
     * @param array $fromValues copied-from field => value
     * @return array copied-to field => value (code => '1'/'0' for a checkbox)
     */
    public function buildValues(array $fromValues): array {
        $values = array();
        $to = $this->toFields();
        foreach ($this->fromFields() as $i => $from) {
            $value = $fromValues[$from] ?? '';
            if (isset($this->checkbox_codes[$to[$i]]) || is_array($value)) {
                $codes = $this->checkbox_codes[$to[$i]] ?? array_keys((array) $value);
                $set = array();
                foreach ($codes as $code) {
                    $set[(string) $code] = is_array($value) && (string) ($value[$code] ?? '0') === '1' ? '1' : '0';
                }
                $values[$to[$i]] = $set;
            } else {
                $values[$to[$i]] = (string) $value;
            }
        }
        return $values;
    }

    /**
     * Keep only the values that differ from what the receiving record already holds,
     * so unchanged records aren't written (or logged) at all. For a checkbox, only the
     * choices that change are kept (saveData() leaves the others as they are).
     */
    public static function changedValues(array $wanted, array $current): array {
        $changed = array();
        foreach ($wanted as $field => $value) {
            $now = $current[$field] ?? null;
            if (is_array($value)) {
                $codes = array();
                foreach ($value as $code => $ticked) {
                    $was = is_array($now) ? (string) ($now[$code] ?? '0') : '0';
                    if ($was !== (string) $ticked) $codes[(string) $code] = (string) $ticked;
                }
                if (!empty($codes)) $changed[$field] = $codes;
            } elseif ((is_array($now) ? '' : (string) $now) !== (string) $value) {
                $changed[$field] = (string) $value;
            }
        }
        return $changed;
    }

    /** Empty receiving field: no value, or for a checkbox, no choice ticked. */
    public static function isEmptyValue($value): bool {
        if (is_array($value)) return !in_array('1', array_map('strval', $value), true);
        return (string) $value === '';
    }

    /**
     * A field's choices from its metadata element_enum ("1, Anxiety \n 2, Depression"; the
     * separator is a literal \n as REDCap stores it, or a newline).
     * @return array code => label
     */
    public static function parseChoices(string $enum): array {
        $choices = array();
        foreach (preg_split('/\\\\n|\n/', $enum) as $line) {
            $parts = explode(',', $line, 2);
            if (count($parts) < 2 || trim($parts[0]) === '') continue;
            $choices[trim($parts[0])] = trim($parts[1]);
        }
        return $choices;
    }

    /**
     * Why a multiple-answer checkbox can't be copied between these two fields, or null if
     * it can (or neither is a checkbox). The receiving field needs every choice the
     * copied-from field has, with the same label (case, spacing and formatting ignored), so
     * a ticked choice always means the same answer on both sides. It may have extra choices.
     * @param array|null $fromChoices code => label, or null if the copied-from field isn't a checkbox
     * @param array|null $toChoices   the same for the receiving field
     */
    public static function checkboxProblem(?array $fromChoices, ?array $toChoices): ?string {
        if ($fromChoices === null && $toChoices === null) return null;
        if ($fromChoices === null || $toChoices === null) {
            return "only one side is a multiple-answer checkbox";
        }
        $plain = function ($label) {
            return strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $label), ENT_QUOTES))));
        };
        foreach ($fromChoices as $code => $label) {
            if (!array_key_exists((string) $code, $toChoices)) {
                return "choice {$code} (\"{$label}\") is missing from the receiving field";
            }
            if ($plain($label) !== $plain($toChoices[(string) $code])) {
                return "choice {$code} is \"{$label}\" in the copied-from field but \"{$toChoices[(string) $code]}\" in the receiving field";
            }
        }
        return null;
    }

    /** Copied-to fields marked "Copy only if destination field empty (don't update)". */
    public function onlyIfEmptyFields(): array {
        $fields = array();
        foreach ($this->copy_fields as $pair) {
            if (!empty($pair['only_if_empty'])) $fields[] = $this->isPull() ? $pair['here'] : $pair['there'];
        }
        return $fields;
    }

    /**
     * The values to write into a receiving record: those that differ from what it holds,
     * minus "only if empty" fields that already have a value there (0 counts as a value).
     */
    public function valuesToWrite(array $wanted, array $current): array {
        $changed = self::changedValues($wanted, $current);
        foreach ($this->onlyIfEmptyFields() as $field) {
            if (!self::isEmptyValue($current[$field] ?? '')) unset($changed[$field]);
        }
        return $changed;
    }

    /**
     * Find every record/event whose $field exactly equals $value in REDCap::getData()
     * 'array' output. Matching is done here rather than with filterLogic so key values
     * containing quotes match safely in every REDCap version. Repeating data (the
     * 'repeat_instances' branch) is included only when $withInstances is true (pushes):
     * then each repeating instance whose $field matches is a match of its own.
     * @return array list of [record, event_id, row, repeat_key, instance]; repeat_key is the
     *               form name ('' for a repeating event) or null for non-repeating data
     */
    public static function findMatches(array $data, string $field, string $value, bool $withInstances = false): array {
        $matches = array();
        if ($value === '') return $matches;
        foreach ($data as $record => $events) {
            if (!is_array($events)) continue;
            foreach ($events as $eventId => $row) {
                if ($eventId === 'repeat_instances' || !is_array($row)) continue;
                if (array_key_exists($field, $row) && (string) $row[$field] === $value) {
                    $matches[] = array((string) $record, $eventId, $row, null, 1);
                }
            }
            if (!$withInstances) continue;
            foreach ((array) ($events['repeat_instances'] ?? array()) as $eventId => $forms) {
                foreach ((array) $forms as $repeatKey => $instances) {
                    foreach ((array) $instances as $instance => $row) {
                        if (is_array($row) && array_key_exists($field, $row) && (string) $row[$field] === $value) {
                            $matches[] = array((string) $record, $eventId, $row, (string) $repeatKey, (int) $instance);
                        }
                    }
                }
            }
        }
        return $matches;
    }

    /**
     * Raw settings as passed to validateSettings() (ExternalModules::formatRawSettings
     * output: one array per key, indexed by instance; nested copy-fields doubly indexed)
     * turned into one config array per Update Configuration instance.
     */
    public static function configsFromRawSettings(array $settings): array {
        $configs = array();
        $keys = array('config-description','config-enabled','direction','trigger-form','trigger-logic','source-key-field',
                      'source-key-event','dest-project','dest-key-field','dest-event','other-condition','copy-instrument',
                      'instrument-only-if-empty');
        foreach (array_keys((array) ($settings['update-config'] ?? array())) as $i) {
            $config = array();
            foreach ($keys as $key) {
                $config[$key] = $settings[$key][$i] ?? null;
            }
            $config['leave-out'] = array();
            foreach (array_keys((array) ($settings['leave-out'][$i] ?? array())) as $j) {
                $config['leave-out'][] = $settings['leave-out-field'][$i][$j] ?? null;
            }
            $config['copy-fields'] = array();
            foreach (array_keys((array) ($settings['copy-fields'][$i] ?? array())) as $j) {
                $config['copy-fields'][] = array(
                    'source-field' => $settings['source-field'][$i][$j] ?? null,
                    'dest-field'   => $settings['dest-field'][$i][$j] ?? null,
                    'only-if-empty' => $settings['only-if-empty'][$i][$j] ?? null,
                );
            }
            $configs[] = $config;
        }
        return $configs;
    }

    /**
     * Rows of CSV text (as uploaded for import): a UTF-8 byte order mark is ignored, quotes
     * are doubled to escape them (as Excel writes them), and blank lines are skipped.
     * @return array list of rows, each a list of values
     */
    public static function parseCsv(string $csv, string $delimiter = ','): array {
        if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) $csv = substr($csv, 3);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $rows = array();
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '"')) !== false) {
            if ($row === array(null) || (count($row) === 1 && trim((string) $row[0]) === '')) continue;
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * One CSV row (values in CSV_COLUMNS order) from a getSubSettings() config.
     * copy_fields lists "from:to" pairs in the direction values are copied, or just the
     * field when it has the same name in both projects. leave_out is ";"-separated.
     * copy_fields_only_if_empty lists 1/0 for each pair in the same order, left blank when
     * every pair can overwrite (as Copy Data on Save's copy-fields-ifempty column).
     */
    public static function toCsvRow(array $config): array {
        $pull = strtolower(trim((string) ($config['direction'] ?? ''))) === self::PULL;
        $pairs = array();
        $ifEmpty = array();
        foreach (($config['copy-fields'] ?? array()) as $key => $pair) {
            if (!is_int($key) || !is_array($pair)) continue; // empty group shape from getSubSettings()
            $here = trim((string) ($pair['source-field'] ?? ''));
            $there = trim((string) ($pair['dest-field'] ?? ''));
            if ($here === '' && $there === '') continue;
            if ($there === '' || $there === $here) {
                $pairs[] = $here;
            } else {
                $pairs[] = $pull ? "{$there}:{$here}" : "{$here}:{$there}";
            }
            $ifEmpty[] = self::isTrue($pair['only-if-empty'] ?? false) ? '1' : '0';
        }
        $leaveOut = self::leaveOutList($config['leave-out'] ?? array());
        $instrument = (string) ($config['copy-instrument'] ?? '');
        return array(
            (string) ($config['config-description'] ?? ''),
            self::isTrue($config['config-enabled'] ?? false) ? '1' : '0',
            $pull ? self::PULL : self::PUSH,
            (string) ($config['trigger-form'] ?? ''),
            (string) ($config['trigger-logic'] ?? ''),
            (string) ($config['source-key-field'] ?? ''),
            (string) ($config['source-key-event'] ?? ''),
            (string) ($config['dest-project'] ?? ''),
            (string) ($config['dest-key-field'] ?? ''),
            (string) ($config['dest-event'] ?? ''),
            (string) ($config['other-condition'] ?? ''),
            $instrument,
            implode(';', $leaveOut),
            implode(';', $pairs),
            trim($instrument) !== '' && self::isTrue($config['instrument-only-if-empty'] ?? false) ? '1' : '',
            in_array('1', $ifEmpty, true) ? implode(';', $ifEmpty) : '',
        );
    }

    /**
     * A CSV row's config made safe to import: the other project is left blank (a PID from
     * another REDCap server, or an old copy, may be a different project here) and the
     * instruction is switched off until someone chooses the other project and ticks Enabled.
     * The other project's key, event, condition and field names are kept.
     */
    public static function forImport(array $config): array {
        $config['dest-project'] = '';
        $config['config-enabled'] = false;
        return $config;
    }

    /**
     * A config array from one CSV row. Malformed copy-field pairs are reported in $errors.
     * @param array $row values in CSV_COLUMNS order
     */
    public static function fromCsvRow(array $row, int $fileRow, array &$errors): array {
        $values = array();
        foreach (self::CSV_COLUMNS as $i => $column) {
            $values[$column] = (string) ($row[$i] ?? '');
        }
        $direction = strtolower(trim($values['direction']));
        if ($direction !== self::PUSH && $direction !== self::PULL) {
            $errors[] = "Row {$fileRow}: direction must be push or pull.";
        }
        $pairStrings = array_values(array_filter(array_map('trim', explode(';', $values['copy_fields'])), 'strlen'));

        // "Only if empty" flags: 1/0 per pair in the same order; blank = every pair overwrites.
        $flagsText = trim($values['copy_fields_only_if_empty']);
        $flags = $flagsText === '' ? array() : array_map('trim', explode(';', $flagsText));
        if (!empty($flags) && count($flags) !== count($pairStrings)) {
            $errors[] = "Row {$fileRow}: copy_fields_only_if_empty has ".count($flags)." values but copy_fields has ".count($pairStrings)." pairs.";
            $flags = array();
        }
        foreach ($flags as $flag) {
            if ($flag !== '0' && $flag !== '1') {
                $errors[] = "Row {$fileRow}: copy_fields_only_if_empty values must be 1 or 0.";
                $flags = array();
                break;
            }
        }
        $instrumentFlag = trim($values['instrument_only_if_empty']);
        if ($instrumentFlag !== '' && $instrumentFlag !== '0' && $instrumentFlag !== '1') {
            $errors[] = "Row {$fileRow}: instrument_only_if_empty must be 1, 0 or blank.";
        }

        $copyFields = array();
        foreach ($pairStrings as $p => $pairStr) {
            $parts = array_map('trim', explode(':', $pairStr, 2));
            if ($parts[0] === '') {
                $errors[] = "Row {$fileRow}: invalid copy field pair \"{$pairStr}\" (expected field or from:to).";
                continue;
            }
            $from = $parts[0];
            $to = ($parts[1] ?? '') === '' ? $from : $parts[1]; // "field" or "field:" = same name in both projects
            $pair = $direction === self::PULL
                ? array('source-field' => $to, 'dest-field' => $from)
                : array('source-field' => $from, 'dest-field' => $to);
            $pair['only-if-empty'] = ($flags[$p] ?? '0') === '1';
            $copyFields[] = $pair;
        }
        return array(
            'config-description' => $values['description'],
            'config-enabled'     => trim($values['enabled']) === '' || self::isTrue($values['enabled']), // blank = ticked (the default)
            'direction'          => $direction,
            'trigger-form'       => trim($values['trigger_form']),
            'trigger-logic'      => trim($values['trigger_logic']),
            'source-key-field'   => trim($values['key_field']),
            'source-key-event'   => trim($values['key_event']),
            'dest-project'       => trim($values['other_project_id']),
            'dest-key-field'     => trim($values['other_key_field']),
            'dest-event'         => trim($values['other_event']),
            'other-condition'    => trim($values['other_condition']),
            'copy-instrument'    => trim($values['instrument']),
            'instrument-only-if-empty' => $instrumentFlag === '1',
            'leave-out'        => array_values(array_filter(array_map('trim', explode(';', $values['leave_out'])), 'strlen')),
            'copy-fields'        => $copyFields,
        );
    }

    /**
     * This instruction in the raw parallel-array shape setProjectSettings() stores for one
     * instance of a repeatable sub_settings group: scalars for top-level keys, and for the
     * nested 'copy-fields' group a 'true' marker per pair plus parallel field arrays.
     * Mirrors Copy Data on Save's CopyInstruction::getAsModuleSettings().
     */
    public function getAsModuleSettings(): array {
        $settings = array(
            'config-description' => $this->config_description,
            'config-enabled'     => $this->config_enabled,
            'direction'          => $this->direction,
            'trigger-form'       => $this->trigger_form,
            'trigger-logic'      => $this->trigger_logic,
            'source-key-field'   => $this->key_field,
            'source-key-event'   => $this->key_event,
            // The project-id dropdown only shows a string value as selected. No project is saved
            // as null, not '': the framework only lets non-admins change a project-id setting to
            // a project they have Design rights in, and treats '' as a project but null as unset.
            'dest-project'       => $this->other_project_id > 0 ? (string) $this->other_project_id : null,
            'dest-key-field'     => $this->other_key_field,
            'dest-event'         => $this->other_event,
            'other-condition'    => $this->other_condition,
            'copy-instrument'    => $this->copy_instrument,
            'instrument-only-if-empty' => $this->instrument_only_if_empty,
            'leave-out'          => array(),
            'leave-out-field'    => array(),
            'copy-fields'        => array(),
            'source-field'       => array(),
            'dest-field'         => array(),
            'only-if-empty'      => array(),
        );
        foreach ($this->leave_out as $field) {
            $settings['leave-out'][]       = 'true';
            $settings['leave-out-field'][] = $field;
        }
        // Only the configured pairs are saved; the instrument's fields are worked out at run time.
        foreach ($this->explicit_pairs as $pair) {
            $settings['copy-fields'][]  = 'true';
            $settings['source-field'][] = $pair['here'];
            $settings['dest-field'][]   = $pair['there'] === $pair['here'] ? '' : $pair['there'];
            $settings['only-if-empty'][] = !empty($pair['only_if_empty']);
        }
        // An empty group must be saved as one blank row, as REDCap's dialog saves it: with no
        // rows at all the dialog shows no row (and no + button) to add one.
        if (empty($settings['leave-out'])) {
            $settings['leave-out'] = array('true');
            $settings['leave-out-field'] = array(null);
        }
        if (empty($settings['copy-fields'])) {
            $settings['copy-fields'] = array('true');
            $settings['source-field'] = array(null);
            $settings['dest-field'] = array(null);
            $settings['only-if-empty'] = array(null);
        }
        return $settings;
    }
}
